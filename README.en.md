# ipam, an IPAM database for LibreNMS

Builds an address management database out of what LibreNMS already collects, and
reports the addresses that are configured more than once inside the same VLAN and
the same site.

It does not modify LibreNMS. A core update does not break it.

**Italiano**: [README.md](README.md) · Development manual:
[docs/development.md](docs/development.md)

- [What it is for](#what-it-is-for)
- [Sites and locations](#sites-and-locations)
- [Using it](#using-it)
- [Stretched domains](#stretched-domains)
- [Installation](#installation)
- [Uninstallation](#uninstallation)
- [LibreNMS updates](#librenms-updates)
- [Commands](#commands)
- [Permissions](#permissions)
- [A warning about the Validate page](#a-warning-about-the-validate-page)
- [Development](#development)
- [Layout](#layout)

## What it is for

The same address in two different VLANs is normal addressing. The same address
twice inside one VLAN at one site is a conflict nobody can route. That distinction
is why the VLAN and the site are part of the key, and it is also why a report that
only counted repetitions would be useless: carrying `10.0.0.1` as a gateway at
every branch is correct, and reporting it forty times would bury the only case
that matters.

Either one of the two places an address on its own: twice in the same site with no
known VLAN is still a conflict (a wireless gateway carried by three access points
is how that one turned up), and so is twice in the same VLAN with no known site.
Only a row with neither is unplaceable, and it goes to the unclassified queue.

On this install **196 of 224 VLAN ids show up at more than one site**, and it
takes 621 records to hold them all: the VLAN id on its own is not an identity.
VLAN 100 at head office and VLAN 100 at a branch are different broadcast domains.

What the plugin adds is the context an address cannot have on its own: the
network it belongs to, the owner, the function, and the VLAN/site pair.

## Sites and locations

A site is **not** what the devices report. Devices report a `sysLocation`, which
is a room with the spelling wrong: the same building arrives under a different
string from every switch inside it. On this install the **41 strings described 41
sites** — "Galazzano", "Galazzano Sala B", "Galazzano, (Sala server A)" and "AZIENDA0
Galazzano (Sala server A)" are one building.

Taken literally, each of those names becomes a site of its own, and the same VLAN
looks like a different broadcast domain per room: two switches in one building are
never reconciled and the collision report fills up with pairs that are really the
same network described twice.

The plugin therefore keeps the two concepts apart:

- a **room** (`ipam_locations`) is the string a device reported, stored exactly
  as reported and never guessed at;
- a **site** (`ipam_sites`) is the grouping, and only an operator decides it.

A `sysLocation` nobody has filed yet gets a site of its own, so nothing is lost
and every address stays classifiable. Filing it under the real site is one action,
and since the site is the scope a collision is judged in, doing it is what
reconciles two rooms of one building.

The site created that way is a **placeholder** (`auto_created`): a string with a
name on it, not a place somebody chose. The **Sites and locations** page tells a
placeholder from a defined site, and the two carry different weight everywhere: a
placeholder counts as a scope for collisions — an address is inside that perimeter,
and that is all — but it is **not offered** where an operator has to pick a real
place, for instance the endpoints of a stretched network. A placeholder becomes a
defined site the moment the operator renames it, gives it an address, or accepts
a grouping proposal.

The **Sites and locations** page proposes the groupings: strings that share a word
that looks like a place name, closed transitively ("if A shares a word with B and B
with C, all three together"). The proposal also names the sites that would
disappear. Accepting one merges the sites, moves the rooms, and **collapses the
VLANs that were the same broadcast domain counted twice**: the rows that pointed
at the losing record follow the survivor, so no datum is left with a foot in two
sites.

On this install the accepted proposals took it from 41 sites to **21**: **3 real
sites** with their rooms (Galazzano 23, AZIENDA1-Sede 6, AZIENDA0-Borgo Maggiore 2),
**10 placeholders** still to be filed, and **8 empty leftovers** left by a merge
that carried off the last room. The site's VLANs went from 601 scattered rows to
**130 rows, one per VLAN id**.

## Using it

Six pages under **Plugins → IPAM**, in LibreNMS's own styling: panels, badges, a
site filter on every list. Each page shows the numbers at the top that say whether
it is worth opening, and the **Page** button in Plugin Admin opens an index with
the same numbers.

**Network map.** The tree of networks. Importing a single `10.14.146.0/24` also
creates the `10.14.0.0/16` and `10.0.0.0/8` that contain it: site, owner and
function are set once on the `/8` and everything below inherits. That is what
makes a wide segment describable. A block described by hand stays that way: the
import does not touch it. The site column also shows how many rooms were grouped
into the site. A network carried at layer 2 between sites is declared from the
block itself, see [Stretched domains](#stretched-domains).

**VLANs.** One row per VLAN per site, each with its own owner, site and
description, and the **rooms of the site it was seen in**: a VLAN that spans a
building stays one record, and the rooms it was reported in go into
`ipam_vlan_locations` instead of multiplying the VLAN. The name LibreNMS reads from
the devices arrives truncated at 32 characters, so it is only a seed: the real text
lives in a column nothing truncates. Every name every device reported is kept, and
that is how it becomes visible that two switches call the same VLAN different
things. A VLAN carried at layer 2 between sites is declared from the page itself,
see [Stretched domains](#stretched-domains).

**Addresses.** Every address with its block, VLAN, site — room included — and the
owner it was assigned to. The search box takes a CIDR to see the whole segment.
Every classification can be corrected by hand and pinned, and the pin is what
stops the next import from touching it. The same classification can be applied to
a whole block at once, by default only to the addresses not yet classified.

**Collisions.** Addresses configured more than once inside the same VLAN and the
same site, with the ports and devices where they were seen, grouped by site. An
intentional collision, a floating address for instance, is marked as expected with
a reason and stops appearing.

**Sites and locations.** The list of sites with their grouped rooms, the counts that
say how much each one holds, the merge proposals, and the rooms not yet filed.

**Owners and functions.** The three lists the other pages draw from: network
owners, network functions, VLAN owners. Entries are added with a name and a
description, renamed, and deleted **only when nothing points at them**: the refusal
says where they are used (how many blocks, addresses or VLANs), because the
references are plain columns without a foreign key and it would be the kind of
silent damage no engine would stop. Pages with empty lists show the link until
there is at least one entry.

Every "--" option in the forms sends **0**, because 0 is the value that means
"inherit from the block" or "none". Three times the validation rule was rewritten
with an `exists:` that refuses the 0 — no row has key 0 — and three times a
partially filled form was refused in silence: editing an "auto" block on the
network map and setting only the site saved nothing and showed nothing. The rule
is now **one shared class** (`ScopeRules`): 0 always passes, any other value has
to exist. And the pages finally show the **messages**: the success and error flash
(19 messages no view was drawing) and the list of validation refusals, so a
refused form is no longer indistinguishable from one that worked.

**Lifecycle: hide or delete, and whose row it is.** The rule is one sentence and
everything else follows from it. What **LibreNMS reports belongs to the import**,
and its rows can only be **hidden**: the next poll brings them back, so deleting
one would be a slower way of hiding it. What **an operator wrote** — or what the
import **handed over** when LibreNMS stopped reporting it — is theirs, and is
deleted.

The movement happens in **both directions**, and it is the same sentence seen from
the other side.

**When an object disappears from LibreNMS**, the import decides by **hierarchy**:

- **no hierarchy** it was scaffolding: a `/24` existed because an address was
  inside it, and that address is gone. The row goes.
- **with hierarchy** it is a description of live data: addresses are still in the
  range, or narrower ranges still hang below it. Deleting it would take the map
  with it, so the import **stops calling it its own** (`auto_created` becomes
  false) and from then on it behaves like something typed by hand: hideable and
  deletable, with a button that appears.

**When an object you wrote by hand turns up in LibreNMS**, the opposite happens:
**it goes back to the import**, because now a poll reports it. That is not your
choices being undone, it is the boundary that had been drawn from the other side:
until now the row was yours because nobody was reporting it, and now someone is.
But **the words you put on it stay**: a description does not become the device's
because a device turned up. And it is exactly those words that protect the row
when the device disappears again — the loop closes there, and the import tells you
every time it happens.

**Rooms** follow the same rule: a `sysLocation` LibreNMS no longer knows and that
has nothing hanging off it goes, and with it the placeholder site that was born
only to hold it. A room an operator's VLAN names, or that an address comes from,
stays.

One case that used to do visible harm and is now settled: **an address written in
advance that a device has really been configured with**. The plan has no port
behind it, so the importer never met it: the same address ended up twice, and
twice in one scope reads as a duplicate — a conflict invented by the plan rather
than by the estate. The two now meet and become **one object**: the row is the
one the port really has, and the plan's words (classification, description and its
pin) go on top, so the next poll does not re-derive them away. If the plan named
**another site**, the two stay apart — they are different objects by the plugin's
own rule, the site is what separates two scopes — and the import **says so**
rather than moving an address from one building to another.

**Sites** are the one exception, and by construction: nobody reports a site, it is
a decision by whoever writes it down, so the import never claims one. What it does
is **fill it with the rooms** the devices report, and nothing else: a site you
created or renamed stays yours, and placeholder sites stay placeholders until you
file them.

Two explicit **refusals**, because they are the two situations where a delete
would do damage: a block with addresses inside is not deleted (they would be left
pointing at nothing, and moving them is a decision about where they belong), and
neither is a VLAN with addresses classified into it. And an import that **read
nothing** concludes nothing: that is also what a LibreNMS that has never been
discovered looks like, and deleting the map on that evidence would be the worst
thing the plugin could do — so it says so and does not act.

The **"described"** badge answers whether somebody has said something, not whose
row it is: a block you described stays "described" even after the import takes it
back, because the description is still there.

**Attributing a site and a room by hand.** VLANs, networks and addresses can
always be attributed by hand, to sites and also to **rooms**: a network or an
address can be in one cabinet and not only in the building, a VLAN can be written
down for a room nothing has been plugged into yet. On addresses the rule is the
one the scope fields already follow: without the **pin** the correction is undone
by the next poll, with the pin it stays.

**Two records, one VLAN.** The key `(id, domain, site)` is not decoration: it is
the scope a collision is judged in, so two records of the same VLAN at one site
would be two broadcast domains where there is one, and the same address on both
sides would never be reported as the conflict it is. But that pair is not a
mistake to refuse, it is the operator saying "they are the same thing": it can come
from a merge of two sites, or from the record of a VLAN dragged by hand onto a
site that already reports that VLAN. Either way **the two records become one**,
and everything pointing at the loser follows it: the addresses classified into it,
the rooms it was seen in, the name each switch gave it, the stretched domain it
belonged to, the verdicts on its collisions. Nothing the operator wrote on the
record that disappears is lost. It is the only case where a plugin operation
**changes the number of rows** of a table, and it says so.

**Names are unique**, and for a while the only thing enforcing that was the
database constraint: renaming a site (or a taxonomy entry) onto a name already
taken came back as a 500 with a stack trace. The rename now gives one of the three
possible answers: **free** if nobody holds the name, **absorbed** if an
emptied-by-merges site holds it (the leftover is removed and the operator is told),
**refused** with instructions if a site with data holds it. The source of the
leftovers was closed at the root: moving the last room out of a site that has
nothing else removes it at once, instead of leaving a name waiting to collide with
a rename.

**The group that uses an entry.** The "3 blocks" badge on an entry is a link: it
lands on the page that holds those blocks, with the filter already applied, so the
classification can be reviewed without scrolling the whole list. The owner and
function filters are selectable by hand on Network map, VLANs and Addresses.

**Selection and bulk reclassification.** On the Addresses page rows are ticked, and
the "Reclassify the selection" panel sets site, VLAN, owner and function in one
go: a field left alone is not touched, "inherit" puts the column back to 0. The
"every address matching the filters" tick takes **all** the rows the current
filters match, not just the page — and the server rebuilds the set with the same
builder the list uses, so it changes exactly what the operator was looking at,
device permissions included.

**Sorting.** The column headers on Addresses, Network map, VLANs and Owners sort
the list, and three clicks go all the way round: ascending, descending, then the
page's natural order. The active column is marked with an arrow, and the sort does
not throw the filters away.

**Hidden objects.** Loopback, link-local and multicast exist on every device and
say nothing about the network: `127.0.0.1` seen on three devices would read as a
three-way collision. The **excluded ranges** (editable in the settings) are
imported but kept out of every list, every count and collision detection. Hidden
does not mean deleted: every page has a "show hidden" toggle, and the edit form
hides or un-hides one row at a time — a whole block with everything below it — and
the import never touches that choice again. The column is called `ignored`, as in
LibreNMS (`devices.ignore`, `ports.ignore`): "present, but out of the reports".

**Adding objects.** Networks, addresses and VLANs can be recorded by hand, with
every value — site, VLAN, owner, function, description — and not only what
LibreNMS already knows: a firewall not yet turned on, a reserve being handed out,
the gateway of a segment that has not gone live. An address added by hand is born
**pinned**, so the import does not delete it merely for not seeing it in LibreNMS; a
VLAN added by hand is born described, so the import does not rename it; a network
added by hand is not the import's, so it does not touch it. Values left on
"inherit" come from the block containing the address, as at import time.
Everything added by hand can be **deleted** as well as hidden, see
[Lifecycle](#using-it).

**Free addresses.** The panel on the Addresses page — or `lnms ipam:free
10.14.146.0/24` — answers the question search cannot: what can I hand out in
here. It reports how many are in use, the **free ranges** as from-to pairs, and
the first concrete addresses to use. Hidden addresses count as used, and that is
on purpose: they are configured on real devices, just uninteresting ones.

**Alignment.** `lnms ipam:sync` compares the IPAM with what LibreNMS is actually
polling, with the same identity and the same filters as the import: what has not
been imported yet, what is no longer reported and will be removed on the next
import. Rows added by hand are not expected in LibreNMS, so they do not count as
drift. The **LibreNMS and the ipam** panel on the Page button shows the same
numbers; on this install it says `in sync` on **547/547 addresses** and **621/621
VLAN/site pairs**.

Still to do: after the first import, **66 of the 425 visible addresses stay
unclassified**, because they sit on hosts and firewalls rather than switch ports.
They have neither VLAN nor site, so they take no part in collision detection.
Describing one block is enough to classify all of them.

## Stretched domains

Some VLANs and some networks **do not stop at one site**: they are carried at
layer 2 over fibre, over an E-Line, or inside an 802.1ad S-VID with QinQ, and they
arrive at the other site **unchanged**. Two different things to declare, because
they are two levels:

| | what it is | where you declare it | table |
|---|---|---|---|
| **broadcast domain** | the VLAN: which L2 area is one area | **VLANs** page, the VLAN's row | `ipam_vlan_groups` |
| **subnet** | the network: which addressing plan exists unchanged | **Network map** page, the block's row | `ipam_network_stretches` |

Why it has to be declared rather than read: **no device reports its own layer 2
topology**. A device can say "I am on VLAN 999", not "this VLAN reaches here from
the Galazzano site over a fibre". The only source is the operator, so it is a
decision: taken once, and recognised by the plugin from then on. **The import never
touches it**, in either direction.

What the plugin reads on its own stays right: **one VLAN per site** is what the
devices report, and it is the correct reading for the 196 ids out of 224 that are
merely the same number reused locally. Transversality is a declared exception, not
the default.

### When to declare one, and when not

Declare a domain **when the same address at the two ends would be a real
conflict**: a gateway, a management address, a service that exists once for the
whole circuit. **Do not** declare it for two sites that merely reuse the same VLAN
number: those are separate broadcast domains, the site keeps them apart, and the
two points with the same address are two different things. Declaring them together
would produce a conflict that does not exist.

### VLANs: how

1. Open **Plugins → IPAM → Vlans**, find the VLAN's row at the site the transport
   starts from.
2. Click **Edit**. In the **Stretched across sites** section tick the sibling
   records — the rows with the **same id and domain at another site**, which are
   exactly the candidates the import created for that same number.
3. Write **how it travels** in the field next to it: "QinQ inside S-VID 400, dark
   fibre Galazzano – AZIENDA1 Sede". The text lives on the domain and every member
   shows it, because it is something no device reports and would otherwise end up
   in a spreadsheet.
4. **Link as one domain**.

From that moment, inside the domain, **the VLAN alone is the scope**: the site stops
separating, and the same address at the two ends is **one conflict**, because at
layer 2 nobody can own it twice. The report labels it
`vlan 999 (Galazzano + AZIENDA1-Sede)`, and the "expected" verdict is given **once on
the domain** and holds for every site.

To undo: **Take out of the domain** on any member. If one member is left, it goes
back to being a local VLAN and the group goes with it.

### Networks: how

1. Open **Plugins → IPAM → Network map**, find the block (say `172.31.225.0/24`),
   click **Edit**.
2. In the **Stretched across places** section tick the places the subnet reaches
   and write how it travels.
3. **Declare it stretched**.

Only two kinds of place are offered, and that is deliberate:

- the **sites an operator defined that have at least one room** — the real places;
- the **rooms LibreNMS says carry the network and that belong to no site**, for a
  segment that reaches one cabinet only.

A **placeholder is not offered**: it is the fallback the resolver created for a
`sysLocation` nobody has filed yet, not a place anybody chose. If your network
reaches a place that is still a placeholder, the way is to define that site (or
accept the grouping proposal on the **Sites and locations** page) and come back here.

From that moment, inside the domain, **the site stops separating and the scope is
the VLAN by number**. The practical consequence: the same address at the two ends
of the fibre is **one conflict** even though the two sites have two different VLAN
records — which is exactly the case, because those two records are the same
broadcast domain. The report labels it `172.31.225.0/24 (Galazzano + AZIENDA1-Sede)`.
The "expected" verdict is given once on the domain.

Worth knowing: **two different VLANs inside the same stretched network stay two
domains**. They are two L2 areas reusing one addressing plan, and neither is in
conflict with the other.

### The rules that hold for both

- **The import neither looks nor touches.** No poll creates, moves or deletes a
  domain: if it did, an `ipam:import` on every polling cycle could undo a decision
  taken at the desk.
- **A block inside a domain inherits it**, without anybody saying so: a network
  inside another is inside that one, because the domain covers the block and
  everything below it. That is why the rows the import creates under a stretched
  network are born in the domain.
- **It cannot be stretched twice**: declaring a network that is already inside
  another is **refused**, with a message saying which one. Otherwise "where does it
  reach?" would have two answers.
- **Two domains meeting merge**: if you link a VLAN that was already in a domain
  to one that had its own, they end up as one domain, and the transport note
  survives.
- **A domain is never deleted**: the declaration is withdrawn. The only thing that
  goes with it is the group, once it has no members left.

## Installation

The package registers itself as a plugin with `php artisan plugin:add`, which
writes `composer.plugins.json`. That file is what makes the plugin survive
LibreNMS updates, see [LibreNMS updates](#librenms-updates).

### Development version

For development, with a symlink: edits to the files take effect immediately.

The `path` repository has to be declared once, in the global composer
configuration. Where that is depends on the install, so ask for it:

```sh
cd /opt/librenms
COMPOSER_HOME_DIR=$(php composer.phar config --global home)
echo "$COMPOSER_HOME_DIR"
```

On this install it is `/opt/librenms/.config/composer`. The file to create or
edit is `config.json` in there, with the list of repositories in `repositories`:

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

If the file already exists, because of other plugins, **add** an element to the
`repositories` array rather than overwriting it.

Then:

```sh
cd /opt/librenms-plugin-ipam
php /opt/librenms/composer.phar install     # only for the tests

cd /opt/librenms
php composer.phar require dercol1/librenms-plugin-ipam:@dev
php artisan ipam:install (./lnms ipam:install)
php artisan ipam:import (./lnms ipam:import)
```

Check:

```sh
./lnms route:clear
php artisan ipam:check (./lnms ipam:check)
php artisan route:list (./lnms route:list) | grep -c ipam    # 33 routes
```

With the symlink, `vendor/dercol1/librenms-plugin-ipam` points at the working
directory. Plugin updates are a `git pull` (or copy-and-paste) and nothing else.

### Released version

For a stable install, from a tag. It needs a remote git repository with the tags,
for instance:

```sh
git remote add origin git@github.com:dercol1/librenms-plugin-ipam.git
git tag -a v1.0.0 -m "first release"
git push origin main --tags
```

On the server the repository is registered as `vcs` and a precise tag requested:

```sh
cd /opt/librenms
php composer.phar config repositories.ipam vcs git@github.com:dercol1/librenms-plugin-ipam.git
php composer.phar require dercol1/librenms-plugin-ipam:1.0.0
php artisan ipam:install
php artisan ipam:import
```

`composer.plugins.json` will get the exact version, `1.0.0`, and not `@dev`.
That is what matters: with a precise reference `daily.sh` reinstalls the same
version on every update, while `@dev` always takes the newest commit available.

Worth knowing: `daily.sh` runs `composer install --no-dev`, so the plugin's
development dependencies are not reinstalled. Install them with:

```sh
cd /opt/librenms-plugin-ipam && php /opt/librenms/composer.phar install
```

## Uninstallation

In two steps, because they are two different things. The first touches the
database, the second deregisters the package.

```sh
cd /opt/librenms

# 1. asks for confirmation and shows how many rows it is about to delete
php artisan ipam:uninstall

# 2. deregisters the package
php composer.phar remove dercol1/librenms-plugin-ipam
```

`ipam:uninstall` drops the fourteen `ipam_*` tables, the `ipam.view` and
`ipam.manage` rows from the `permissions` table, and deactivates the plugin. It
asks for confirmation and shows the counts first:

```
This deletes the ipam database: every classification, description and owner you entered.

  ipam_sites             38 rows
  ipam_networks          285 rows
  ipam_vlans             796 rows
  ipam_addresses         556 rows
  ...
```

Two variants:

```sh
php artisan ipam:uninstall --keep-data    # tables stay, only permissions and settings go
php artisan ipam:uninstall --force        # does not ask
```

For an automated script, remember that `composer remove` has to run from
`/opt/librenms`, not from the plugin directory.

One thing is left to do by hand: the other plugins and other entries in
`composer.plugins.json` stay, they are independent of this one. The `"name": "ipam"`
element in the global composer configuration can be removed, but there is no need:
without the package it is not resolved and does no harm.

Final check:

```sh
php artisan ipam:check                    # must say the tables are gone
grep -c ipam composer.plugins.json         # must say 0
```

## LibreNMS updates

`daily.sh` handles the plugins by itself, as long as the package is in
`composer.plugins.json`. Here is what happens when it updates (lines 296-368 of
`daily.sh`):

```sh
php artisan optimize:clear                 # route, config and view caches
git checkout -- composer.json composer.lock # restores composer.json
git pull                                    # or git checkout <tag>
# post-pull:
#   reads composer.plugins.json and puts the plugins back into composer.json
#   composer install --no-dev
./lnms migrate --force --no-interaction --isolated
```

The delicate part is the third line. `daily.sh` does a `git checkout` of
`composer.json`, so the line `plugin:add` had added **is restored through git and
removed**. The plugin is put back straight after, reading `composer.plugins.json`,
which is in `.gitignore` and therefore untouched by that checkout.

In practice: **as long as the entry is in `composer.plugins.json`, an update does
not lose the plugin.** If instead the plugin is installed only by editing
`composer.json` by hand, it disappears on the first update.

To check after an update:

```sh
php artisan ipam:check
grep ipam composer.plugins.json
php artisan route:list | grep -c ipam      # 33
```

### The plugin's tables and `lnms migrate`

The `ipam_*` tables are created by `ipam:install`, not by a migration. On purpose.

The plugin registers no migration, so `lnms migrate` does not touch them and cannot
stop halfway on them. Registering one would have been worse: a registered migration
ends up in the `migrations` table, `CheckDatabaseSchemaVersion` reports it as an
unexpected migration and returns `false`. And that `false` makes
`CheckSchemaStructure::enabled()` skip, which would **silently disable
LibreNMS's own schema check**.

If the tables are missing after an update, it is simple:

```sh
php artisan ipam:install
php artisan ipam:import
```

### Updating the plugin

The plugin does not touch LibreNMS's database, so updating it does not require
`lnms migrate`. Its tables have a single owner and are upgraded by the plugin's own
code.

An update that adds tables or columns creates them on the first command, because
`ipam:install` compares what is there with what the plugin defines:

```sh
php artisan ipam:install
```

The settings saved on the configuration page survive a plugin update. The ones
saved in `config/ipam.php` do not: the file is no longer touched and the values are
orphaned. The page is better.

## Commands

```sh
php artisan ipam:install              # creates the tables and registers the permissions
php artisan ipam:uninstall            # drops tables, permissions and settings
php artisan ipam:import               # rebuilds from what LibreNMS collects
php artisan ipam:import --vlans       # only the vlans
php artisan ipam:import --json        # machine readable output
php artisan ipam:duplicates           # the collision report
php artisan ipam:duplicates --unclassified   # addresses with no vlan and no site
php artisan ipam:duplicates --site=3         # one site only
php artisan ipam:duplicates --all            # including the ones marked expected
php artisan ipam:check                # checks the plugin's tables
php artisan ipam:sync                 # do the ipam and LibreNMS say the same thing?
php artisan ipam:sync --json          # the same, for machines
php artisan ipam:free 10.14.146.0/24  # the free addresses of a segment
php artisan ipam:free 10.14.146.0/24 --suggest=3   # only the first three
```

`ipam:import` can be re-run without trouble, and it is meant to run on every
polling cycle. It updates what it saw and keeps what an operator pinned, and then
it decides in **both directions** and says so:

- what LibreNMS **stopped** reporting: with **nothing** hanging off it the row is
  removed, with data still above it — or with the operator's words on it — it is
  **handed to the operator**;
- what LibreNMS **started** reporting again, that the operator had written down,
  **goes back to the import** with the operator's words still on it;
- **planned addresses** a device has really been configured with become **one**
  object, carrying the plan's classification and its pin.

And it concludes nothing if it read nothing.

Rows in the excluded ranges are hidden only at creation: a row brought back into
the light by hand stays that way on every later import.

## Permissions

| Permission     | Allows                                                   |
|----------------|----------------------------------------------------------|
| `ipam.view`    | reading the six pages                                    |
| `ipam.manage`  | describing blocks, reclassifying, merging sites, adding objects, annotating collisions |

`admin` has both, `global-read` only `ipam.view`. Anyone with `plugin.admin` gets
through anyway.

The addresses and collisions pages also apply LibreNMS's per-device restriction,
so the IPAM cannot be used to find out what is on a device the user has no access
to.

## A warning about the Validate page

LibreNMS compares the database with `db_schema.yaml`, which describes the schema it
expects to find. Every table it does not know is reported as an "extra table" and
the fixer offers to drop it. The `ipam_*` tables are exactly that: correct, and
unknown to the core.

**Do not apply that fixer.** It would drop the plugin's tables.

`ipam:check` answers the question that actually matters about these tables: whether
they are all there, whether the columns match what this version expects, and how
many rows they hold.

## Development

```sh
composer install                # once
vendor/bin/phpunit              # 282 tests
composer test:lint              # pint
composer test:types             # phpstan, level 6
composer test                   # all three in a row
```

The three gates are **blocking**: no change to the plugin counts as finished with
the tests green but phpstan or pint complaining.

```sh
cd /opt/librenms
php artisan optimize:clear      # after changing a route
php artisan ipam:install        # after changing the schema
php artisan ipam:import         # to see the effect on the real data
```

The tests run on sqlite with the LibreNMS tables the plugin reads recreated by
hand: **no database, no network and no running LibreNMS are needed**, and a full
suite takes about ten seconds. The loop while working is a single file
(`vendor/bin/phpunit tests/SomethingTest.php`); before finishing, the three gates.

The **development manual** — architecture, how to add a feature, the conventions,
**how to run the tests and what to check when one fails**, and the traps already
met — is in [docs/development.md](docs/development.md).

## Layout

```
README.md / README.en.md     this document, in Italian and in English
docs/development.md          the development manual, in English
config/ipam.php              defaults, all overridable from the page
resources/lang/en/ipam.php   every string the plugin shows
resources/views/             the six pages and the shared fragments (nav, stats,
                             site cell, shared option lists, filling, free address
                             panel, form action urls)
src/Collision/               the collision rule
src/Import/                  vlans and addresses, the sysLocation → room → site
                             resolution, and the reconciliation of what LibreNMS
                             stopped reporting
src/Sites/                   grouping proposals and site merges
src/Networks/                stretched networks: the places they reach and what follows
src/Vlans/                   the broadcast domains that cross sites, and the
                             collapse of two records of one vlan into one
src/Sync/                    the comparison between the ipam and what LibreNMS polls
src/Models/                  the plugin's tables
src/Support/                 address arithmetic, schema
src/Web/                     controllers, site directory, free address finder
src/Console/                 the seven commands
tests/                       282 tests
```
