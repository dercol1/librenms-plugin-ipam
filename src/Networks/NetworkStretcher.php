<?php

namespace Dercol1\LibrenmsIpam\Networks;

use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkPlace;
use Dercol1\LibrenmsIpam\Models\NetworkStretch;
use Dercol1\LibrenmsIpam\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which subnets exist unchanged at more than one place, and what that means.
 *
 * A block is one row however many places it is at — the range does not change,
 * only where it exists — so "stretched" is a statement hung on the block rather
 * than a property of it. The places it reaches are on the domain, and the
 * account of how it gets there (dark fibre, an E-Line, QinQ inside an outer
 * 802.1ad tag) is its description, because nothing in a poll reports that and
 * an operator would otherwise keep it in a spreadsheet.
 *
 * Three consequences follow, and all three are the point:
 *
 *   the collision scope stops being the site. One subnet carried at layer 2 has
 *   one copy of every address, so the same address at two of its places is one
 *   conflict, not two unrelated rows.
 *
 *   the places are the operator's real ones, and only those. A site nobody
 *   chose — the placeholder LocationResolver makes for a sysLocation still
 *   unfiled, named after its own room — is not somewhere a segment goes, and a
 *   room that belongs to no site is exactly the kind of thing that does get
 *   carried somewhere on its own.
 *
 *   nothing here is derived. The import may create the block, and does, but it
 *   never guesses that a subnet spans buildings, and never takes this back.
 */
class NetworkStretcher
{
    /**
     * The places this range could be stretched to.
     *
     * Two kinds, and the difference matters: a whole site for a segment that
     * reaches every room of it, a single room for one that reaches only that
     * cabinet. The places already on the domain are listed first and ticked,
     * so an operator editing the list starts from what is actually there rather
     * than from what the heuristics still recognise.
     *
     * @param  list<array{id: int, name: string}>  $rooms  the unassigned rooms carrying this range
     * @return list<array{key: string, type: string, id: int, name: string, rooms: int, taken: bool}>
     */
    public function candidates(Network $block, array $rooms = []): array
    {
        $candidates = [];

        foreach ($this->currentPlaces($block) as $place) {
            $candidates[$place->key()] = [
                'key' => $place->key(),
                'type' => (string) $place->place_type,
                'id' => (int) $place->place_id,
                'name' => $place->placeName(),
                'rooms' => 0,
                'taken' => true,
            ];
        }

        // A defined site is one an operator built: renamed, given an address, or
        // holding more than one room. A placeholder is a sysLocation with a name
        // on it, and offering it as a destination would fill the list with the
        // estate's own bookkeeping.
        $sites = Site::query()
            ->with('locations')
            ->where('auto_created', false)
            ->whereHas('locations')
            ->orderBy('name')
            ->get();

        foreach ($sites as $site) {
            $key = NetworkPlace::SITE . ':' . $site->site_id;

            $candidates[$key] ??= [
                'key' => $key,
                'type' => NetworkPlace::SITE,
                'id' => (int) $site->site_id,
                'name' => (string) $site->name,
                'rooms' => $site->locations->count(),
                'taken' => false,
            ];
        }

        // the rooms that carry this range and belong to no site
        foreach ($rooms as $room) {
            $key = NetworkPlace::LOCATION . ':' . $room['id'];

            $candidates[$key] ??= [
                'key' => $key,
                'type' => NetworkPlace::LOCATION,
                'id' => (int) $room['id'],
                'name' => (string) $room['name'],
                'rooms' => 0,
                'taken' => false,
            ];
        }

        // sites first, rooms after: a site is the coarser truth and reads as
        // the headline of the list
        usort($candidates, function (array $a, array $b): int {
            return [$a['type'] === NetworkPlace::LOCATION ? 1 : 0, mb_strtolower((string) $a['name'])]
                <=> [$b['type'] === NetworkPlace::LOCATION ? 1 : 0, mb_strtolower((string) $b['name'])];
        });

        return $candidates;
    }

    /**
     * The unassigned rooms carrying a range, for a whole page of blocks at once.
     *
     * Answered by walking up from the blocks that hold such an address rather
     * than down from every block on the page: a room that carries nothing is
     * the normal case, so the expensive direction has nothing to do on an
     * install with no unassigned rooms at all.
     *
     * @param  list<Network>  $blocks
     * @return array<int, list<array{id: int, name: string}>> network id to the rooms
     */
    public function roomsCarrying(array $blocks): array
    {
        $unassigned = Location::query()->where('site_id', 0)->orderBy('name')->get();

        if ($unassigned->isEmpty()) {
            return [];
        }

        $holders = DB::table('ipam_addresses')
            ->select('network_id', 'location_id')
            ->whereIn('location_id', $unassigned->pluck('location_id')->all())
            ->distinct()
            ->get()
            ->all();

        if ($holders === []) {
            return [];
        }

        $names = $unassigned->pluck('name', 'location_id');
        $found = [];

        foreach ($holders as $holder) {
            $node = Network::query()->find((int) $holder->network_id);
            $seen = [];

            // up the tree, so a range that only holds the room in a described
            // /24 is still offered the room when the operator looks at the /16
            while ($node !== null && ! isset($seen[(int) $node->network_id])) {
                $seen[(int) $node->network_id] = true;

                $locationId = (int) $holder->location_id;

                $found[(int) $node->network_id][] = [
                    'id' => $locationId,
                    'name' => (string) ($names[$locationId] ?? '?'),
                ];

                $node = (int) $node->parent_id === 0
                    ? null
                    : Network::query()->find((int) $node->parent_id);
            }
        }

        return $found;
    }

