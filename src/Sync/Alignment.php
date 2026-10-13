<?php

namespace Dercol1\LibrenmsIpam\Sync;

use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Illuminate\Support\Facades\DB;

/**
 * Does the ipam say what LibreNMS is currently polling?
 *
 * The ipam is a copy, and a copy can go stale: a poller that has run since the
 * last import has brought addresses the ipam has never seen, and a decommissioned
 * device leaves rows behind that the next import would remove. Neither is an
 * error, but an operator reading the collision report deserves to know which
 * world they are looking at.
 *
 * The comparison uses the same identity the import uses, address and port and
 * vrf, and the same port filters, so "not imported" means exactly what
 * `lnms ipam:import` would fix. Rows an operator added by hand are left out of
 * the ipam side: they are documentation, not discovery, and expecting them in
 * LibreNMS would report them missing forever.
 *
 * Hidden rows are counted on both sides: hiding changes what is displayed, not
 * what is aligned.
 */
final class Alignment
{
    /**
     * @return array{
     *     addresses_in_librenms: int,
     *     addresses_in_ipam: int,
     *     addresses_not_imported: int,
     *     addresses_gone: int,
     *     not_imported_samples: list<string>,
     *     gone_samples: list<string>,
     *     vlans_in_librenms: int,
     *     vlans_in_ipam: int,
     *     vlans_not_imported: int,
     *     vlans_gone: int,
     *     in_sync: bool,
     * }
     */
    public static function check(Settings $settings): array
    {
        [$sourceKeys, $sourceCount] = self::sourceAddressKeys($settings);
        [$ipamKeys, $ipamCount] = self::ipamAddressKeys();

        $notImported = array_values(array_diff($sourceKeys, $ipamKeys));
        $gone = array_values(array_diff($ipamKeys, $sourceKeys));

        [$sourceVlans, $ipamVlans] = self::vlanSets($settings);
        $vlansNotImported = array_values(array_diff($sourceVlans, $ipamVlans));
        $vlansGone = array_values(array_diff($ipamVlans, $sourceVlans));

        $notImportedTotal = count($notImported) + count($vlansNotImported);
        $goneTotal = count($gone) + count($vlansGone);

        return [
            'addresses_in_librenms' => $sourceCount,
            'addresses_in_ipam' => $ipamCount,
            'addresses_not_imported' => count($notImported),
            'addresses_gone' => count($gone),
            'not_imported_samples' => array_slice($notImported, 0, 5),
            'gone_samples' => array_slice($gone, 0, 5),
            'vlans_in_librenms' => count($sourceVlans),
            'vlans_in_ipam' => count($ipamVlans),
            'vlans_not_imported' => count($vlansNotImported),
            'vlans_gone' => count($vlansGone),
            // both directions have to be empty, or the copy and the source
            // disagree and every report built on the copy is provisional
            'in_sync' => $notImportedTotal === 0 && $goneTotal === 0,
        ];
    }

    /**
     * The address identities LibreNMS currently polls.
     *
     * Same source query, same port filters and same normalization as the import,
     * so a difference here is a difference the import would resolve.
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function sourceAddressKeys(Settings $settings): array
    {
        $keys = [];

        foreach ([4 => ['ipv4_addresses', 'ipv4_address'], 6 => ['ipv6_addresses', 'ipv6_address']] as $family => [$table, $column]) {
            if (! $settings->importIpv4() && $family === 4) {
                continue;
            }

            if (! $settings->importIpv6() && $family === 6) {
                continue;
            }

            $query = DB::table($table)
                ->join('ports as p', 'p.port_id', '=', "$table.port_id")
                ->select("$table.$column as address", "$table.ipv{$family}_prefixlen as prefixlen", "$table.port_id", "$table.context_name");

            if (! $settings->importInactivePorts()) {
                $query->where('p.deleted', 0)->where('p.ignore', 0);
            }

            foreach ($query->cursor() as $row) {
                $address = Cidr::address((string) $row->address, true);

                if ($address === null) {
                    continue; // the import skips it too
                }

                $keys[] = self::key($family, $address->uncompressed(), (int) $row->prefixlen, (int) $row->port_id, (string) ($row->context_name ?? ''));
            }
        }

        return [$keys, count($keys)];
    }

    /**
     * The address identities the ipam holds, manual rows excluded.
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function ipamAddressKeys(): array
    {
        $keys = DB::table('ipam_addresses')
            ->where('scope_locked', false)
            ->select(['family', 'address', 'prefixlen', 'port_id', 'context_name'])
            ->get()
            ->map(fn ($row): string => self::key((int) $row->family, (string) $row->address, (int) $row->prefixlen, (int) $row->port_id, (string) $row->context_name))
            ->all();

        return [$keys, count($keys)];
    }

    /**
     * The (vlan, domain, site) triples each side knows about.
     *
     * A device's site is the rollup of its sysLocation, the same walk the vlan
     * import does, so a vlan that shows up at a new site counts as missing from
     * the ipam until an import files it.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function vlanSets(Settings $settings): array
    {
        $resolver = $settings->resolver();

        $source = [];

        $query = DB::table('vlans as v')
            ->join('devices as d', 'd.device_id', '=', 'v.device_id')
            ->select(['v.vlan_vlan', 'v.vlan_domain', 'd.location_id'])
            ->whereNotNull('v.vlan_vlan')
            ->where('v.vlan_vlan', '>', 0);

        foreach ($query->get() as $row) {
            $siteId = $settings->siteFromDevice() && $row->location_id !== null
                ? $resolver->siteIdForLocation((int) $row->location_id)
                : 0;

            $source[] = self::vlanKey((int) $row->vlan_vlan, (int) ($row->vlan_domain ?: 1), $siteId);
        }

        $ipam = DB::table('ipam_vlans')
            ->select(['vlan_id', 'vlan_domain', 'site_id'])
            ->get()
            ->map(fn ($row): string => self::vlanKey((int) $row->vlan_id, (int) $row->vlan_domain, (int) $row->site_id))
            ->all();

        return [array_values(array_unique($source)), array_values(array_unique($ipam))];
    }

    private static function key(int $family, string $address, int $prefixlen, int $portId, string $context): string
    {
        return implode('|', [$family, $address, $prefixlen, $portId, $context]);
    }

    private static function vlanKey(int $vlanId, int $domain, int $siteId): string
    {
        return implode('|', [$vlanId, $domain, $siteId]);
    }
}
