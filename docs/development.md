# Development manual

How this plugin is put together, the rules it follows, and how to add something to
it without breaking what is already there. Written for whoever works on the code
next, including the parts that exist because something went wrong once.

The user-facing document is [../README.md](../README.md) (Italian) and
[../README.en.md](../README.en.md) (English). This one is about the code.

---

## 1. The one rule that outranks everything

**LibreNMS core is never modified.** Not a line, not a template, not a view
override. Everything goes through the plugin API: `IpamPluginProvider` registers
the routes and the commands, `Hooks\Page` renders the pages, `Hooks\Menu` adds the
navigation, `Hooks\Settings` the configuration panel.

The reason is not purity. A core change only lives until the next `git pull`, and
this plugin is distributed as a composer package that people install into an
install they do not own. A modification that survives only until the next update
is a modification that will surprise somebody.

When a feature seems to need core, the answer is nearly always a plugin surface
that has not been looked at yet: a page hook, a panel, a menu entry, a command.

## 2. Getting set up

```sh
# once
cd /opt/librenms-plugin-ipam
php /opt/librenms/composer.phar install

# the three gates
composer test                   # lint, then phpstan, then the tests
vendor/bin/phpunit              # the tests alone, ~10s, 282 of them
composer test:lint              # pint, check only
composer test:types             # phpstan, level 6
```

