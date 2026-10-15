<?php

namespace Dercol1\LibrenmsIpam\Import;

use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Illuminate\Support\Facades\DB;

/**
 * Builds the address side of the ipam from LibreNMS's ipv4_addresses and
 * ipv6_addresses.
 *
 * For each address LibreNMS reports, this works out four things and stores
 * them: which block of the tree it belongs to, which site it sits in, which
 * sysLocation it was reported at, and which vlan it is scoped to. The middle two
 * are what make duplicate detection mean anything.
 *
 * The resolution order is deliberate:
 *
 *   1. an operator's manual pin, if there is one, wins outright
 *   2. the enclosing block answers, walking up the tree, so a /8 assignment
 *      covers everything under it
 *   3. the vlan comes from the block, then from ports_vlans / ports.ifVlan
 *   4. the site comes from the block, then from the device's sysLocation
 *
 * Step 4 matters more than it looks. In this install 455 of 474 addresses sit
 * on a port with no vlan information at all, because they are host and firewall
 * addresses rather than switch port addresses. The block hierarchy is what
 * turns those into something classifiable, and the device location is the last
 * resort when even that is missing.
 *
 * The site is a rollup of sysLocation strings rather than the string itself:
 * several spellings of one building belong to one site, which is what keeps two
 * rooms of the same place out of the collision report. See LocationResolver.
 */
class AddressImporter
{
    /**
     * Shared with the vlan importer: both walk sysLocation strings to sites, and
     * they have to agree or an address and the vlan it sits in would end up in
     * different sites.
     */
    public LocationResolver $resolver;

    /**
     * The blocks this run walked over, by id: the evidence the reconciler uses
     * to tell a range the estate still has from one nothing points at.
     *
     * @var array<int, bool>
     */
    private array $seenNetworks = [];

    /**
     * The vlan records this run resolved an occurrence to, by id.
     *
     * Same evidence for the other half: ports_vlans names a vlan the vlans
     * table has no row for, and the record made for it is one the estate still
     * has — the reconciler must not mistake it for one the devices dropped.
     *
     * @var array<int, bool>
     */
    private array $seenVlanRecords = [];

    public function __construct(
        private readonly bool $importIpv4 = true,
        private readonly bool $importIpv6 = true,
        private readonly bool $includeInactivePorts = false,
        private readonly bool $siteFromDevice = true,
        private readonly bool $uniquePerScope = true,
        ?LocationResolver $resolver = null,
        /**
         * Ranges imported but hidden: loopback, link-local, multicast. Empty
         * when the caller wants everything in plain sight, which is what the
         * tests do unless they are specifically about the exclusions.
         *
         * @var list<Cidr>
         */
        private readonly array $excludeRanges = [],
    ) {
        $this->resolver = $resolver ?? new LocationResolver;
    }

    public function import(ImportResult $result): void
    {
        $this->importFamily(4, $this->importIpv4, $result);

        if ($this->importIpv6) {
            $this->importFamily(6, $this->importIpv6, $result);
        }
    }

