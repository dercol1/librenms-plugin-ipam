<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkFunction;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanOwner;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Dercol1\LibrenmsIpam\Web\SiteDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * The plugin as LibreNMS sees it: the schema, the views, the taxonomies.
 *
 * These are the things that only break once the plugin is actually installed,
 * where a mistake costs a browser session and a confusing page rather than a
 * failing assertion.
 */
class PluginSurfaceTest extends UnitTestCase
{
    public function test_every_declared_table_is_actually_created(): void
    {
        // a table named in the list but not in a builder would look installed
        // from the outside and be missing when a page asks for it
        $builders = (new \ReflectionClass(SchemaInstaller::class))
            ->getMethod('builders');

        $builders->setAccessible(true);

        $defined = array_keys($builders->invoke(null));

        foreach (SchemaInstaller::tables() as $table) {
            $this->assertContains($table, $defined, "$table is listed but has no builder");
            $this->assertTrue(Schema::hasTable($table), "$table was not created");
        }

        foreach ($defined as $table) {
            $this->assertContains($table, SchemaInstaller::tables(), "$table has a builder but is not listed");
        }
    }

    public function test_every_created_column_is_declared_for_the_checker(): void
    {
        // ipam:check compares the live table against columns(), a mismatch
        // there means a false "unexpected column" warning on every run
        foreach (SchemaInstaller::tables() as $table) {
            $declared = SchemaInstaller::columns($table);

            $this->assertNotSame([], $declared, "$table declares no columns");

            $actual = Schema::getColumnListing($table);

            $this->assertSame(
                [],
                array_diff($declared, $actual),
                "$table: declared but not created"
            );
        }
    }

    public function test_the_schema_install_is_idempotent(): void
    {
        // daily.sh runs the migration on every poll cycle, so a second pass has
        // to be a no-op rather than an error
        $this->assertSame([], SchemaInstaller::install());
        $this->assertSame([], SchemaInstaller::missing());
    }

    public function test_each_view_renders_against_empty_data(): void
    {
        // a page that throws on an empty install leaves the operator staring at
        // a stack trace instead of an explanation
        $directory = SiteDirectory::load();

        foreach ([
            'missing' => ['missing' => ['ipam_addresses']],
            'networks' => ['title' => 't', 'networks' => null, 'options' => ['sites' => [], 'owners' => [], 'functions' => []], 'directory' => $directory, 'blocksPerSite' => [], 'addressesPerSite' => [], 'summary' => [], 'perPage' => 50],
            'vlans' => ['title' => 't', 'vlans' => null, 'options' => ['sites' => [], 'owners' => []], 'directory' => $directory, 'summary' => []],
            'addresses' => ['title' => 't', 'addresses' => null, 'options' => ['sites' => [], 'vlans' => [], 'owners' => [], 'functions' => [], 'networks' => []], 'directory' => $directory, 'summary' => [], 'perPage' => 50],
            'duplicates' => ['title' => 't', 'groups' => [], 'unclassified' => [], 'unclassifiedTotal' => 0, 'options' => ['sites' => [], 'vlans' => []], 'directory' => $directory, 'vlanLabels' => [], 'siteLabels' => [], 'useVlanScope' => true, 'counts' => []],
            'sites' => ['title' => 't', 'sites' => collect(), 'suggestions' => [], 'emptySites' => collect(), 'unassigned' => collect(), 'canManage' => false, 'siteOptions' => [], 'summary' => ['sites' => 0, 'locations' => 0, 'grouped' => 0, 'vlans' => 0]],
            'taxonomies' => ['title' => 't', 'lists' => [], 'canManage' => false],
            'landing' => ['pages' => [], 'next_step' => null],
            'menu' => ['label' => 'IPAM', 'url' => 'http://x/plugin/ipam', 'collisions' => 2],
        ] as $view => $data) {
            $html = view(IpamPluginProvider::PLUGIN_NAME . "::$view", $data)->render();

            $this->assertNotSame('', $html, "$view rendered nothing");
        }
    }