How to run the tests, and what to do when one fails, is its own section:
[§11 Tests](#11-tests). The short version while working is a single file
(`vendor/bin/phpunit tests/FooTest.php`), and the three gates before finishing.

`composer test:types` runs **LibreNMS's** phpstan binary and larastan, not a
local copy:

```
"test:types": "@php /opt/librenms/vendor/bin/phpstan analyse --no-progress"
```

That is deliberate and it cost two days to learn. A second larastan copy inside
the plugin produced two incompatible service definitions for the same extension,
and a third combination made larastan resolve `view()` against an application that
was never booted, so every `view()` in the plugin looked like a typo. The pair
LibreNMS ships and tests against is the only pair that works.

`daily.sh` runs `composer install --no-dev` in `/opt/librenms`, which strips
phpstan and larastan out of that vendor directory. When the types gate suddenly
cannot find its dependencies:

```sh
cd /opt/librenms && php composer.phar install
```

The tests need no database and no network. `LIBRENMS_PATH` points them at
LibreNMS if it is not in `/opt/librenms`.

Against the real install, after a change:

```sh
cd /opt/librenms
php artisan optimize:clear      # after a route change, always
php artisan ipam:install        # after a schema change
php artisan ipam:import         # to see what it does to the real data
```

## 3. Architecture

Five paths, and knowing which one a change belongs to is most of the work.

**A page request.** `src/IpamPluginProvider::routes()` defines the routes.
Controllers live in `src/Web`, one per page, extending `Web\Controller` for the
authorisation helpers. The list data builders that both the controller and the
JSON endpoint need live in a class of their own (`NetworksPage`), because two
builders for one view once disagreed on an array key and the page rendered empty
with no error anywhere.

Views are full pages extending `layouts.librenmsv1`. The Page hook renders a
fragment, never a layout-extending view: a view that extends a layout inside
`plugins.settings` is a view that renders inside another view.

**The import.** `src/Import`. `VlanImporter` reads `vlans`, `AddressImporter` reads
`ipv4_addresses`/`ipv6_addresses`, `LocationResolver` is the shared walk from a
LibreNMS `sysLocation` to a room and then to a site. `Reconciler` runs after both
and decides what happens to what LibreNMS stopped reporting.

**Collision detection.** `src/Collision`. `DuplicateDetector` is one SQL query
that groups occurrences by address and scope; `DuplicateGroup` is what comes back
and what the report renders. This is the part that has to be right: everything else
is bookkeeping.

**Sites.** `src/Sites/SiteService` proposes groupings with union-find over the
significant words of the room names, and merges sites when a proposal is accepted.

**Domains across sites.** `src/Vlans` (broadcast domains) and `src/Networks`
(stretched subnets). Both are operator decisions, both change the collision rule,
and neither is ever touched by the import.

## 4. The data model

Fourteen tables, all prefixed `ipam_`, all owned by this plugin and created by
`ipam:install`.

- `ipam_sites`, `ipam_locations` — the places
- `ipam_networks` — the tree of ranges
- `ipam_vlans`, `ipam_vlan_groups`, `ipam_vlan_locations`, `ipam_vlan_names` — vlans
- `ipam_network_stretches`, `ipam_network_places` — subnets carried across places
- `ipam_addresses`, `ipam_duplicates`
- `ipam_net_owners`, `ipam_net_functions`, `ipam_vlan_owners` — the taxonomies

Three conventions hold everywhere:

**Every reference is an unsigned int with default 0, and 0 means "not
associated".** No nullable foreign keys. A NULL never equals another NULL in a
unique index, so a `(vlan_id, domain, site_id)` key would happily accept the same
unassigned VLAN twice. The 0 sentinel keeps the unique indexes honest and every
lookup indexable.

**No foreign keys to LibreNMS tables.** LibreNMS reinstalls drop and recreate
their tables, and a foreign key would block the reinstall. Those rows are derived
data and the plugin rebuilds them on import.

**No migrations, ever.** The plugin registers none, and that is not an oversight.
A registered migration lands in the `migrations` table, LibreNMS reports it as an
unexpected migration, `CheckDatabaseSchemaVersion` returns `false`, and that
`false` makes `CheckSchemaStructure::enabled()` skip — which would silently
disable LibreNMS's own schema check. `src/Support/SchemaInstaller.php` is the
single source of truth for the schema instead, and `ipam:install` is the only path
that can change it.

## 5. Conventions

### 0 means "inherit", and the rule that enforces it lives in one class

Every classification field (site, room, vlan, owner, function) is either 0 — take
it from the enclosing block — or a row that exists. The validation for that is
`src/Support/ScopeRules.php`, and **every** controller that writes a
classification must go through it:

```php
$data = $request->validate(ScopeRules::make([
    'site_id' => ['ipam_sites', 'site_id'],
    'location_id' => ['ipam_locations', 'location_id'],
    'vlan_owner_id' => ['ipam_vlan_owners', 'vlan_owner_id'],
], $request) + [
    'vlan_id' => ['nullable', 'integer', 'min:1', 'max:4094'],
]);
```

A plain `exists:` rule was rewritten three times and broke the same way each time:
it refuses the 0 that every "--" option in every form sends, so a partially filled
form is refused with nothing saved and nothing shown. If you find yourself writing
`exists:` on one of these columns, you are re-introducing that bug.

### The column is `ignored`, not `hidden`

`hidden` is a real Eloquent property — the attributes kept out of array and JSON
output — so inside a model method `$model->hidden` returns that list and not the
column. Ask for it by name:

```php
if ((bool) $current->getAttribute('ignored')) { ... }
```

### Forms post through the action partial

```blade
action="@include('ipam::partials.action', [
    'route' => 'ipam.networks.stretch',
    'params' => [$block->network_id],
    'fallback' => 'plugin/ipam/networks/' . $block->network_id . '/stretch',
])"
```

The plugin's routes are not registered everywhere: the page hook renders the same
views in contexts that never went through the router, and the tests render
controllers directly. `Route::has()` takes only a name — pass extra arguments and it
reads an offset of type array — and the fallback keeps the form usable there.

### Every page shows its messages

`partials/notices` renders the flash and the validation errors. Nineteen flash
messages went undisplayed for the entire first version of this plugin: the action
worked, the operator saw nothing, and a refusal was indistinguishable from a
success. Any new page includes it, and any new controller answers with
`back()->with('success'|'error', ...)`.

### Long option lists live in templates

The VLAN list runs to hundreds of entries and an edit row can carry five selects.
Rendering them per row produced a 7 MB page. They live once in
`partials/options.blade.php` inside `<template>` elements, and `partials/fill`
copies the options in when a row is expanded:

```blade
<select name="site_id" class="form-control form-control-sm"
        data-fill="ipam-options-sites" data-selected="{{ $block->site_id }}">
    <option value="0">--</option>
</select>
```

Nothing is fetched and nothing is duplicated; a row that is never expanded costs
nothing. When you add an option list, add the template, not a loop in the row.

### Comments say why

The comments in this codebase are unusually long and that is the style, not an
accident. They explain the decision, the alternative that was tried, and what went
wrong — the number of ids on more than one site, the 32-character SNMP truncation,
the rule that was rewritten three times. A comment that only restates the code is
noise; a comment that saves the next person from the same experiment is the point
of the file.

## 6. Adding a feature

The order matters, because of the gates.

1. **Schema first.** Add the table or column to `SchemaInstaller`: `tables()`, the
   builder, `columns()`, and `addMissingColumns()`. All four, or `ipam:check` and
   phpstan will disagree with the database.
2. **Model.** Fillable, casts, `@property` for every column (phpstan reads the
   properties, not the database), and the relations the pages need.
3. **Service, if there is a decision in it.** Anything an operator decides —
   grouping, domains, collapses — belongs in `src/Sites`, `src/Vlans`,
   `src/Networks` or `src/Import`, not in a controller. Controllers arrange; they
   do not decide.
4. **Controller.** Thin: authorise, validate through `ScopeRules`, call the
   service, flash, redirect.
5. **View.** Include the notices partial, use the action partial for forms, use the
   shared option templates, and check device visibility where the page shows
   addresses.
6. **Route**, behind `can:ipam.manage` for anything that writes. Then
   `php artisan optimize:clear`.
7. **Language.** Every visible string in `resources/lang/en/ipam.php`. There is a
   test that fails on a duplicated key and one that checks the files agree, so run
   it.
8. **Tests.** The behaviour, not the implementation: what is refused, what is kept,
   what survives an import, what the operator sees in the flash.
9. **README**, in both languages, and the manual here if the change alters a rule.

Then the gates, in this order, because they fail differently:

```sh
vendor/bin/phpunit          # behaviour
composer test:types         # types; the test suite does not replace it
composer test:lint          # style; run pint, not --test, if it fails
```

## 7. Changing the schema

`SchemaInstaller` does four different jobs and they are easy to confuse.

**`tables()`** — the list, in dependency order. `ipam:uninstall` iterates it, so a
new table that is not on the list survives an uninstall.

**The builders** — one method per table, fluent, with every index named. The index
name matters: `dropIndex('ipam_vlans_group_index')` in a migration path needs it,
and an unnamed index cannot be dropped safely on sqlite.

**`addMissingColumns()`** — the upgrade path. One closure per column, which runs
only when the column is actually missing, which is only on an upgrade:

```php
'ipam_vlans' => [
    'vlan_group_id' => function (Blueprint $t): void {
        $t->unsignedInteger('vlan_group_id')->default(0)->index('ipam_vlans_group_index');
    },
],
```

An index that has to be **replaced** goes in the same closure, because the closure
only runs when the column is being added and a fresh table is created with the new
index already in place:

```php
$t->dropUnique('ipam_duplicates_scope_unique');
$t->unique([...], 'ipam_duplicates_scope_unique');
```

**One-time backfills** hang off the change list, keyed by the exact string
`addMissingColumns` returns:

```php
if (in_array('added ipam_sites.auto_created', $changes, true)) {
    $flagged = self::flagPlaceholderSites();
}
```

This is the only moment nobody has spoken about a row yet. It runs once, when the
column appears, and never again — otherwise it would keep re-deciding whatever the
operator changed afterwards. The `ignored` backfill and the placeholder-site
backfill both work this way.

## 8. The import, and what it does with what is gone

Both importers publish what they **saw**:

- `AddressImporter::publishSeen()` hands over the blocks it walked and the VLAN
  records it resolved an occurrence to;
- `VlanImporter` publishes the records it saw through the `vlans` table and sets
  `vlanImportRan`.

Then `Reconciler` runs, once, after both. Two rules and three guards:

**A row with nothing hanging off it is scaffolding and goes.** A `/24` that existed
because an address was in it, and the address is gone.

**A row with data still under it is handed over.** `auto_created` becomes false,
the import stops calling it its own, and from then on it behaves like something
typed by hand: hideable, deletable.

**A row with the operator's words on it is handed over too**, even with nothing
under it. That is the other half of the rule and it exists because the two
directions meet: an import that finds a described range takes it back, and if the
device then stops again a delete-on-scaffolding would take the description with
it. So the reconciler asks the content, not the flag — `isDescribed()` for a
block, for a vlan — and the words are the protection. The badge on the page asks
the same question, which is why "described" survives the row changing hands.

**Blocks are judged deepest first.** A `/16` whose only child is a scaffolding
`/24` still has a block under it while the `/24` is being judged, and would be
handed over when both are actually empty. Ordering by descending prefix length
makes the answer right on the first run instead of needing a second one.

**The mirror: a row the poll finds again goes back to being the poll's.** A range,
a vlan or an address the operator wrote down and that a device now reports too
stops being theirs. Nothing the operator typed is touched — the import never
writes a description or an owner — and what they wrote is what protects the row
when the device stops once more. Sites are the exception by construction: nobody
reports a site, so the import only ever fills it with the rooms the devices
report, and a site somebody named stays theirs.

**A planned address is absorbed, not duplicated.** A manual address has
`port_id = 0`, so the importer's natural key never matched it against the port
that later really got the address: the same address ended up twice, and twice in
one scope reads as a duplicate — a conflict invented by the plan. `absorbPlan()`
meets the two: the occurrence wins the row and takes the plan's non-zero
classification, its description and its pin. Zero on a plan means "inherit", the
same word the row edit uses, so it is never read as "strip what the poll derived".
A plan that named **another** site keeps both rows and warns, because moving an
address between buildings is not a thing an import may decide on its own.

**Three guards, all of them refusals to act on thin evidence.** An import that read
no address removes no block; one that read no VLAN removes no VLAN; one that read
no room removes no room. That is also what a LibreNMS that has never been discovered
looks like, and deleting the map on that evidence would be the worst thing this
plugin could do. The reconciler says so in the warnings and moves on.

Both directions are counted and reported: `handed_over` for what went to the
operator, `handed_back` for what came back, `plans_absorbed` for the planned
addresses a port really has.

Things that are never touched, whatever the poll says: anything `auto_created = false`,
anything inside a declared domain (no poll will ever confirm a layer 2 statement),
and anything an operator pinned.

The `last_seen` rule on addresses deserves its own note: pruning only touches rows
older than ten minutes, so a device that has just stopped answering does not lose
its rows on the next run.

## 9. The collision rule

The whole rule is one SQL query, and it is worth being able to picture it:

```sql
SELECT address, family,
       CASE WHEN v.vlan_group_id > 0 THEN 0
            WHEN dom.stretch_id IS NOT NULL THEN COALESCE(v.vlan_id, 0)
            ELSE ipam_addresses.vlan_record_id END AS scope_vlan,
       CASE WHEN v.vlan_group_id > 0 THEN v.vlan_group_id ELSE 0 END AS scope_group,
       COALESCE(dom.stretch_id, 0) AS scope_network,
       CASE WHEN v.vlan_group_id > 0 OR dom.stretch_id IS NOT NULL
            THEN 0 ELSE ipam_addresses.site_id END AS scope_site,
       COUNT(*) AS occurrences
FROM ipam_addresses
LEFT JOIN ipam_vlans v ON v.vlan_record_id = ipam_addresses.vlan_record_id
LEFT JOIN ( ... ) dom ON dom.address_record_id = ipam_addresses.address_record_id
WHERE ipam_addresses.ignored = 0 AND (site_id > 0 OR vlan_record_id > 0)
GROUP BY family, address, scope_vlan, scope_group, scope_network, scope_site
HAVING occurrences > 1
```

Each column answers one question: is this the same place twice?

- **Same VLAN record, same site** — the ordinary case, and a real conflict.
- **Same VLAN group** — the vlan is a declared broadcast domain: the site stops
  separating, because at layer 2 there is one domain and one place for an address.
- **Same stretched network** — the subnet is declared carried across places: the
  site stops separating and the scope is the VLAN **by number**, because the two
  sites have two different VLAN records that are in fact the same domain.
- **Different VLAN number in the same stretched subnet** — two L2 areas reusing
  one addressing plan. Not a conflict, and the query says so by keeping the number
  in the key.

Three things about the implementation that are not obvious:

**It is built on the query builder, not on the `Address` model.** The scope columns
are computed, not model attributes. Reading a `CASE` alias off a typed model is a
lie the type checker catches and the rows cannot tell.

**The domain membership is a derived table, not a join.** An occurrence carries both
a site and a room, and a domain can list both, so a plain join would double the
row — and a doubled row makes every occurrence of every address in a stretched
subnet a duplicate of itself. `MIN(p.stretch_id)` with a `GROUP BY` on the address
collapses the matches to one row, which is all the scope needs.

**`where` for a value, `whereColumn` only for a column.** `whereColumn('p.place_type',
'=', NetworkPlace::SITE)` emits the word `site` unquoted, MySQL reads it as a column
name, and the whole report dies with *Unknown column 'site' in 'WHERE'*. sqlite
does not care, so the test suite was green and the real install was broken. The
regression test for it checks the **shape** of the statement, not that it runs:

```php
$this->assertStringNotContainsString("place_type = site", $sql);
$this->assertContains('site', $bindings);
```

When you add something to that query, check it on the real MySQL. sqlite is more
forgiving in ways that matter.

## 10. Domains across sites

Two kinds, two tables, one idea: a fact about layer 2 that no device reports.

**VLANs** (`VlanLinker`): records are linked into `ipam_vlan_groups`. Two groups
meeting merge; unlinking the last-but-one member drops the group; the import never
touches the group column.

**Networks** (`NetworkStretcher`): a block gets `stretch_id`, the places go into
`ipam_network_places`, and the **subtree inherits it**. The propagation happens on
the write, like `ignoreSubtree()`, so "is this address in a stretched subnet" is
one indexed column at query time and not a tree walk in a report.

The candidate lists are deliberately narrow, because offering everything is how a
form becomes useless:

- **VLANs** — the sibling records: same id and domain, another site. Those are the
  records the import made for the same number elsewhere, which is exactly the set
  an operator chooses among.
- **Networks** — sites an operator defined that have at least one room, plus the
  rooms that carry the network and belong to no site. A placeholder site is not
  offered: it is the fallback the resolver created for an unfiled `sysLocation`, not
  a place anybody chose.

Nesting is refused rather than merged. A `/24` inside a stretched `/16` is in that
domain by inheritance, and declaring it in another one too would leave two answers
to "where does this reach?".

**One vlan, one record per site** is a rule the database enforces with a unique
key, and the pair it can produce is not a mistake to refuse — it is the operator
saying the two records are one broadcast domain. `VlanCollapser` handles both ways
it can arrive (a site merge, or a record dragged onto a site that already reports
that VLAN) and everything pointing at the loser follows it: the addresses, the
rooms, the per-device names, the stretched domain, the verdicts. What the operator
typed on the record that disappears is carried over. Anything that changes the
number of rows in a table must say so in the flash, and this is the one place in
the plugin that does.

## 11. Tests

### Running them

One command, from the plugin directory:

```sh
vendor/bin/phpunit
```

That is the whole loop: about ten seconds, 282 tests, and **no database, no
network and no running LibreNMS**. The harness builds the plugin's schema and the
stand-in LibreNMS tables per test, on sqlite in memory, so there is nothing to
reset when a run fails and the suite behaves the same on any machine.

Narrowing it down, which is what the loop while working looks like:

```sh
vendor/bin/phpunit                                          # everything, ~10s
vendor/bin/phpunit tests/StretchedNetworksTest.php          # one file
vendor/bin/phpunit --filter ObjectMobilityTest              # one class
vendor/bin/phpunit --filter test_a_domain_with_no_places    # one test
vendor/bin/phpunit --filter stretch                         # a word, several tests
vendor/bin/phpunit --list-tests                             # what is there
```

`--filter` takes a **name fragment, not a path**: a class name matches that
class, a test name matches that test, and anything in between matches whatever
contains it. `phpunit.xml` declares one testsuite, `Unit`, over `tests/`, so
`--testsuite Unit` is the same as running everything and only matters when
pointing phpunit at another configuration.

Through composer, which is how the gates call them:

| command | what it runs |
|---|---|
| `composer test:unit` | the tests |
| `composer test:lint` | pint, check only |
| `composer test:types` | phpstan level 6, with LibreNMS's own binary |
| `composer test` | **lint, then types, then the tests** — the order is the order in `composer.json`, and composer stops at the first one that fails |
| `composer fix:lint` | pint, which fixes what it can rather than only reporting |

Cheapest first on purpose: pint answers in a second, phpstan in a few, the suite
in ten. The order does not change what passes, only how long it takes to find
out what does not.

Two environment variables, and they are not a choice:

- `LIBRENMS_PATH` — where the LibreNMS install is, default `/opt/librenms`. The
  bootstrap needs its autoloader and its blade components, and if it cannot find
  them it says which path it looked at and exits rather than failing 282 times:

  ```sh
  LIBRENMS_PATH=/srv/librenms vendor/bin/phpunit
  ```

- `DB_CONNECTION=testing` and `APP_ENV=testing` are forced in `phpunit.xml`, so a
  test cannot reach a real database even by accident. There is nothing to
  configure and nothing to clean up after a failure.

`phpunit.xml` also turns on `failOnWarning` and `failOnRisky`, which is why a
test that asserts nothing, or that leaves something behind, fails the run instead
of passing quietly. That is not decoration: a green suite that asserted nothing is
the one failure mode a suite cannot notice about itself.

Two caches, and both of them will lie to you once:

- `.phpunit.cache/` holds the results of the last runs, which is what lets
  PHPUnit offer `--order-by=defects`. Deleting it costs nothing and is the first
  thing to try when a run behaves as if it remembered something.
- `vendor/orchestra/testbench-core/laravel/storage/framework/views/` holds
  compiled blade. A view file that changes in a way the compiled copy does not
  notice produces a page rendering the old markup while the test name says the new
  one — the most confusing failure in this suite, and the one that looks like a
  code bug:

  ```sh
  rm -rf vendor/orchestra/testbench-core/laravel/storage/framework/views/*
  ```

**When a test fails**, read the harness before the assertion. Three of them look
exactly like a code bug and are not:

1. **sqlite is more forgiving than MySQL.** A query that only mysql rejects
   passes here — see the `whereColumn` trap in §9. Anything that touches SQL has to
   be run once against the real MySQL before it is called done.
2. **A direct controller render does not have the request.** The view's
   `request()` is not the synthetic one unless it is bound with
   `$this->app->instance('request', $req)`, and `$errors` is unset without
   middleware. To exercise the notices partial, share a `ViewErrorBag`.
3. **A model instance is stale after a bulk update.** `Vlan::query()->whereIn(...)->update(...)`
   writes the rows and leaves every model in memory holding the old value. Re-read
   with `->fresh()` before asserting, and the test stops depending on which layer
   happened to write.

### What the harness builds

`tests/UnitTestCase.php` builds, per test:

- the plugin's own schema, through `SchemaInstaller::install()`, on sqlite;
- stand-in tables for the LibreNMS ones the plugin reads (`devices`, `ports`,
  `ports_vlans`, `vlans`, `ipv4_addresses`, `ipv6_addresses`, `locations`), with
  one device and two ports;
- the `INET_ATON` / `INET6_ATON` functions sqlite does not have, registered as PHP
  callables that return **packed bytes** rather than a number — a number works for
  v4, but a v6 address does not fit in a PHP int and every comparison after the
  eighth byte is against a wrapped value;
- the layout, with `show_menu = false`, because including LibreNMS's menu needs
  half of its schema;
- an `array` cache driver, because LibreNMS's `ConfigRepository` uses a persistent
  file cache that poisons a run.

What the suite is for, in rough order of importance: the collision rule (the part
that has to be right), the import's resolution order and its reconciliation, the
mobility of objects between the operator and the poll in both directions, the
refusals (what is not deleted and why), and the pages rendering at all.

Three files hold the stretched domains down from two directions each:
`StretchedVlansTest` and `StretchedNetworksTest` go through the services and the
collision rule, `StretchedDomainsTest` goes through the actions an operator
clicks, what the pages offer, and the promises that are not about finding a
conflict — the import leaves a declared domain alone, nesting is refused, a domain
of one site goes with itself, two domains merge.

## 12. What "done" means

- `vendor/bin/phpunit` green.
- `composer test:types` green. It reads the `@property` docblocks, so a column
  added to a schema and forgotten in a model passes the tests and fails phpstan.
- `composer test:lint` green.
- Verified against the real install if the change touches data or routes. There is a
  safe way to do that, and it is worth doing every time:

```php
DB::beginTransaction();
// the change
DB::rollBack();
```

If a smoke test has to leave data behind — a domain, a demo address — remove it in
the same session and check that the counts are back where they started. The
operator is using the install while the work is going on, so read the database
before writing to it: there may be sites, owners and taxonomies in it that a test
would overwrite.

- README in both languages when a rule changed, this manual when a rule changed.

## 13. Releasing

```sh
vendor/bin/phpunit && composer test && composer test:types
git commit -am "..."
git tag -a v1.1.0 -m "..."
git push origin main --tags
```

Then on the server, for a released install:

```sh
cd /opt/librenms
php composer.phar require dercol1/librenms-plugin-ipam:1.1.0
php artisan optimize:clear
php artisan ipam:install
php artisan ipam:import
```

`composer.plugins.json` is what makes the plugin survive `daily.sh`, and a precise
version is what makes `daily.sh` reinstall the same one.

## 14. Traps, so they are only paid once

- **`exists:` on a classification field** refuses the 0 every "--" option sends.
  `ScopeRules` exists because this was rewritten three times. See §5.
- **`hidden` inside a model** is Eloquent's property, not the column. Use
  `getAttribute('ignored')`.
- **`whereColumn` with a word in it** emits an unquoted identifier. See §9.
- **A flash message nothing renders** is a silent failure. Nineteen of them.
- **`Route::has()` takes one argument.** More than that reads an offset of type
  array.
- **A view extending a layout inside another view** renders nonsense. The Page hook
  renders fragments.
- **Two builders for one view** drift apart. One class, both callers.
- **A migration** would silently switch off LibreNMS's schema check. See §4.
- **Renaming onto a taken name** returns a 500 from the unique constraint, which
  tells the operator nothing. Release the name or refuse it, in a sentence.
- **Deleting an import-owned row** is a slower way of hiding it. The rule is §8.
- **Recreating the same block in a loop** gives a page that costs fifty queries and
  renders empty when it times out. Batch per page, not per row.
- **Trusting a test run on sqlite to mean the query works.** Run it against the real
  MySQL once.