    private function importFamily(int $family, bool $enabled, ImportResult $result): void
    {
        if (! $enabled) {
            return;
        }

        $source = $family === 4 ? 'ipv4_addresses' : 'ipv6_addresses';
        $addressColumn = $family === 4 ? 'ipv4_address' : 'ipv6_address';

        $query = DB::table($source)
            ->join('ports as p', 'p.port_id', '=', "$source.port_id")
            ->join('devices as d', 'd.device_id', '=', 'p.device_id')
            ->leftJoin('locations as l', 'l.id', '=', 'd.location_id')
            ->select([
                "$source.$addressColumn as address",
                "$source.ipv{$family}_prefixlen as prefixlen",
                "$source.port_id as port_id",
                "$source.context_name as context_name",
                'p.device_id as device_id',
                'p.ifVlan as if_vlan',
                'd.location_id as location_id',
            ]);

        if (! $this->includeInactivePorts) {
            $query->where('p.deleted', 0)->where('p.ignore', 0);
        }

        $seenKeys = [];

        foreach ($query->orderBy("$source.$addressColumn")->get() as $row) {
            $result->seen++;

            $address = Cidr::address((string) $row->address, true);

            if ($address === null) {
                $result->warnings[] = "skipped an unreadable address: {$row->address}";

                continue;
            }

            $prefixLen = (int) ($row->prefixlen ?: Cidr::maxPrefixLen($family));
            $context = (string) ($row->context_name ?? '');

            $key = implode('-', [$family, $row->address, $prefixLen, $row->port_id, $context]);
            $seenKeys[$key] = true;

            $this->ensureBlock($address->withPrefixLen($prefixLen), $result);

            $block = Network::enclosing($address);

            $existing = Address::query()
                ->where('family', $family)
                ->where('address', $address->uncompressed())
                ->where('prefixlen', $prefixLen)
                ->where('port_id', (int) $row->port_id)
                ->where('context_name', $context)
                ->first();

            if ($existing !== null) {
                $this->touchExisting($existing, $row, $block, $result);

                continue;
            }

            $record = new Address([
                'family' => $family,
                'address' => $address->uncompressed(),
                'prefixlen' => $prefixLen,
                'port_id' => (int) $row->port_id,
                'device_id' => (int) $row->device_id,
                'context_name' => $context,
                'network_id' => $block->network_id ?? 0,
                'scope_locked' => false,
                // Set at creation only: an excluded range, or a block someone
                // already ignored. A refresh never re-derives it, so an operator
                // who un-ignores a row keeps it that way.
                'ignored' => $this->isExcluded($address)
                    || ($block !== null && $block->ignoredInChain()),
                'first_seen' => now(),
                'last_seen' => now(),
            ]);

            if ($record->ignored) {
                $result->ignored++;
            }

            $this->applyScope($record, $row, $block);

            // One row per address and scope, unless the network never reuses an
            // address, in which case the address alone identifies it.
            if ($this->uniquePerScope) {
                $clash = Address::query()
                    ->where('family', $family)
                    ->where('address', $address->uncompressed())
                    ->where('port_id', '!=', (int) $row->port_id)
                    ->when($this->uniquePerScope, fn ($q) => $q->where('vlan_record_id', $record->vlan_record_id))
                    ->where('site_id', $record->site_id)
                    ->exists();

                if ($clash) {
                    // Already recorded for this scope: the second occurrence is
                    // the duplicate, and it is kept as its own row on purpose so
                    // the report can name both ports.
                    $result->inserted++;
                    $record->save();
                    $this->countUnscoped($record, $result);

                    continue;
                }
            }

            $record->save();
            $result->inserted++;
            $this->countUnscoped($record, $result);
        }

        $this->pruneMissing($family, $seenKeys, $result);
    }

    /**
     * Refresh an occurrence we already know about.
     *
     * A pinned scope is never re-derived: an operator who corrected the site by
     * hand should not have it undone by the next poll.
     */
    private function touchExisting(
        Address $record,
        object $row,
        ?Network $block,
        ImportResult $result
    ): void {
        $record->last_seen = now();
        $record->device_id = (int) $row->device_id;

        if (! $record->scope_locked) {
            $record->network_id = $block->network_id ?? $record->network_id;
            $this->applyScope($record, $row, $block);
        }

        $record->save();
        $result->updated++;
    }

