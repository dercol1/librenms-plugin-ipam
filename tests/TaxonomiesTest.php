<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkFunction;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanOwner;
use Dercol1\LibrenmsIpam\Web\TaxonomiesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The pick lists the classifications choose from.
 *
 * Until this page existed the lists were write-only: the dropdowns on the
 * other pages offered whatever happened to be in the tables, and on a real
 * install all three tables were empty, so three columns of the classification
 * had values nobody could set. The tests here cover the two behaviours that
 * would quietly rot: an entry disappearing while things still point at it,
 * and the empty-list state on the pages that consume it.
 */
class TaxonomiesTest extends UnitTestCase
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

    protected function request(string $uri = '/'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }

    public function test_the_page_lists_the_three_lists_and_their_usage(): void
    {
        $this->user();

        $owner = NetworkOwner::query()->create(['name' => 'Network team']);
        Network::query()->create(['cidr' => '10.0.0.0/8', 'net_owner_id' => $owner->net_owner_id]);

        $html = (new TaxonomiesController)->index($this->request('/plugin/ipam/taxonomies'))->render();

        $this->assertStringContainsString('Network owners', $html);
        $this->assertStringContainsString('Network functions', $html);
        $this->assertStringContainsString('Vlan owners', $html);

        // the usage says where an entry is used, so the count is visible before
        // anyone tries to delete it
        $this->assertStringContainsString('Network team', $html);
        $this->assertStringContainsString('1 blocks', $html, 'the count of blocks using the entry');
    }

    public function test_an_owner_can_be_added_and_then_renamed(): void
    {
        $this->user();

        $request = $this->request('/plugin/ipam/taxonomies/net-owner');
        $request->merge(['name' => 'Server team', 'description' => 'the hypervisor people']);

        (new TaxonomiesController)->store($request, 'net-owner');

        $owner = NetworkOwner::query()->where('name', 'Server team')->first();
        $this->assertNotNull($owner);
        $this->assertSame('the hypervisor people', $owner->description);

        $rename = $this->request('/plugin/ipam/taxonomies/net-owner/' . $owner->net_owner_id);
        $rename->merge(['name' => 'Platform team', 'description' => 'renamed']);

        (new TaxonomiesController)->update($rename, 'net-owner', (int) $owner->net_owner_id);

        $this->assertSame(0, NetworkOwner::query()->where('name', 'Server team')->count());
        $this->assertSame('renamed', $owner->fresh()->description);
    }

    public function test_adding_the_same_name_twice_does_not_duplicate_the_entry(): void
    {
        $this->user();

        $request = $this->request('/plugin/ipam/taxonomies/net-function');
        $request->merge(['name' => 'Management']);

        (new TaxonomiesController)->store($request, 'net-function');
        (new TaxonomiesController)->store($request, 'net-function');

        $this->assertSame(1, NetworkFunction::query()->where('name', 'Management')->count());
    }

    public function test_an_entry_nobody_points_at_can_be_deleted(): void
    {
        $this->user();

        $owner = NetworkOwner::query()->create(['name' => 'Unused']);

        (new TaxonomiesController)
            ->destroy($this->request('/plugin/ipam/taxonomies/net-owner/' . $owner->net_owner_id), 'net-owner', (int) $owner->net_owner_id);

        $this->assertSame(0, NetworkOwner::query()->where('name', 'Unused')->count());
    }

    public function test_an_entry_in_use_is_refused_and_says_where(): void
    {
        // references to these tables are plain columns, not foreign keys, so
        // nothing in the engine would stop a delete from leaving a block
        // silently unowned. The refusal has to come from counting.
        $this->user();

        $owner = NetworkOwner::query()->create(['name' => 'In use']);
        Network::query()->create(['cidr' => '10.0.0.0/8', 'net_owner_id' => $owner->net_owner_id]);
        Address::query()->create([
            'family' => 4, 'address' => '10.0.0.1', 'prefixlen' => 24,
            'port_id' => 1, 'device_id' => 1, 'context_name' => '',
            'net_owner_id' => $owner->net_owner_id,
        ]);

        $response = (new TaxonomiesController)
            ->destroy($this->request('/plugin/ipam/taxonomies/net-owner/' . $owner->net_owner_id), 'net-owner', (int) $owner->net_owner_id);

        $this->assertNotNull(NetworkOwner::query()->find($owner->net_owner_id), 'the entry survives');
        $this->assertStringContainsString(
            'In use is still in use (1 blocks + 1 addresses)',
            (string) $response->getSession()->get('error'),
        );
    }

    public function test_a_vlan_owner_in_use_is_refused_too(): void
    {
        $this->user();

        $owner = VlanOwner::query()->create(['name' => 'Wireless']);
        Vlan::query()->create(['vlan_id' => 100, 'site_id' => 0, 'vlan_owner_id' => $owner->vlan_owner_id]);

        $response = (new TaxonomiesController)
            ->destroy($this->request('/plugin/ipam/taxonomies/vlan-owner/' . $owner->vlan_owner_id), 'vlan-owner', (int) $owner->vlan_owner_id);

        $this->assertNotNull(VlanOwner::query()->find($owner->vlan_owner_id));
        $this->assertStringContainsString(
            '1 vlans',
            (string) $response->getSession()->get('error'),
        );
    }

    public function test_an_unknown_list_is_refused_rather_than_crashing(): void
    {
        $this->user();

        $request = $this->request('/plugin/ipam/taxonomies/nope');
        $request->merge(['name' => 'x']);

        $response = (new TaxonomiesController)->store($request, 'nope');

        $this->assertSame(0, NetworkOwner::query()->count(), 'nothing was written anywhere');
        $this->assertSame('That is not one of the lists.', $response->getSession()->get('error'));
    }
}
