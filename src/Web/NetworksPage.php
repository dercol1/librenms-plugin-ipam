<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkFunction;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * View data for the network map.
 *
 * Kept as a class of its own rather than private methods on the controller,
 * because the query builder is also what the json tree endpoint reads. Two
 * builders for one view once disagreed on the data key name, and the page
 * rendered empty with no error anywhere, so the list is built in exactly one
 * place.
 */
final class NetworksPage
{
    /**
     * The block list, narrowed by whichever filters are present.
     *
     * Extracted so the controller and the page hook cannot drift apart: two
     * builders for one view once disagreed on the data key name, and the
     * controller path rendered an empty page with no error anywhere.
     *
     * @return Builder<Network>
     */
    public static function query(Request $request): Builder
    {
        $query = Network::query()
            ->with(['owner', 'function'])
            // the list shows how many addresses each block holds, and counting
            // them per row from the view is a query per row
            ->withCount('addresses')
            ->orderBy('family')
            ->orderBy('prefixlen')
            ->orderBy('network');

        // blocks nobody wants to see stay out unless the page was asked
        $query = Visibility::shown($query, $request);

        if ($request->filled('q')) {
            $search = (string) $request->input('q');

            $query->where(function ($q) use ($search): void {
                $q->where('cidr', 'like', "%$search%")
                    ->orWhere('description', 'like', "%$search%");
            });
        }

        SiteScope::apply($query, $request);

        if ($request->filled('owner_id')) {
            $query->where('net_owner_id', (int) $request->input('owner_id'));
        }

        if ($request->filled('function_id')) {
            $query->where('net_function_id', (int) $request->input('function_id'));
        }

        if ($request->filled('described')) {
            // "described" means somebody said something about this range
            $request->input('described') === 'yes'
                ? $query->where(function ($q): void {
                    $q->where('site_id', '>', 0)->orWhere('net_owner_id', '>', 0);
                })
                : $query->where('site_id', 0)->where('net_owner_id', 0);
        }

        // the sort the headers asked for. never a raw column name from the
        // request: it would be an injection point, so the column is looked up.
        $sortable = [
            'cidr' => null, // handled on its own: the binary, not the string
            'site' => 'site_id',
            'owner' => 'net_owner_id',
            'function' => 'net_function_id',
            'addresses' => 'addresses_count',
        ];

        $requested = (string) $request->input('order_by');

        if ($requested === 'cidr' && $request->filled('order_by')) {
            $direction = strtolower((string) $request->input('order_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

            // packed_start is the network in binary: sorting on it is the
            // numeric order, which is the order an address plan reads in
            $query->reOrder()->orderBy('family', $direction)->orderBy('packed_start', $direction);
        } elseif (isset($sortable[$requested]) && $request->filled('order_by')) {
            $direction = strtolower((string) $request->input('order_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

            // addresses_count is the withCount alias, and sorting on it works
            // like any other column once the query has selected the models
            $query->reOrder()->orderBy($sortable[$requested], $direction);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function data(array $stored, ?Request $request = null): array
    {
        $request ??= request();
        $settings = Settings::fromArray($stored);

        $missing = SchemaInstaller::missing();

        if ($missing !== []) {
            return [
                'title' => __('ipam::ipam.page_titles.networks'),
                'missing' => $missing,
                'networks' => null,
                'options' => ['sites' => [], 'rooms' => [], 'owners' => [], 'functions' => []],
                'directory' => SiteDirectory::load(),
                'blocksPerSite' => [],
                'addressesPerSite' => [],
                'summary' => [],
                'perPage' => $settings->perPage(),
            ];
        }

        $directory = SiteDirectory::load();

        // The rooms, for the two forms that can attribute a range to one. A site
        // says which building, a room which cabinet: a segment carried to one
        // cabinet of a site is at the site but not in the building's room.
        $rooms = Location::query()->orderBy('name')->pluck('name', 'location_id')
            ->map(fn ($name): string => (string) $name)->all();

        // counted per site so the list can say how much of the estate each row
        // holds, and how much of it still needs describing
        $blocksPerSite = DB::table('ipam_networks')
            ->select('site_id', DB::raw('COUNT(*) as blocks'))
            ->groupBy('site_id')
            ->pluck('blocks', 'site_id')
            ->all();

        $addressesPerSite = DB::table('ipam_addresses')
            ->select('site_id', DB::raw('COUNT(DISTINCT address) as addresses'))
            ->groupBy('site_id')
            ->pluck('addresses', 'site_id')
            ->all();

        return [
            'title' => __('ipam::ipam.page_titles.networks'),
            'missing' => null,
            'networks' => self::query($request)->paginate($settings->perPage())->withQueryString(),
            'options' => [
                'sites' => $directory->options(),
                'rooms' => $rooms,
                'owners' => NetworkOwner::query()->orderBy('name')->pluck('name', 'net_owner_id'),
                'functions' => NetworkFunction::query()->orderBy('name')->pluck('name', 'net_function_id'),
            ],
            'directory' => $directory,
            'blocksPerSite' => array_map(fn ($n): int => (int) $n, $blocksPerSite),
            'addressesPerSite' => array_map(fn ($n): int => (int) $n, $addressesPerSite),
            'summary' => [
                'blocks' => Network::query()->where('ignored', false)->count(),
                'described' => Network::query()->where('ignored', false)->where(function ($q): void {
                    $q->where('site_id', '>', 0)->orWhere('net_owner_id', '>', 0);
                })->count(),
                'addresses' => (int) DB::table('ipam_addresses')->where('ignored', false)->count(),
                'ignored_addresses' => (int) DB::table('ipam_addresses')->where('ignored', true)->count(),
            ],
            'perPage' => $settings->perPage(),
        ];
    }
}