    /**
     * Work out the vlan and the site for an occurrence.
     *
     * @param  object  $row  the source row, with if_vlan and location_id
     */
    private function applyScope(Address $record, object $row, ?Network $block): void
    {
        [$vlanRecordId, $siteId, $netOwnerId, $netFunctionId] = [0, 0, 0, 0];

        // 1. the block tree, which is where a wide assignment is expressed
        if ($block !== null) {
            $siteId = $block->inherit('site_id');
            $netOwnerId = $block->inherit('net_owner_id');
            $netFunctionId = $block->inherit('net_function_id');

            // a block declares a bare vlan id, which has to be tied to the site
            // the address ended up in, so this runs after the site is known
            if ($block->inherit('vlan_id') !== 0) {
                $vlanRecordId = $this->vlanRecordFor($block, $siteId);
            }
        }

        // 2. the port's own vlan, for the addresses that do come from a switch
        if ($vlanRecordId === 0) {
            $portVlan = $this->vlanForPort((int) $row->port_id, (int) $row->if_vlan);

            if ($portVlan !== null) {
                $vlanRecordId = (int) $portVlan->vlan_record_id;
                $this->seenVlanRecords[$vlanRecordId] = true;

                if ($siteId === 0) {
                    $siteId = (int) $portVlan->site_id;
                }
            }
        }

        // 3. the device's location, the last resort for a site. The location is
        // recorded either way, because it says which room the address was seen
        // in even when the site came from a block.
        $locationId = 0;

        if ($row->location_id !== null) {
            $locationId = $this->resolver->locationId((int) $row->location_id);

            if ($siteId === 0 && $this->siteFromDevice) {
                $siteId = $this->resolver->siteIdForLocation((int) $row->location_id);
            }
        }

        // A block that declared a vlan id but whose site was still unknown above
        // has to be resolved now that the site is settled, otherwise the id
        // would be attached to no vlan record at all.
        if ($vlanRecordId === 0 && $block !== null && $block->inherit('vlan_id') !== 0) {
            $vlanRecordId = $this->vlanRecordFor($block, $siteId);
        }

        $record->vlan_record_id = $vlanRecordId;
        $record->site_id = $siteId;
        $record->location_id = $locationId;
        $record->net_owner_id = $netOwnerId;
        $record->net_function_id = $netFunctionId;
    }

    /**
     * The site scoped ipam vlan for a block's declared vlan id.
     */
    private function vlanRecordFor(Network $block, int $siteId): int
    {
        $vlanId = $block->inherit('vlan_id');

        if ($vlanId === 0) {
            return 0;
        }

        $record = Vlan::query()
            ->where('vlan_id', $vlanId)
            ->where('site_id', $siteId)
            ->first();

        if ($record === null) {
            return 0;
        }

        // Deliberately not marked as seen: this is the record a *block*
        // declares, and a block is somebody's description. What the devices
        // report is what vlanForPort marks, and the reconciler is asking about
        // the devices — otherwise a vlan the switches dropped would look alive
        // for as long as an operator keeps naming it on a range.
        return (int) $record->vlan_record_id;
    }

    /**
     * The vlan a port is in, from ports_vlans, falling back to ports.ifVlan.
     */
    private function vlanForPort(int $portId, int $ifVlan): ?Vlan
    {
        if ($portId === 0) {
            return null;
        }

        $vlanNumber = DB::table('ports_vlans')
            ->where('port_id', $portId)
            ->where('vlan', '>', 0)
            ->orderBy('vlan')
            ->value('vlan');

        if ($vlanNumber === null) {
            $vlanNumber = $ifVlan > 0 ? $ifVlan : null;
        }

        if ($vlanNumber === null) {
            return null;
        }

        // The record for the port's own site comes first: that is the vlan as
        // it exists where the address was actually seen. Only if the site has no
        // record of this vlan id does any other record have to do, which keeps
        // a port from being classified against an unrelated branch.
        $deviceId = (int) DB::table('ports')->where('port_id', $portId)->value('device_id');

        $locationId = $deviceId === 0
            ? null
            : DB::table('devices')->where('device_id', $deviceId)->value('location_id');

        $siteId = $locationId === null ? 0 : $this->resolver->siteIdForLocation((int) $locationId);

        $record = Vlan::query()
            ->where('vlan_id', (int) $vlanNumber)
            ->where('site_id', $siteId)
            ->first();

        if ($record !== null) {
            return $record;
        }

        $record = Vlan::query()
            ->where('vlan_id', (int) $vlanNumber)
            ->orderBy('site_id')
            ->first();

        if ($record !== null) {
            return $record;
        }

        // ports_vlans can name a vlan the vlans table has no row for, which
        // happens when a discovery was partial. Creating the record keeps the
        // address classifiable instead of dropping it into the unclassified
        // queue for a reason nobody can act on.
        return Vlan::query()->create([
            'vlan_id' => (int) $vlanNumber,
            'vlan_domain' => 1,
            'site_id' => $siteId,
            'vlan_owner_id' => 0,
            'name' => null,
            'description' => null,
            'auto_created' => true,
        ]);
    }

