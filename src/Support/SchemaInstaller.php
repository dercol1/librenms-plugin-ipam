<?php

/*
 * The ipam plugin schema.
 *
 * This class is the single source of truth for the plugin tables. Both the
 * migration and the ipam:install command call install(), so the two can never
 * drift apart.
 *
 * Conventions used throughout:
 *
 * - Every reference to another ipam table is an unsigned int NOT NULL DEFAULT 0,
 *   where 0 means "not associated". Nullable foreign keys would work too, but a
 *   NULL never equals another NULL in a unique index, so a (vlan_id, domain,
 *   site_id) key would happily accept the same unassigned vlan twice. A 0
 *   sentinel keeps the unique indexes honest and every lookup indexable.
 *
 * - References to LibreNMS tables (device_id, port_id) are plain unsigned ints
 *   without a foreign key. LibreNMS reinstalls drop and recreate their tables,
 *   and a foreign key would then block the reinstall. The plugin treats those
 *   rows as derived data and rebuilds them on import.
 *
 * - created_at/updated_at are nullable timestamps, matching LibreNMS.
 */

namespace Dercol1\LibrenmsIpam\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaInstaller
{
    /**
     * The tables the plugin owns, in dependency order.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return [
            'ipam_sites',
            'ipam_locations',
            'ipam_net_owners',
            'ipam_net_functions',
            'ipam_vlan_owners',
            'ipam_networks',
            'ipam_vlans',
            'ipam_vlan_locations',
            'ipam_vlan_names',
            'ipam_addresses',
            'ipam_duplicates',
        ];
    }

    /**
     * Create any table that is not there yet, and add any column that is
     * missing from a table that is already there.
     *
     * Idempotent on purpose: an operator may run ipam:install by hand, and does
     * so again after pulling a new version of the plugin. It is the only path
     * that can change the schema, because the plugin registers no migration: a
     * migration would land in the migrations table, LibreNMS would report it as
     * unexpected, and CheckSchemaStructure would then stop running at all.
     *
     * @return list<string> what changed, for the command to report
     */
    public static function install(): array
    {
        $changes = [];

        foreach (self::builders() as $table => $builder) {
            if (Schema::hasTable($table)) {
                $changes = array_merge($changes, self::addMissingColumns($table));

                continue;
            }

            Schema::create($table, $builder);
            $changes[] = "created $table";
        }

        // The ignored flag is set when a row is created and never re-derived
        // afterwards, so an operator's "show this one" survives every import.
        // Rows that predate the flag were created before the rule existed, and
        // the only moment nobody has spoken about them yet is the moment the
        // column appears: that is when loopback already in the table gets its
        // flag, once, and never again.
        if (in_array('added ipam_addresses.ignored', $changes, true)) {
            $ignored = self::ignoreExistingExcludedRows();

            if ($ignored > 0) {
                $changes[] = "ignored $ignored existing address(es) inside the excluded ranges";
            }
        }

        // The old one-to-one site/location link is copied into ipam_locations
        // BEFORE the column goes. Doing it the other way round drops the only
        // record of which LibreNMS location belonged to which site, and the
        // addresses of that site stop resolving to it.
        if (Schema::hasTable('ipam_locations') && Schema::hasTable('ipam_sites')) {
            $moved = self::moveSiteLinks();

            if ($moved > 0) {
                $changes[] = "moved $moved location link(s) into ipam_locations";
            }
        }

        if (Schema::hasTable('ipam_sites') && Schema::hasColumn('ipam_sites', 'location_id')) {
            // the index goes first and by name: mysql would drop it along with
            // the column, but sqlite refuses to drop an indexed column and the
            // plugin's own tests run there
            if (Schema::hasIndex('ipam_sites', 'ipam_sites_location_id_index')) {
                Schema::table('ipam_sites', function (Blueprint $table): void {
                    $table->dropIndex('ipam_sites_location_id_index');
                });
            }

            Schema::table('ipam_sites', function (Blueprint $table): void {
                $table->dropColumn('location_id');
            });

            $changes[] = 'dropped ipam_sites.location_id';
        }

        return $changes;
    }

