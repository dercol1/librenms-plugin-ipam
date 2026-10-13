<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Illuminate\Support\Facades\DB;

/**
 * Hiding the addresses that carry no information.
 *
 * Loopback exists on every device, link-local on every interface, and
 * 127.0.0.1 seen on three of them reads as a three-way collision. Those rows
 * are imported but hidden: kept, queryable with the "show hidden" toggle, and
 * out of every count and every report by default.
 *
 * The load-bearing rule is that hidden is set when a row is created and never
 * re-derived afterwards. Without that, un-hiding one loopback row would be
 * undone by the next poll, and the operator's choice would not be a choice.
 */
class HiddenObjectsTest extends UnitTestCase
{
    /**
     * An importer with the default exclusions, which is what the real install
     * runs with: loopback, link-local, multicast, the unspecified address.
     */
    private function importer(): AddressImporter
    {
        return new AddressImporter(
            importIpv6: true,
            excludeRanges: array_map(
                fn (string $cidr): Cidr => Cidr::parse($cidr),
                ['127.0.0.0/8', '0.0.0.0/32', '169.254.0.0/16', '224.0.0.0/4', '::1/128', '::/128', 'fe80::/10', 'ff00::/8']
            ),
        );
    }

    private function ipv4(string $ip, int $portId, int $prefixLen = 32): void
    {
        DB::table('ipv4_addresses')->insert([
            'ipv4_address' => $ip,
            'ipv4_prefixlen' => $prefixLen,
            'port_id' => $portId,
            'context_name' => '',
        ]);
    }

    private function ipv6(string $ip, int $portId, int $prefixLen = 64): void
    {
        DB::table('ipv6_addresses')->insert([
            'ipv6_address' => $ip,
            'ipv6_prefixlen' => $prefixLen,
            'port_id' => $portId,
            'context_name' => '',
        ]);
    }

    public function test_loopback_is_imported_but_hidden(): void
    {
        $this->ipv4('10.14.146.7', 1);
        $this->ipv4('127.0.0.1', 1);

        $result = new ImportResult;
        $this->importer()->import($result);

        $this->assertFalse(Address::query()->where('address', '10.14.146.7')->value('ignored'));
        $this->assertTrue(Address::query()->where('address', '127.0.0.1')->value('ignored'));
        $this->assertSame(1, $result->ignored, 'the count says how much of the import was noise');
    }

    public function test_a_block_entirely_inside_an_excluded_range_starts_hidden(): void
    {
        $this->ipv4('127.0.0.1', 1);

        $this->importer()->import(new ImportResult);

        $this->assertTrue(Network::query()->where('cidr', '127.0.0.0/8')->value('ignored'));

        // a real block in the same import stays visible
        $this->ipv4('10.0.0.1', 1);
        $this->importer()->import(new ImportResult);

        $this->assertFalse(Network::query()->where('cidr', '10.0.0.0/8')->value('ignored'));
    }

    public function test_an_address_under_a_hidden_block_is_hidden_at_import(): void
    {
        // an operator hid the block, or it was imported hidden; either way a
        // new address arriving under it must not show up in the list
        $this->ipv4('172.16.5.9', 1);

        $this->importer()->import(new ImportResult);

        $block = Network::query()->where('cidr', '172.16.0.0/16')->first();
        $block->ignored = true;
        $block->save();

        // a second switch reports an address in the same range
        DB::table('ports')->insert(['device_id' => 1, 'ifName' => 'Gig0/2']);
        $this->ipv4('172.16.5.10', 2);

        $this->importer()->import(new ImportResult);

        $this->assertTrue(Address::query()->where('address', '172.16.5.10')->value('ignored'));
        // the address that arrived before the block was hidden is untouched:
        // hiding a block cascades eagerly from the web action, not from import
        $this->assertFalse(Address::query()->where('address', '172.16.5.9')->value('ignored'));
    }

