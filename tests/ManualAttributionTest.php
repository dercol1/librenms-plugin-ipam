<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanLocation;
use Dercol1\LibrenmsIpam\Web\AddressesController;
use Dercol1\LibrenmsIpam\Web\NetworksController;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saying where something is, by hand.
 *
 * Everything the plugin builds is read off a poll, and a poll only ever answers
 * where a device is: a sysLocation string, at best. Everything finer than that
 * — which cabinet a segment is homed in, which room a vlan is really going to
 * be in before anything is plugged into it — has to be written down, and it has
 * to stick.
 *
 * So all three of the things that carry a place (vlans, networks, addresses)
 * accept one, and what makes it stick is the same rule in every case: a pinned
 * row is not re-derived. Without that the next import would undo the correction
 * a minute after it was made, which is the same lesson the classification
 * columns already taught.
 */
class ManualAttributionTest extends UnitTestCase
{
    private Site $site;

    private Location $room;

    protected function setUp(): void
    {
        parent::setUp();

        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        Gate::define('ipam.view', fn ($u, mixed $target = null): bool => true);
        Gate::define('ipam.manage', fn ($u, mixed $target = null): bool => true);

        // the device visibility the pages apply: a permissive policy, so the
        // bulk test is about the reclassification and not about permissions
        Gate::policy(Device::class, AlwaysAllowsPolicy::class);
        Gate::policy(Port::class, AlwaysAllowsPolicy::class);

        $this->site = Site::query()->create(['name' => 'Galazzano']);
        $this->room = Location::query()->create(['name' => 'Armadio A13', 'site_id' => $this->site->site_id]);
    }

    private function request(string $uri = '/'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }

    // --------------------------------------------------------------- networks

    public function test_a_range_can_be_attributed_to_a_room_by_hand(): void
    {
        $block = Network::query()->create(['cidr' => '10.40.0.0/16', 'auto_created' => true]);

        $request = $this->request('/plugin/ipam/networks');
        $request->merge([
            'network_id' => (string) $block->network_id,
            'site_id' => (string) $this->site->site_id,
            'location_id' => (string) $this->room->location_id,
            'net_owner_id' => '0',
            'net_function_id' => '0',
            'vlan_id' => '',
            'description' => '',
        ]);

        (new NetworksController)->save($request);

        $fresh = $block->fresh();

        $this->assertSame($this->room->location_id, $fresh->location_id);
        $this->assertSame($this->site->site_id, $fresh->site_id);
    }

    public function test_a_range_added_by_hand_carries_its_room(): void
    {
        $request = $this->request('/plugin/ipam/networks/ladder');
        $request->merge([
            'cidr' => '10.41.146.0/24',
            'site_id' => (string) $this->site->site_id,
            'location_id' => (string) $this->room->location_id,
            'net_owner_id' => '0',
            'net_function_id' => '0',
            'vlan_id' => '',
            'description' => '',
        ]);

        (new NetworksController)->createWithParents($request);

        $block = Network::query()->where('cidr', '10.41.146.0/24')->first();

        $this->assertNotNull($block);
        $this->assertSame($this->room->location_id, $block->location_id, 'the room typed on the range stays on that range');
        $this->assertSame(0, (int) Network::query()->where('cidr', '10.41.0.0/16')->value('location_id'), 'the wider ones are not touched');
    }

    public function test_a_room_that_does_not_exist_is_refused(): void
    {
        $block = Network::query()->create(['cidr' => '10.42.0.0/16']);

        $request = $this->request('/plugin/ipam/networks');
        $request->merge([
            'network_id' => (string) $block->network_id,
            'site_id' => '0',
            'location_id' => '424242',
            'net_owner_id' => '0',
            'net_function_id' => '0',
            'vlan_id' => '',
            'description' => '',
        ]);

        $this->expectException(ValidationException::class);

        (new NetworksController)->save($request);
    }

    // -------------------------------------------------------------- addresses

