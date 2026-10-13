<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Support\Cidr;
use PHPUnit\Framework\TestCase;

/**
 * The range arithmetic, without a database.
 *
 * Everything the import and the collision detection trust rests on these
 * answers, so they are checked on their own rather than only through the
 * importers.
 */
class CidrTest extends TestCase
{
    public function test_it_masks_the_address_part(): void
    {
        $this->assertSame('10.14.146.0/24', Cidr::parse('10.14.146.5/24')->cidr());
        $this->assertSame('10.0.0.0/8', Cidr::parse('10.1.2.3/8')->cidr());
        $this->assertSame('0.0.0.0/0', Cidr::parse('0.0.0.0/0')->cidr());
    }

    public function test_it_reads_a_bare_address_as_a_host_route(): void
    {
        $this->assertSame('10.0.0.1/32', Cidr::parse('10.0.0.1')->cidr());
        $this->assertSame('::1/128', Cidr::parse('::1')->cidr());
    }

    public function test_range_end_is_inclusive(): void
    {
        // the whole point of storing an upper bound: 10.14.146.255 is inside
        $this->assertSame('10.14.146.255', inet_ntop(Cidr::parse('10.14.146.0/24')->packedEnd()));
        $this->assertSame('10.255.255.255', inet_ntop(Cidr::parse('10.0.0.0/8')->packedEnd()));
        $this->assertSame('255.255.255.255', inet_ntop(Cidr::parse('0.0.0.0/0')->packedEnd()));
        // not a multiple of 8, the mask has to land mid byte
        $this->assertSame('192.168.1.127', inet_ntop(Cidr::parse('192.168.1.64/26')->packedEnd()));
        $this->assertSame('192.168.1.191', inet_ntop(Cidr::parse('192.168.1.128/26')->packedEnd()));
    }

    public function test_ipv6_range_end(): void
    {
        $this->assertSame(
            '2001:db8::ffff:ffff:ffff:ffff',
            inet_ntop(Cidr::parse('2001:db8::/64')->packedEnd())
        );
        $this->assertSame(
            '2001:db8:0:ffff:ffff:ffff:ffff:ffff',
            inet_ntop(Cidr::parse('2001:db8::/48')->packedEnd())
        );
        $this->assertSame(
            '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff',
            inet_ntop(Cidr::parse('2001:db8::/32')->packedEnd())
        );
    }

    public function test_containment(): void
    {
        $block = Cidr::parse('10.14.146.0/24');

        $this->assertTrue(Cidr::address('10.14.146.0')->isIn($block), 'first address');
        $this->assertTrue(Cidr::address('10.14.146.255')->isIn($block), 'last address');
        $this->assertFalse(Cidr::address('10.14.147.0')->isIn($block), 'just past');
        $this->assertFalse(Cidr::address('10.14.145.255')->isIn($block), 'just before');
    }

    public function test_families_never_contain_each_other(): void
    {
        $this->assertFalse(Cidr::address('10.14.146.7')->isIn(Cidr::parse('2001:db8::/32')));
        $this->assertFalse(Cidr::address('2001:db8::1')->isIn(Cidr::parse('10.0.0.0/8')));
    }

    public function test_it_reads_the_expanded_ipv6_form_librenms_stores(): void
    {
        // ipv6_addresses.ipv6_address holds the expanded form
        $this->assertSame('2001:db8::1', Cidr::address('2001:0db8:0000:0000:0000:0000:0000:0001')->compressed());
        $this->assertSame('::1', Cidr::address('0:0:0:0:0:0:0:1')->compressed());
    }

    public function test_uncompressed_is_what_to_compare_against_librenms(): void
    {
        $this->assertSame(
            '2001:0db8:0000:0000:0000:0000:0000:0001',
            Cidr::address('2001:db8::1')->uncompressed()
        );
        $this->assertSame('10.14.146.7', Cidr::address('10.14.146.7')->uncompressed());
    }

    public function test_a_range_run_is_compressed_exactly_once(): void
    {
        // inet_ntop already compresses. Running our own pass over its output
        // used to produce 2001:db8::1::1, because it then found the run of
        // zeros inet_ntop had just introduced.
        $this->assertSame('2001:db8::1:0:0:1', Cidr::address('2001:db8:0:0:1:0:0:1')->compressed());
    }

    public function test_size_saturates_instead_of_overflowing(): void
    {
        $this->assertSame(254, Cidr::parse('10.0.0.0/24')->size());
        $this->assertSame(2, Cidr::parse('10.0.0.0/30')->size());
        // 1 << 64 would be a float, and the caller would get nonsense
        $this->assertSame(PHP_INT_MAX, Cidr::parse('::/0')->size());
    }

    public function test_the_ladder_is_what_creates_the_wide_segments(): void
    {
        $this->assertSame([8, 16, 24], Cidr::ladder(4));
        $this->assertSame([16, 32, 48], Cidr::ladder(6));
    }

    public function test_unreadable_input_can_be_tolerated(): void
    {
        $this->assertNull(Cidr::address('not an address', true));
        $this->assertNull(Cidr::address('', true));
        $this->assertNull(Cidr::parse('10.0.0.0/99', true));
        $this->assertNull(Cidr::parse('nonsense/24', true));
    }

    public function test_bad_input_throws_when_errors_are_wanted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cidr::parse('10.0.0.0/99');
    }
}