    /**
     * Say that this range is one domain at the given places.
     *
     * @param  list<string>  $keys  "site:12" or "location:34"
     * @return array{0: int, 1: ?string} the domain id, or null and the reason it was refused
     */
    public function stretch(Network $block, array $keys, ?string $description): array
    {
        $inside = $this->stretchedAncestor($block);

        if ($inside !== null) {
            return [0, __('ipam::ipam.networks.already_stretched', ['cidr' => $inside->cidr])];
        }

        $places = $this->resolvePlaces($keys);

        if ($places === []) {
            return [0, (string) __('ipam::ipam.networks.no_places')];
        }

        $domain = NetworkStretch::query()->firstOrNew(['network_id' => $block->network_id]);

        if (! $domain->exists) {
            $domain->name = $block->cidr;
        }

        if ($description !== null && $description !== '') {
            $domain->description = $description;
        }

        $domain->save();

        // the ticked list is the list: an unticked place leaves the domain
        NetworkPlace::query()->where('stretch_id', $domain->stretch_id)->delete();

        foreach ($places as $place) {
            NetworkPlace::query()->create([
                'stretch_id' => $domain->stretch_id,
                'place_type' => $place[0],
                'place_id' => $place[1],
            ]);
        }

        $this->applyToSubtree($block, (int) $domain->stretch_id);

        return [(int) $domain->stretch_id, null];
    }

    /**
     * Take the statement back.
     *
     * The domain goes with it, and every block of the subtree that was in it is
     * released. Only the blocks carrying this domain are touched: a block can
     * also sit inside a wider stretched range, and releasing that would be a
     * different statement being quietly undone.
     */
    public function unstretch(Network $block): bool
    {
        $stretchId = (int) $block->stretch_id;

        if ($stretchId === 0) {
            return false;
        }

        NetworkPlace::query()->where('stretch_id', $stretchId)->delete();
        Network::query()
            ->whereIn('network_id', $block->subtreeIds())
            ->where('stretch_id', $stretchId)
            ->update(['stretch_id' => 0]);
        NetworkStretch::query()->whereKey($stretchId)->delete();

        return true;
    }

    /**
     * The account of the transport, kept on the domain.
     */
    public function describe(Network $block, string $description): bool
    {
        $stretchId = (int) $block->stretch_id;

        if ($stretchId === 0) {
            return false;
        }

        NetworkStretch::query()->whereKey($stretchId)->update(['description' => $description]);

        return true;
    }

    /**
     * The domain this range is in, places included.
     */
    public function domainOf(Network $block): ?NetworkStretch
    {
        if ((int) $block->stretch_id === 0) {
            return null;
        }

        return NetworkStretch::query()->with('places')->find((int) $block->stretch_id);
    }

    /**
     * The places currently on the domain, in the order they were added.
     *
     * @return Collection<int, NetworkPlace>
     */
    public function currentPlaces(Network $block): Collection
    {
        if ((int) $block->stretch_id === 0) {
            return collect();
        }

        return NetworkPlace::query()->where('stretch_id', (int) $block->stretch_id)->get();
    }

    /**
     * The nearest range above this one that is already stretched, if any.
     *
     * Nesting is refused rather than merged: a /24 inside a stretched /16 is in
     * that domain by inheritance, and saying it is in another one as well would
     * leave two answers to "which places does this reach".
     */
    public function stretchedAncestor(Network $block): ?Network
    {
        $seen = [];
        $current = (int) $block->parent_id === 0
            ? null
            : Network::query()->find((int) $block->parent_id);

        while ($current !== null && ! isset($seen[(int) $current->network_id])) {
            $seen[(int) $current->network_id] = true;

            if ((int) $current->stretch_id > 0) {
                return $current;
            }

            $current = (int) $current->parent_id === 0
                ? null
                : Network::query()->find((int) $current->parent_id);
        }

        return null;
    }

    /**
     * Put the whole subtree in the domain.
     *
     * Eagerly, on the action itself, for the same reason hidden is a plain
     * column: a /24 inside a stretched /16 is in the domain without anything
     * having to walk the tree at report time to work it out.
     */
    private function applyToSubtree(Network $block, int $stretchId): void
    {
        Network::query()
            ->whereIn('network_id', $block->subtreeIds())
            ->update(['stretch_id' => $stretchId]);
    }

    /**
     * Turn the ticked keys into places that exist.
     *
     * A key that names nothing is dropped rather than stored: the form is built
     * from what exists, so a key that does not is either a stale page or a
     * crafted request, and neither is worth a row.
     *
     * @param  list<string>  $keys
     * @return list<array{0: string, 1: int}>
     */
    private function resolvePlaces(array $keys): array
    {
        $places = [];

        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, '0');
            $id = (int) $id;

            if ($id <= 0) {
                continue;
            }

            if ($type === NetworkPlace::SITE && Site::query()->whereKey($id)->exists()) {
                $places[NetworkPlace::SITE . ':' . $id] = [NetworkPlace::SITE, $id];

                continue;
            }

            if ($type === NetworkPlace::LOCATION && Location::query()->whereKey($id)->exists()) {
                $places[NetworkPlace::LOCATION . ':' . $id] = [NetworkPlace::LOCATION, $id];
            }
        }

        return array_values($places);
    }
}
