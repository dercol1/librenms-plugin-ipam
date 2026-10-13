<?php

/*
 * Plugin settings.
 *
 * The web ui stores its choices in the plugins.settings column, so a value saved
 * from the browser wins over config/ipam.php, which only carries the defaults.
 * This resolves the two into typed values the rest of the code can use without
 * guessing at casts or array shapes.
 */

namespace Dercol1\LibrenmsIpam;

use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\LocationResolver;
use Dercol1\LibrenmsIpam\Import\VlanImporter;
use Dercol1\LibrenmsIpam\Support\Cidr;

class Settings
{
    /**
     * One resolver per Settings instance, so the vlans and the addresses of a
     * single import walk sysLocation to site the same way. Two resolvers would
     * eventually disagree and put an address and its vlan in different sites.
     */
    private ?LocationResolver $resolver = null;

    /** @param array<string, mixed> $stored */
    private function __construct(private readonly array $stored) {}

    /**
     * @param  array<string, mixed>  $stored  the plugin row settings, as the hook receives them
     */
    public static function fromArray(array $stored): self
    {
        return new self($stored);
    }

    private function value(string $key): mixed
    {
        // A saved setting wins. The switch is there because an unchecked
        // checkbox is simply absent from the posted array, so "absent" cannot
        // be told apart from "false" without it.
        if (array_key_exists($key, $this->stored)) {
            return $this->stored[$key];
        }

        return config("ipam.$key");
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->value($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->value($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function importIpv4(): bool
    {
        return $this->bool('import_ipv4', true);
    }

    public function importIpv6(): bool
    {
        return $this->bool('import_ipv6', true);
    }

    /**
     * Include addresses on ports the operator deleted or disabled.
     */
    public function importInactivePorts(): bool
    {
        return $this->bool('import_inactive_ports', false);
    }

    /**
     * Fall back to the site of the device when no network block declares one.
     */
    public function siteFromDevice(): bool
    {
        return $this->bool('site_from_device', true);
    }

    /**
     * Count the vlan as part of the scope, so the same address in two vlans is
     * not a duplicate.
     */
    public function useVlanScope(): bool
    {
        return $this->bool('use_vlan_scope', true);
    }

    /**
     * Leave occurrences that have neither vlan nor site out of duplicate
     * detection. They go to the unclassified queue instead.
     */
    public function ignoreUnscopedDuplicates(): bool
    {
        return $this->bool('ignore_unscoped_duplicates', true);
    }

    /**
     * Allow one address per scope rather than one row per address.
     */
    public function uniquePerScope(): bool
    {
        return $this->bool('unique_per_scope', true);
    }

    public function perPage(): int
    {
        return max(10, min(500, $this->int('per_page', 50)));
    }

    /**
     * The address ranges that hold no information about the estate.
     *
     * Loopback exists on every device, link-local on every interface, multicast
     * on every router running OSPF: importing them fills the address list with
     * rows that say nothing and makes 127.0.0.1 look like a collision. Ranges
     * here are hidden as they are imported, never deleted, because the operator
     * may still want to look at them with the "show hidden" toggle.
     *
     * A comma or newline separated list of CIDRs. An entry that does not parse
     * is dropped rather than fatal: a typo in a settings field must not take
     * the import down, and the page shows what the list resolved to.
     *
     * @return list<Cidr>
     */
    public function excludeRanges(): array
    {
        $raw = $this->value('exclude_ranges');

        $ranges = [];

        foreach (preg_split('/[\n,]+/', (string) $raw) ?: [] as $entry) {
            $cidr = Cidr::parse(trim($entry), true);

            if ($cidr !== null) {
                $ranges[] = $cidr;
            }
        }

        return $ranges;
    }

    /**
     * The list as the settings page shows it: entries that parsed, one per line.
     */
    public function excludeRangesText(): string
    {
        return implode("\n", array_map(
            fn (Cidr $cidr): string => $cidr->cidr(),
            $this->excludeRanges()
        ));
    }

    /**
     * Does one address fall in an excluded range?
     */
    public function isExcluded(Cidr $address): bool
    {
        foreach ($this->excludeRanges() as $range) {
            if ($address->isIn($range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The settings an importer was configured with.
     */
    public function importer(): AddressImporter
    {
        return new AddressImporter(
            importIpv4: $this->importIpv4(),
            importIpv6: $this->importIpv6(),
            includeInactivePorts: $this->importInactivePorts(),
            siteFromDevice: $this->siteFromDevice(),
            uniquePerScope: $this->uniquePerScope(),
            resolver: $this->resolver(),
            excludeRanges: $this->excludeRanges(),
        );
    }

    public function vlanImporter(): VlanImporter
    {
        return new VlanImporter(
            siteFromDevice: $this->siteFromDevice(),
            resolver: $this->resolver(),
        );
    }

    /**
     * The shared sysLocation to site resolver.
     */
    public function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver;
    }

    public function detector(): DuplicateDetector
    {
        return new DuplicateDetector(
            useVlanScope: $this->useVlanScope(),
            ignoreUnscoped: $this->ignoreUnscopedDuplicates(),
        );
    }
}