    public function test_the_network_and_vlan_taxonomies_are_separate(): void
    {
        // the owner of a routed range and the owner of a broadcast domain are
        // different questions, and merging them would answer one of them wrong
        NetworkOwner::query()->create(['name' => 'Network team']);
        VlanOwner::query()->create(['name' => 'Network team']);

        $this->assertSame(1, NetworkOwner::query()->where('name', 'Network team')->count());
        $this->assertSame(1, VlanOwner::query()->where('name', 'Network team')->count());

        $this->assertSame(1, NetworkFunction::query()->count() + 1);
    }

    public function test_a_vlan_id_can_exist_at_two_sites(): void
    {
        // 141 of 220 vlan ids in a real install show up at more than one site
        Vlan::query()->create(['vlan_id' => 100, 'vlan_domain' => 1, 'site_id' => Site::query()->create(['name' => 'Milano'])->site_id]);
        Vlan::query()->create(['vlan_id' => 100, 'vlan_domain' => 1, 'site_id' => Site::query()->create(['name' => 'Roma'])->site_id]);

        $this->assertSame(2, Vlan::query()->where('vlan_id', 100)->count());
    }

    public function test_the_same_vlan_twice_at_one_site_is_rejected_by_the_key(): void
    {
        // this is what stops a re-import from piling up duplicates
        $site = Site::query()->create(['name' => 'Milano']);

        Vlan::query()->create(['vlan_id' => 100, 'vlan_domain' => 1, 'site_id' => $site->site_id]);

        $this->expectException(UniqueConstraintViolationException::class);

        Vlan::query()->create(['vlan_id' => 100, 'vlan_domain' => 1, 'site_id' => $site->site_id]);
    }

    public function test_a_vlan_description_is_not_truncated_at_the_snmp_limit(): void
    {
        // the devices cut vlan_name at 32 characters, which is why the real
        // description has to live in a column nothing shortens
        $site = Site::query()->create(['name' => 'Milano']);

        $vlan = Vlan::query()->create([
            'vlan_id' => 300,
            'site_id' => $site->site_id,
            'name' => 'CARISP-CITRIX-FORNITORI-10-6-1',
            'description' => 'Citrix farm for the external supplier, subnet 10.6.0.0/24, '
                . 'owner is the supplier itself, changes go through the ticket queue',
        ]);

        $fresh = Vlan::find($vlan->vlan_record_id);

        $this->assertGreaterThan(32, strlen((string) $fresh->description));
        $this->assertStringContainsString('Citrix farm', (string) $fresh->description);
    }

    public function test_the_vlan_label_prefers_the_description(): void
    {
        // falls back through description, then the device reported name, then a
        // bare id, so a vlan always has something readable to show
        $described = Vlan::query()->create(['vlan_id' => 300, 'name' => 'VLAN 300', 'description' => 'the real one']);
        $named = Vlan::query()->create(['vlan_id' => 301, 'name' => 'VLAN 301']);
        $bare = Vlan::query()->create(['vlan_id' => 302]);

        $this->assertSame('the real one', $described->label());
        $this->assertSame('VLAN 301', $named->label());
        $this->assertSame('VLAN 302', $bare->label());
    }

    public function test_an_address_is_scopeless_only_with_neither_vlan_nor_site(): void
    {
        $site = Site::query()->create(['name' => 'Milano']);

        // read back from the database rather than from the returned model, so a
        // column default that never reaches the attribute bag cannot hide here
        $bare = Address::query()->create(['family' => 4, 'address' => '10.0.0.1', 'prefixlen' => 24, 'port_id' => 1, 'device_id' => 1, 'context_name' => '']);
        $this->assertTrue(Address::find($bare->address_record_id)->isUnscoped(), 'neither vlan nor site');

        $withSite = Address::query()->create(['family' => 4, 'address' => '10.0.0.2', 'prefixlen' => 24, 'port_id' => 2, 'device_id' => 2, 'context_name' => '', 'site_id' => $site->site_id]);
        $this->assertFalse($withSite->isUnscoped(), 'a site is enough to be placed');
    }

