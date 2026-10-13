<?php

namespace Dercol1\LibrenmsIpam\Sites;

use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The grouping of LibreNMS locations into sites.
 *
 * This exists because devices do not report a site. They report a free text
 * sysLocation, and every switch in a room phrases it differently. This install
 * has 38 of those strings for about five real places: ten of them name
 * Galazzano and five name Piazzetta del Titano.
 *
 * Taken one at a time each string is its own site, which is not wrong so much as
 * useless. It makes the same vlan look like several broadcast domains, so two
 * switches in one building never get reconciled and the collision report fills
 * up with pairs that are really the same network described twice.
 *
 * So the rollup is a decision, and this class does two things around it:
 *
 *   suggest()  proposes groups, so the operator starts from something
 *   merge()    applies one, moving the rows as well as the locations
 *
 * suggest() deliberately over-groups rather than under-groups. A suggestion the
 * operator rejects costs one click; a pair that was never suggested never gets
 * looked at, and those are the ones that hide a real duplicate.
 */
class SiteService
{
    /**
     * Words that say nothing about which place a device is in.
     *
     * "Galazzano Sala server A" and "SSIS Galazzano (Sala server B)" are one
     * building, and the only word that says so is "galazzano". "sala", "server",
     * "rack" and the rest are furniture and would otherwise group every room in
     * the estate into one site.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'sala', 'server', 'armadio', 'rack', 'stanza', 'ufficio', 'office', 'room',
        'magazzino', 'deposito', 'sede', 'plant', 'floor', 'piano', 'corridoio',
        'unknown', 'default', 'sede principale', 'principale', 'headquarters',
        'laboratorio', 'magazzino', 'deposito', 'garage', 'bagni', 'toilets',
        'corridoio', 'hallway', 'stairs', 'scala', 'ascensore', 'reception',
    ];

    /**
     * Proposals for locations that look like they belong to one site.
     *
     * Locations are linked when they share a word worth linking on, and the
     * links are then closed transitively: if A shares a word with B and B with C,
     * all three are proposed together, because that is the shape of the problem
     * ("ten spellings of one place") rather than a pairwise decision.
     *
     * @return list<array{name: string, locations: list<array{id: int, name: string, site: string, site_id: int}>, sites: list<string>, site_ids: list<int>, reason: string}>
     */
    public function suggest(): array
    {
        $locations = Location::query()
            ->with('site')
            ->orderBy('name')
            ->get();

        if ($locations->isEmpty()) {
            return [];
        }

        // word to the locations carrying it
        $byWord = [];

        foreach ($locations as $location) {
            foreach ($this->significantWords((string) $location->name) as $word) {
                $byWord[$word][] = $location;
            }
        }

        // only words that actually discriminate: a word on a single location links
        // nothing, and a word on every location would group the whole estate
        // into one site. Everything in between is worth proposing on.
        $byWord = array_filter(
            $byWord,
            fn (array $carrying): bool => count($carrying) > 1 && count($carrying) < $locations->count()
        );

        if ($byWord === []) {
            return [];
        }

        $parent = [];

        foreach ($locations as $location) {
            $parent[(int) $location->location_id] = (int) $location->location_id;
        }

        foreach ($byWord as $carrying) {
            $ids = array_map(fn (Location $l): int => (int) $l->location_id, $carrying);

            foreach (array_slice($ids, 1) as $id) {
                $this->union($parent, $ids[0], $id);
            }
        }

        $groups = [];

        foreach ($locations as $location) {
            $root = $this->find($parent, (int) $location->location_id);
            $groups[$root][] = $location;
        }

        $suggestions = [];

        foreach ($groups as $rows) {
            $group = collect($rows);

            // a group of one is not a proposal, and a group where everything
            // already sits in one site has nothing left to gain
            if ($group->count() < 2) {
                continue;
            }

            $siteIds = $group->pluck('site_id')->map(fn ($id): int => (int) $id)->unique();

            if ($siteIds->count() < 2) {
                continue;
            }

            $suggestions[] = [
                'name' => $this->commonWord($group, array_keys($byWord)) ?? (string) $group->first()->name,
                'reason' => $this->reason($group, array_keys($byWord)),
                'sites' => Site::query()->whereIn('site_id', $siteIds->all())->orderBy('name')->pluck('name')->all(),
                'locations' => $group
                    ->sortBy('name')
                    ->map(fn (Location $l): array => [
                        'id' => (int) $l->location_id,
                        'name' => (string) $l->name,
                        'site' => (string) ($l->site->name ?? ''),
                        'site_id' => (int) $l->site_id,
                    ])->values()->all(),
                'site_ids' => $siteIds->values()->all(),
            ];
        }

        // the most convincing proposal first: the one that collapses the most sites
        usort($suggestions, fn (array $a, array $b): int => count($b['locations']) <=> count($a['locations']));

        return $suggestions;
    }

