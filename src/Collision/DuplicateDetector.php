<?php

namespace Dercol1\LibrenmsIpam\Collision;

use Closure;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\NetworkPlace;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds addresses configured more than once inside the same scope.
 *
 * The rule, in one line: the same address in two different vlans or two
 * different sites is normal addressing, the same address twice inside one
 * (vlan, site) is a conflict.
 *
 * That distinction is the whole point, and it is why the scope has to be part
 * of the key rather than the address alone. Carrying 10.0.0.1 as a gateway on
 * every branch is correct, and reporting it 40 times would make the report
 * useless. Reporting it twice inside vlan 300 at one site is a real problem,
 * because nothing can route both.
 *
 * Occurrences with neither a vlan nor a site cannot be placed, so there is
 * nothing to compare them against. They are reported separately as
 * unclassified rather than silently treated as unique or lumped together.
 */
class DuplicateDetector
{
    public function __construct(
        private readonly bool $useVlanScope = true,
        private readonly bool $ignoreUnscoped = true,
    ) {}

    /**
     * The duplicate groups, each with the occurrences that make it up.
     *
     * The grouping happens in SQL on the address and the scope columns, which
     * is the ipam_addresses_family_address_index, so this stays cheap as the
     * table grows. Occurrences are then fetched per group.
     *
     * @return list<DuplicateGroup>
     */
    public function detect(?int $siteId = null, ?int $vlanRecordId = null, int $limit = 500): array
    {
        return $this->collect(
            $this->groupQuery($this->resolveSite($siteId), $this->useVlanScope ? 'vlan_record_id' : null, $limit, $vlanRecordId)->get()
        );
    }

    /**
     * Turn grouped rows into DuplicateGroup objects.
     *
     * The scope columns arrive as vlan_record_id, vlan_group_id and site_id.
     * A stretched vlan sets the group and zeroes the record and the site, so
     * the rest of the pipeline — the group object, the annotation identity,
     * the report — carries the group wherever a plain vlan would have carried
     * the record.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<DuplicateGroup>
     */
    private function collect($rows): array
    {
        $vlanColumn = $this->useVlanScope ? 'vlan_record_id' : null;

        if ($rows->isEmpty()) {
            return [];
        }

        $groups = [];

        foreach ($rows as $row) {
            $scopeVlan = $vlanColumn === null ? 0 : (int) $row->scope_vlan;
            $scopeGroup = $vlanColumn === null ? 0 : (int) $row->scope_group;
            $scopeNetwork = $vlanColumn === null ? 0 : (int) $row->scope_network;

            $group = $this->buildGroup(
                (int) $row->family,
                $row->address,
                $scopeVlan,
                (int) $row->scope_site,
                $scopeGroup,
                $scopeNetwork
            );

            if ($group === null) {
                continue;
            }

            // keyed by address *and* scope: one address can repeat in two
            // vlans at once, and those are two separate findings
            $key = implode('-', [$row->address, $scopeVlan, $scopeGroup, $scopeNetwork, $row->scope_site]);

            $groups[$key] = $group;
        }

        return array_values($groups);
    }

    /**
     * The site filter, with the select's "no site" spelling resolved.
     *
     * The filter offers -1 for "the addresses that have no site", because 0
     * already means "all sites" in an unset filter and a select cannot send an
     * absent value. Resolving it here keeps every caller from having to.
     */
    private function resolveSite(?int $siteId): ?int
    {
        return $siteId === -1 ? 0 : $siteId;
    }

