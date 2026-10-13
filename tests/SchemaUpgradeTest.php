<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The upgrade path from the schema this plugin had before sites and locations
 * were separated.
 *
 * The interesting part is the ordering: ipam_sites.location_id held the only
 * record of which LibreNMS location belonged to which site, so it has to be
 * copied into ipam_locations before the column is dropped. Doing it the other
 * way round loses the mapping silently, and the addresses of that site simply
 * stop resolving to anywhere.
 */
class SchemaUpgradeTest extends UnitTestCase
{
    /**
     * Put the tables back the way the earlier version left them.
     */
    private function downgradeToTheOldSchema(): void
    {
        SchemaInstaller::uninstall();

        Schema::create('ipam_sites', function ($table): void {
            $table->increments('site_id');
            $table->string('name', 128);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->timestamps();

            $table->unique('name', 'ipam_sites_name_unique');
            $table->index('location_id', 'ipam_sites_location_id_index');
        });

        // the addresses table as it was, without location_id
        Schema::create('ipam_addresses', function ($table): void {
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
            $table->unsignedInteger('net_owner_id')->default(0);
            $table->unsignedInteger('net_function_id')->default(0);
            $table->boolean('scope_locked')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
        });
    }

    public function test_the_upgrade_copies_the_old_site_location_link(): void
    {
        $this->downgradeToTheOldSchema();

        DB::table('locations')->insert([
            ['id' => 1, 'location' => 'Galazzano'],
            ['id' => 2, 'location' => 'Galazzano Sala B'],
            ['id' => 3, 'location' => 'Piazzetta del Titano'],
        ]);

        DB::table('ipam_sites')->insert([
            ['site_id' => 1, 'name' => 'Galazzano', 'location_id' => 1],
            ['site_id' => 2, 'name' => 'Galazzano Sala B', 'location_id' => 2],
            ['site_id' => 3, 'name' => 'Piazzetta del Titano', 'location_id' => 3],
        ]);

        $changes = SchemaInstaller::install();

        $this->assertContains('dropped ipam_sites.location_id', $changes);
        $this->assertNotContains('ipam_locations', $changes, 'the table already existed');

        // every location survived the rename of the relationship
        $this->assertSame(3, DB::table('ipam_locations')->count());

        $this->assertSame(
            1,
            DB::table('ipam_locations')->where('name', 'Galazzano')->value('site_id')
        );
        $this->assertSame(
            2,
            DB::table('ipam_locations')->where('name', 'Galazzano Sala B')->value('site_id')
        );
    }

    public function test_the_upgrade_copies_the_link_before_dropping_the_column(): void
    {
        // the ordering is the whole point of this test: if the column were
        // dropped first, moveSiteLinks() would find nothing and return 0, and
        // the sites would silently lose their locations
        $this->downgradeToTheOldSchema();

        DB::table('locations')->insert(['id' => 1, 'location' => 'Galazzano']);
        DB::table('ipam_sites')->insert(['site_id' => 1, 'name' => 'Galazzano', 'location_id' => 1]);

        $changes = SchemaInstaller::install();

        $this->assertContains('moved 1 location link(s) into ipam_locations', $changes);
        $this->assertFalse(Schema::hasColumn('ipam_sites', 'location_id'));
    }

    public function test_the_upgrade_adds_the_location_column_to_existing_addresses(): void
    {
        $this->downgradeToTheOldSchema();

        $record = new Address([
            'family' => 4,
            'address' => '10.0.0.1',
            'prefixlen' => 24,
            'context_name' => '',
            'port_id' => 1,
            'device_id' => 1,
        ]);
        $record->save();

        $changes = SchemaInstaller::install();

        $this->assertContains('added ipam_addresses.location_id', $changes);
        $this->assertTrue(Schema::hasColumn('ipam_addresses', 'location_id'));

        // an existing row gets 0, meaning "not associated", rather than null
        $fresh = Address::find($record->address_record_id);
        $this->assertSame(0, $fresh->location_id);
        $this->assertTrue($fresh->isUnscoped());
    }

    public function test_the_upgrade_ignores_the_loopback_already_in_the_table(): void
    {
        // The flag is set at row creation only, so rows that predate it would
        // never be touched again. The one pass that adds the column is the one
        // moment no operator has spoken about a row yet, and that is when the
        // backfill happens: once, and never on any later install.
        $this->downgradeToTheOldSchema();

        foreach (['10.0.0.1', '127.0.0.1', '10.0.0.2'] as $ip) {
            DB::table('ipam_addresses')->insert([
                'family' => 4,
                'address' => $ip,
                'prefixlen' => 24,
                'port_id' => 1,
                'device_id' => 1,
                'context_name' => '',
            ]);
        }

        $changes = SchemaInstaller::install();

        $this->assertContains('added ipam_addresses.ignored', $changes);
        $this->assertContains('ignored 1 existing address(es) inside the excluded ranges', $changes);

        $this->assertSame(1, DB::table('ipam_addresses')->where('address', '127.0.0.1')->where('ignored', true)->count());
        $this->assertSame(2, DB::table('ipam_addresses')->where('ignored', false)->count());
    }

    public function test_a_second_install_never_re_ignores_a_row(): void
    {
        // the operator showed a loopback row on purpose; a later install (which
        // daily.sh runs on every poll cycle) must not quietly undo that
        $this->downgradeToTheOldSchema();

        DB::table('ipam_addresses')->insert([
            'family' => 4, 'address' => '127.0.0.1', 'prefixlen' => 24,
            'port_id' => 1, 'device_id' => 1, 'context_name' => '',
        ]);

        SchemaInstaller::install();

        DB::table('ipam_addresses')->where('address', '127.0.0.1')->update(['ignored' => false]);

        $changes = SchemaInstaller::install();

        $this->assertNotContains('ignored 1 existing address(es) inside the excluded ranges', $changes);
        $this->assertSame(0, (int) DB::table('ipam_addresses')->where('address', '127.0.0.1')->value('ignored'));
    }

    public function test_running_the_upgrade_twice_changes_nothing_the_second_time(): void
    {
        $this->downgradeToTheOldSchema();

        DB::table('locations')->insert(['id' => 1, 'location' => 'Galazzano']);
        DB::table('ipam_sites')->insert(['site_id' => 1, 'name' => 'Galazzano', 'location_id' => 1]);

        $first = SchemaInstaller::install();
        $second = SchemaInstaller::install();

        $this->assertNotSame([], $first);
        $this->assertSame([], $second, 'the second run must be a no-op');
    }

    public function test_a_location_without_a_site_is_not_invented(): void
    {
        $this->downgradeToTheOldSchema();

        DB::table('locations')->insert(['id' => 1, 'location' => 'Galazzano']);
        // a site whose location points at nothing: the id no longer exists
        DB::table('ipam_sites')->insert(['site_id' => 1, 'name' => 'Galazzano', 'location_id' => 99]);

        SchemaInstaller::install();

        // the dangling id is dropped with the column, and nothing is guessed at
        $this->assertSame(0, DB::table('ipam_locations')->count());
    }
}