    /**
     * Fold one site into another, moving everything that pointed at it.
     *
     * The locations move, and so do the vlans, blocks and addresses that carried
     * the old site id. Repointing them matters: a vlan is keyed by
     * (id, domain, site), so leaving half of the rows on the old id would
     * produce two records for one broadcast domain, which is the exact thing
     * the grouping exists to prevent.
     *
     * @return array<string, int> how many rows were repointed, per table
     */
    public function merge(int $keepSiteId, int $dropSiteId): array
    {
        if ($keepSiteId === $dropSiteId) {
            return [];
        }

        if (! Site::query()->whereKey($keepSiteId)->exists() || ! Site::query()->whereKey($dropSiteId)->exists()) {
            return [];
        }

        $moved = [
            'ipam_locations' => Location::query()->where('site_id', $dropSiteId)->update(['site_id' => $keepSiteId]),
            'ipam_networks' => DB::table('ipam_networks')->where('site_id', $dropSiteId)->update(['site_id' => $keepSiteId]),
            'ipam_addresses' => DB::table('ipam_addresses')->where('site_id', $dropSiteId)->update(['site_id' => $keepSiteId]),
            'ipam_duplicates' => DB::table('ipam_duplicates')->where('site_id', $dropSiteId)->update(['site_id' => $keepSiteId]),
        ];

        // The vlans need care. Their unique key is (vlan_id, vlan_domain, site_id),
        // so moving a site onto another can land two rows on the same key. Those
        // are the same vlan seen in two rooms, which is what the merge is for, so
        // they have to collapse into one record and the rows pointing at the
        // loser have to follow the winner.
        //
        // ipam_networks is not touched here: a block stores the bare vlan_id, not
        // a vlan_record_id, and it already got the right site_id from the
        // repoint above.
        $collisions = DB::table('ipam_vlans')
            ->select('vlan_id', 'vlan_domain')
            ->whereIn('site_id', [$keepSiteId, $dropSiteId])
            ->groupBy('vlan_id', 'vlan_domain')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('vlan_id')
            ->all();

        $moved['ipam_vlans_merged'] = 0;
        $moved['ipam_addresses_repointed'] = 0;

        foreach ($collisions as $vlanId) {
            $winner = DB::table('ipam_vlans')
                ->where('vlan_id', $vlanId)
                ->whereIn('site_id', [$keepSiteId, $dropSiteId])
                // a described record beats an auto created one, then the older,
                // so the survivor is the one an operator has put work into
                ->orderByDesc('auto_created')
                ->orderBy('vlan_record_id')
                ->first();

            if ($winner === null) {
                continue;
            }

            $losers = DB::table('ipam_vlans')
                ->where('vlan_id', $vlanId)
                ->whereIn('site_id', [$keepSiteId, $dropSiteId])
                ->where('vlan_record_id', '!=', $winner->vlan_record_id)
                ->pluck('vlan_record_id')
                ->all();

            foreach ($losers as $loser) {
                $moved['ipam_addresses_repointed'] += DB::table('ipam_addresses')
                    ->where('vlan_record_id', $loser)
                    ->update(['vlan_record_id' => $winner->vlan_record_id]);

                DB::table('ipam_vlan_locations')->where('vlan_record_id', $loser)->delete();
                DB::table('ipam_vlan_names')->where('vlan_record_id', $loser)->delete();
                DB::table('ipam_vlans')->where('vlan_record_id', $loser)->delete();

                $moved['ipam_vlans_merged']++;
            }
        }

        $moved['ipam_vlans'] = DB::table('ipam_vlans')->where('site_id', $dropSiteId)->update(['site_id' => $keepSiteId]);

        Site::query()->whereKey($dropSiteId)->delete();

        return $moved;
    }