    /**
     * Make sure the block and its ancestors exist, so the tree can carry a wide
     * assignment.
     *
     * Walked from the widest candidate to the narrowest, and each block is
     * parented to the one handled just before it. Getting the direction right
     * matters: a /8 is the parent of the /16 inside it, so looking for a parent
     * among *longer* prefixes builds the tree upside down and inheritance finds
     * nothing.
     */
    private function ensureBlock(Cidr $cidr, ImportResult $result): void
    {
        $prefixLens = array_values(array_unique(array_filter(
            [...Cidr::ladder($cidr->getFamily()), $cidr->getPrefixLen()],
            fn (int $prefixLen): bool => $prefixLen <= $cidr->getPrefixLen()
        )));

        // ascending means widest first, so the previous entry is always the parent
        sort($prefixLens);

        $parentId = 0;
        // A range created inside a stretched one is in that domain without the
        // operator saying it again: the block above it was stretched, and this
        // is the block below it. Carried along the walk rather than looked up
        // afterwards, because the row does not exist yet at this point.
        $parentStretchId = 0;

        foreach ($prefixLens as $prefixLen) {
            $candidate = $cidr->withPrefixLen($prefixLen);

            $existing = Network::query()->where('cidr', $candidate->cidr())->value('network_id');

            if ($existing !== null) {
                $parentId = (int) $existing;
                $parentStretchId = (int) Network::query()->whereKey($parentId)->value('stretch_id');
                $this->seenNetworks[(int) $existing] = true;

                continue;
            }

            $created = Network::query()->create([
                'parent_id' => $parentId,
                'cidr' => $candidate->cidr(),
                'description' => null,
                'site_id' => 0,
                'vlan_id' => 0,
                'net_owner_id' => 0,
                'net_function_id' => 0,
                'auto_created' => true,
                'stretch_id' => $parentStretchId,
                // A block entirely inside an excluded range starts ignored, so
                // 127.0.0.0/8 does not sit in the map as a block nobody wants.
                // Partially excluded blocks stay visible: the range is real, only
                // some of its addresses are noise.
                'ignored' => $this->blockIsExcluded($candidate),
            ]);

            $result->networksCreated[$prefixLen] = ($result->networksCreated[$prefixLen] ?? 0) + 1;
            $parentId = (int) $created->network_id;
            $this->seenNetworks[$parentId] = true;
        }
    }

    /**
     * Hand the evidence over to the reconciler, which runs once both importers
     * are done: a vlan record the address side resolved to is a record the
     * estate still has, and reconciling before the address import would not know
     * that yet.
     */
    public function publishSeen(ImportResult $result): void
    {
        $result->seenNetworks = $this->seenNetworks;
        $result->seenVlanRecords = $this->seenVlanRecords + $result->seenVlanRecords;
    }

    private function countUnscoped(Address $record, ImportResult $result): void
    {
        if ($record->isUnscoped()) {
            $result->unscoped++;
        }
    }

    /**
     * Does one address fall inside an excluded range?
     */
    private function isExcluded(Cidr $address): bool
    {
        foreach ($this->excludeRanges as $range) {
            if ($address->isIn($range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the whole block inside one excluded range?
     *
     * The block's own start is not enough: 127.0.0.0/8 starts inside
     * 127.0.0.0/8, but so does a /24 carved out of a range only partly
     * excluded. Both ends have to be inside the same range.
     */
    private function blockIsExcluded(Cidr $block): bool
    {
        foreach ($this->excludeRanges as $range) {
            $start = Cidr::fromPacked($block->packedStart());
            $end = Cidr::fromPacked($block->packedEnd());

            if ($start->isIn($range) && $end->isIn($range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop occurrences LibreNMS no longer reports.
     *
     * Only rows this run did not touch, so a pinned address is never lost just
     * because a device stopped answering.
     *
     * @param  array<string, bool>  $seenKeys
     */
    private function pruneMissing(int $family, array $seenKeys, ImportResult $result): void
    {
        Address::query()
            ->where('family', $family)
            ->where('last_seen', '<', now()->subMinutes(10))
            ->each(function (Address $address) use ($seenKeys, $result): void {
                $key = implode('-', [
                    $address->family,
                    $address->address,
                    $address->prefixlen,
                    $address->port_id,
                    $address->context_name,
                ]);

                if (isset($seenKeys[$key])) {
                    return;
                }

                if ($address->scope_locked) {
                    return; // an operator owns this one, keep it
                }

                $address->delete();
                $result->removed++;
            });
    }
}
