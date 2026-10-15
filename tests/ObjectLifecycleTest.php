<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Import\Reconciler;
use Dercol1\LibrenmsIpam\Import\VlanImporter;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Networks\NetworkStretcher;
use Dercol1\LibrenmsIpam\Web\AddressesController;
use Dercol1\LibrenmsIpam\Web\NetworksController;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Who owns an object, and what may be done to it.
 *
 * The rule is one sentence long and everything else follows from it: what
 * LibreNMS reports is the import's, and the import's rows can only be hidden,
 * because the next poll brings them back and deleting one is a slower way of
 * hiding it. What an operator wrote — or what the import handed over once
 * LibreNMS stopped reporting it — is theirs, and theirs can be deleted.
 *
 * "Handed over" is the second half and it is not a courtesy. When a range stops
 * being reported and nothing hangs off it any more it was scaffolding and goes;
 * when something does hang off it, the row is a description of live data, and
 * deleting it would take the map with it, so the import stops calling it its own
 * and the operator decides what happens next.
 */
class ObjectLifecycleTest extends UnitTestCase
{
    protected function user(): User
    {
        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        Gate::define('ipam.view', fn ($u, mixed $target = null): bool => true);
        Gate::define('ipam.manage', fn ($u, mixed $target = null): bool => true);

        return $user;
    }

    private function request(string $uri = '/'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }

    /**
     * One run of both importers, followed by the reconciliation the command
     * does, which is the whole point: the two are never run apart.
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

    private function addressOn(string $ip, int $portId, int $prefixLen = 24): void
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
            'vlan_name' => "VLAN $vlanId",
        ]);
    }

    // ---------------------------------------------------------------- blocks

    public function test_a_block_the_import_owns_can_be_hidden_but_not_deleted(): void
    {
        $this->user();

        $block = Network::query()->create(['cidr' => '10.0.0.0/24', 'auto_created' => true]);

        $response = (new NetworksController)->destroy($this->request(), (int) $block->network_id);

        $this->assertNotNull($response->getSession()?->get('error'));
        $this->assertTrue(Network::query()->whereKey($block->network_id)->exists());
    }

    public function test_a_block_the_operator_owns_and_nothing_hangs_off_can_be_deleted(): void
    {
        $this->user();

        $block = Network::query()->create(['cidr' => '10.9.0.0/24', 'auto_created' => false]);

        (new NetworksController)->destroy($this->request(), (int) $block->network_id);

        $this->assertFalse(Network::query()->whereKey($block->network_id)->exists());
    }

    public function test_a_block_with_addresses_under_it_is_not_deleted(): void
    {
        $this->user();

        $block = Network::query()->create(['cidr' => '10.9.0.0/24', 'auto_created' => false]);

        Address::query()->create([
            'family' => 4, 'address' => '10.9.0.7', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 1, 'device_id' => 1, 'network_id' => $block->network_id,
        ]);

        $response = (new NetworksController)->destroy($this->request(), (int) $block->network_id);

        $this->assertNotNull($response->getSession()?->get('error'));
        $this->assertTrue(Network::query()->whereKey($block->network_id)->exists());
    }

    // ----------------------------------------------------------------- vlans

    public function test_a_vlan_the_import_owns_is_not_deleted(): void
    {
        $this->user();

        $vlan = Vlan::query()->create(['vlan_id' => 300, 'auto_created' => true]);

        $response = (new VlansController)->destroy($this->request(), (int) $vlan->vlan_record_id);

        $this->assertNotNull($response->getSession()?->get('error'));
        $this->assertTrue(Vlan::query()->whereKey($vlan->vlan_record_id)->exists());
    }

    public function test_a_vlan_with_addresses_classified_into_it_is_not_deleted(): void
    {
        $this->user();

        $vlan = Vlan::query()->create(['vlan_id' => 301, 'auto_created' => false]);

        Address::query()->create([
            'family' => 4, 'address' => '10.0.0.9', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 1, 'device_id' => 1, 'vlan_record_id' => $vlan->vlan_record_id,
        ]);

        $response = (new VlansController)->destroy($this->request(), (int) $vlan->vlan_record_id);

        $this->assertNotNull($response->getSession()?->get('error'));
        $this->assertTrue(Vlan::query()->whereKey($vlan->vlan_record_id)->exists());
    }

    public function test_a_vlan_the_operator_owns_and_nothing_points_at_can_be_deleted(): void
    {
        $this->user();

        $vlan = Vlan::query()->create(['vlan_id' => 302, 'auto_created' => false]);

        (new VlansController)->destroy($this->request(), (int) $vlan->vlan_record_id);

        $this->assertFalse(Vlan::query()->whereKey($vlan->vlan_record_id)->exists());
    }

    // ------------------------------------------------------------- addresses

    public function test_an_address_on_a_real_port_is_not_deleted(): void
    {
        $this->user();

        $address = Address::query()->create([
            'family' => 4, 'address' => '10.0.0.11', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 7, 'device_id' => 1, 'scope_locked' => true,
        ]);

        $response = (new AddressesController)->destroy($this->request(), (int) $address->address_record_id);

        $this->assertNotNull($response->getSession()?->get('error'));
        $this->assertTrue(Address::query()->whereKey($address->address_record_id)->exists());
    }

    public function test_an_address_the_operator_wrote_can_be_deleted(): void
    {
        $this->user();

        $address = Address::query()->create([
            'family' => 4, 'address' => '10.0.0.12', 'prefixlen' => 32, 'context_name' => '',
            'port_id' => 0, 'device_id' => 0, 'scope_locked' => true,
        ]);

        (new AddressesController)->destroy($this->request(), (int) $address->address_record_id);

        $this->assertFalse(Address::query()->whereKey($address->address_record_id)->exists());
    }

    // ---------------------------------------------------- what import leaves

    public function test_a_range_nothing_points_at_any_more_goes_away(): void
    {
        // an address the devices reported once, in a range of its own, while the
        // rest of the estate keeps being reported as it always was
        $this->addressOn('10.77.0.5', 1);
        $this->addressOn('10.90.0.5', 2);

        $this->runImport();

        $this->assertSame(1, Network::query()->where('cidr', '10.77.0.0/24')->count());

        // and now it is gone: the device stopped reporting it, and the occurrence
        // is old enough for the prune to let go of it
        DB::table('ipv4_addresses')->where('ipv4_address', '10.77.0.5')->delete();
        DB::table('ipam_addresses')->where('address', '10.77.0.5')->update(['last_seen' => now()->subHour()]);

        $this->runImport();

        $this->assertSame(0, Address::query()->where('address', '10.77.0.5')->count());
        $this->assertSame(0, Network::query()->where('cidr', '10.77.0.0/24')->count(), 'scaffolding with nothing in it is gone');
    }

    public function test_a_range_still_holding_an_address_is_handed_over_not_deleted(): void
    {
        $this->addressOn('10.78.0.5', 1);
        $this->addressOn('10.90.0.5', 2);

        $this->runImport();

        $block = Network::query()->where('cidr', '10.78.0.0/24')->first();

        $this->assertNotNull($block);
        $this->assertTrue($block->auto_created, 'the devices still report it, so it is still theirs');

        // an address of the operator's inside the range, and LibreNMS stops
        // reporting the one it used to hold
        Address::query()->create([
            'family' => 4, 'address' => '10.78.0.200', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 0, 'device_id' => 0, 'network_id' => $block->network_id,
            'scope_locked' => true,
        ]);

        DB::table('ipv4_addresses')->where('ipv4_address', '10.78.0.5')->delete();
        DB::table('ipam_addresses')
            ->where('address', '10.78.0.5')
            ->update(['last_seen' => now()->subHour()]);

        $result = $this->runImport();

        $this->assertSame(0, (int) Network::query()->where('cidr', '10.78.0.0/24')->value('auto_created'), 'now the operator owns it');
        // the /16 as well: it is not a leaf of nothing, the /24 below it is a
        // description now, and a range whose child describes something is not
        // scaffolding
        $this->assertSame(0, (int) Network::query()->where('cidr', '10.78.0.0/16')->value('auto_created'));
        $this->assertSame(2, $result->handedOver);
    }

    public function test_a_block_the_operator_wrote_is_never_taken_away(): void
    {
        $this->addressOn('10.79.0.5', 1);

        $this->runImport();

        $block = Network::query()->where('cidr', '10.79.0.0/24')->first();
        $block->update(['auto_created' => false, 'description' => 'piano di amministrazione']);

        $result = $this->runImport();

        $this->assertSame(0, $result->networksRemoved);
        $this->assertSame(0, $result->handedOver, 'it was never the import\'s to hand over');
        $this->assertTrue(Network::query()->whereKey($block->network_id)->exists());
    }

    public function test_a_stretched_range_is_never_taken_away(): void
    {
        $this->addressOn('10.80.0.5', 1);

        $this->runImport();

        $block = Network::query()->where('cidr', '10.80.0.0/24')->first();

        // the operator declared it stretched, then the devices stopped reporting it
        $site = Site::query()->create(['name' => 'Galazzano']);
        Location::query()->create(['name' => 'Galazzano sala A', 'site_id' => $site->site_id]);
        (new NetworkStretcher)->stretch($block, ['site:' . $site->site_id], null);

        $result = $this->runImport();

        $this->assertSame(0, $result->handedOver, 'no poll will ever confirm a domain, so it is not the import\'s');
        $this->assertSame(0, $result->networksRemoved);
        $this->assertTrue((int) $block->fresh()->stretch_id > 0);
    }

    public function test_an_import_that_reads_nothing_takes_nothing_away(): void
    {
        // an estate that was there last run and is not reported this one is not
        // the same as an estate that was never discovered
        $this->addressOn('10.81.0.5', 1);
        $this->vlanOn(700);

        $this->runImport();

        DB::table('ipv4_addresses')->delete();
        DB::table('vlans')->delete();

        $result = $this->runImport();

        $this->assertSame(0, $result->networksRemoved, 'no address read is not evidence that the ranges are gone');
        $this->assertSame(0, $result->vlansRemoved);
        $this->assertNotEmpty($result->warnings, 'and it says so rather than acting quietly');
    }

    public function test_a_vlan_nothing_points_at_any_more_goes_away(): void
    {
        $this->vlanOn(701);
        $this->addressOn('10.90.0.5', 2);

        $this->runImport();

        $this->assertSame(1, Vlan::query()->where('vlan_id', 701)->count());

        // the switch drops 701 and gains 702, and 701 was never written down by
        // hand or classified into
        DB::table('vlans')->where('vlan_vlan', 701)->delete();
        $this->vlanOn(702);

        $this->runImport();

        $this->assertSame(0, Vlan::query()->where('vlan_id', 701)->count());
        $this->assertSame(1, Vlan::query()->where('vlan_id', 702)->count());
    }

    public function test_a_vlan_addresses_are_classified_into_is_handed_over(): void
    {
        // another vlan keeps being reported, so the estate is visibly not empty
        // and the reconciliation is entitled to conclude anything about 703
        $this->vlanOn(705);
        $this->vlanOn(703);
        $this->addressOn('10.82.0.5', 1);
        $this->addressOn('10.90.0.5', 2);

        $this->runImport();

        $vlan = Vlan::query()->where('vlan_id', 703)->first();
        $this->assertNotNull($vlan);

        // The block declares the vlan, which is what keeps the occurrences
        // classified into it once the switch stops naming it: the port has no
        // vlan of its own to offer, so without that the address would simply
        // fall out of the scope.
        Network::query()->where('cidr', '10.82.0.0/24')->update(['vlan_id' => 703]);

        DB::table('vlans')->where('vlan_vlan', 703)->delete();

        $result = $this->runImport();

        $this->assertSame(0, (int) $vlan->fresh()->auto_created, 'an address is classified into it, so it is a description now');
        $this->assertSame(1, $result->handedOver);
    }

    // ------------------------------------------------------------- locations

    public function test_a_room_nobody_reports_any_more_goes_with_its_placeholder(): void
    {
        DB::table('locations')->insert(['id' => 1, 'location' => 'Galazzano']);
        DB::table('locations')->insert(['id' => 2, 'location' => 'Armadio che non esiste piu']);
        $this->addressOn('10.91.0.5', 1);

        $this->runImport();

        $this->assertSame(2, Location::query()->count(), 'syncAll files every room LibreNMS knows');

        // the device in the second room is gone, so LibreNMS drops the room
        DB::table('ipv4_addresses')->where('port_id', 2)->delete();
        DB::table('ports')->where('port_id', 2)->delete();
        DB::table('devices')->where('device_id', 2)->delete();
        DB::table('locations')->where('id', 2)->delete();

        $result = $this->runImport();

        $this->assertSame(0, Location::query()->where('name', 'Armadio che non esiste piu')->count());
        $this->assertSame(1, $result->locationsRemoved);
        // the site that existed to give that one string somewhere to land
        $this->assertSame(0, Site::query()->where('name', 'Armadio che non esiste piu')->count());
        $this->assertSame(1, $result->sitesRemoved);
        $this->assertSame(1, Site::query()->where('name', 'Galazzano')->count(), 'the room still reported stays');
    }

    public function test_a_room_a_vlan_of_the_operator_names_stays(): void
    {
        DB::table('locations')->insert(['id' => 1, 'location' => 'Galazzano']);
        DB::table('locations')->insert(['id' => 2, 'location' => 'Armadio A13']);
        $this->addressOn('10.93.0.5', 1);

        $this->runImport();

        // a vlan somebody wrote down, for a room, with the room written on it
        $room = Location::query()->where('name', 'Armadio A13')->firstOrFail();
        $vlan = Vlan::query()->create([
            'vlan_id' => 951, 'site_id' => $room->site_id, 'auto_created' => false,
        ]);
        DB::table('ipam_vlan_locations')->insert(['vlan_record_id' => $vlan->vlan_record_id, 'location_id' => $room->location_id]);

        // the string disappears from LibreNMS: no device reports it any more
        DB::table('locations')->where('id', 2)->delete();

        $result = $this->runImport();

        $this->assertSame(1, Location::query()->where('name', 'Armadio A13')->count(), 'a room somebody named it in is not residue');
        $this->assertSame(0, $result->locationsRemoved);
    }

    public function test_an_import_that_reads_no_room_takes_none_away(): void
    {
        DB::table('locations')->insert(['id' => 1, 'location' => 'Galazzano']);
        $this->addressOn('10.92.0.5', 1);

        $this->runImport();

        DB::table('locations')->delete();

        $result = $this->runImport();

        $this->assertSame(1, Location::query()->count(), 'no room read is not evidence that they are gone');
        $this->assertSame(0, $result->locationsRemoved);
    }

    public function test_a_vlan_in_a_stretched_domain_is_never_taken_away(): void
    {
        $this->vlanOn(704);
        $this->runImport();

        $vlan = Vlan::query()->where('vlan_id', 704)->first();

        $other = Vlan::query()->create(['vlan_id' => 704, 'site_id' => 5]);
        $vlan->update(['vlan_group_id' => DB::table('ipam_vlan_groups')->insertGetId([
            'name' => 'vlan 704', 'created_at' => now(), 'updated_at' => now(),
        ])]);
        $other->update(['vlan_group_id' => $vlan->vlan_group_id]);

        DB::table('vlans')->where('vlan_vlan', 704)->delete();

        $result = $this->runImport();

        $this->assertTrue(Vlan::query()->whereKey($vlan->vlan_record_id)->exists());
        $this->assertTrue($vlan->fresh()->auto_created, 'and the import still leaves it alone');
        $this->assertSame(0, $result->vlansRemoved);
    }
}
