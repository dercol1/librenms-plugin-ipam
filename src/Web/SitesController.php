<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Sites\SiteService;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Sites and the LibreNMS locations that roll up into them.
 *
 * This is the one page that is not a report. It is where an operator decides
 * that "Galazzano Sala B" and "SSIS Galazzano (Sala server A)" are one building,
 * which is a judgement no amount of automation should make on their behalf. The
 * rest of the plugin then follows: a site is the scope a collision is judged in,
 * so correcting the grouping here corrects the collision report too.
 */
class SitesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeView();

        if (SchemaInstaller::missing() !== []) {
            return view('ipam::missing', ['missing' => SchemaInstaller::missing()]);
        }

        $service = new SiteService;

        return view('ipam::sites', [
            'title' => __('ipam::ipam.page_titles.sites'),
            'sites' => $this->siteRows($request),
            'suggestions' => $service->suggest(),
            'emptySites' => $service->emptySites(),
            'unassigned' => Location::query()->where('site_id', 0)->orderBy('name')->get(),
            'canManage' => IpamPermissions::canManage(),
            // loaded once and handed to the view, because the move-location
            // select needs the whole list and a query inside a loop would run
            // once per location on a page that has 38 of them
            'siteOptions' => Site::query()->orderBy('name')->pluck('name', 'site_id'),
            'summary' => [
                'sites' => Site::query()->count(),
                'locations' => Location::query()->count(),
                'grouped' => Location::query()->where('site_id', '>', 0)->count(),
                'vlans' => DB::table('ipam_vlans')->count(),
            ],
        ]);
    }

    /**
     * One row per site, with its locations and the counts that make a place
     * worth looking at.
     *
     * @return Collection<int, array{site: Site, locations: \Illuminate\Database\Eloquent\Collection<int, Location>, vlans: int<0, max>, addresses: int<0, max>, networks: int<0, max>}>
     */
    private function siteRows(Request $request): Collection
    {
        $sites = Site::query()
            ->with(['locations'])
            ->orderBy('name')
            ->get();

        if ($request->filled('site_id')) {
            $wanted = (int) $request->input('site_id');
            $sites = $sites->filter(fn (Site $s): bool => (int) $s->site_id === $wanted)->values();
        }

        if ($request->filled('q')) {
            $search = mb_strtolower((string) $request->input('q'));
            $sites = $sites->filter(function (Site $site) use ($search): bool {
                if (str_contains(mb_strtolower((string) $site->name), $search)) {
                    return true;
                }

                return $site->locations->contains(
                    fn (Location $l): bool => str_contains(mb_strtolower((string) $l->name), $search)
                );
            })->values();
        }

        return $sites->map(function (Site $site): array {
            $siteId = (int) $site->site_id;

            return [
                'site' => $site,
                'locations' => $site->locations->sortBy('name')->values(),
                'vlans' => DB::table('ipam_vlans')->where('site_id', $siteId)->count(),
                'addresses' => DB::table('ipam_addresses')->where('site_id', $siteId)->count(),
                'networks' => DB::table('ipam_networks')->where('site_id', $siteId)->count(),
            ];
        });
    }

    /**
     * Create a site by hand.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name' => 'required|string|max:128',
            'description' => 'nullable|string|max:255',
        ]);

        $service = new SiteService;

        // a name held by a leftover is released, a name held by a live site is
        // refused: firstOrCreate would have answered "created" while quietly
        // handing back the existing site, which is the same lie in a softer
        // voice
        $release = $service->releaseName($data['name'], 0);

        if ($release === 'taken') {
            return back()->with('error', __('ipam::ipam.sites.name_taken', ['name' => $data['name']]));
        }

        Site::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $message = $release === 'released'
            ? __('ipam::ipam.sites.name_released', ['name' => $data['name']])
            : __('ipam::ipam.created', ['what' => $data['name']]);

        return back()->with('success', $message);
    }

    public function update(Request $request, int $siteId): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name' => 'required|string|max:128',
            'description' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        $service = new SiteService;

        // site names are unique, so a rename onto a taken name has to be
        // answered here rather than by a 500 from the database constraint.
        // An empty holder is absorbed, a live one is refused.
        $release = $service->releaseName($data['name'], $siteId);

        if ($release === 'taken') {
            return back()->with('error', __('ipam::ipam.sites.name_taken', ['name' => $data['name']]));
        }

        Site::query()->whereKey($siteId)->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'address' => $data['address'] ?? null,
            // An operator has written something about this place, so from now on
            // it is one they defined. It stops being a sysLocation with a name
            // on it, which is what a placeholder is, and it becomes a place an
            // operator can stretch a subnet to.
            'auto_created' => false,
        ]);

        $message = $release === 'released'
            ? __('ipam::ipam.sites.name_released', ['name' => $data['name']])
            : __('ipam::ipam.saved', ['what' => $data['name']]);

        return back()->with('success', $message);
    }

    public function destroy(Request $request, int $siteId): RedirectResponse
    {
        $this->authorizeManage();

        $site = Site::query()->find($siteId);

        if ($site === null) {
            return back();
        }

        // a site holding data is not deleted, it is emptied, because deleting it
        // would orphan every vlan and address scoped to it
        $holdsData = DB::table('ipam_vlans')->where('site_id', $siteId)->exists()
            || DB::table('ipam_addresses')->where('site_id', $siteId)->exists();

        if ($holdsData) {
            return back()->with('error', __('ipam::ipam.sites.site_in_use'));
        }

        Location::query()->where('site_id', $siteId)->delete();
        $site->delete();

        return back()->with('success', __('ipam::ipam.deleted', ['what' => $site->name]));
    }

    /**
     * Move one location under a different site.
     *
     * The point of the page: a sysLocation that landed in its own site is moved
     * into the real one, and from that moment its addresses and vlans share a
     * scope with the rest of the building.
     */
    public function moveLocation(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'location_id' => 'required|integer',
            'site_id' => 'required|integer',
        ]);

        $result = (new SiteService)->moveLocation((int) $data['location_id'], (int) $data['site_id']);

        if (! $result['moved']) {
            return back()->with('error', __('ipam::ipam.sites.location_gone'));
        }

        // the site that lost its last location was removed with the move: the
        // operator is told, because a site disappearing has to be something
        // that happened on purpose and in the open
        return back()->with('success', $result['emptied'] !== null
            ? __('ipam::ipam.sites.site_emptied', ['name' => $result['emptied']->name])
            : __('ipam::ipam.saved', ['what' => 'location']));
    }

    /**
     * Accept a suggestion: fold every site in the group into one, named by the
     * operator.
     *
     * The name is part of the action rather than a detail of it. The sites being
     * folded are named after whichever room happened to answer first, and ten
     * sites called "Galazzano Sala server A" is not a thing anybody wants to
     * keep, so accepting a proposal also gets to say what the site is called.
     */
    public function acceptSuggestion(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name' => 'required|string|max:128',
            'site_ids' => 'required|array',
            'site_ids.*' => 'integer',
        ]);

        $service = new SiteService;
        $siteIds = array_values(array_unique(array_map('intval', $data['site_ids'])));

        // the operator is naming this place in the act of accepting the
        // proposal, so it is one they defined
        $target = Site::query()->firstOrCreate(['name' => $data['name']], ['auto_created' => false]);
        $targetId = (int) $target->site_id;

        $folded = $service->foldInto($targetId, $siteIds);

        // when the target was itself one of the folded sites, and its old name is
        // now unused, the operator's name stands
        return back()->with('success', __('ipam::ipam.sites.suggestion_accepted', [
            'name' => $data['name'],
            'vlans' => $folded['ipam_vlans_merged'] ?? 0,
            'locations' => $folded['ipam_locations'] ?? 0,
        ]));
    }
}
