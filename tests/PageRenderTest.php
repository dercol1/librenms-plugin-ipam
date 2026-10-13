<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanOwner;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Web\AddressesController;
use Dercol1\LibrenmsIpam\Web\DuplicatesController;
use Dercol1\LibrenmsIpam\Web\NetworksController;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The four pages, rendered for real with real rows behind them.
 *
 * The empty case is covered in PluginSurfaceTest; this one puts a site, a block,
 * a vlan and a couple of addresses in place first, because that is when a view
 * reaches for a relation the fixture did not provide and only then finds out.
 */
class PageRenderTest extends UnitTestCase
{
    private Site $milano;

    private NetworkOwner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->milano = Site::query()->create(['name' => 'Milano']);
        $this->owner = NetworkOwner::query()->create(['name' => 'Network Operations']);

        $block = Network::query()->create([
            'cidr' => '10.0.0.0/8',
            'site_id' => $this->milano->site_id,
            'net_owner_id' => $this->owner->net_owner_id,
        ]);

        Network::query()->create([
            'cidr' => '10.14.0.0/16',
            'parent_id' => $block->network_id,
        ]);

        Vlan::query()->create([
            'vlan_id' => 300,
            'site_id' => $this->milano->site_id,
            'vlan_owner_id' => VlanOwner::query()->create(['name' => 'Network Operations'])->vlan_owner_id,
            'name' => 'FROM-DEVICE',
            'description' => 'the storage vlan',
        ]);

        $this->user();
    }

    /**
     * A signed in user, with every gate the pages consult allowed.
     */
    protected function user(): User
    {
        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        // The second argument is the ability or the model, whichever the caller
        // passed. Spelling it as variadic keeps a bare allows('viewAll') from
        // blowing up on an argument count.
        Gate::define(IpamPermissions::VIEW, fn (User $u, mixed $target = null): bool => true);
        Gate::define(IpamPermissions::MANAGE, fn (User $u, mixed $target = null): bool => true);
        // DevicePolicy is a real class in this install and the Gate finds it by
        // convention, ahead of any ability defined here, so a Gate::define for
        // viewAll would be ignored. Binding a permissive policy is what actually
        // answers the question the pages ask.
        Gate::policy(Device::class, AlwaysAllowsPolicy::class);
        Gate::policy(Port::class, AlwaysAllowsPolicy::class);

        return $user;
    }

    protected function request(string $uri = '/'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }

    public function test_the_network_map_renders_with_blocks_and_a_tree(): void
    {
        $view = (new NetworksController)->index($this->request('/plugin/ipam/networks'));

        $html = $view->render();

        $this->assertStringContainsString('10.0.0.0/8', $html);
        $this->assertStringContainsString('10.14.0.0/16', $html, 'the child block is listed');
        $this->assertStringContainsString('Milano', $html);
        $this->assertStringContainsString('Network Operations', $html);
    }

    public function test_the_vlan_page_renders_with_its_description(): void
    {
        $view = (new VlansController)->index($this->request('/plugin/ipam/vlans'));

        $html = $view->render();

        $this->assertStringContainsString('300', $html);
        $this->assertStringContainsString('the storage vlan', $html);
    }

    public function test_the_address_page_renders_the_classified_addresses(): void
    {
        $this->seedAddress('10.14.146.7');
        $this->seedAddress('10.25.0.1');

        $html = (new AddressesController)->index($this->request('/plugin/ipam/addresses'))->render();

        $this->assertStringContainsString('10.14.146.7', $html);
        $this->assertStringContainsString('10.25.0.1', $html);
        $this->assertStringContainsString('10.14.146.0/24', $html, 'the block is shown');
    }

    public function test_the_address_search_accepts_a_whole_network(): void
    {
        // this is the cidr branch of the search, which uses a binary range
        // comparison rather than a string match
        $this->seedAddress('10.14.146.7');
        $this->seedAddress('10.14.147.7');

        $view = (new AddressesController)->index($this->request('/plugin/ipam/addresses?q=10.14.146.0/24'));
        $html = $view->render();

        $this->assertStringContainsString('10.14.146.7', $html);
        $this->assertStringNotContainsString('10.14.147.7', $html);
    }

    public function test_the_address_order_is_numeric_not_lexical(): void
    {
        // 10.14.9.1 has to come before 10.14.10.1, a string sort gets it backwards
        $this->seedAddress('10.14.10.1');
        $this->seedAddress('10.14.9.1');

        $html = (new AddressesController)->index($this->request('/plugin/ipam/addresses'))->render();

        $this->assertLessThan(
            strpos($html, '10.14.10.1'),
            strpos($html, '10.14.9.1'),
            '9.1 should sort before 10.1'
        );
    }

    public function test_the_collision_page_renders_a_found_duplicate(): void
    {
        $vlan = Vlan::query()->where('vlan_id', 300)->first();

        foreach ([1, 2] as $portId) {
            $this->seedAddress('10.14.146.7', $vlan->vlan_record_id, $portId);
        }

        $html = (new DuplicatesController)->index($this->request('/plugin/ipam/duplicates'))->render();

        $this->assertStringContainsString('10.14.146.7', $html);
        // the label, which is the description when one was written
        $this->assertStringContainsString('the storage vlan', $html);
        $this->assertStringContainsString('Milano', $html);
    }

    public function test_the_collision_page_says_so_when_there_is_nothing(): void
    {
        $this->seedAddress('10.14.146.7');

        $html = (new DuplicatesController)->index($this->request('/plugin/ipam/duplicates'))->render();

        $this->assertStringContainsString('No address is configured twice', $html);
    }

    public function test_a_page_is_refused_without_the_permission(): void
    {
        Gate::define(IpamPermissions::VIEW, fn (User $u, mixed $target = null): bool => false);
        Gate::define(IpamPermissions::MANAGE, fn (User $u, mixed $target = null): bool => false);

        $this->expectException(HttpException::class);

        (new NetworksController)->index($this->request());
    }

    public function test_a_write_is_refused_without_the_manage_permission(): void
    {
        Gate::define(IpamPermissions::VIEW, fn (User $u, mixed $target = null): bool => true);
        Gate::define(IpamPermissions::MANAGE, fn (User $u, mixed $target = null): bool => false);

        $this->expectException(HttpException::class);

        (new NetworksController)->save($this->request());
    }

    /**
     * @return array<string, mixed>
     */
    protected function addressAttributes(string $address, int $vlanRecordId = 0, int $portId = 1): array
    {
        return [
            'family' => 4,
            'address' => $address,
            'prefixlen' => 24,
            'context_name' => '',
            'port_id' => $portId,
            'device_id' => 1,
            'vlan_record_id' => $vlanRecordId,
            'site_id' => $this->milano->site_id,
        ];
    }

    protected function seedAddress(string $address, int $vlanRecordId = 0, int $portId = 1): void
    {
        $record = new Address(
            $this->addressAttributes($address, $vlanRecordId, $portId)
        );

        $record->save();
    }
}
