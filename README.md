# ipam, un database IPAM per LibreNMS

Costruisce un database di gestione degli indirizzi a partire da quello che
LibreNMS già raccoglie, e segnala gli indirizzi configurati più di una volta
all'interno della stessa VLAN e dello stesso sito.

Non modifica LibreNMS. Un aggiornamento del core non lo rompe.

- [A cosa serve](#a-cosa-serve)
- [Siti e sedi](#siti-e-sedi)
- [Uso](#uso)
- [Installazione](#installazione)
- [Disinstallazione](#disinstallazione)
- [Aggiornamenti di LibreNMS](#aggiornamenti-di-librenms)
- [Comandi](#comandi)
- [Avvertenza sulla pagina Validate](#avvertenza-sulla-pagina-validate)
- [Sviluppo](#sviluppo)

## A cosa serve

Lo stesso indirizzo in due VLAN diverse è addressing normale. Lo stesso
indirizzo due volte dentro una sola VLAN in un solo sito è un conflitto che
nessuno può instradare. Quel distinguimento è il motivo per cui VLAN e sito
fanno parte della chiave, ed è anche il motivo per cui un report che conta solo
le ripetizioni sarebbe inutile: portare `10.0.0.1` come gateway su ogni branch è
corretto, e segnalarlo quaranta volte seppellirebbe l'unico caso che conta.

Basta **uno** dei due per collocare un indirizzo: due volte nello stesso sito
senza VLAN nota sono comunque un conflitto (un gateway wlan portato da tre
access point è così che è saltato fuori), e due volte nella stessa VLAN senza
sito noto anche. Non collocabile è solo chi non ha né l'una né l'altro, e finisce
nella coda dei non classificati.

In un'installazione tipica **141 dei 220 VLAN id compaiono in più di un sito**,
quindi il VLAN id da solo non è un'identità. VLAN 100 a sede centrale e VLAN 100
in un branch sono due domini di broadcast diversi.

Il plugin aggiunge il contesto che a un indirizzo da solo manca: la rete di
appartenenza, il proprietario, la funzione, e la coppia VLAN/sito.

## Siti e sedi

Un sito **non** è quello che gli apparati riportano. Gli apparati riportano una
`sysLocation`, che è una stanza con scritta male: lo stesso edificio arriva con
una stringa diversa da ogni switch che ci sta dentro. In questa installazione
**38 stringhe descrivono circa 5 posti** — «Galazzano», «Galazzano Sala B»,
«Galazzano, (Sala server A)» e «SSIS Galazzano (Sala server A)» sono lo stesso
sito.

Preso alla lettera, ognuno di quei nomi diventa un sito a sé, e la stessa VLAN
sembra un dominio di broadcast diverso per ogni stanza: due switch dello stesso
edificio non vengono mai riconciliati e il report delle collisioni si riempie di
coppie che sono la stessa rete descritta due volte.

Il plugin quindi tiene separati i due concetti:

- la **sede** (`ipam_locations`) è la stringa che un apparato ha riportato,
  riportata identica e mai indovinata;
- il **sito** (`ipam_sites`) è il raggruppamento, e a deciderlo è solo un
  operatore.

Una `sysLocation` che nessuno ha ancora sistemato ottiene un sito suo, così
niente va perso e ogni indirizzo resta classificabile. Sistemarla sotto il sito
vero è un'azione, e siccome il sito è l'ambito in cui si giudica una collisione,
farlo è ciò che riconcilia due stanze dello stesso edificio.

La pagina **Siti e sedi** propone i raggruppamenti: stringhe che condividono una
parola che sembra un toponimo, chiuse transitivamente («se A condivide una parola
con B e B con C, tutti e tre insieme»). La proposta nomina anche i siti che
scomparirebbero. Accettarla fonde i siti, sposta le sedi, e **collassa le VLAN
che erano lo stesso dominio di broadcast contato due volte**: le righe che
puntavano al record «perdente» seguono il superstite, così nessun dato resta con
un piede in due siti.

In questa installazione, fondere le dieci sedi di Galazzano porta le VLAN del
sito da 601 righe sparse a **130 righe, una per VLAN id**.

## Uso

Sei pagine sotto **Plugins → IPAM**, con la grafica di LibreNMS: pannelli,
badge, filtro per sito su tutte le liste. Ogni pagina mostra in testa i numeri
che dicono se vale la pena aprirla, e il pulsante **Page** in Plugin Admin apre
un indice con gli stessi numeri.

**Mappa reti.** L'albero delle reti. Importando un solo `10.14.146.0/24` vengono
creati anche il `10.14.0.0/16` e il `10.0.0.0/8` che lo contengono: sito,
proprietario e funzione si impostano una volta sola sul `/8` e tutto sotto
eredita. È questo che rende descrivibile un segmento ampio. Un blocco descritto a
mano resta tale: l'import non lo tocca. La colonna del sito mostra anche quante
sedi vi sono state raggruppate.

**VLAN.** Una riga per VLAN per sito, ciascuna con proprietario, sito e
descrizione propria, e le **sedi del sito in cui è stata vista**: una VLAN che
attraversa un edificio resta un record, e le stanze dove è stata riportata
finiscono in `ipam_vlan_locations` invece di moltiplicare la VLAN. Il nome che
LibreNMS legge dagli apparati arriva troncato a 32 caratteri, quindi fa solo da
seme: il testo vero sta in una colonna che nulla tronca. Ogni nome che ogni
apparato ha riportato viene conservato, ed è così che diventa visibile che due
switch chiamano in modo diverso la stessa VLAN.

**Indirizzi.** Ogni indirizzo con il blocco, la VLAN, il sito — sede compresa —
e il proprietario a cui è stato assegnato. Nel campo di ricerca si può mettere
una CIDR per vedere tutto il segmento. Ogni classificazione si può correggere a
mano e bloccare, e il blocco fa sì che l'import successivo non la tocchi. La
stessa classificazione si può applicare a un blocco intero in un colpo solo,
di default solo agli indirizzi non ancora classificati.

**Collisioni.** Gli indirizzi configurati più di una volta dentro la stessa VLAN e
lo stesso sito, con porte e apparati dove sono stati visti, raggruppati per
sito. Una collisione intenzionale, per esempio un indirizzo flottante, si marca
come prevista con una motivazione e smette di comparire.

**Siti e sedi.** L'elenco dei siti con le sedi raggruppate, i conteggi che dicono
quanto ciascuno pesa, le proposte di fusione, e le sedi non ancora sistemate.

**Proprietari e funzioni.** I tre elenchi da cui le altre pagine attingono:
proprietari di rete, funzioni di rete, proprietari di VLAN. Fino a questa pagina
le dropdown erano a sola lettura — a decriverle era solo il database, e con le
tabelle vuote le colonne Owner e Function non potevano essere impostate da
nessuno. Si aggiungono voci con nome e descrizione, si rinominano, e si
cancellano **solo quando nulla le punta**: il rifiuto dice dove sono usate
(quanti blocchi, indirizzi o VLAN), perché i riferimenti sono colonne piane
senza chiave esterna e sarebbe il genere di danno silenzioso che nessun engine
fermerebbe. Le pagine con le liste vuote mostrano il collegamento finché non
c'è almeno una voce.

Ogni opzione «--» dei form spedisce **0**, perché 0 è il valore che significa
«inherit dal blocco» o «nessuno». Per tre volte la regola di validazione è
stata riscritta con un `exists:` che rifiuta lo 0 — nessuna riga ha chiave 0 —
e per tre volte un form parzialmente compilato è stato rifiutato in silenzio:
modificare un blocco «auto» sulla Mappa reti impostando solo il sito non
salvava nulla e non mostrava nulla. La regola ora è **una sola classe condivisa**
(`ScopeRules`): 0 passa sempre, qualsiasi altro valore deve esistere. E le
pagine mostrano finalmente i **messaggi**: il flash di successo e di errore
(19 messaggi che nessuna vista disegnava) e l'elenco dei rifiuti di
validazione, così un form rifiutato non è più indistinguibile da uno che ha
funzionato.

**VLAN trasversali ai siti.** Una VLAN trasportata a layer 2 — fibra, E-Line,
o QinQ dentro una S-VID 802.1ad — è **un solo dominio di broadcast che arriva
uguale a più sedi**, mentre il plugin ne legge una per sito: è quello che gli
apparati riportano, ed è la lettura giusta per gli id che sono solo lo stesso
numero riusato in locale. Sulla pagina VLAN ogni record offre **«Stretched
across sites»**: si spuntano i record fratelli (stesso id e dominio, altra
sede) e si scrive come viaggia — la nota vive sul dominio e ogni membro la
mostra. Da lì la regola delle collisioni cambia: **dentro un dominio la VLAN è
da sola l'ambito**, la sede smette di separare, e lo stesso indirizzo alle due
estremità diventa un conflitto, perché a layer 2 nessuno può possederlo due
volte. Il verdetto «previsto» si dà una volta sul dominio e vale per tutte le
sedi. Il collegamento è una **decisione dell'operatore** che l'import non tocca
mai: due gruppi che si incontrano si fondono, un membro tolto lascia il resto
intatto, l'ultimo che resta riporta la VLAN locale e il gruppo se ne va.

I **nomi sono unici**, e per un po' l'unica cosa che lo faceva rispettare era
il vincolo del database: rinominare un sito (o una voce degli elenchi) su un
nome già occupato tornava come errore 500 con uno stack trace. Ora il rename
dà una delle tre risposte possibili: **libero** se nessuno lo tiene, **assorbito**
se lo tiene un sito svuotato dai merge (il residuo viene rimosso e l'operatore
ne è informato), **rifiutato** con le istruzioni se lo tiene un sito con dati.
La fonte dei residui è stata chiusa alla radice: spostare l'ultima sede fuori da
un sito che non ha altro lo rimuove subito, invece di lasciare un nome che
aspetta di scontrarsi con un rename.

**Il gruppo di chi usa una voce.** Il badge «3 blocks» su una voce è un link:
atterra sulla pagina che porta quei blocchi, con il filtro già applicato, così
la classificazione si rivede senza scorrere tutto l'elenco. I filtri owner e
function sono selezionabili a mano su Mappa reti, VLAN e Indirizzi.

**Selezione e riclassificazione in blocco.** Sulla pagina Indirizzi le righe si
spuntano, e il pannello «Reclassify the selection» imposta sito, VLAN,
proprietario e funzione una volta sola: un campo lasciato com'è non viene
toccato, «inherit» riporta la colonna a 0. La spunta «every address matching
the filters» prende **tutte** le righe che rispettano i filtri correnti, non
solo la pagina — e il server ricostruisce l'insieme con lo stesso builder
della lista, quindi cambia esattamente ciò che l'operatore stava guardando,
permessi di dispositivo inclusi.

**Ordinamento.** Le intestazioni di colonna su Indirizzi, Mappa reti, VLAN e
Proprietari ordinano l'elenco, e tre click fanno un giro completo: ascendente,
discendente, poi l'ordine naturale della pagina. La colonna attiva è segnata
dalla freccia, e il tipo di ordinamento non butta via i filtri.

**Oggetti nascosti.** Loopback, link-local e multicast esistono su ogni
dispositivo e non dicono nulla della rete: `127.0.0.1` visto su tre apparati
leggerebbe come una collisione a tre. Le **range escluse** (modificabili nelle
impostazioni) vengono importate ma tenute fuori da ogni elenco, da ogni conteggio
e dal rilevamento delle collisioni. Nascosto non vuol dire cancellato: ogni
pagina ha il toggle «show hidden», e dal form di modifica si nasconde o si
riporta in luce una riga alla volta — una blocco intero con tutto ciò che sta
sotto — e l'import non tocca mai più quella scelta. La colonna si chiama
`ignored` come in LibreNMS (`devices.ignore`, `ports.ignore`): «presente, ma
fuori dai report».

**Aggiungere oggetti.** Reti, indirizzi e VLAN si possono registrare a mano, con
tutti i valori — sito, VLAN, proprietario, funzione, descrizione — e non solo
quello che LibreNMS already sa: un firewall non ancora acceso, una riserva in
consegna, il gateway di un segmento che non è ancora andato in servizio.
Un indirizzo aggiunto a mano nasce **pinned**, così l'import non lo cancella per
il solo fatto di non vederlo in LibreNMS; una VLAN aggiunta a mano nasce
descritta, così l'import non la rinomina. I valori lasciati su «inherit» vengono
dal blocco che contiene l'indirizzo, come all'import.

**Indirizzi liberi.** Il pannello sulla pagina Indirizzi — o `lnms ipam:free
10.14.146.0/24` — risponde alla domanda che la ricerca non può fare: cosa posso
consegnare qui dentro. Riporta quanti sono in uso, gli **intervalli liberi** come
coppie da-a, e i primi indirizzi concreti da usare. Gli indirizzi nascosti
contano come usati, e questo è voluto: sono configurati su apparati veri, solo
poco interessanti.

**Allineamento.** `lnms ipam:sync` confronta l'IPAM con ciò che LibreNMS sta
effettivamente pollando, con la stessa identità e gli stessi filtri dell'import:
quanto non è ancora stato importato, quanto non è più riportato e verrà rimosso
al prossimo import. Le righe aggiunte a mano non sono attese in LibreNMS, quindi
non contano come deriva. Il pannello **LibreNMS and the ipam** sul pulsante Page
mostra gli stessi numeri; in questa installazione, dopo la pulizia delle VLAN
residue dei vecchi siti, dice `in sync` su 556/556 indirizzi e 752/752 coppie
VLAN/sito.

Da rivedere: dopo l'import iniziale, **68 indirizzi su 556 restano non
classificati**, perché stanno su host e firewall e non su porte di switch. Non
hanno né VLAN né sito, quindi non partecipano al rilevamento delle collisioni.
Basta descrivere un blocco per classificarli tutti insieme.

## Installazione

Il pacchetto si registra come plugin con `php artisan plugin:add`, che scrive
`composer.plugins.json`. Quel file è ciò che fa sopravvivere il plugin agli
aggiornamenti di LibreNMS, vedi [Aggiornamenti](#aggiornamenti-di-librenms).

### Versione dev

Per sviluppare, con un symlink: le modifiche ai file hanno effetto subito.

Il repository `path` va dichiarato una volta sola, nella configurazione composer
globale. Dove sia dipende dall'installazione, quindi conviene chiederlo:

```sh
cd /opt/librenms
COMPOSER_HOME_DIR=$(php composer.phar config --global home)
echo "$COMPOSER_HOME_DIR"
```

Su questa installazione è `/opt/librenms/.config/composer`. Il file da creare o
modificare è `config.json` lì dentro, con la lista dei repository in `repositories`:

```sh
mkdir -p "$COMPOSER_HOME_DIR"
cat > "$COMPOSER_HOME_DIR/config.json" <<'JSON'
{
    "config": {},
    "repositories": [{
        "name": "ipam",
        "type": "path",
        "url": "/opt/librenms-plugin-ipam",
        "options": { "symlink": true }
    }]
}
JSON
```

Se il file esiste già, a causa di plugin, va **aggiunto** un
elemento all'array `repositories` senza sovrascriverlo.

Poi:

```sh
cd /opt/librenms-plugin-ipam
php /opt/librenms/composer.phar install     # solo per i test

cd /opt/librenms
php composer.phar require dercol1/librenms-plugin-ipam:@dev
php artisan ipam:install (./lnms ipam:install)
php artisan ipam:import (./lnms ipam:import)
```

Verifica:

```sh
./lnms route:clear
php artisan ipam:check (./lnms ipam:check)
php artisan route:list (./lnms route:list) | grep ipam      # 11 rotte 
```

Con il symlink, `vendor/dercol1/librenms-plugin-ipam` punta alla directory di
lavoro. Gli aggiornamenti del plugin sono un `git pull` (o un copia-in-colla) e
null'altro.

### Versione released

Per un'installazione stabile, da un tag. Serve un repository git remoto con i
tag, per esempio:

```sh
git remote add origin git@github.com:<utente>/librenms-plugin-ipam.git
git tag -a v1.0.0 -m "prima release"
git push origin main --tags
```

Sul server si registra il repository come `vcs` e si richiede un tag preciso:

```sh
cd /opt/librenms
php composer.phar config repositories.ipam vcs git@github.com:<utente>/librenms-plugin-ipam.git
php composer.phar require dercol1/librenms-plugin-ipam:1.0.0
php artisan ipam:install
php artisan ipam:import
```

`composer.plugins.json` riceverà la versione esatta, `1.0.0`, e non `@dev`.
Questo è ciò che conta: con un riferimento preciso `daily.sh` reinstalla la stessa
versione a ogni aggiornamento, mentre con `@dev` prende sempre l'ultimo commit
disponibile.

Da notare: `composer install --no-dev` viene eseguito da `daily.sh`, quindi le
dipendenze di sviluppo del plugin non vengono reinstallate. Installarle con:

```sh
cd /opt/librenms-plugin-ipam && php /opt/librenms/composer.phar install
```

## Disinstallazione

In due passi, perché sono due cose diverse. Il primo tocca il database, il
secondo deregistra il pacchetto.

```sh
cd /opt/librenms

# 1. chiede conferma e mostra quante righe sta per cancellare
php artisan ipam:uninstall

# 2. deregistra il pacchetto
php composer.phar remove dercol1/librenms-plugin-ipam
```

`ipam:uninstall` elimina le undici tabelle `ipam_*`, le due righe
`ipam.view` e `ipam.manage` dalla tabella `permissions`, e disattiva il plugin.
Chiede conferma e mostra il conteggio prima di cancellare:

```
This deletes the ipam database: every classification, description and owner you entered.

  ipam_sites             38 rows
  ipam_networks          285 rows
  ipam_vlans             796 rows
  ipam_addresses         556 rows
  ...
```

Due varianti:

```sh
php artisan ipam:uninstall --keep-data    # tabelle restano, solo permessi e impostazioni via
php artisan ipam:uninstall --force        # non chiede conferma
```

Per uno script automatico, ricordare che `composer remove` va eseguito da
`/opt/librenms`, non dalla directory del plugin.

Resta una cosa da fare a mano: gli altri plugin e le altre voci in
`composer.plugins.json` restano, sono indipendenti dal plugin. L'elemento
`"name": "ipam"` nella configurazione composer globale si può togliere, ma non
serve: senza il pacchetto non viene risolto e non dà fastidio.

Verifica finale:

```sh
php artisan ipam:check                    # deve dire che le tabelle non ci sono
grep -c ipam composer.plugins.json         # deve dire 0
```

## Aggiornamenti di LibreNMS

`daily.sh` gestisce i plugin da solo, purché il pacchetto sia in
`composer.plugins.json`. Ecco cosa succede quando aggiorna (righe 296-368 di
`daily.sh`):

```sh
php artisan optimize:clear                 # cache rotte, config e viste
git checkout -- composer.json composer.lock # ripristina composer.json
git pull                                    # o git checkout <tag>
# post-pull:
#   legge composer.plugins.json e rimette i plugin in composer.json
#   composer install --no-dev
./lnms migrate --force --no-interaction --isolated
```

Il punto delicato è la terza riga. `daily.sh` fa `git checkout` di
`composer.json`, quindi la riga che `plugin:add` aveva aggiunto **viene
ripristinata via git e rimossa**. Il plugin viene rimesso subito dopo, leggendo
`composer.plugins.json`, che è in `.gitignore` e quindi quel checkout non lo
toccano.

In pratica: **finché la voce è in `composer.plugins.json`, un aggiornamento non
perde il plugin.** Se invece si installa il plugin solo modificando a mano
`composer.json`, al primo aggiornamento sparisce.

Da verificare dopo un aggiornamento:

```sh
php artisan ipam:check
grep ipam composer.plugins.json
php artisan route:list | grep -c ipam      # 27
```

### Le tabelle del plugin e `lnms migrate`

Le tabelle `ipam_*` sono create da `ipam:install`, non da una migration. È
voluto.

Il plugin non registra alcuna migration, quindi `lnms migrate` non le tocca e
non può interrompersi a metà su di esse. Registerne una sarebbe stato peggio:
una migration registrata finisce nella tabella `migrations`, e
`CheckDatabaseSchemaVersion` la segnala come migration inattesa, restituendo
`false`. E quel `false` fa saltare `CheckSchemaStructure::enabled()`, che
**disattiverebbe in silenzio il controllo dello schema di LibreNMS**.

Se dopo un aggiornamento le tabelle mancano, il caso è semplice:

```sh
php artisan ipam:install
php artisan ipam:import
```

### Se si aggiorna il plugin

Il plugin non tocca il database di LibreNMS, quindi un suo aggiornamento non
richiede `lnms migrate`. Le sue tabelle hanno un solo proprietario e vengono
aggiornate dal codice del plugin stesso.

Un aggiornamento che aggiunge tabelle o colonne le crea al primo comando, perché
`ipam:install` confronta quello che c'è con quello che il plugin definisce:

```sh
php artisan ipam:install
```

Le impostazioni salvate nella pagina di configurazione sopravvivono a un
aggiornamento del plugin. Quelle salvate in `config/ipam.php`, no: il file non
viene più toccato e i valori diventano orfani. Meglio la pagina.

## Comandi

```sh
php artisan ipam:install              # crea le tabelle e registra i permessi
php artisan ipam:uninstall            # rimuove tabelle, permessi e impostazioni
php artisan ipam:import               # ricostruisce da ciò che LibreNMS raccoglie
php artisan ipam:import --vlans       # solo le vlan
php artisan ipam:import --json        # output leggibile da macchina
php artisan ipam:duplicates           # il report delle collisioni
php artisan ipam:duplicates --unclassified   # gli indirizzi senza vlan e senza sito
php artisan ipam:duplicates --site=3         # un solo sito
php artisan ipam:duplicates --all            # comprese quelle segnate come previste
php artisan ipam:check                # verifica le tabelle del plugin
php artisan ipam:sync                 # l'ipam e LibreNMS dicono la stessa cosa?
php artisan ipam:sync --json          # lo stesso, per macchine
php artisan ipam:free 10.14.146.0/24  # gli indirizzi liberi di un segmento
php artisan ipam:free 10.14.146.0/24 --suggest=3   # solo i primi tre
```

`ipam:import` si può rieseguire senza problemi. Aggiorna ciò che ha visto,
conserva ciò che un operatore ha bloccato, e rimuove solo le occorrenze che
LibreNMS ha smesso di segnalare. Le righe nelle range escluse vengono nascoste
solo alla creazione: una riga riportata in luce a mano resta tale per ogni
import successivo.

## Permessi

| Permesso     | Consente                                                |
|--------------|---------------------------------------------------------|
| `ipam.view`  | leggere le sei pagine                               |
| `ipam.manage`| descrivere blocchi, riclassificare, fondere siti, aggiungere oggetti, annotare collisioni |

`admin` li ha entrambi, `global-read` solo `ipam.view`. Chi ha `plugin.admin`
passa comunque.

Le pagine degli indirizzi e delle collisioni applicano anche la restrizione per
apparato di LibreNMS, così l'IPAM non serve a scoprire cosa c'è su un apparato a
cui l'utente non ha accesso.

## Avvertenza sulla pagina Validate

LibreNMS confronta il database con `db_schema.yaml`, che descrive lo schema che
si aspetta di trovare. Ogni tabella che non conosce viene segnalata come "extra
table" e il correttore propone di eliminarla. Le tabelle `ipam_*` sono esattamente
così: corrette, e sconosciute al core.

**Non applicare quel correttore.** Eliminerebbe le tabelle del plugin.

`ipam:check` risponde alla domanda che su queste tabelle vale davvero: se sono
tutte presenti, se le colonne corrispondono a quelle che questa versione si
aspetta, e quante righe contengono.

## Sviluppo

```sh
vendor/bin/phpunit          # 199 test
composer test:lint          # pint
composer test:types         # phpstan, livello 6
```

phpstan gira con il binario e con larastan di LibreNMS, la coppia che LibreNMS
rilascia e collauda insieme. Una seconda copia dentro il plugin produceva due
definizioni di servizio incompatibili per la stessa estensione, e una terza
combinazione faceva risolvere a larastan `view()` contro un'applicazione non
avviata, così ogni `view()` del plugin sembrava un errore di battitura.

I test girano su sqlite con le tabelle di LibreNMS che il plugin legge ricreate a
mano, quindi non servono né database né rete.

Impostare `LIBRENMS_PATH` se LibreNMS non è in `/opt/librenms`.

## Struttura

```
config/ipam.php              valori di default, tutti sovrascrivibili dalla pagina
resources/lang/en/ipam.php   ogni stringa mostrata dal plugin
resources/views/             le sei pagine e i frammenti condivisi (nav, statistiche,
                             cella del sito, liste di opzioni condivise, riempimento,
                             pannello indirizzi liberi)
src/Collision/               la regola delle collisioni
src/Import/                  vlan e indirizzi, la risoluzione sysLocation → sede → sito
src/Sites/                   proposte di raggruppamento e fusione dei siti
src/Sync/                    il confronto tra l'ipam e ciò che LibreNMS polla
src/Models/                  le tabelle del plugin
src/Support/                 aritmetica degli indirizzi, schema
src/Web/                     controller, directory dei siti, ricerca di indirizzi liberi
src/Console/                 i sette comandi
tests/                       199 test
```
