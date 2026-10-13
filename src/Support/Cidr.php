<?php

/*
 * IP range arithmetic.
 *
 * LibreNMS stores addresses as strings and networks as dotted nets, which is
 * fine to display and useless to compare. Everything the import and the
 * duplicate detection do with ranges happens here, on the binary form.
 *
 * PHP has inet_pton/inet_ntop, so no dependency is needed.
 */

namespace Dercol1\LibrenmsIpam\Support;

use InvalidArgumentException;

class Cidr
{
    /** @var string binary address, 4 bytes for v4, 16 for v6 */
    private string $packed;

    private int $family;

    private int $prefixLen;

    private function __construct(string $packed, int $prefixLen)
    {
        $this->packed = $packed;
        $this->family = strlen($packed) === 4 ? 4 : 6;
        $this->prefixLen = $prefixLen;
    }

    /**
     * Build from a bare address: "10.0.0.1", "2001:db8::1" or the expanded form
     * LibreNMS stores ("0:0:0:0:0:0:0:1").
     *
     * The prefix length is a host route, /32 or /128.
     */
    public static function address(string $address, bool $ignoreErrors = false): ?self
    {
        $address = trim($address);

        if ($address === '') {
            return $ignoreErrors ? null : throw new InvalidArgumentException('empty address');
        }

        if (str_contains($address, ':') && ! str_contains($address, '.')) {
            $address = self::expandIpv6($address);
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            return $ignoreErrors
                ? null
                : throw new InvalidArgumentException("not an ip address: $address");
        }

        return new self($packed, strlen($packed) === 4 ? 32 : 128);
    }

    /**
     * Build from "10.0.0.0/24". The address is masked, so "10.0.0.5/24" and
     * "10.0.0.0/24" are the same object.
     */
    public static function parse(string $cidr, bool $ignoreErrors = false): ?self
    {
        $cidr = trim($cidr);

        if (! str_contains($cidr, '/')) {
            return self::address($cidr, $ignoreErrors);
        }

        [$addressPart, $prefixPart] = explode('/', $cidr, 2);

        $address = self::address($addressPart, true);

        if ($address === null) {
            return $ignoreErrors
                ? null
                : throw new InvalidArgumentException("not an ip address in $cidr");
        }

        if (! ctype_digit($prefixPart)) {
            return $ignoreErrors
                ? null
                : throw new InvalidArgumentException("bad prefix length in $cidr");
        }

        try {
            return $address->withPrefixLen((int) $prefixPart);
        } catch (InvalidArgumentException) {
            return $ignoreErrors ? null : throw new InvalidArgumentException("bad prefix length in $cidr");
        }
    }

    public static function fromPacked(string $packed): self
    {
        return new self($packed, strlen($packed) === 4 ? 32 : 128);
    }

    public static function maxPrefixLen(int $family): int
    {
        return $family === 4 ? 32 : 128;
    }

    public function getFamily(): int
    {
        return $this->family;
    }

    public function getPrefixLen(): int
    {
        return $this->prefixLen;
    }

    /**
     * The same address, re-masked at a different prefix length.
     */
    public function withPrefixLen(int $prefixLen): self
    {
        $max = self::maxPrefixLen($this->family);

        if ($prefixLen < 0 || $prefixLen > $max) {
            throw new InvalidArgumentException("prefix length $prefixLen out of range for ipv$this->family");
        }

        return new self($this->packed & self::mask($this->family, $prefixLen), $prefixLen);
    }

    public function packed(): string
    {
        return $this->packed;
    }

    /**
     * First address of the range, which is the masked address itself.
     */
    public function packedStart(): string
    {
        return $this->packed;
    }

    /**
     * Last address of the range, so a containment test can use an inclusive
     * upper bound and stay indexable.
     */
    public function packedEnd(): string
    {
        $bytes = strlen($this->packed);
        $wildcard = str_repeat("\xFF", $bytes) ^ self::mask($this->family, $this->prefixLen);

        return $this->packed | $wildcard;
    }

    /**
     * The masked address in dotted or colon form.
     */
    public function network(): string
    {
        return $this->compressed();
    }

    /**
     * The full form, every group written out. This is what LibreNMS stores in
     * ipv6_addresses.ipv6_address, so it is the form to compare against when
     * importing.
     */
    public function uncompressed(): string
    {
        if ($this->family === 4) {
            return (string) inet_ntop($this->packed);
        }

        $groups = array_map(
            fn (string $pair): string => (string) sprintf('%04x', unpack('n', $pair)[1]),
            str_split($this->packed, 2)
        );

        return implode(':', $groups);
    }

    /**
     * The short form, which is what an operator types and reads.
     *
     * inet_ntop already applies RFC 5952 compression, so nothing else is done
     * here. An earlier version re-compressed its output and produced
     * 2001:db8::1::1 for 2001:db8:0:0:1:0:0:1, because it then found the run
     * of zeros that inet_ntop had just introduced.
     */
    public function compressed(): string
    {
        return (string) inet_ntop($this->packed);
    }

    public function cidr(): string
    {
        return $this->network() . '/' . $this->prefixLen;
    }

    public function __toString(): string
    {
        return $this->compressed();
    }

    /**
     * Does this address fall inside $other's range?
     *
     * Families never match, so a v4 address is never inside a v6 block.
     */
    public function isIn(Cidr $other): bool
    {
        if ($this->family !== $other->family) {
            return false;
        }

        return $this->packed >= $other->packedStart() && $this->packed <= $other->packedEnd();
    }

    /**
     * Usable host addresses, saturating instead of overflowing to a float.
     */
    public function size(): int
    {
        $hostBits = self::maxPrefixLen($this->family) - $this->prefixLen;

        if ($hostBits >= 62) {
            return PHP_INT_MAX;
        }

        return (1 << $hostBits) - 2;
    }

    /**
     * The prefix lengths to auto-create as ancestors of a discovered block.
     *
     * This is what gives you "anche i segmenti di rete più ampli": importing a
     * 10.14.146.0/24 also creates the 10.14.0.0/16 and the 10.0.0.0/8 above it,
     * so assigning an owner once on the /8 covers the whole estate.
     *
     * @return list<int>
     */
    public static function ladder(int $family): array
    {
        return $family === 4 ? [8, 16, 24] : [16, 32, 48];
    }

    /**
     * A mask of the family's byte length with the top $prefixLen bits set.
     */
    private static function mask(int $family, int $prefixLen): string
    {
        $bytes = intdiv(self::maxPrefixLen($family), 8);
        $mask = array_fill(0, $bytes, 0);
        $bitsLeft = $prefixLen;

        for ($i = 0; $i < $bytes && $bitsLeft > 0; $i++) {
            $take = min(8, $bitsLeft);
            $mask[$i] = $take === 8 ? 0xFF : (0xFF << (8 - $take)) & 0xFF;
            $bitsLeft -= $take;
        }

        return pack('C*', ...$mask);
    }

    /**
     * inet_pton only takes the compressed v6 form, LibreNMS stores the expanded
     * one, so pad the leading zero groups back out.
     */
    private static function expandIpv6(string $address): string
    {
        $doubleColon = strpos($address, '::');

        if ($doubleColon === false) {
            return $address;
        }

        $head = substr($address, 0, $doubleColon);
        $tail = substr($address, $doubleColon + 2);

        $headGroups = $head === '' ? [] : explode(':', $head);
        $tailGroups = $tail === '' ? [] : explode(':', $tail);
        $missing = 8 - count($headGroups) - count($tailGroups);

        return implode(':', array_merge(
            $headGroups,
            array_fill(0, max($missing, 0), '0'),
            $tailGroups
        ));
    }
}