    /**
     * Move every location of a set of sites into one site.
     *
     * Used by the one-click proposal: the operator accepts a group of locations
     * and the sites that currently hold them go away.
     *
     * @param  list<int>  $siteIds  the sites to fold in, the target excluded
     * @return array<string, int>
     */
    public function foldInto(int $targetSiteId, array $siteIds): array
    {
        $total = [];

        foreach (array_unique($siteIds) as $siteId) {
            if ($siteId === $targetSiteId) {
                continue;
            }

            foreach ($this->merge($targetSiteId, $siteId) as $table => $count) {
                $total[$table] = ($total[$table] ?? 0) + $count;
            }
        }

        return $total;
    }

    /**
     * Put one location under a different site.
     *
     * Moving a location out of its site leaves the site holding nothing, which
     * is allowed: an operator is part way through reorganising and a half empty
     * site is not a problem. What is not allowed is losing the location, so the
     * check is on the row existing rather than on the site.
     */
    public function moveLocation(int $locationId, int $siteId): bool
    {
        if (! Location::query()->whereKey($locationId)->exists()) {
            return false;
        }

        if ($siteId !== 0 && ! Site::query()->whereKey($siteId)->exists()) {
            return false;
        }

        Location::query()->whereKey($locationId)->update(['site_id' => $siteId]);

        return true;
    }

    /**
     * Sites holding no location, no vlan and no address.
     *
     * They are the residue of a previous merge: the site is a name that no
     * longer describes anything.
     *
     * @return Collection<int, Site>
     */
    public function emptySites(): Collection
    {
        return Site::query()
            ->whereDoesntHave('locations')
            ->whereDoesntHave('vlans')
            ->whereDoesntHave('addresses')
            ->whereDoesntHave('networks')
            ->orderBy('name')
            ->get();
    }

    /**
     * Words in a location string worth grouping on.
     *
     * @return list<string>
     */
    private function significantWords(string $name): array
    {
        // a pair of coordinates is a device that had nothing better to say, and
        // every one of them would group with every other
        if (str_starts_with(trim($name), '[') || preg_match('/^-?\d+\.\d+\s*,\s*-?\d+\.\d+$/', trim($name))) {
            return [];
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: [];

        return array_values(array_filter(
            $words,
            function (string $word): bool {
                if (mb_strlen($word) < 4 || in_array($word, self::STOPWORDS, true)) {
                    return false;
                }

                // "22" or "1000" locate nothing
                return (bool) preg_match('/\p{L}/u', $word);
            }
        ));
    }

    /**
     * The word that ties a group together, for naming the proposal.
     *
     * @param  Collection<int, Location>  $group
     * @param  list<string>  $words
     */
    private function commonWord(Collection $group, array $words): ?string
    {
        $counted = [];

        foreach ($group as $location) {
            foreach ($this->significantWords((string) $location->name) as $word) {
                if (in_array($word, $words, true)) {
                    $counted[$word] = ($counted[$word] ?? 0) + 1;
                }
            }
        }

        arsort($counted);

        return $counted === [] ? null : (string) array_key_first($counted);
    }

    /**
     * A sentence saying why these were proposed together.
     *
     * @param  Collection<int, Location>  $group
     * @param  list<string>  $words
     */
    private function reason(Collection $group, array $words): string
    {
        $word = $this->commonWord($group, $words);

        return $word === null
            ? (string) __('ipam::ipam.sites.suggest_same_site')
            : (string) __('ipam::ipam.sites.suggest_shared_word', ['word' => $word]);
    }

    /**
     * @param  array<int, int>  $parent
     */
    private function find(array &$parent, int $id): int
    {
        while ($parent[$id] !== $id) {
            // path compression, so a long chain does not become quadratic
            $parent[$id] = $parent[$parent[$id]];
            $id = $parent[$id];
        }

        return $id;
    }

    /**
     * @param  array<int, int>  $parent
     */
    private function union(array &$parent, int $a, int $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);

        if ($rootA !== $rootB) {
            // the lower id wins, so the group root is stable between runs
            $parent[max($rootA, $rootB)] = min($rootA, $rootB);
        }
    }
}
