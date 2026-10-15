# ipam, un database IPAM per LibreNMS

Costruisce un database di gestione degli indirizzi a partire da quello che
LibreNMS già raccoglie, e segnala gli indirizzi configurati più di una volta
all'interno della stessa VLAN e dello stesso sito.

Non modifica LibreNMS. Un aggiornamento del core non lo rompe.

**English**: [README.en.md](README.en.md) · Development manual:
[docs/development.md](docs/development.md)

- [A cosa serve](#a-cosa-serve)
- [Siti e sedi](#siti-e-sedi)
- [Uso](#uso)
- [Domini trasversali](#domini-trasversali)
- [Installazione](#installazione)
- [Disinstallazione](#disinstallazione)
- [Aggiornamenti di LibreNMS](#aggiornamenti-di-librenms)
- [Comandi](#comandi)
- [Permessi](#permessi)
- [Avvertenza sulla pagina Validate](#avvertenza-sulla-pagina-validate)
- [Sviluppo](#sviluppo)
- [Struttura](#struttura)

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

In questa installazione **196 dei 224 id VLAN compaiono in più di un sito**, e
servono 621 record per tenerli tutti: quindi il VLAN id da solo non è
un'identità. VLAN 100 a sede centrale e VLAN 100 in un branch sono due domini di
broadcast diversi.

Il plugin aggiunge il contesto che a un indirizzo da solo manca: la rete di
appartenenza, il proprietario, la funzione, e la coppia VLAN/sito.

## Siti e sedi

Un sito **non** è quello che gli apparati riportano. Gli apparati riportano una
`sysLocation`, che è una stanza con scritta male: lo stesso edificio arriva con
una stringa diversa da ogni switch che ci sta dentro. Su questa installazione le
**41 stringhe descrivevano 41 siti** — «Galazzano», «Galazzano Sala B»,
«Galazzano, (Sala server A)» e «AZIENDA0 Galazzano (Sala server A)» sono lo stesso
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

Il sito così creato è un **segnaposto** (`auto_created`): è una stringa con un
nome sopra, non un posto che qualcuno ha scelto. La pagina **Sites and
locations** lo distingue da un sito definito, e i due posti hanno un peso diverso ovunque: un
segnaposto conta come ambito per le collisioni — è un indirizzo in quel
perimetro, e basta — ma **non viene offerto** dove un operatore deve scegliere un
posto vero, per esempio gli endpoint di una rete trasversale. Un segnaposto
diventa sito definito nel momento in cui l'operatore lo rinomina, gli dà
un'indirizzo, o accetta una proposta di raggruppamento.

La pagina **Sites and locations** propone i raggruppamenti: stringhe che condividono una
parola che sembra un toponimo, chiuse transitivamente («se A condivide una parola
con B e B con C, tutti e tre insieme»). La proposta nomina anche i siti che
scomparirebbero. Accettarla fonde i siti, sposta le sedi, e **collassa le VLAN
che erano lo stesso dominio di broadcast contato due volte**: le righe che
puntavano al record «perdente» seguono il superstite, così nessun dato resta con
un piede in due siti.

Su questa installazione le proposte accettate hanno portato da 41 siti a **21**:
**3 siti veri** con le loro sedi (Galazzano 23, AZIENDA1-Sede 6, AZIENDA0-Borgo Maggiore
2), **10 segnaposto** ancora da sistemare e **8 residui** vuoti, rimasti da un
merge che ha portato via l'ultima stanza. Le VLAN del sito sono passate da 601
righe sparse a **130 righe, una per VLAN id**.

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
sedi vi sono state raggruppate. Una rete portata a layer 2 tra più sedi si
dichiara dal blocco stesso, vedi [Domini trasversali](#domini-trasversali).

**VLAN.** Una riga per VLAN per sito, ciascuna con proprietario, sito e
descrizione propria, e le **sedi del sito in cui è stata vista**: una VLAN che
attraversa un edificio resta un record, e le stanze dove è stata riportata
finiscono in `ipam_vlan_locations` invece di moltiplicare la VLAN. Il nome che
LibreNMS legge dagli apparati arriva troncato a 32 caratteri, quindi fa solo da
seme: il testo vero sta in una colonna che nulla tronca. Ogni nome che ogni
apparato ha riportato viene conservato, ed è così che diventa visibile che due
switch chiamano in modo diverso la stessa VLAN. Una VLAN portata a layer 2 tra
più sedi si dichiara dalla pagina stessa: vedi
[Domini trasversali](#domini-trasversali).

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

**Sites and locations** (Siti e sedi). L'elenco dei siti con le sedi raggruppate, i conteggi che dicono
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

**Ciclo di vita: nascondere o cancellare.** La regola è una frase sola e tutto il
resto ne segue. Ciò che **LibreNMS riporta è dell'import**, e le sue righe si
possono **solo nascondere**: il prossimo poll le riporta indietro, quindi
cancellarle sarebbe un modo più lento di nasconderle. Ciò che **un operatore ha
scritto** — o che l'import gli ha **affidato** quando LibreNMS ha smesso di
riportarlo — è suo, e si cancella.

Ed è proprio l'import a decidere quando affida. A un `lnms ipam:import` gli
oggetti che **non ci sono più** in LibreNMS si dividono in due, e il criterio è
la gerarchia:

- **senza gerarchia** erano impalcatura: un `/24` esisteva perché dentro c'era
  un indirizzo, e quell'indirizzo non c'è più. La riga va via.
- **con gerarchia** sono una descrizione di dati vivi: dentro la rete ci sono
  ancora indirizzi, o sotto ci sono altre reti. Cancellarla porterebbe via la
  mappa, quindi l'import **smette di chiamarla sua** (`auto_created` diventa
  falso) e da lì in poi si comporta come una scritta a mano: nascondibile e
  cancellabile, con un bottone che compare.

Le **stanze** seguono la stessa regola: una `sysLocation` che LibreNMS non
conosce più e non ha nulla addosso va via, e con lei il sito segnaposto che era
nato solo per accoglierla. Una stanza che una VLAN dell'operatore nomina, o da
cui arriva un indirizzo, resta.

Due **rifiuti espliciti**, perché sono le due situazioni in cui una cancellazione
farebbe danno: un blocco con indirizzi dentro non si cancella (li lascerebbe
appesi al nulla, e spostarli è una decisione su dove stanno), e una VLAN con
indirizzi classificati dentro neppure. E un import che **non ha letto nulla** non
conclude niente: è anche quello che si vede con LibreNMS mai scoperto, e
cancellare la mappa su quella prova sarebbe la cosa peggiore che il plugin
potesse fare — quindi lo dice e non agisce.

**Imputare a mano sito e sede.** VLAN, reti e indirizzio si possono sempre
attribuire a mano, oltre che ai siti anche alle **sedi**: una rete o un indirizzo
possono essere di un armadio e non solo dell'edificio, una VLAN può essere
scritta per una stanza in cui non è stato ancora collegato niente. Sui
indirizzi vale la stessa regola dei campi di ambito: senza il **pin** la
correzione verrebbe disfatta dal poll successivo, con il pin resta.

**Due record, una sola VLAN.** La chiave `(id, dominio, sito)` non è un vezzo:
è l'ambito in cui si giudica una collisione, quindi due record della stessa VLAN
nello stesso sito sarebbero due domini di broadcast dove ce n'è uno, e lo stesso
indirizzo sui due lati non verrebbe mai segnalato come il conflitto che è. Ma
quella coppia non è un errore da rifiutare, è l'operatore che dice «sono la stessa
cosa»: può arrivare da un merge di due siti, o dal record di una VLAN trascinato
a mano su un sito che quella VLAN la riporta già. In entrambi i casi **i due
record diventano uno**, e li segue tutto quello che puntava al perdente: gli
indirizzi classificati dentro, le stanze in cui è stata vista, il nome che ogni
switch le dava, il dominio stirato di cui faceva parte, i verdetti sulle sue
collisioni. Non si perde nulla di quello che l'operatore aveva scritto sul record
che sparisce. È l'unico caso in cui un'operazione del plugin **cambia il numero
di righe** di una tabella, e lo dice.

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
descritta, così l'import non la rinomina; una rete aggiunta a mano non è
dell'import, quindi non la tocca. I valori lasciati su «inherit» vengono dal
blocco che contiene l'indirizzo, come all'import. Ogni cosa aggiunta a mano si
può **cancellare**, oltre che nascondere: vedi
[Ciclo di vita](#uso).

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
mostra gli stessi numeri; su questa installazione dice `in sync` su **547/547
indirizzi** e **621/621 coppie VLAN/sito**.

Da rivedere: dopo l'import iniziale, **66 indirizzi sui 425 visibili restano non
classificati**, perché stanno su host e firewall e non su porte di switch. Non
hanno né VLAN né sito, quindi non partecipano al rilevamento delle collisioni.
Basta descrivere un blocco per classificarli tutti insieme.

## Domini trasversali

Alcune VLAN e alcune reti **non si fermano a un sito**: sono trasportate a layer 2
su fibra, su una E-Line, o dentro una S-VID 802.1ad con QinQ, e arrivano all'altra
sede **uguali**. Sono due cose diverse da dichiarare, perché sono due livelli:

| | che cosa è | dove si dichiara | tabella |
|---|---|---|---|
| **dominio di broadcast** | la VLAN: quale zona L2 è un'unica zona | pagina **VLAN**, riga della VLAN | `ipam_vlan_groups` |
| **subnet** | la rete: quale piano di indirizzamento esiste invariato | pagina **Mappa reti**, riga del blocco | `ipam_network_stretches` |

Perché serve una dichiarazione e non basta l'import: **nessun apparato riporta la
propria topologia di layer 2**. Un device sa dire «sono nella VLAN 999», non
«questa VLAN arriva qui dalla sede di Galazzano su una fibra». L'unica fonte è
l'operatore, quindi è una decisione: si prende una volta, e da lì in poi la
riconosce il plugin. **L'import non la tocca mai**, in nessuna direzione.

Quello che il plugin legge da solo resta giusto: **una VLAN per sito** è ciò che
gli apparati riportano, ed è la lettura corretta per i 196 id su 224 che sono solo
lo stesso numero riusato in locale. La trasversalità è un'eccezione dichiarata,
non il default.

### Quando conviene dichiararla, e quando no

Dichiara un dominio **quando lo stesso indirizzo alle due estremità sarebbe un
conflitto vero**: è quello che succede a un gateway, a un indirizzo di gestione,
a un servizio che esiste una volta sola per l'intero circuito.

**Non** dichiararla per due siti che semplicemente riusano lo stesso numero di
VLAN: sono due domini di broadcast distinti, il sito li tiene separati, e i due
punti con lo stesso indirizzo sono due cose diverse. Dichiararli insieme
produrrebbe un conflitto che non esiste.

### VLAN: come si fa

1. Apri **Plugins → IPAM → Vlans** e trova la riga della VLAN nel sito da cui
   parte il trasporto.
2. Clicca **Edit**. Nella sezione **Stretched across sites** spunta i record
   fratelli — sono le righe con lo **stesso id e dominio in un altro sito**, che
   sono esattamente le candidate che l'import ha creato per quello stesso numero.
3. Scrivi **come viaggia** nel campo accanto: «QinQ dentro S-VID 400, fibra
   Galazzano – AZIENDA1 Sede». Il testo vive sul dominio e ogni membro lo mostra,
   perché è una cosa che nessuno riporta e che altrimenti finisce in un foglio
   di calcolo.
4. **Link as one domain**.

Da quel momento, dentro il dominio, **la VLAN è da sola l'ambito**: la sede smette
di separare e lo stesso indirizzo alle due estremità è **un conflitto**, perché a
layer 2 nessuno può possederlo due volte. Il report lo etichetta
`vlan 999 (Galazzano + AZIENDA1-Sede)`, e il verdetto «previsto» si dà **una volta
sul dominio** e vale per tutte le sedi.

Per tornare indietro: **Take out of the domain** su uno qualsiasi dei membri. Se
ne resta uno solo, quello torna una VLAN locale e il gruppo se ne va da sé.

### Reti: come si fa

1. Apri **Plugins → IPAM → Network map** e trova il blocco (per esempio
   `172.31.225.0/24`), clicca **Edit**.
2. Nella sezione **Stretched across places** spunta i posti che la subnet
   raggiunge e scrivi come viaggia.
3. **Declare it stretched**.

I posti offerti sono due soli, e la scelta è deliberata:

- i **siti che un operatore ha definito e che hanno almeno una sede** — i posti
  veri;
- le **sede che LibreNMS dice avere la rete e che non appartengono a nessun
  sito**, per il caso di un segmento che arriva in un solo armadio.

Un **segnaposto non viene offerto**: è il posto di ripiego creato dal resolver
per una `sysLocation` che nessuno ha ancora sistemato, non un luogo che qualcuno
ha scelto. Se la tua rete arriva in un posto che è ancora un segnaposto, la strada
è definire quel sito (o accettare la proposta di raggruppamento sulla pagina
**Sites and locations**) e poi tornare qui.

Da quel momento, dentro il dominio, **la sede smette di separare e l'ambito è la
VLAN per numero**. La conseguenza pratica: lo stesso indirizzo alle due estremità
della fibra è **un conflitto** anche se le due sedi hanno due record VLAN
diversi — che è proprio il caso, perché quei due record sono lo stesso dominio di
broadcast. Il report lo etichetta `172.31.225.0/24 (Galazzano + AZIENDA1-Sede)`.
Il verdetto «previsto» si dà una volta sul dominio.

Da notare che **due VLAN diverse dentro la stessa rete trasversale restano due
domini**: sono due zone L2 che riusano lo stesso piano di indirizzamento, e
nessuna delle due è in conflitto con l'altra.

### Le regole che valgono per entrambi

- **L'import non guarda e non tocca**. Nessun poll crea, sposta o cancella un
  dominio: se lo facesse, un `ipam:import` a ogni ciclo di polling potrebbe
  ribaltare una decisione presa al tavolo.
- **Un blocco dentro un dominio lo eredita**, senza che nessuno lo dichiari: una
  rete dentro un'altra è dentro quella, perché il dominio copre il blocco e tutto
  ciò che sta sotto. Per questo le righe create dall'import sotto una rete
  stirata nascono già nel dominio.
- **Non si può stirare due volte**: dichiarare una rete che è già dentro un'altra
  è **rifiutato**, con il messaggio che dice dentro quale. Altrimenti «dove
  arriva?» avrebbe due risposte.
- **Due domini che si incontrano si fondono**: se colleghi una VLAN che era già in
  un dominio a un'altra che ne aveva uno suo, finisce in un dominio solo, e la nota
  sul trasporto sopravvive.
- **Un dominio non si cancella**: si toglie la dichiarazione. L'unica cosa che
  sparisce con lui è il gruppo, quando non ha più membri.

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
php artisan route:list (./lnms route:list) | grep -c ipam    # 33 rotte
```

Con il symlink, `vendor/dercol1/librenms-plugin-ipam` punta alla directory di
lavoro. Gli aggiornamenti del plugin sono un `git pull` (o un copia-in-colla) e
null'altro.

### Versione released

Per un'installazione stabile, da un tag. Serve un repository git remoto con i
tag, per esempio:

```sh
git remote add origin git@github.com:dercol1/librenms-plugin-ipam.git
git tag -a v1.0.0 -m "prima release"
git push origin main --tags
```

Sul server si registra il repository come `vcs` e si richiede un tag preciso:

```sh
cd /opt/librenms
php composer.phar config repositories.ipam vcs git@github.com:dercol1/librenms-plugin-ipam.git
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

`ipam:uninstall` elimina le quattordici tabelle `ipam_*`, le due righe
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
php artisan route:list | grep -c ipam      # 33
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

`ipam:import` si può rieseguire senza problemi, ed è pensato per girare a ogni
ciclo di polling. Aggiorna ciò che ha visto e conserva ciò che un operatore ha
bloccato. Sul ciò che LibreNMS ha smesso di riportare decide in base alla
gerarchia: **senza** nulla addosso la riga viene rimossa, **con** ancora dati
sopra viene **affidata all'operatore** — smette di essere dell'import e diventa
una riga che si può nascondere o cancellare come una scritta a mano. Il comando
dice entrambe le cose, e non conclude niente se non ha letto nulla.

Le righe nelle range escluse vengono nascoste solo alla creazione: una riga
riportata in luce a mano resta tale per ogni import successivo.

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
composer install                # una volta sola
vendor/bin/phpunit              # 251 test
composer test:lint              # pint
composer test:types             # phpstan, livello 6
composer test                   # i tre in fila
```

I tre gate sono **bloccanti**: nessuna modifica al plugin si considera finita con
i test verdi ma phpstan o pint che si lamentano.

```sh
cd /opt/librenms
php artisan optimize:clear      # dopo aver cambiato una rotta
php artisan ipam:install        # dopo aver cambiato lo schema
php artisan ipam:import         # per vedere l'effetto sui dati veri
```

I test girano su sqlite con le tabelle di LibreNMS che il plugin legge ricreate a
mano, quindi non servono né database né rete. Il **manuale di sviluppo** — architettura,
come si aggiunge una funzione, le convenzioni e i trabocchetti già incontrati — è
in [docs/development.md](docs/development.md).

## Struttura

```
README.md / README.en.md     il documento, in italiano e in inglese
docs/development.md          il manuale di sviluppo, in inglese
config/ipam.php              valori di default, tutti sovrascrivibili dalla pagina
resources/lang/en/ipam.php   ogni stringa mostrata dal plugin
resources/views/             le sei pagine e i frammenti condivisi (nav, statistiche,
                             cella del sito, liste di opzioni condivise, riempimento,
                             pannello indirizzi liberi)
src/Collision/               la regola delle collisioni
src/Import/                  vlan e indirizzi, la risoluzione sysLocation → sede → sito,
                             e la riconciliazione di ciò che LibreNMS ha smesso di riportare
src/Sites/                   proposte di raggruppamento e fusione dei siti
src/Networks/                le reti stirate: i posti che raggiungono e cosa ne segue
src/Vlans/                   i domini di broadcast che attraversano i siti, e il
                             collasso di due record della stessa vlan in uno
src/Sync/                    il confronto tra l'ipam e ciò che LibreNMS polla
src/Models/                  le tabelle del plugin
src/Support/                 aritmetica degli indirizzi, schema
src/Web/                     controller, directory dei siti, ricerca di indirizzi liberi
src/Console/                 i sette comandi
tests/                       251 test
```
