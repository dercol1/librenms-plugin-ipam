<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Import\VlanImporter;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Dercol1\LibrenmsIpam\Sync\Alignment;
use Dercol1\LibrenmsIpam\Web\AddressesController;
use Dercol1\LibrenmsIpam\Web\FreeAddressFinder;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The tools around the data: where a free address is, and whether the copy
 * still matches what LibreNMS polls.
 *
 * Both answer questions an operator asks before touching anything: "can I use
 * this address" and "am I looking at the network as it is". Both are read-only,
 * on purpose — the finder suggests, the alignment reports, and neither one
 * quietly writes behind the operator's back.
 */
class FreeAndSyncTest extends UnitTestCase
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

    /**
     * An address row, straight in the ipam table.
     *
     * The overrides go through setAttribute rather than through create(): the
     * base is a literal the type checker can read, and the overrides are
     * per-test facts, applied by name, which setAttribute is exactly for.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function address(string $ip, array $overrides = []): Address
    {
        $cidr = Cidr::address($ip);

        $record = Address::query()->create([
            'family' => $cidr->getFamily(),
            'address' => $cidr->uncompressed(),
            'prefixlen' => Cidr::maxPrefixLen($cidr->getFamily()),
            'port_id' => 1,
            'device_id' => 1,
            'context_name' => '',
        ]);

        foreach ($overrides as $key => $value) {
            $record->setAttribute($key, $value);
        }

        $record->save();

        return $record->refresh();
    }

    /**
     * The gaps of a /24 with two used addresses.
     */
    public function test_the_finder_reports_the_gaps_of_a_range(): void
    {
        $this->address('10.14.146.1');
        $this->address('10.14.146.5');

        $result = (new FreeAddressFinder)->find(Cidr::parse('10.14.146.0/24'));

        $this->assertSame(2, $result['used_count']);

        // 10.14.146.2 to .4, then .6 to .254: 252 free of the 254 usable
        $this->assertSame(252, $result['free_count']);

        $this->assertSame([
            ['start' => '10.14.146.2', 'end' => '10.14.146.4', 'count' => 3],
            ['start' => '10.14.146.6', 'end' => '10.14.146.254', 'count' => 249],
        ], $result['gaps']);

        // the first addresses of the first gaps, in order, ready to hand out
        $this->assertSame([
            '10.14.146.2', '10.14.146.3', '10.14.146.4', '10.14.146.6', '10.14.146.7',
        ], array_slice($result['suggestions'], 0, 5));
    }

    public function test_an_empty_range_is_free_from_the_second_address(): void
    {
        $result = (new FreeAddressFinder)->find(Cidr::parse('192.168.50.0/24'));

        $this->assertSame(0, $result['used_count']);
        $this->assertSame(254, $result['free_count']);
        $this->assertSame('192.168.50.1', $result['suggestions'][0], 'the network address is not handed out');
    }

    public function test_the_finder_does_not_double_count_when_the_broadcast_is_used(): void
    {
        // some devices report the broadcast as configured; the last gap must
        // appear exactly once, not once per path through the loop
        $this->address('10.0.0.1');
        $this->address('10.0.0.255');

        $result = (new FreeAddressFinder)->find(Cidr::parse('10.0.0.0/24'));

        $this->assertSame(253, $result['free_count']);
        $this->assertCount(1, $result['gaps']);
        $this->assertSame('10.0.0.254', $result['gaps'][0]['end']);
    }

    public function test_hidden_addresses_count_as_used(): void
    {
        // loopback on a device in the range: invisible in the list, but handing
        // it out as free would hand out a live address
        $this->address('10.1.1.1', ['ignored' => true]);

        $result = (new FreeAddressFinder)->find(Cidr::parse('10.1.1.0/24'));

        $this->assertSame(1, $result['used_count']);
        $this->assertSame('10.1.1.2', $result['suggestions'][0], 'the loopback is not suggested');
    }

    public function test_the_finder_works_on_ipv6(): void
    {
        $this->address('2001:db8:1::1');

        $result = (new FreeAddressFinder)->find(Cidr::parse('2001:db8:1::/64'));

        // a /64 counts every address as usable, no network/broadcast to skip
        $this->assertSame(1, $result['used_count']);
        $this->assertSame('2001:db8:1::2', $result['suggestions'][0]);
    }

    /**
     * Alignment: does the copy say what LibreNMS polls?
     */
    public function test_a_fresh_import_is_in_sync(): void
    {
        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => '10.10.10.1', 'ipv4_prefixlen' => 32, 'port_id' => 1, 'context_name' => '',
        ]);
        DB::table('vlans')->insert([
            'device_id' => 1, 'vlan_vlan' => 300, 'vlan_domain' => 1, 'vlan_name' => 'users',
        ]);

        (new AddressImporter(importIpv6: false))->import(new ImportResult);
        (new VlanImporter)->import(new ImportResult);

        $report = Alignment::check(Settings::fromArray([]));

        $this->assertTrue($report['in_sync']);
        $this->assertSame(1, $report['addresses_in_librenms']);
        $this->assertSame(1, $report['addresses_in_ipam']);
    }

    public function test_an_address_polled_after_the_last_import_is_reported(): void
    {
        (new AddressImporter(importIpv6: false))->import(new ImportResult);

        // the poller brings something new
        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => '10.10.10.99', 'ipv4_prefixlen' => 32, 'port_id' => 1, 'context_name' => '',
        ]);

        $report = Alignment::check(Settings::fromArray([]));

        $this->assertFalse($report['in_sync']);
        $this->assertSame(1, $report['addresses_not_imported']);
    }

    public function test_an_address_removed_from_librenms_is_reported(): void
    {
        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => '10.10.10.1', 'ipv4_prefixlen' => 32, 'port_id' => 1, 'context_name' => '',
        ]);

        (new AddressImporter(importIpv6: false))->import(new ImportResult);

        // the device goes away
        DB::table('ipv4_addresses')->where('ipv4_address', '10.10.10.1')->delete();

        $report = Alignment::check(Settings::fromArray([]));

        $this->assertFalse($report['in_sync']);
        $this->assertSame(1, $report['addresses_gone']);
    }

    public function test_an_address_added_by_hand_is_not_expected_in_librenms(): void
    {
        (new AddressImporter(importIpv6: false))->import(new ImportResult);

        // an operator documents a planned address; it is not in LibreNMS and
        // never will be until it goes live
        $this->address('10.10.10.50', ['port_id' => 0, 'device_id' => 0, 'scope_locked' => true]);

        $report = Alignment::check(Settings::fromArray([]));

        $this->assertTrue($report['in_sync'], 'a documented plan is not drift');
        $this->assertSame(0, $report['addresses_gone']);
    }

    /**
     * Adding objects by hand.
     */
    public function test_an_address_added_by_hand_is_pinned_against_the_next_import(): void
    {
        $this->user();

        $request = $this->request('/plugin/ipam/addresses');
        $request->merge(['address' => '10.99.0.1', 'description' => 'the new firewall']);

        (new AddressesController)->store($request);

        $record = Address::query()->where('address', '10.99.0.1')->first();

        $this->assertNotNull($record);
        $this->assertTrue($record->scope_locked, 'an import removes what it cannot see in LibreNMS, so a planned row is pinned');

        // an import runs: the row must survive
        (new AddressImporter(importIpv6: false))->import(new ImportResult);

        $this->assertNotNull(Address::query()->where('address', '10.99.0.1')->first());
    }

    public function test_an_address_added_by_hand_inherits_the_block_values(): void
    {
        $this->user();

        $site = Site::query()->create(['name' => 'Milano']);
        $eight = Network::query()->create(['cidr' => '10.0.0.0/8', 'site_id' => $site->site_id]);

        $request = $this->request('/plugin/ipam/addresses');
        $request->merge(['address' => '10.14.99.99']);

        (new AddressesController)->store($request);

        $record = Address::query()->where('address', '10.14.99.99')->first();

        $this->assertSame($site->site_id, $record->site_id, 'the site came from the /8 above');
        $this->assertSame($eight->network_id, $record->network_id);
    }

    public function test_adding_the_same_address_twice_is_refused(): void
    {
        $this->user();

        $request = $this->request('/plugin/ipam/addresses');
        $request->merge(['address' => '10.99.0.1']);

        (new AddressesController)->store($request);
        $response = (new AddressesController)->store($request);

        $this->assertSame(1, Address::query()->where('address', '10.99.0.1')->count());
        $this->assertTrue($response->isRedirect(), 'the second add is refused and sent back');
    }

    public function test_a_vlan_added_by_hand_keeps_its_description_through_import(): void
    {
        $this->user();

        $site = Site::query()->create(['name' => 'Milano']);

        $request = $this->request('/plugin/ipam/vlans');
        $request->merge([
            'vlan_id' => 400,
            'vlan_domain' => 1,
            'site_id' => $site->site_id,
            'name' => 'documented',
            'description' => 'written by an operator',
        ]);

        (new VlansController)->store($request);

        // LibreNMS starts reporting the same vlan, with a device name of its own
        DB::table('vlans')->insert([
            'device_id' => 1, 'vlan_vlan' => 400, 'vlan_domain' => 1, 'vlan_name' => 'FROM-DEVICE',
        ]);

        (new VlanImporter)->import(new ImportResult);

        $vlan = Vlan::query()->where('vlan_id', 400)->where('site_id', $site->site_id)->first();

        $this->assertSame('documented', $vlan->name, 'the import seeds names on rows it created, not on rows it found');
        $this->assertSame('written by an operator', $vlan->description);
    }
}