    /**
     * Bring a table that predates a column up to date.
     *
     * Uses the fluent column methods rather than addColumn(), which wants the
     * bare sql type and throws on anything else.
     *
     * @return list<string> what changed
     */
    private static function addMissingColumns(string $table): array
    {
        // a closure per table, so a new column is declared the same way it is in
        // the builder above, fluent and with its index
        $wanted = [
            'ipam_addresses' => [
                'location_id' => function (Blueprint $t): void {
                    $t->unsignedInteger('location_id')
                        ->default(0)
                        ->index('ipam_addresses_location_id_index');
                },
                'ignored' => function (Blueprint $t): void {
                    $t->boolean('ignored')->default(false)->index('ipam_addresses_ignored_index');
                },
            ],
            'ipam_sites' => [
                'address' => function (Blueprint $t): void {
                    $t->string('address', 255)->nullable();
                },
            ],
            'ipam_networks' => [
                'ignored' => function (Blueprint $t): void {
                    $t->boolean('ignored')->default(false)->index('ipam_networks_ignored_index');
                },
            ],
            'ipam_vlans' => [
                'ignored' => function (Blueprint $t): void {
                    $t->boolean('ignored')->default(false)->index('ipam_vlans_ignored_index');
                },
            ],
        ];

        $forThisTable = $wanted[$table] ?? [];

        if ($forThisTable === []) {
            return [];
        }

        $existing = Schema::getColumnListing($table);
        $added = array_diff_key($forThisTable, array_flip($existing));

        if ($added === []) {
            return [];
        }

        Schema::table($table, function (Blueprint $blueprint) use ($added): void {
            foreach ($added as $define) {
                $define($blueprint);
            }
        });

        return array_map(
            fn (string $column): string => "added $table.$column",
            array_keys($added)
        );
    }

    /**
    /**
     * Flag the rows that already sit in an excluded range, once.
     *
     * Runs only in the same pass that adds the column, which is the only
     * moment nobody could have made a choice about a row yet. The ranges come
     * from the config defaults: the web-stored settings are the operator's
     * later word, and this step must not anticipate it.
     *
     * The comparison is binary, not lexical: this is the same rule the import
     * applies at creation time, replayed for rows that were created before
     * there was a rule.
     */
    private static function ignoreExistingExcludedRows(): int
    {
        $raw = config('ipam.exclude_ranges');

        if ($raw === null) {
            // The provider merges its config into the application at boot, but
            // not every context that runs install() has booted it: the tests
            // have not, and the one-time backfill must not silently skip. The
            // file is the same default the merge would have made.
            $defaults = require dirname(__DIR__, 2) . '/config/ipam.php';
            $raw = $defaults['exclude_ranges'] ?? '';
        }

        $ranges = [];

        foreach (preg_split('/[\n,]+/', (string) $raw) ?: [] as $entry) {
            $cidr = Cidr::parse(trim($entry), true);

            if ($cidr !== null) {
                $ranges[] = $cidr;
            }
        }

        if ($ranges === []) {
            return 0;
        }

        $total = 0;

        foreach ($ranges as $range) {
            $function = $range->getFamily() === 4 ? 'INET_ATON' : 'INET6_ATON';

            $total += DB::table('ipam_addresses')
                ->where('family', $range->getFamily())
                ->whereRaw("$function(address) BETWEEN $function(?) AND $function(?)", [
                    $range->compressed(),
                    Cidr::fromPacked($range->packedEnd())->compressed(),
                ])
                ->update(['ignored' => true]);
        }

        return $total;
    }

