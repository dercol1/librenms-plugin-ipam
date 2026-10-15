<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Import\Reconciler;
use Dercol1\LibrenmsIpam\Import\VlanImporter;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Illuminate\Support\Facades\DB;

/**
 * Objects that change hands, in both directions.
 *
 * The rule the plugin follows is one sentence: what LibreNMS reports is the
 * import's, and everything else belongs to the operator. An import therefore
 * moves rows between the two in both directions, and the interesting part is
 * what it does with the words the operator typed on them.
 *
 *   gone from LibreNMS   with nothing under it, the row was scaffolding and
 *                       goes; with data still under it, or with an operator's
 *                       words on it, it is handed over instead.
 *
 *   found in LibreNMS   the mirror: a range, a vlan or an address the operator
 *                       had written down and that a device now reports too stop
 *                       being theirs and become the import's again. What they
 *                       wrote stays on the row — a description does not become
 *                       the device's because a device turned up — and it is
 *                       exactly that, which stops the row being deleted when the
 *                       device stops once more.
 *
 * A plan is the case that used to go wrong in a visible way: an address written
 * down in advance has no port behind it, so the importer never matched it against
 * the port that later really got it. The same address ended up twice, and twice
 * in one scope reads as a duplicate — a conflict invented by the plan.
 */
class ObjectMobilityTest extends UnitTestCase
{
    private Site $milano;