    /**
     * Group by address and scope, keeping only the groups that repeat.
     *
     * Built on the query builder rather than the Address model on purpose:
     * the scope columns are computed, not model attributes, and reading a
     * CASE alias off a typed model is a lie the type checker catches and the
     * rows cannot tell.
     *
     * When the vlan is part of the scope, the scope is computed rather than
     * read: a record whose vlan_group_id is set belongs to a broadcast domain
     * carried across sites, and inside such a domain the vlan group alone is
     * the scope — the record id and the site both stop separating, because at
     * layer 2 there is one domain and one place for an address to be.
     *
     * @param  string|null  $vlanColumn  the vlan column when it is part of the scope, null otherwise
     */
    private function groupQuery(?int $siteId, ?string $vlanColumn, int $limit, ?int $vlanRecordId = null): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('ipam_addresses')
            ->selectRaw('COUNT(*) as occurrences')
            ->groupBy('ipam_addresses.family')
            ->groupBy('ipam_addresses.address')
            ->having('occurrences', '>', 1)
            ->orderBy('occurrences', 'desc')
            ->orderBy('ipam_addresses.address')
            ->limit($limit);

        if ($vlanColumn === null) {
            // no vlan in the scope: the site separates, as it always did
            $query->selectRaw('ipam_addresses.site_id as scope_site')
                ->selectRaw('ipam_addresses.family as family')
                ->selectRaw('ipam_addresses.address as address')
                ->groupBy('scope_site');
        } else {
            $query->selectRaw(
                'CASE WHEN v.vlan_group_id > 0 THEN 0'
                . ' WHEN dom.stretch_id IS NOT NULL THEN COALESCE(v.vlan_id, 0)'
                . ' ELSE ipam_addresses.vlan_record_id END as scope_vlan'
            )
                ->selectRaw('CASE WHEN v.vlan_group_id > 0 THEN v.vlan_group_id ELSE 0 END as scope_group')
                // a stretched subnet is a scope of its own: the same address
                // inside one, in the same vlan, at two of its places, is one
                // conflict. COALESCE rather than a CASE because a row with no
                // domain has to read as 0, and NULL is not 0 in a group by
                ->selectRaw('COALESCE(dom.stretch_id, 0) as scope_network')
                ->selectRaw(
                    'CASE WHEN v.vlan_group_id > 0 OR dom.stretch_id IS NOT NULL'
                    . ' THEN 0 ELSE ipam_addresses.site_id END as scope_site'
                )
                ->selectRaw('ipam_addresses.family as family')
                ->selectRaw('ipam_addresses.address as address')
                // the join is over the record the address was reported under;
                // every record of the group carries the same group id, so each
                // address lands in its domain whichever site reported it
                ->leftJoin('ipam_vlans as v', 'v.vlan_record_id', '=', 'ipam_addresses.vlan_record_id')
                // and dom says whether this occurrence sits in a stretched
                // subnet at one of that domain's places
                // left, not inner: an occurrence outside every stretched
                // subnet has no row in dom and must still be in the picture,
                // scoped the way it always was
                ->joinSub(
                    $this->domainSubquery(),
                    'dom',
                    'dom.address_record_id',
                    '=',
                    'ipam_addresses.address_record_id',
                    'left'
                )
                ->groupBy('scope_vlan')
                ->groupBy('scope_group')
                ->groupBy('scope_network')
                ->groupBy('scope_site');
        }

        // An ignored address is deliberately out of the picture, so it cannot be
        // half of a conflict either. 127.0.0.1 on three devices is loopback, not
        // a three-way collision, and that is the whole reason the exclusions
        // exist: the report was burying real findings under them.
        //
        // Every column here carries its table: the vlan join puts two tables
        // behind these names, and an unqualified one is an ambiguous-column
        // error on the first query.
        $query->where('ipam_addresses.ignored', false);

        if ($this->ignoreUnscoped) {
            // Placeable means having at least one of the two: an address with a
            // site and no vlan still lives in a place, and one with a vlan and
            // no site still lives in a broadcast domain. Only a row with
            // neither is unclassifiable, which is what the queue of the same
            // name holds.
            //
            // Written as an OR it was once an AND in spirit — the query dropped
            // every row missing either, and 41 real findings (a wlan gateway
            // carried by three access points, a failover pair's whole public
            // /29) stayed invisible while the report said "nothing".
            $query->where(function ($inner) use ($vlanColumn): void {
                $inner->where('ipam_addresses.site_id', '>', 0);

                if ($vlanColumn !== null) {
                    $inner->orWhere('ipam_addresses.vlan_record_id', '>', 0);
                }
            });
        }