    /**
     * Turn the old one-to-one site/location link into rows in ipam_locations.
     *
     * Before ipam_locations existed, every LibreNMS location became its own
     * site, which is what produced 38 sites for an estate with about five real
     * places. Each old row carries exactly one location id, so this is a copy,
     * not a merge: deciding which locations share a site is the operator's call
     * and is made afterwards from the Sites page.
     */
    private static function moveSiteLinks(): int
    {
        if (! Schema::hasColumn('ipam_sites', 'location_id')) {
            return 0; // already done, or never had it
        }

        $moved = 0;

        foreach (DB::table('ipam_sites')->whereNotNull('location_id')->where('location_id', '>', 0)->get() as $site) {
            $name = DB::table('locations')->where('id', $site->location_id)->value('location');

            if ($name === null) {
                continue;
            }

            DB::table('ipam_locations')->updateOrInsert(
                ['name' => $name],
                ['site_id' => $site->site_id]
            );

            $moved++;
        }

        return $moved;
    }

    /**
     * The column names this plugin version defines for one of its tables.
     *
     * ipam:check compares it against the live table to catch a downgrade that
     * left a column behind. Declared here rather than introspected from a
     * Blueprint, because the Blueprint cannot be built without creating
     * anything.
     *
     * @return list<string>
     */
    public static function columns(string $table): array
    {
        return match ($table) {
            'ipam_sites' => ['site_id', 'name', 'description', 'address', 'created_at', 'updated_at'],
            'ipam_locations' => ['location_id', 'site_id', 'name', 'created_at', 'updated_at'],
            'ipam_net_owners', 'ipam_net_functions', 'ipam_vlan_owners' => self::taxonomyColumns($table),
            'ipam_networks' => [
                'network_id', 'parent_id', 'cidr', 'family', 'network', 'prefixlen',
                'packed_start', 'packed_end', 'description', 'site_id', 'vlan_id',
                'net_owner_id', 'net_function_id', 'auto_created', 'ignored',
                'created_at', 'updated_at',
            ],
            'ipam_vlans' => [
                'vlan_record_id', 'vlan_id', 'vlan_domain', 'site_id', 'vlan_owner_id',
                'name', 'description', 'auto_created', 'ignored', 'created_at', 'updated_at',
            ],
            'ipam_vlan_locations' => [
                'vlan_location_id', 'vlan_record_id', 'location_id', 'created_at', 'updated_at',
            ],
            'ipam_vlan_names' => [
                'vlan_name_id', 'vlan_record_id', 'device_id', 'name', 'reported_at',
                'created_at', 'updated_at',
            ],
            'ipam_addresses' => [
                'address_record_id', 'family', 'address', 'prefixlen', 'port_id', 'device_id',
                'context_name', 'network_id', 'vlan_record_id', 'site_id', 'location_id',
                'net_owner_id', 'net_function_id', 'scope_locked', 'ignored', 'description',
                'first_seen', 'last_seen', 'created_at', 'updated_at',
            ],
            'ipam_duplicates' => [
                'duplicate_id', 'family', 'address', 'vlan_record_id', 'site_id',
                'ignored', 'note', 'updated_by', 'created_at', 'updated_at',
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private static function taxonomyColumns(string $table): array
    {
        $key = match ($table) {
            'ipam_net_owners' => 'net_owner_id',
            'ipam_net_functions' => 'net_function_id',
            default => 'vlan_owner_id',
        };

        return [$key, 'name', 'description', 'created_at', 'updated_at'];
    }

    /**
     * Drop every plugin table. Used by ipam:uninstall and the tests.
     */
    public static function uninstall(): void
    {
        // Reverse order, so a table is never dropped before something pointing
        // at it. There are no foreign keys, but this keeps the order readable.
        foreach (array_reverse(self::tables()) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Are all plugin tables present?
     *
     * @return list<string> the tables that are missing
     */
    public static function missing(): array
    {
        return array_values(array_filter(
            self::tables(),
            fn (string $table): bool => ! Schema::hasTable($table)
        ));
    }

    /**
     * @return array<string, callable(Blueprint): void>
     */
    private static function builders(): array
    {
        return [
            'ipam_sites' => self::sites(...),
            'ipam_locations' => self::locations(...),
            'ipam_net_owners' => self::netOwners(...),
            'ipam_net_functions' => self::netFunctions(...),
            'ipam_vlan_owners' => self::vlanOwners(...),
            'ipam_networks' => self::networks(...),
            'ipam_vlans' => self::vlans(...),
            'ipam_vlan_locations' => self::vlanLocations(...),
            'ipam_vlan_names' => self::vlanNames(...),
            'ipam_addresses' => self::addresses(...),
            'ipam_duplicates' => self::duplicates(...),
        ];
    }

    /**
     * Sites: the places the estate actually consists of.
     *
     * A site is an authoring decision, not something read off a device. Devices
     * report a sysLocation, and there are far more of those than there are real
     * places: in this install 38 location strings describe about five sites,
     * with "Galazzano", "Galazzano Sala B", "Galazzano, (Sala server A)" and
     * "SSIS Galazzano (Sala server A)" all naming the same building. The site
     * is what the operator decides, and it is what separates two scopes in
     * duplicate detection.
     */
    private static function sites(Blueprint $table): void
    {
        $table->increments('site_id');
        $table->string('name', 128);
        $table->string('description', 255)->nullable();
        $table->string('address', 255)->nullable();
        $table->timestamps();

        $table->unique('name', 'ipam_sites_name_unique');
    }

    /**
     * The LibreNMS sysLocation strings that belong to a site.
     *
     * Several rows per site, which is the whole point: one site, many rooms,
     * one site for the purposes of collision detection.
     */
    private static function locations(Blueprint $table): void
    {
        $table->increments('location_id');
        $table->unsignedInteger('site_id')->default(0);
        // the sysLocation string exactly as LibreNMS stores it, so it can be
        // matched on import and shown as the device reported it
        $table->string('name', 255);
        $table->timestamps();

        $table->unique('name', 'ipam_locations_name_unique');
        $table->index('site_id', 'ipam_locations_site_id_index');
    }

    private static function netOwners(Blueprint $table): void
    {
        self::taxonomy($table, 'net_owner_id');
    }

    private static function netFunctions(Blueprint $table): void
    {
        self::taxonomy($table, 'net_function_id');
    }

    /**
     * The vlan owner taxonomy is deliberately a separate table from the network
     * owner one: a vlan owner is a person or team accountable for a broadcast
     * domain, which is not the same question as who a routed range belongs to.
     */
    private static function vlanOwners(Blueprint $table): void
    {
        self::taxonomy($table, 'vlan_owner_id');
    }

    private static function taxonomy(Blueprint $table, string $key): void
    {
        $table->increments($key);
        $table->string('name', 128);
        $table->string('description', 255)->nullable();
        $table->timestamps();

        $table->unique('name', $table->getTable() . '_name_unique');
    }

    /**
     * Network blocks, the tree of ranges.
     *
     * packed_start/packed_end hold the binary range so that "which block
     * contains this address" is an indexable range scan instead of a full
     * table walk. Longest prefix match is then decided by prefixlen.
     */
    private static function networks(Blueprint $table): void
    {
        $table->increments('network_id');
        $table->unsignedInteger('parent_id')->default(0);
        $table->string('cidr', 64);
        $table->tinyInteger('family')->default(4);
        $table->string('network', 64);
        $table->smallInteger('prefixlen');
        $table->binary('packed_start', 16);
        $table->binary('packed_end', 16);

        $table->string('description', 255)->nullable();
        $table->unsignedInteger('site_id')->default(0);
        // The 802.1q id as the devices report it. Not a vlans.vlan_id: the same
        // id on another device is a different vlans row but often the same vlan.
        $table->unsignedInteger('vlan_id')->default(0);
        $table->unsignedInteger('net_owner_id')->default(0);
        $table->unsignedInteger('net_function_id')->default(0);
        $table->boolean('auto_created')->default(false);
        // Set by hand, or at import when the whole block sits inside an
        // excluded range like 127.0.0.0/8: loopback exists on every device and
        // says nothing about the estate. Hidden blocks also hide the addresses
        // under them, so one click clears a whole family of noise.
        $table->boolean('ignored')->default(false);
        $table->timestamps();

        $table->unique('cidr', 'ipam_networks_cidr_unique');
        $table->index(['family', 'packed_start'], 'ipam_networks_family_start_index');
        $table->index('parent_id', 'ipam_networks_parent_id_index');
        $table->index('site_id', 'ipam_networks_site_id_index');
        $table->index('vlan_id', 'ipam_networks_vlan_id_index');
    }

    /**
     * A vlan as it applies to one site.
     *
     * 141 of the 220 vlan ids in a typical install show up at more than one
     * site, so a vlan id on its own is not a key: the same 100 at the head
     * office and at a branch are two different broadcast domains.
     */
    private static function vlans(Blueprint $table): void
    {
        $table->increments('vlan_record_id');
        $table->unsignedInteger('vlan_id');
        $table->unsignedInteger('vlan_domain')->default(1);
        $table->unsignedInteger('site_id')->default(0);
        $table->unsignedInteger('vlan_owner_id')->default(0);

        // Seeded from vlan_name on import. The devices truncate that to 32
        // characters, so this is a starting point, not the description.
        $table->string('name', 255)->nullable();
        $table->text('description')->nullable();
        $table->boolean('auto_created')->default(false);
        // Listing only: a hidden vlan stays out of the vlan page, but its
        // addresses are real and keep taking part in collision detection.
        $table->boolean('ignored')->default(false);
        $table->timestamps();

        // The scope a collision is judged in. A vlan id alone is not an
        // identity: in this install 141 of 220 ids show up at more than one
        // site, and vlan 100 at the head office and vlan 100 at a branch are
        // unrelated broadcast domains.
        $table->unique(
            ['vlan_id', 'vlan_domain', 'site_id'],
            'ipam_vlans_scope_unique'
        );
        $table->index('site_id', 'ipam_vlans_site_id_index');
        $table->index('vlan_owner_id', 'ipam_vlans_vlan_owner_id_index');
    }

    /**
     * Which locations inside a site a vlan was actually reported at.
     *
     * A vlan spans a site, so one record can be seen in several rooms, and this
     * is where those rooms are listed. The vlan itself is not duplicated per
     * room: it stays one record for the site, which is what makes it one
     * broadcast domain as far as collision detection is concerned.
     */
    private static function vlanLocations(Blueprint $table): void
    {
        $table->increments('vlan_location_id');
        $table->unsignedInteger('vlan_record_id')->default(0);
        $table->unsignedInteger('location_id')->default(0);
        $table->timestamps();

        $table->unique(
            ['vlan_record_id', 'location_id'],
            'ipam_vlan_locations_pair_unique'
        );
        $table->index('location_id', 'ipam_vlan_locations_location_id_index');
    }

    /**
     * Every name the devices reported for one vlan, kept per device.
     *
     * A single vlan is routinely called different things on different switches,
     * and in this install 24 (vlan, site) pairs disagree outright. Keeping
     * them all is what makes that visible.
     */
    private static function vlanNames(Blueprint $table): void
    {
        $table->increments('vlan_name_id');
        $table->unsignedInteger('vlan_record_id');
        $table->unsignedInteger('device_id')->default(0);
        $table->string('name', 255)->nullable();
        $table->unsignedInteger('reported_at')->nullable();
        $table->timestamps();

        $table->unique(
            ['vlan_record_id', 'device_id'],
            'ipam_vlan_names_record_device_unique'
        );
        $table->index('device_id', 'ipam_vlan_names_device_id_index');
    }

    /**
     * One row per address seen on a port.
     *
     * This is the table duplicate detection reads. The natural key mirrors
     * LibreNMS's own Ipv4Address::getCompositeKey(), so an address reported on
     * the same port twice in two vrfs stays two rows, exactly as LibreNMS
     * stores them.
     */
    private static function addresses(Blueprint $table): void
    {
        $table->increments('address_record_id');
        $table->tinyInteger('family')->default(4);
        $table->string('address', 45);
        $table->smallInteger('prefixlen')->default(32);

        $table->unsignedInteger('port_id')->default(0);
        $table->unsignedInteger('device_id')->default(0);
        $table->string('context_name', 128)->default('');

        $table->unsignedInteger('network_id')->default(0);
        $table->unsignedInteger('vlan_record_id')->default(0);
        $table->unsignedInteger('site_id')->default(0);
        // which sysLocation this occurrence was reported at, which is finer than
        // the site. The site stays the scope a collision is judged in; the
        // location is kept so the addresses page can show the room, and so two
        // rooms of one site are visibly one site.
        $table->unsignedInteger('location_id')->default(0);
        $table->unsignedInteger('net_owner_id')->default(0);
        $table->unsignedInteger('net_function_id')->default(0);

        // Set once an operator pins the scope by hand. Import then leaves the
        // scope columns alone instead of re-deriving them.
        $table->boolean('scope_locked')->default(false);
        // Loopback, link-local and the like: set at import from the excluded
        // ranges or from an ignored enclosing block, set by hand from the row,
        // and never re-derived once the row exists, so an operator who un-ignores
        // one keeps it that way.
        //
        // The column is called ignored, not hidden, on purpose: hidden is a
        // reserved Eloquent property (the attributes kept out of array and json
        // output), and a column by that name turns every $model->hidden into an
        // ambiguity between the column and the framework's own list.
        $table->boolean('ignored')->default(false);
        $table->string('description', 255)->nullable();

        $table->timestamp('first_seen')->nullable();
        $table->timestamp('last_seen')->nullable();
        $table->timestamps();

        // mysql allows 64 characters for an index name, so these are short on
        // purpose and cannot spell out every column
        $table->unique(
            ['family', 'address', 'prefixlen', 'port_id', 'context_name'],
            'ipam_addresses_identity_unique'
        );
        // The duplicate query groups by this, and scoping by site or vlan is a
        // filter on it.
        $table->index(['family', 'address'], 'ipam_addresses_family_address_index');
        $table->index('site_id', 'ipam_addresses_site_id_index');
        $table->index('location_id', 'ipam_addresses_location_id_index');
        $table->index('vlan_record_id', 'ipam_addresses_vlan_record_id_index');
        $table->index('network_id', 'ipam_addresses_network_id_index');
        $table->index('device_id', 'ipam_addresses_device_id_index');
        $table->index('port_id', 'ipam_addresses_port_id_index');
    }

    /**
     * Operator annotations on a duplicate: what it is, and whether to keep
     * reporting it.
     *
     * The duplicate list itself is always recomputed from ipam_addresses, so a
     * table that only holds notes can never go stale and hide a real conflict.
     */
    private static function duplicates(Blueprint $table): void
    {
        $table->increments('duplicate_id');
        $table->tinyInteger('family')->default(4);
        $table->string('address', 45);
        $table->unsignedInteger('vlan_record_id')->default(0);
        $table->unsignedInteger('site_id')->default(0);

        $table->boolean('ignored')->default(false);
        $table->string('note', 255)->nullable();
        $table->unsignedInteger('updated_by')->default(0);
        $table->timestamps();

        $table->unique(
            ['family', 'address', 'vlan_record_id', 'site_id'],
            'ipam_duplicates_scope_unique'
        );
    }
}