    private Site $roma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->milano = Site::query()->create(['name' => 'Milano']);
        $this->roma = Site::query()->create(['name' => 'Roma']);
    }

    /**
     * One run of both importers and the reconciliation, the way the command does.
     */
    private function runImport(): ImportResult
    {
        $result = new ImportResult;

        $vlans = new VlanImporter;
        $vlans->import($result);

        $addresses = new AddressImporter;
        $addresses->import($result);
        $addresses->publishSeen($result);

        (new Reconciler)->run($result);

        return $result;
    }

    private function addressOn(string $ip, int $portId = 1, int $prefixLen = 24): void
    {
        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => $ip,
            'ipv4_prefixlen' => $prefixLen,
            'port_id' => $portId,
            'context_name' => '',
        ]);
    }

    private function vlanOn(int $vlanId, int $deviceId = 1, int $domain = 1): void
    {
        DB::table('vlans')->insert([
            'device_id' => $deviceId,
            'vlan_vlan' => $vlanId,
            'vlan_domain' => $domain,
            'vlan_name' => "FROM-SWITCH-$vlanId",
        ]);
    }

    // ---------------------------------------------------------------- blocks

    public function test_a_range_written_by_hand_and_found_again_becomes_the_polls_again(): void
    {
        // the operator describes a wide segment before anything is plugged in
        $block = Network::query()->create([
            'cidr' => '10.70.0.0/16',
            'description' => 'il segmento di espansione',
            'auto_created' => false,
        ]);

        $this->assertFalse($block->auto_created, 'written by hand, it is the operator\'s');

        $this->addressOn('10.70.1.5');
        $result = $this->runImport();

        $fresh = $block->fresh();

        $this->assertTrue($fresh->auto_created, 'a device reports it, so the poll owns the row again');
        $this->assertSame('il segmento di espansione', $fresh->description, 'and what the operator wrote stays on it');
        $this->assertSame(1, $result->handedBack);
    }

    public function test_a_described_range_that_disappears_again_is_handed_over_not_deleted(): void
    {
        $this->addressOn('10.71.1.5');

        $this->runImport();

        $block = Network::query()->where('cidr', '10.71.0.0/16')->firstOrFail();

        $block->update(['description' => 'piano di espansione']);
        $this->assertTrue($block->fresh()->auto_created);

        // the address goes, and the block has nothing left under it. The estate
        // keeps reporting something else, otherwise there is no evidence about
        // anything at all and the import refuses to conclude.
        DB::table('ipv4_addresses')->where('ipv4_address', '10.71.1.5')->delete();
        DB::table('ipam_addresses')->update(['last_seen' => now()->subHour()]);
        $this->addressOn('10.99.1.5', 2);

        $result = $this->runImport();

        $this->assertSame(1, $result->networksRemoved, 'the leaf below it is scaffolding and goes');

        $fresh = $block->fresh();

        $this->assertNotNull($fresh, 'a range somebody wrote on is not scaffolding');
        $this->assertFalse($fresh->auto_created, 'it is handed to the operator instead');
        $this->assertSame('piano di espansione', $fresh->description);
        $this->assertSame(1, $result->handedOver);
    }

    public function test_a_range_nobody_wrote_on_still_goes_when_nothing_is_left_in_it(): void
    {
        $this->addressOn('10.72.1.5');

        $this->runImport();

        $block = Network::query()->where('cidr', '10.72.0.0/16')->firstOrFail();

        DB::table('ipv4_addresses')->where('ipv4_address', '10.72.1.5')->delete();
        DB::table('ipam_addresses')->update(['last_seen' => now()->subHour()]);
        $this->addressOn('10.99.1.5', 2);

        $result = $this->runImport();

        $this->assertNull(Network::query()->find($block->network_id));
        // the /24 that held the address and the /16 above it. The /8 and the /0
        // keep the address the estate still reports, so they stay.
        $this->assertSame(2, $result->networksRemoved);
    }

    public function test_the_poller_does_not_churn_the_flag_on_every_run(): void
    {
        $this->addressOn('10.73.1.5');

        $this->runImport();

        $block = Network::query()->where('cidr', '10.73.0.0/16')->firstOrFail();

        // the operator writes on it, so the next import takes it back
        $block->update(['description' => 'nota', 'auto_created' => false]);

        $first = $this->runImport();
        $second = $this->runImport();

        $this->assertSame(1, $first->handedBack, 'once, when the poll found it');
        $this->assertSame(0, $second->handedBack, 'and not again on every poll after it');
        $this->assertTrue($block->fresh()->auto_created);
        $this->assertSame('nota', $block->fresh()->description);
    }

    // ----------------------------------------------------------------- vlans

    public function test_a_vlan_written_by_hand_and_reported_again_becomes_the_polls_again(): void
    {
        // the switch that will report it sits in Milano, so the record it produces
        // is the one the operator wrote there
        DB::table('devices')->where('device_id', 1)->update(['location_id' => 1]);
        DB::table('locations')->insert(['id' => 1, 'location' => 'Milano']);

        $vlan = Vlan::query()->create([
            'vlan_id' => 700,
            'site_id' => $this->milano->site_id,
            'description' => 'la vlan che verrà attivata',
            'auto_created' => false,
        ]);

        $this->vlanOn(700);

        $result = $this->runImport();

        $fresh = $vlan->fresh();

        $this->assertTrue($fresh->auto_created, 'the switch reports it, so the poll owns the row again');
        $this->assertSame('la vlan che verrà attivata', $fresh->description);
        $this->assertSame('FROM-SWITCH-700', $fresh->name, 'and the name the device gives it is filled in');
        $this->assertSame(1, $result->handedBack);
    }

    public function test_a_described_vlan_that_disappears_again_is_handed_over_not_deleted(): void
    {
        $this->addressOn('10.74.1.5', 2);

        $this->runImport();

        $vlan = Vlan::query()->create([
            'vlan_id' => 701,
            'site_id' => $this->milano->site_id,
            'description' => 'per il laboratorio',
            'auto_created' => true,
        ]);

        DB::table('vlans')->where('vlan_vlan', 701)->delete();
        // the estate is still reporting something else, so the reconciliation is
        // entitled to conclude anything about 701
        $this->vlanOn(799);

        $result = $this->runImport();

        $fresh = $vlan->fresh();

        $this->assertNotNull($fresh, 'words somebody typed are not residue');
        $this->assertFalse($fresh->auto_created);
        $this->assertSame('per il laboratorio', $fresh->description);
        $this->assertSame(1, $result->handedOver);
    }

    public function test_an_unnamed_unowned_vlan_that_disappears_still_goes(): void
    {
        $this->vlanOn(702);

        $this->runImport();

        $this->assertSame(1, Vlan::query()->where('vlan_id', 702)->count());

        DB::table('vlans')->where('vlan_vlan', 702)->delete();
        $this->vlanOn(703);

        $result = $this->runImport();

        $this->assertSame(0, Vlan::query()->where('vlan_id', 702)->count(), 'a name the device gave it is not a reason to keep it');
        $this->assertSame(1, $result->vlansRemoved);
    }

    // -------------------------------------------------------------- addresses

    public function test_an_address_written_in_advance_and_configured_for_real_becomes_one_object(): void
    {
        $room = Location::query()->create(['name' => 'Milano rack A', 'site_id' => $this->milano->site_id]);

        $plan = Address::query()->create([
            'family' => 4, 'address' => '10.75.0.7', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 0, 'device_id' => 0, 'site_id' => $this->milano->site_id,
            'location_id' => $room->location_id, 'description' => 'il gateway del laboratorio',
            'scope_locked' => true,
        ]);

        // the device in Milano reports the address the plan was written for
        DB::table('devices')->where('device_id', 1)->update(['location_id' => $room->location_id]);
        DB::table('locations')->insert(['id' => 1, 'location' => 'Milano rack A']);
        $this->addressOn('10.75.0.7');

        $result = $this->runImport();

        $rows = Address::query()->where('address', '10.75.0.7')->get();

        $this->assertCount(1, $rows, 'one object, not a plan and an occurrence');
        $this->assertSame(1, (int) $rows[0]->port_id, 'and it is the one the port really has');
        $this->assertFalse(Address::query()->whereKey($plan->address_record_id)->exists());
        $this->assertSame('il gateway del laboratorio', $rows[0]->description, 'the words came with it');
        $this->assertSame($room->location_id, $rows[0]->location_id);
        $this->assertTrue($rows[0]->scope_locked, 'and the pin, or the next poll would re-derive them');
        $this->assertSame(1, $result->plansAbsorbed);
    }

    public function test_a_plan_with_no_site_is_absorbed_wherever_the_device_is(): void
    {
        $plan = Address::query()->create([
            'family' => 4, 'address' => '10.76.0.9', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 0, 'device_id' => 0, 'site_id' => 0, 'scope_locked' => true,
        ]);

        DB::table('devices')->where('device_id', 1)->update(['location_id' => 1]);
        DB::table('locations')->insert(['id' => 1, 'location' => 'Roma']);
        $this->addressOn('10.76.0.9');

        $this->runImport();

        $rows = Address::query()->where('address', '10.76.0.9')->get();

        $this->assertCount(1, $rows);
        // the plan did not say where, so wherever the device is, it is the
        // address the operator meant
        $this->assertSame($this->roma->site_id, (int) $rows[0]->site_id);
    }

    public function test_a_plan_named_for_another_site_stays_two_objects_and_says_so(): void
    {
        $plan = Address::query()->create([
            'family' => 4, 'address' => '10.77.0.9', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 0, 'device_id' => 0, 'site_id' => $this->roma->site_id, 'scope_locked' => true,
        ]);

        // the same address turns up in Milano, which is a different object by
        // the plugin's own rule: the site is what separates two scopes
        DB::table('devices')->where('device_id', 1)->update(['location_id' => 1]);
        DB::table('locations')->insert(['id' => 1, 'location' => 'Milano']);
        $this->addressOn('10.77.0.9');

        $result = $this->runImport();

        $this->assertCount(2, Address::query()->where('address', '10.77.0.9')->get());
        $this->assertTrue(Address::query()->whereKey($plan->address_record_id)->exists());
        $this->assertSame(0, $result->plansAbsorbed);
        $this->assertNotEmpty(
            array_filter($result->warnings, fn (string $w): bool => str_contains($w, '10.77.0.9')),
            'and the operator is told, rather than the plugin moving an address between buildings'
        );
    }

    public function test_a_plan_that_is_never_configured_survives_every_run(): void
    {
        $plan = Address::query()->create([
            'family' => 4, 'address' => '10.78.0.9', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 0, 'device_id' => 0, 'scope_locked' => true,
        ]);

        // some other address, so the run is substantive
        $this->addressOn('10.78.1.5', 2);

        $this->runImport();
        $this->runImport();

        $this->assertTrue(Address::query()->whereKey($plan->address_record_id)->exists(), 'a plan nobody has built is still a plan');
    }

    // ------------------------------------------------------- sites and rooms

    public function test_a_site_the_operator_named_stays_theirs_when_devices_report_into_it(): void
    {
        DB::table('devices')->where('device_id', 1)->update(['location_id' => 1]);
        DB::table('locations')->insert(['id' => 1, 'location' => 'Milano']);

        $this->vlanOn(800);
        $this->addressOn('10.79.1.5');

        $this->runImport();

        $site = Site::query()->where('name', 'Milano')->firstOrFail();

        $this->assertFalse($site->auto_created, 'a site is an authoring decision, never the poll\'s row');
        $this->assertSame(1, Location::query()->where('site_id', $site->site_id)->count(), 'the device did fill it with its room');
    }

    public function test_a_placeholder_site_keeps_its_flag_when_devices_report_into_it(): void
    {
        DB::table('devices')->where('device_id', 1)->update(['location_id' => 1]);
        DB::table('locations')->insert(['id' => 1, 'location' => 'Sala operatori']);

        $this->vlanOn(801);

        $this->runImport();

        $site = Site::query()->where('name', 'Sala operatori')->firstOrFail();

        // it is still the resolver's shape: named after its own single room, and
        // that is what keeps it off the list of places a segment can reach
        $this->assertTrue($site->auto_created);
        $this->assertSame(1, Location::query()->where('site_id', $site->site_id)->count());
    }
}
