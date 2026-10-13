<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Illuminate\Support\Facades\DB;

/**
 * The block tree: the range columns, and inheritance up the tree.
 *
 * Inheritance is what makes a wide segment describable, which is the reason the
 * plugin keeps a tree at all instead of a flat list of ranges.
 */
class NetworkInheritanceTest extends UnitTestCase
{
    public function test_creating_a_block_derives_its_range_columns(): void
    {
        $block = Network::query()->create(['cidr' => '10.14.146.0/24']);

        $this->assertSame(4, $block->family);
        $this->assertSame(24, $block->prefixlen);
        $this->assertSame('10.14.146.0', $block->network);
        $this->assertSame('10.14.146.0', inet_ntop($block->packed_start));
        $this->assertSame('10.14.146.255', inet_ntop($block->packed_end));
    }

    public function test_editing_a_cidr_rewrites_the_range_columns(): void
    {
        // otherwise containment would keep answering for the old range
        $block = Network::query()->create(['cidr' => '10.14.146.0/24']);
        $block->cidr = '192.168.5.0/25';
        $block->save();

        $this->assertSame('192.168.5.0', $block->network);
        $this->assertSame(25, $block->prefixlen);
        $this->assertSame('192.168.5.127', inet_ntop($block->packed_end));
    }

    public function test_an_ipv6_block_is_derived_too(): void
    {
        $block = Network::query()->create(['cidr' => '2001:db8::/48']);

        $this->assertSame(6, $block->family);
        $this->assertSame(48, $block->prefixlen);
        $this->assertSame('2001:db8:0:ffff:ffff:ffff:ffff:ffff', inet_ntop($block->packed_end));
    }

    public function test_the_most_specific_block_containing_an_address_wins(): void
    {
        Network::query()->create(['cidr' => '10.0.0.0/8']);
        $twentyFour = Network::query()->create(['cidr' => '10.14.0.0/16']);
        Network::query()->create(['cidr' => '10.14.146.0/24']);

        $found = Network::enclosing(Cidr::address('10.14.146.7'));

        $this->assertNotNull($found);
        $this->assertSame('10.14.146.0/24', $found->cidr);
        $this->assertSame($twentyFour->network_id, $twentyFour->network_id);
    }

    public function test_an_address_outside_every_block_finds_nothing(): void
    {
        Network::query()->create(['cidr' => '10.0.0.0/8']);

        $this->assertNull(Network::enclosing(Cidr::address('192.168.1.1')));
    }

    public function test_ipv6_never_matches_an_ipv4_block(): void
    {
        Network::query()->create(['cidr' => '0.0.0.0/0']);

        $this->assertNull(Network::enclosing(Cidr::address('2001:db8::1')));
    }

    public function test_an_address_inherits_from_the_block_above_it(): void
    {
        $eight = Network::query()->create([
            'cidr' => '10.0.0.0/8',
            'site_id' => $site = Site::query()->create(['name' => 'Milano'])->site_id,
            'net_owner_id' => $owner = NetworkOwner::query()->create(['name' => 'Network team'])->net_owner_id,
        ]);

        $twentyFour = Network::query()->create([
            'cidr' => '10.14.146.0/24',
            'parent_id' => $eight->network_id,
        ]);

        // nothing of its own, so it takes the /8's values
        $this->assertSame($site, $twentyFour->inherit('site_id'));
        $this->assertSame($owner, $twentyFour->inherit('net_owner_id'));
    }

    public function test_a_child_overrides_its_parent(): void
    {
        $parent = Network::query()->create([
            'cidr' => '10.0.0.0/8',
            'net_owner_id' => NetworkOwner::query()->create(['name' => 'Network team'])->net_owner_id,
        ]);

        $child = Network::query()->create([
            'cidr' => '10.14.146.0/24',
            'parent_id' => $parent->network_id,
            'net_owner_id' => NetworkOwner::query()->create(['name' => 'Storage team'])->net_owner_id,
        ]);

        $this->assertNotSame($parent->net_owner_id, $child->inherit('net_owner_id'));
    }

    public function test_inheritance_reaches_further_up(): void
    {
        $eight = Network::query()->create([
            'cidr' => '10.0.0.0/8',
            'site_id' => Site::query()->create(['name' => 'Milano'])->site_id,
        ]);
        $sixteen = Network::query()->create(['cidr' => '10.14.0.0/16', 'parent_id' => $eight->network_id]);
        $twentyFour = Network::query()->create(['cidr' => '10.14.146.0/24', 'parent_id' => $sixteen->network_id]);

        // nothing on the /24 or the /16, the answer is on the /8
        $this->assertSame($eight->site_id, $twentyFour->inherit('site_id'));
    }

    public function test_inheritance_returns_zero_when_nothing_sets_it(): void
    {
        $block = Network::query()->create(['cidr' => '192.168.0.0/16']);

        $this->assertSame(0, $block->inherit('site_id'));
    }

    public function test_a_cycle_in_the_tree_does_not_loop_forever(): void
    {
        // only reachable by editing the database by hand, but a hang here
        // would be much worse than a wrong answer
        $a = Network::query()->create(['cidr' => '10.0.0.0/8']);
        $b = Network::query()->create(['cidr' => '10.14.0.0/16', 'parent_id' => $a->network_id]);

        DB::table('ipam_networks')
            ->where('network_id', $a->network_id)
            ->update(['parent_id' => $b->network_id]);

        $fresh = Network::find($a->network_id);

        $this->assertSame(0, $fresh->inherit('site_id'));
    }
}