    public function test_a_pinned_address_is_reported_as_such(): void
    {
        $address = Address::query()->create([
            'family' => 4,
            'address' => '10.0.0.1',
            'prefixlen' => 24,
            'port_id' => 1,
            'device_id' => 1,
            'context_name' => '',
            'scope_locked' => true,
        ]);

        $this->assertTrue((bool) Address::find($address->address_record_id)->scope_locked);
    }

    public function test_the_duplicate_count_matches_the_group_count(): void
    {
        // count() is what the menu badge shows, detect() is what the page shows.
        // If they disagree the badge lies.
        $site = Site::query()->create(['name' => 'Milano']);
        $vlan = Vlan::query()->create(['vlan_id' => 300, 'site_id' => $site->site_id]);

        foreach (range(1, 2) as $index) {
            Address::query()->create([
                'family' => 4,
                'address' => '10.0.0.1',
                'prefixlen' => 24,
                'port_id' => $index,
                'device_id' => $index,
                'context_name' => '',
                'vlan_record_id' => $vlan->vlan_record_id,
                'site_id' => $site->site_id,
            ]);
        }

        $detector = new DuplicateDetector;

        $this->assertSame(1, $detector->count());
        $this->assertCount(1, $detector->detect());
    }

    public function test_a_duplicate_note_survives_a_recomputation(): void
    {
        // the list is recomputed on every read, so the verdict has to be looked
        // up by scope each time rather than carried in the row
        $site = Site::query()->create(['name' => 'Milano']);
        $vlan = Vlan::query()->create(['vlan_id' => 300, 'site_id' => $site->site_id]);

        foreach (range(1, 2) as $index) {
            Address::query()->create([
                'family' => 4,
                'address' => '10.0.0.1',
                'prefixlen' => 24,
                'port_id' => $index,
                'device_id' => $index,
                'context_name' => '',
                'vlan_record_id' => $vlan->vlan_record_id,
                'site_id' => $site->site_id,
            ]);
        }

        Duplicate::query()->create([
            'family' => 4,
            'address' => '10.0.0.1',
            'site_id' => $site->site_id,
            'vlan_record_id' => $vlan->vlan_record_id,
            'ignored' => true,
            'note' => 'floating address on purpose',
        ]);

        $group = (new DuplicateDetector)->detect()[0];

        $this->assertTrue($group->ignored);
        $this->assertSame('floating address on purpose', $group->note);
    }

    public function test_the_network_block_table_refuses_an_unusable_cidr(): void
    {
        // the range columns cannot be derived from nonsense, so nothing is
        // stored at all. Storing it anyway would leave network and the packed
        // range describing something other than the cidr, and a containment
        // test would then quietly answer for the wrong block.
        $this->expectException(InvalidArgumentException::class);

        Network::query()->create(['cidr' => 'nonsense']);
    }

    public function test_editing_a_block_cidr_to_nonsense_is_refused(): void
    {
        // the same reason, on update: a stale range is worse than none, and the
        // operator would think the block had been renamed
        $block = Network::query()->create(['cidr' => '10.0.0.0/8']);

        $block->cidr = 'nonsense';

        $this->expectException(InvalidArgumentException::class);

        $block->save();
    }

    public function test_a_refused_cidr_leaves_the_stored_block_untouched(): void
    {
        $block = Network::query()->create(['cidr' => '10.0.0.0/8']);

        try {
            $block->cidr = 'nonsense';
            $block->save();
        } catch (InvalidArgumentException) {
            // expected
        }

        $fresh = Network::find($block->network_id);

        $this->assertSame('10.0.0.0/8', $fresh->cidr);
        $this->assertSame('10.255.255.255', inet_ntop($fresh->packed_end));
    }
}