    public function test_unhiding_survives_the_next_import(): void
    {
        $this->ipv4('127.0.0.1', 1);

        $this->importer()->import(new ImportResult);

        Address::query()->where('address', '127.0.0.1')->update(['ignored' => false]);

        $this->importer()->import(new ImportResult);

        $this->assertFalse(
            Address::query()->where('address', '127.0.0.1')->value('ignored'),
            'a refresh must not re-ignore what an operator chose to show'
        );
    }

    public function test_hiding_a_block_hides_the_subtree_and_its_addresses(): void
    {
        $this->ipv4('10.1.2.3', 1);
        $this->ipv4('10.9.9.9', 1);

        $this->importer()->import(new ImportResult);

        $sixteen = Network::query()->where('cidr', '10.1.0.0/16')->first();
        $twentyfour = Network::query()->where('cidr', '10.1.2.0/24')->first();

        $sixteen->ignoreSubtree();

        $this->assertTrue($twentyfour->fresh()->ignored, 'the block below went quiet');
        $this->assertTrue(Address::query()->where('address', '10.1.2.3')->value('ignored'));
        $this->assertFalse(Address::query()->where('address', '10.9.9.9')->value('ignored'), 'outside the subtree, untouched');

        $sixteen->unignoreSubtree();

        $this->assertFalse($twentyfour->fresh()->ignored);
        $this->assertFalse(Address::query()->where('address', '10.1.2.3')->value('ignored'));
    }

    public function test_a_hidden_address_is_not_half_of_a_collision(): void
    {
        $site = Site::query()->create(['name' => 'Milano']);
        $vlan = Vlan::query()->create(['vlan_id' => 300, 'site_id' => $site->site_id]);

        // the same address twice on one device, inside one scope
        $this->ipv4('10.0.0.5', 1);
        DB::table('ports')->insert(['device_id' => 1, 'ifName' => 'Gig0/2']);
        $this->ipv4('10.0.0.5', 2);

        foreach ([1, 2] as $portId) {
            Address::query()->create([
                'family' => 4,
                'address' => '10.0.0.5',
                'prefixlen' => 32,
                'port_id' => $portId,
                'device_id' => 1,
                'context_name' => '',
                'site_id' => $site->site_id,
                'vlan_record_id' => $vlan->vlan_record_id,
            ]);
        }

        $detector = new DuplicateDetector;
        $this->assertCount(1, $detector->detect(), 'two occurrences in one scope are a collision');

        Address::query()->where('port_id', 2)->update(['ignored' => true]);

        $this->assertCount(0, (new DuplicateDetector)->detect(), 'hide one and the conflict is gone');
    }

    public function test_hidden_addresses_stay_out_of_the_unclassified_queue(): void
    {
        Address::query()->create([
            'family' => 4,
            'address' => '127.0.0.1',
            'prefixlen' => 32,
            'port_id' => 1,
            'device_id' => 1,
            'context_name' => '',
            'ignored' => true,
        ]);

        Address::query()->create([
            'family' => 4,
            'address' => '10.5.5.5',
            'prefixlen' => 32,
            'port_id' => 1,
            'device_id' => 1,
            'context_name' => '',
            'ignored' => false,
        ]);

        $detector = new DuplicateDetector;

        $this->assertSame(1, $detector->unclassifiedCount(), 'the loopback is not work waiting to be done');
        $this->assertSame(['10.5.5.5'], array_column($detector->unclassified(), 'address'));
    }

    public function test_link_local_and_multicast_v6_are_hidden_too(): void
    {
        $this->ipv6('fe80::a55:31ff:febd:2905', 1);
        $this->ipv6('2001:db8::1', 1);

        $this->importer()->import(new ImportResult);

        $this->assertTrue(Address::query()->where('address', 'fe80:0000:0000:0000:0a55:31ff:febd:2905')->value('ignored'));
        $this->assertFalse(Address::query()->where('address', '2001:0db8:0000:0000:0000:0000:0000:0001')->value('ignored'));
    }
}
