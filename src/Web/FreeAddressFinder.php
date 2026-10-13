<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Illuminate\Support\Collection;

/**
 * Where the free addresses are in a range.
 *
 * The question an operator actually asks is not "is 10.14.146.37 free" — the
 * search box answers that — but "give me a free address in here". That needs
 * the gaps: fetch the used ones, subtract, and what is left is the answer, as
 * ranges to read and as concrete suggestions to copy.
 *
 * Hidden addresses count as used, and that is deliberate. They are configured
 * on devices — loopback, link-local — they just do not say anything about the
 * estate. Handing one out because it is "hidden" would be the one mistake this
 * tool could make that costs a network outage.
 */
final class FreeAddressFinder
{
    /**
     * The used addresses, the gaps between them, and the first few free ones.
     *
     * @return array{
     *     range: Cidr,
     *     used: list<string>,
     *     used_count: int,
     *     gaps: list<array{start: string, end: string, count: int}>,
     *     suggestions: list<string>,
     *     free_count: int,
     * }
     */
    public function find(Cidr $range, int $suggest = 10): array
    {
        $used = $this->usedInRange($range)
            ->map(fn ($address): string => Cidr::fromPacked($address)->compressed())
            ->unique()
            ->sort(fn (string $a, string $b): int => $this->compare($a, $b))
            ->values();

        // The first address of a range is not handed out in either family: in v4
        // it is the network address, in v6 the subnet-router anycast. The last
        // one only matters for v4, where it is the broadcast; a v6 range runs to
        // its end.
        //
        // Everything from here on works on raw packed bytes. Wrapping them in
        // a Cidr is for display only, and mixing the two is how a gap count
        // once came back as a gmp object's idea of an ip address.
        $max = Cidr::maxPrefixLen($range->getFamily());
        $first = $this->increment($range->packedStart());
        $last = $max === 32 ? $this->decrement($range->packedEnd()) : $range->packedEnd();

        $gaps = [];
        $suggestions = [];
        $free = 0;

        $cursor = $first;

        foreach ($used as $address) {
            $packed = Cidr::address($address)->packed();

            if ($this->lt($cursor, $packed)) {
                $gaps[] = [
                    'start' => Cidr::fromPacked($cursor)->compressed(),
                    'end' => Cidr::fromPacked($this->decrement($packed))->compressed(),
                    'count' => $this->distance($cursor, $packed),
                ];

                $free += $this->distance($cursor, $packed);

                $suggestions = [...$suggestions, ...$this->firstWithin($cursor, $this->decrement($packed), $suggest - count($suggestions))];
            }

            $after = $this->increment($packed);

            // A used address past the end (the broadcast is sometimes
            // configured) must still move the cursor past the end, or the final
            // gap below would be emitted twice.
            $cursor = $this->lte($after, $last) ? $after : $this->increment($last);
        }

        if ($this->lte($cursor, $last)) {
            $gaps[] = [
                'start' => Cidr::fromPacked($cursor)->compressed(),
                'end' => Cidr::fromPacked($last)->compressed(),
                'count' => $this->distance($cursor, $this->increment($last)),
            ];

            $free += $this->distance($cursor, $this->increment($last));

            $suggestions = [...$suggestions, ...$this->firstWithin($cursor, $last, $suggest - count($suggestions))];
        }

        return [
            'range' => $range,
            'used' => $used->all(),
            'used_count' => $used->count(),
            'gaps' => $gaps,
            'suggestions' => array_slice($suggestions, 0, $suggest),
            'free_count' => $free,
        ];
    }

    /**
     * The addresses recorded inside the range, binary form, hidden included.
     *
     * INET6_ATON for v6 and INET_ATON for v4: a string compare would put
     * 10.14.10.1 before 10.14.9.1, and the gap list would be nonsense.
     *
     * @return Collection<int, string>
     */
    private function usedInRange(Cidr $range): Collection
    {
        $function = $range->getFamily() === 4 ? 'INET_ATON' : 'INET6_ATON';

        return Address::query()
            ->where('family', $range->getFamily())
            ->whereRaw("$function(address) BETWEEN $function(?) AND $function(?)", [
                $range->compressed(),
                Cidr::fromPacked($range->packedEnd())->compressed(),
            ])
            ->pluck('address')
            ->map(fn (string $address): string => Cidr::address($address)->packed());
    }

    /**
     * The first $count addresses of a gap, inclusive of both ends.
     *
     * @return list<string>
     */
    private function firstWithin(string $start, string $end, int $count): array
    {
        if ($count <= 0 || $this->lt($end, $start)) {
            return [];
        }

        $out = [];
        $cursor = $start;

        while (count($out) < $count && $this->lte($cursor, $end)) {
            $out[] = Cidr::fromPacked($cursor)->compressed();

            if ($cursor === $end) {
                break;
            }

            $cursor = $this->increment($cursor);
        }

        return $out;
    }

    /**
     * Address order as numbers, not as strings.
     */
    private function compare(string $a, string $b): int
    {
        $pa = Cidr::address($a)->packed();
        $pb = Cidr::address($b)->packed();

        return $pa <=> $pb;
    }

    private function lt(string $a, string $b): bool
    {
        return $a < $b;
    }

    private function lte(string $a, string $b): bool
    {
        return $a <= $b;
    }

    private function increment(string $packed): string
    {
        $bytes = array_values(unpack('C*', $packed));
        $i = count($bytes);

        while ($i >= 1) {
            if ($bytes[$i - 1] === 0xFF) {
                $bytes[$i - 1] = 0;
                $i--;

                continue;
            }

            $bytes[$i - 1]++;

            break;
        }

        return pack('C*', ...$bytes);
    }

    private function decrement(string $packed): string
    {
        $bytes = array_values(unpack('C*', $packed));
        $i = count($bytes);

        while ($i >= 1) {
            if ($bytes[$i - 1] === 0x00) {
                $bytes[$i - 1] = 0xFF;
                $i--;

                continue;
            }

            $bytes[$i - 1]--;

            break;
        }

        return pack('C*', ...$bytes);
    }

    /**
     * How many addresses sit between $a (inclusive) and $b (exclusive).
     *
     * Gmp for the v6 sizes, where the count can leave a PHP int behind.
     */
    private function distance(string $a, string $b): int
    {
        $family = strlen($a) === 4 ? 4 : 6;
        $base = gmp_init(bin2hex($a), 16);
        $end = gmp_init(bin2hex($b), 16);
        $diff = gmp_sub($end, $base);
        $int = gmp_intval($diff);

        // clamp rather than wrap: a gap past the int range reports as the
        // maximum rather than as a negative number
        $limit = $family === 4 ? 0x7FFFFFFF : PHP_INT_MAX;

        return max(0, min($int, $limit));
    }
}