    public function test_an_address_can_be_attributed_to_a_room_and_pinned_there(): void
    {
        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => '10.43.7.9', 'ipv4_prefixlen' => 24, 'port_id' => 1, 'context_name' => '',
        ]);

        (new AddressImporter)->import(new ImportResult);

        $address = Address::query()->where('address', '10.43.7.9')->firstOrFail();

        $request = $this->request('/plugin/ipam/addresses');
        $request->merge([
            'site_id' => (string) $this->site->site_id,
            'location_id' => (string) $this->room->location_id,
            'vlan_record_id' => '0',
            'net_owner_id' => '0',
            'net_function_id' => '0',
            'description' => '',
            'lock' => '1',
        ]);

        (new AddressesController)->update($request, (int) $address->address_record_id);

        // the next import refreshes what it sees and leaves a pinned room alone
        (new AddressImporter)->import(new ImportResult);

        $fresh = $address->fresh();

        $this->assertTrue($fresh->scope_locked);
        $this->assertSame($this->room->location_id, $fresh->location_id, 'the room the operator wrote is still there after a poll');
    }

    public function test_a_bulk_reclassification_can_set_the_room(): void
    {
        $first = Address::query()->create([
            'family' => 4, 'address' => '10.44.1.1', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 1, 'device_id' => 1, 'network_id' => 0,
        ]);

        $request = $this->request('/plugin/ipam/addresses/bulk');
        $request->merge([
            'ids' => [(string) $first->address_record_id],
            'location_id' => (string) $this->room->location_id,
            'site_id' => '',
            'vlan_record_id' => '',
            'net_owner_id' => '',
            'net_function_id' => '',
        ]);

        (new AddressesController)->bulk($request);

        $this->assertSame($this->room->location_id, (int) $first->fresh()->location_id);
    }

    // ------------------------------------------------------------------ vlans

    public function test_a_vlan_can_be_given_a_room_by_hand(): void
    {
        $vlan = Vlan::query()->create(['vlan_id' => 900, 'site_id' => $this->site->site_id, 'auto_created' => true]);

        $request = $this->request('/plugin/ipam/vlans/room');
        $request->merge(['location_id' => (string) $this->room->location_id]);

        (new VlansController)->room($request, (int) $vlan->vlan_record_id);

        $this->assertSame(1, VlanLocation::query()->where('vlan_record_id', $vlan->vlan_record_id)
            ->where('location_id', $this->room->location_id)->count());
    }

    public function test_a_vlan_added_by_hand_can_name_its_room(): void
    {
        $request = $this->request('/plugin/ipam/vlans');
        $request->merge([
            'vlan_id' => '950',
            'vlan_domain' => '1',
            'site_id' => (string) $this->site->site_id,
            'location_id' => (string) $this->room->location_id,
            'vlan_owner_id' => '0',
            'name' => '',
            'description' => '',
        ]);

        (new VlansController)->store($request);

        $vlan = Vlan::query()->where('vlan_id', 950)->first();

        $this->assertNotNull($vlan);
        $this->assertSame(1, VlanLocation::query()->where('vlan_record_id', $vlan->vlan_record_id)
            ->where('location_id', $this->room->location_id)->count());
    }

    public function test_a_room_can_be_taken_back_from_a_vlan(): void
    {
        $vlan = Vlan::query()->create(['vlan_id' => 901, 'site_id' => $this->site->site_id]);
        VlanLocation::query()->create(['vlan_record_id' => $vlan->vlan_record_id, 'location_id' => $this->room->location_id]);

        $request = $this->request('/plugin/ipam/vlans/room');
        $request->merge(['location_id' => (string) $this->room->location_id, 'remove' => '1']);

        (new VlansController)->room($request, (int) $vlan->vlan_record_id);

        $this->assertSame(0, VlanLocation::query()->where('vlan_record_id', $vlan->vlan_record_id)->count());
    }

    public function test_adding_the_same_room_twice_stays_one_row(): void
    {
        $vlan = Vlan::query()->create(['vlan_id' => 902, 'site_id' => $this->site->site_id]);

        foreach (range(1, 2) as $ignored) {
            $request = $this->request('/plugin/ipam/vlans/room');
            $request->merge(['location_id' => (string) $this->room->location_id]);

            (new VlansController)->room($request, (int) $vlan->vlan_record_id);
        }

        $this->assertSame(1, VlanLocation::query()->where('vlan_record_id', $vlan->vlan_record_id)->count());
    }
}