        if ($siteId !== null) {
            $query->where('ipam_addresses.site_id', $siteId);
        }

        if ($vlanColumn !== null && $vlanRecordId !== null) {
            $query->where('ipam_addresses.vlan_record_id', $vlanRecordId);
        }

        return $query;
    }

    /**
     * Per address: the stretched subnet it sits in, when it sits in one.
     *
     * A derived table rather than a join straight onto the places, because one
     * address can match twice — its site is a place and its room is a place —
     * and a join that doubled its rows would report every occurrence of every
     * address in a stretched subnet as a duplicate of itself. MIN collapses the
     * matches to one row, which is all the scope needs.
     *
     * The block's own stretch_id is what makes the containment work: declaring a
     * /16 stretched puts the id on every block below it, so an address inside a
     * described /24 of that /16 is in the domain without a tree walk here.
     */
    private function domainSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('ipam_addresses as a')
            ->join('ipam_networks as n', 'n.network_id', '=', 'a.network_id')
            ->join('ipam_network_places as p', 'p.stretch_id', '=', 'n.stretch_id')
            ->where('n.stretch_id', '>', 0)
            ->where(function ($join): void {
                // where for the kind, not whereColumn: a whereColumn with a
                // word in it emits that word unquoted, so MySQL reads 'site' as
                // a column name and the whole report dies on it. Only the id is a
                // column comparison.
                $join->where(function ($sites): void {
                    $sites->where('p.place_type', NetworkPlace::SITE)
                        ->whereColumn('p.place_id', '=', 'a.site_id');
                })->orWhere(function ($rooms): void {
                    $rooms->where('p.place_type', NetworkPlace::LOCATION)
                        ->whereColumn('p.place_id', '=', 'a.location_id');
                });
            })
            ->select('a.address_record_id')
            ->selectRaw('MIN(p.stretch_id) as stretch_id')
            ->groupBy('a.address_record_id');
    }

    /**
     * One finding, with the occurrences that make it up.
     *
     * Three ways an occurrence can be in scope, and the occurrences that prove
     * it differ in each: a stretched vlan carries the same address at several
     * sites, so a group scope takes them from every record of the group; a
     * stretched subnet carries the same address at several places, so a domain
     * scope takes them from every block inside the domain, in the same vlan
     * *number* — two records of vlan 999 at two sites are two rows in the
     * inventory and one broadcast domain once the subnet says it is carried.
     */
    private function buildGroup(
        int $family,
        string $address,
        int $vlanRecordId,
        int $siteId,
        int $vlanGroupId = 0,
        int $stretchId = 0
    ): ?DuplicateGroup {
        $query = Address::query()
            ->where('family', $family)
            ->where('address', $address);

        if ($stretchId > 0) {
            // the vlan number the grouping used is carried in the vlan column
            // for this branch, so it is read as a number rather than an id
            $vlanNumber = $vlanRecordId;

            $query->whereHas('network', fn (Builder $inner): Builder => $inner->where('stretch_id', $stretchId))
                ->where($this->atAnyPlaceOf($stretchId));

            if ($vlanNumber > 0) {
                $query->whereHas('vlan', fn (Builder $inner): Builder => $inner->where('vlan_id', $vlanNumber));
            }
        } elseif ($vlanGroupId > 0) {
            $records = Vlan::query()->where('vlan_group_id', $vlanGroupId)->pluck('vlan_record_id');
            $query->whereIn('vlan_record_id', $records);
        } else {
            $query->where('site_id', $siteId);

            if ($this->useVlanScope) {
                $query->where('vlan_record_id', $vlanRecordId);
            }
        }

        $occurrences = $query->orderBy('device_id')->orderBy('port_id')->get();

        if ($occurrences->count() < 2) {
            return null;
        }

        $note = Duplicate::query()
            ->where('family', $family)
            ->where('address', $address)
            ->where('vlan_group_id', $vlanGroupId)
            ->where('stretch_id', $stretchId)
            ->where('site_id', $vlanGroupId > 0 || $stretchId > 0 ? 0 : $siteId)
            ->when($this->useVlanScope && $vlanGroupId === 0 && $stretchId === 0, fn ($q) => $q->where('vlan_record_id', $vlanRecordId))
            ->first();

        $group = new DuplicateGroup(
            family: $family,
            address: $address,
            siteId: $siteId,
            vlanRecordId: $this->useVlanScope ? $vlanRecordId : null,
            vlanGroupId: $vlanGroupId,
            stretchId: $stretchId,
            vlanNumber: $stretchId > 0 ? $vlanRecordId : 0,
            occurrences: $occurrences,
            note: $note?->note,
            ignored: (bool) $note?->ignored,
        );

        return $group;
    }

    /**
     * "at one of the places this domain reaches", as a condition.
     *
     * An empty list of places has to match nothing rather than everything: a
     * domain with no places is a domain nobody finished declaring, and reporting
     * every occurrence of an address as a duplicate of itself would be worse
     * than reporting nothing.
     */
    private function atAnyPlaceOf(int $stretchId): Closure
    {
        $places = NetworkPlace::query()->where('stretch_id', $stretchId)->get();

        return function (Builder $inner) use ($places): void {
            if ($places->isEmpty()) {
                $inner->whereRaw('1 = 0');

                return;
            }

            foreach ($places as $place) {
                $column = $place->place_type === NetworkPlace::SITE ? 'site_id' : 'location_id';

                $inner->orWhere(fn (Builder $branch): Builder => $branch->where($column, (int) $place->place_id));
            }
        };
    }

    /**
     * How many addresses repeat inside a scope, for the dashboard counters.
     */
    public function count(): int
    {
        return $this->groupQuery(null, $this->useVlanScope ? 'vlan_record_id' : null, PHP_INT_MAX)->count();
    }

    /**
     * Occurrences with no scope at all: the queue of addresses still waiting for
     * somebody to say where they belong.
     *
     * In this install that is most of them, because 455 of 474 addresses sit on
     * a port with no vlan and a block that nobody has described yet.
     */
    /**
     * @return list<Address>
     */
    public function unclassified(int $limit = 500): array
    {
        return Address::query()
            ->unscoped()
            // loopback and friends are unclassified by nature; leaving them in
            // the queue would make it look like there is work to do when there
            // is none
            ->where('ignored', false)
            ->orderBy('family')
            ->orderBy('address')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function unclassifiedCount(): int
    {
        return Address::query()
            ->unscoped()
            ->where('ignored', false)
            ->count();
    }

    /**
     * Addresses seen on more than one device inside one scope, which is a
     * stronger signal than a repeat on the same device: it means two systems
     * claim the address and only one of them gets the traffic.
     */
    public function crossDeviceCount(): int
    {
        $vlanColumn = $this->useVlanScope ? 'vlan_record_id' : null;

        $query = Address::query()
            ->select(['address', 'family', 'site_id'])
            ->selectRaw('COUNT(DISTINCT device_id) as devices')
            ->groupBy(['address', 'family', 'site_id', ...($vlanColumn ? [$vlanColumn] : [])])
            ->having('devices', '>', 1)
            // the same rule as the duplicate groups: ignored is out of the report
            ->where('ignored', false);

        if ($this->ignoreUnscoped) {
            $query->where('site_id', '>', 0);
        }

        return DB::query()->fromSub($query, 't')->count();
    }
}
