<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Import\VlanImporter;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Illuminate\Support\Facades\DB;

/**
 * The import against a stand-in for the LibreNMS tables it reads.
 *
 * The scope resolution is the part worth testing here: the block tree first,
 * then the port's vlan, then the device's location. Getting that order wrong
 * would classify addresses against the wrong site, which is the one mistake
 * this plugin cannot make without quietly producing wrong collision reports.
 */
class ImportResolutionTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * A LibreNMS location, attached to device 1, mirrored as an ipam site.
     */
    private function site(string $name): Site
    {
        $locationId = DB::table('locations')->insertGetId(['location' => $name]);

        DB::table('devices')->where('device_id', 1)->update(['location_id' => $locationId]);

        return Site::query()->create(['name' => $name, 'location_id' => $locationId]);
    }

    /**
     * A device with its own location, for the tests that need two sites.
     */
    private function device(string $hostname, ?string $location = null, ?int $sameLocationAs = null): int
    {
        $locationId = null;

        if ($location !== null) {
            $locationId = DB::table('locations')->insertGetId(['location' => $location]);
        } elseif ($sameLocationAs !== null) {
            // a second switch in the same rack, for the tests that need two
            // devices reporting one vlan without splitting it across two sites
            $locationId = (int) DB::table('devices')
                ->where('device_id', $sameLocationAs)
                ->value('location_id');
        }

        $deviceId = DB::table('devices')->insertGetId([
            'hostname' => $hostname,
            'display' => $hostname,
            'location_id' => $locationId,
        ]);

        DB::table('ports')->insert([
            'device_id' => $deviceId,
            'ifName' => 'Vlan300',
            'ifVlan' => 0,
        ]);

        return $deviceId;
    }

    private function address(string $ip, int $portId, int $prefixLen = 24, string $context = ''): void
    {
        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => $ip,
            'ipv4_prefixlen' => $prefixLen,
            'port_id' => $portId,
            'context_name' => $context,
        ]);
    }

    private function importer(): AddressImporter
    {
        return new AddressImporter(importIpv6: false);
    }

    public function test_an_address_inherits_the_site_and_owner_from_the_block_above(): void
    {
        // the case that makes the tree worth having: one assignment on the /8
        // describes everything under it
        $site = Site::query()->create(['name' => 'Milano']);
        $owner = NetworkOwner::query()->create(['name' => 'Network team']);

        $eight = Network::query()->create([
            'cidr' => '10.0.0.0/8',
            'site_id' => $site->site_id,
            'net_owner_id' => $owner->net_owner_id,
        ]);

        Network::query()->create(['cidr' => '10.14.0.0/16', 'parent_id' => $eight->network_id]);

        $this->address('10.14.146.7', 1);
        $this->importer()->import(new ImportResult);

        $record = Address::query()->where('address', '10.14.146.7')->first();

        $this->assertSame($site->site_id, $record->site_id);
        $this->assertSame($owner->net_owner_id, $record->net_owner_id);
    }

    public function test_an_undeclared_address_falls_back_to_the_device_location(): void
    {
        // in a real install almost every address is here: they sit on hosts and
        // firewalls, not on switch ports, so nothing declares their site
        $this->site('Milano');
        $this->address('10.1.1.1', 1);

        $this->importer()->import(new ImportResult);

        $record = Address::query()->where('address', '10.1.1.1')->first();

        $this->assertSame('Milano', $record->site->name);
    }

    public function test_the_block_wins_over_the_device_location(): void
    {
        // otherwise an address in a block explicitly placed at one site would be
        // classified by whatever location the reporting device happened to have
        Site::query()->create(['name' => 'Declared']);
        Network::query()->create(['cidr' => '10.2.0.0/16', 'site_id' => Site::query()->where('name', 'Declared')->value('site_id')]);

        $this->site('Device location');

        $this->address('10.2.3.4', 1);
        $this->importer()->import(new ImportResult);

        $record = Address::query()->where('address', '10.2.3.4')->first();

        $this->assertSame('Declared', $record->site->name);
    }

    public function test_the_device_location_can_be_turned_off(): void
    {
        // for a network where sysLocation is not a site, it is better to have
        // nothing than something wrong
        $this->site('Milano');
        $this->address('10.3.3.4', 1);

        (new AddressImporter(importIpv6: false, siteFromDevice: false))->import(new ImportResult);

        $record = Address::query()->where('address', '10.3.3.4')->first();

        $this->assertTrue($record->isUnscoped());
    }

    public function test_a_port_vlan_classifies_an_address(): void
    {
        $this->site('Milano');

        DB::table('ports_vlans')->insert([
            'device_id' => 1,
            'port_id' => 1,
            'vlan' => 300,
        ]);

        (new VlanImporter)->import(new ImportResult);
        $this->address('10.4.4.4', 1);

        $this->importer()->import(new ImportResult);

        $record = Address::query()->where('address', '10.4.4.4')->first();

        $this->assertNotSame(0, $record->vlan_record_id);
        $this->assertSame(300, $record->vlan->vlan_id);
    }

    public function test_ports_ifvlan_is_used_when_ports_vlans_has_nothing(): void
    {
        $this->site('Milano');

        DB::table('ports')->where('port_id', 1)->update(['ifVlan' => 301]);

        (new VlanImporter)->import(new ImportResult);
        $this->address('10.5.5.5', 1);

        $this->importer()->import(new ImportResult);

        $record = Address::query()->where('address', '10.5.5.5')->first();

        $this->assertSame(301, $record->vlan->vlan_id);
    }

    public function test_a_pinned_scope_survives_a_later_import(): void
    {
        // the whole point of scope_locked: an operator who corrected the site by
        // hand must not have it undone by the next poll
        $this->site('Milano');

        $wrongSite = Site::query()->create(['name' => 'Manual']);

        $this->address('10.6.6.6', 1);
        $this->importer()->import(new ImportResult);

        $record = Address::query()->where('address', '10.6.6.6')->first();
        $record->site_id = $wrongSite->site_id;
        $record->scope_locked = true;
        $record->save();

        // change the world, then import again
        $this->address('10.6.6.7', 1);
        $this->importer()->import(new ImportResult);

        $this->assertSame($wrongSite->site_id, Address::query()->where('address', '10.6.6.6')->first()->site_id);
    }

    public function test_ipv6_is_skipped_when_turned_off(): void
    {
        $this->site('Milano');

        DB::table('ipv6_addresses')->insert([
            'ipv6_address' => '2001:0db8:0000:0000:0000:0000:0000:0001',
            'ipv6_prefixlen' => 64,
            'port_id' => 1,
            'context_name' => '',
        ]);

        $this->importer()->import(new ImportResult);

        $this->assertSame(0, Address::query()->where('family', 6)->count());
    }

    public function test_ipv6_is_imported_when_turned_on(): void
    {
        $this->site('Milano');

        DB::table('ipv6_addresses')->insert([
            // the expanded form, which is what LibreNMS stores
            'ipv6_address' => '2001:0db8:0000:0000:0000:0000:0000:0001',
            'ipv6_prefixlen' => 64,
            'port_id' => 1,
            'context_name' => '',
        ]);

        (new AddressImporter(importIpv4: false))->import(new ImportResult);

        $record = Address::query()->where('family', 6)->first();

        $this->assertNotNull($record);
        $this->assertSame('2001:0db8:0000:0000:0000:0000:0000:0001', $record->address);
    }

    public function test_a_deleted_port_is_left_out_by_default(): void
    {
        // a deleted port is usually gone from the network too, so its addresses
        // would show as duplicates of things that no longer exist
        $this->site('Milano');
        DB::table('ports')->where('port_id', 1)->update(['deleted' => true]);

        $this->address('10.7.7.7', 1);
        $this->importer()->import(new ImportResult);

        $this->assertSame(0, Address::query()->where('address', '10.7.7.7')->count());
    }

    public function test_a_deleted_port_can_be_included_on_purpose(): void
    {
        $this->site('Milano');
        DB::table('ports')->where('port_id', 1)->update(['deleted' => true]);

        $this->address('10.8.8.8', 1);
        (new AddressImporter(importIpv6: false, includeInactivePorts: true))->import(new ImportResult);

        $this->assertSame(1, Address::query()->where('address', '10.8.8.8')->count());
    }

    public function test_two_imports_do_not_duplicate_an_address(): void
    {
        // the natural key is per address and port, so a re-run refreshes
        $this->site('Milano');

        $this->address('10.9.9.9', 1);

        $this->importer()->import(new ImportResult);
        $this->importer()->import(new ImportResult);

        $this->assertSame(1, Address::query()->where('address', '10.9.9.9')->count());
    }

    public function test_the_same_address_on_two_ports_keeps_two_rows(): void
    {
        // both occurrences have to survive, the collision report names them
        $this->site('Milano');

        $this->address('10.10.10.1', 1);
        $this->address('10.10.10.1', 2);

        $this->importer()->import(new ImportResult);

        $this->assertSame(2, Address::query()->where('address', '10.10.10.1')->count());
    }

    public function test_the_same_address_in_two_vrfs_on_one_port_keeps_two_rows(): void
    {
        // LibreNMS stores these apart, keyed by context_name, so the plugin does too
        $this->site('Milano');

        $this->address('10.11.11.1', 1, 24, '');
        $this->address('10.11.11.1', 1, 24, 'VRF-RED');

        $this->importer()->import(new ImportResult);

        $this->assertSame(2, Address::query()->where('address', '10.11.11.1')->count());
    }

    public function test_an_unreadable_address_is_reported_not_stored(): void
    {
        $this->site('Milano');

        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => 'not-an-address',
            'ipv4_prefixlen' => 24,
            'port_id' => 1,
            'context_name' => '',
        ]);

        $result = new ImportResult;
        $this->importer()->import($result);

        $this->assertNotSame([], $result->warnings);
        $this->assertSame(0, Address::query()->count());
    }

    public function test_the_vlan_import_creates_one_row_per_site(): void
    {
        // vlan 100 at Milano and vlan 100 at Roma are two broadcast domains
        $this->site('Milano');
        $secondDevice = $this->device('sw2', 'Roma');

        foreach ([1, $secondDevice] as $deviceId) {
            DB::table('vlans')->insert([
                'device_id' => $deviceId,
                'vlan_vlan' => 100,
                'vlan_domain' => 1,
                'vlan_name' => 'OFFICE',
            ]);
        }

        (new VlanImporter)->import(new ImportResult);

        $this->assertSame(2, Vlan::query()->where('vlan_id', 100)->count());
    }

    public function test_the_vlan_import_uses_the_8021q_id_not_the_row_id(): void
    {
        // vlans.vlan_id is the primary key, unique per device, and means nothing.
        // Reading it instead of vlan_vlan would create one "unique" vlan per
        // device, which is what this caught in a real install.
        $this->site('Milano');

        foreach ([$this->device('sw2'), $this->device('sw3'), $this->device('sw4')] as $deviceId) {
            DB::table('vlans')->insert([
                'device_id' => $deviceId,
                'vlan_vlan' => 200,
                'vlan_domain' => 1,
                'vlan_name' => 'SERVERS',
            ]);
        }

        (new VlanImporter)->import(new ImportResult);

        $this->assertSame(1, Vlan::query()->where('vlan_id', 200)->count(), 'one row, not one per device');
    }

    public function test_a_vlan_name_is_seeded_but_the_description_is_left_free(): void
    {
        // the device name is truncated at 32 characters and is only a label, so
        // the description must start empty and be written by a person
        $this->site('Milano');

        DB::table('vlans')->insert([
            'device_id' => 1,
            'vlan_vlan' => 300,
            'vlan_domain' => 1,
            'vlan_name' => 'CARISP-CITRIX-FORNITORI-10-6-1',
        ]);

        (new VlanImporter)->import(new ImportResult);

        $vlan = Vlan::query()->where('vlan_id', 300)->first();

        $this->assertSame('CARISP-CITRIX-FORNITORI-10-6-1', $vlan->name);
        $this->assertNull($vlan->description);
        $this->assertTrue($vlan->auto_created);
    }

    public function test_a_described_vlan_is_not_overwritten_by_the_next_import(): void
    {
        $this->site('Milano');

        DB::table('vlans')->insert([
            'device_id' => 1,
            'vlan_vlan' => 300,
            'vlan_domain' => 1,
            'vlan_name' => 'FROM-DEVICE',
        ]);

        (new VlanImporter)->import(new ImportResult);

        $vlan = Vlan::query()->where('vlan_id', 300)->first();
        $vlan->description = 'written by a person';
        $vlan->auto_created = false;
        $vlan->save();

        DB::table('vlans')->update(['vlan_name' => 'CHANGED-ON-DEVICE']);
        (new VlanImporter)->import(new ImportResult);

        $fresh = Vlan::find($vlan->vlan_record_id);

        $this->assertSame('written by a person', $fresh->description);
        $this->assertSame('FROM-DEVICE', $fresh->name);
    }

    public function test_names_from_different_devices_are_all_kept(): void
    {
        // two switches calling one vlan different things is normal, and in this
        // install 24 (vlan, site) pairs disagree outright. Both switches are in
        // the same site, so the disagreement is about the name and nothing else.
        $this->site('Milano');

        $secondDevice = $this->device('sw2', sameLocationAs: 1);

        DB::table('vlans')->insert(['device_id' => 1, 'vlan_vlan' => 400, 'vlan_domain' => 1, 'vlan_name' => 'ONE']);
        DB::table('vlans')->insert(['device_id' => $secondDevice, 'vlan_vlan' => 400, 'vlan_domain' => 1, 'vlan_name' => 'TWO']);

        $result = new ImportResult;
        (new VlanImporter)->import($result);

        $vlan = Vlan::query()->where('vlan_id', 400)->first();
        $spread = $vlan->nameSpread();

        $this->assertSame(2, $spread['reported']);
        $this->assertSame(2, $spread['distinct']);
        $this->assertNotSame([], $result->warnings, 'the disagreement is worth a warning');
    }

    public function test_ipv4_can_be_turned_off(): void
    {
        $this->site('Milano');

        $this->address('10.12.12.12', 1);
        (new AddressImporter(importIpv4: false, importIpv6: false))->import(new ImportResult);

        $this->assertSame(0, Address::query()->count());
    }

    public function test_the_import_reports_what_it_did(): void
    {
        $this->site('Milano');
        $this->address('10.13.13.13', 1);

        $result = new ImportResult;
        $this->importer()->import($result);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(1, $result->seen);
        $this->assertNotSame([], $result->networksCreated, 'the wide blocks are created too');

        // the second run refreshes instead of inserting again
        $second = new ImportResult;
        $this->importer()->import($second);

        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->updated);
    }
}
