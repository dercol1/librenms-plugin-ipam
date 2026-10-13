<?php

namespace Dercol1\LibrenmsIpam\Web;

use App\Facades\Permissions;
use App\Models\Device;
use Dercol1\LibrenmsIpam\Collision\DuplicateGroup;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The collision report.
 *
 * The list is always recomputed from ipam_addresses, never stored. A cached
 * duplicate list is a list that lies: a device goes down, its address goes
 * away, and the report keeps claiming a conflict that no longer exists. What is
 * stored is only the operator's verdict, an ignored flag and a note, so a
 * duplicate that is fine on purpose stays quiet and keeps its reason.
 */
class DuplicatesController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $this->authorizeView();

        if (SchemaInstaller::missing() !== []) {
            return view('ipam::missing', ['missing' => SchemaInstaller::missing()]);
        }

        $settings = Settings::fromArray($this->storedSettings($request));
        $detector = $settings->detector();

        $groups = $detector->detect(
            siteId: $request->filled('site_id') ? (int) $request->input('site_id') : null,
            vlanRecordId: $request->filled('vlan_record_id') ? (int) $request->input('vlan_record_id') : null,
            limit: 500
        );

        $groups = $this->visibleTo($groups, $request);

        if (! $request->boolean('show_known')) {
            $groups = array_values(array_filter($groups, fn (DuplicateGroup $g): bool => ! $g->ignored));
        }

        if ($request->wantsJson()) {
            return response()->json([
                'data' => array_map(fn (DuplicateGroup $g): array => $g->toArray(), $groups),
                'unclassified' => $detector->unclassifiedCount(),
            ]);
        }

        // one query each rather than one per group inside the view
        [$vlanLabels, $siteLabels] = $this->labels($groups);
        $directory = SiteDirectory::load();

        return view('ipam::duplicates', [
            'title' => __('ipam::ipam.page_titles.duplicates'),
            'groups' => $groups,
            'unclassified' => $detector->unclassified(),
            'unclassifiedTotal' => $detector->unclassifiedCount(),
            'options' => $this->options(),
            'vlanLabels' => $vlanLabels,
            'siteLabels' => $siteLabels,
            'directory' => $directory,
            'useVlanScope' => $settings->useVlanScope(),
            'counts' => [
                'groups' => count($groups),
                'crossing' => count(array_filter($groups, fn (DuplicateGroup $g): bool => $g->crossesDevices())),
                'total' => $detector->count(),
            ],
        ]);
    }

    /**
     * Drop occurrences the user may not see.
     *
     * A duplicate is only a duplicate relative to its occurrences. If the second
     * one is on a device the user cannot see, showing the conflict would leak
     * that device exists. A group where every occurrence is hidden is dropped
     * entirely rather than shown as a count.
     *
     * @param  list<DuplicateGroup>  $groups
     * @return list<DuplicateGroup>
     */
    private function visibleTo(array $groups, Request $request): array
    {
        $user = $request->user();

        if ($user === null || Gate::forUser($user)->allows('viewAll', Device::class)) {
            return $groups;
        }

        $allowed = Permissions::devicesForUser($user)->all();
        $kept = [];

        foreach ($groups as $group) {
            $visible = $group->occurrences
                ->filter(fn (Address $a): bool => in_array($a->device_id, $allowed, false));

            if ($visible->isEmpty()) {
                continue;
            }

            // rebuild with only the visible occurrences, the count follows
            $kept[] = new DuplicateGroup(
                family: $group->family,
                address: $group->address,
                siteId: $group->siteId,
                vlanRecordId: $group->vlanRecordId,
                occurrences: $visible,
                note: $group->note,
                ignored: $group->ignored,
            );
        }

        return $kept;
    }

    /**
     * Vlan and site names for the groups on screen, in two queries.
     *
     * @param  list<DuplicateGroup>  $groups
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function labels(array $groups): array
    {
        $vlanIds = array_values(array_filter(array_unique(array_map(
            fn (DuplicateGroup $g): ?int => $g->vlanRecordId,
            $groups
        ))));

        $siteIds = array_values(array_filter(array_unique(array_map(
            fn (DuplicateGroup $g): int => $g->siteId,
            $groups
        ))));

        $vlans = $vlanIds === []
            ? []
            : Vlan::query()->whereIn('vlan_record_id', $vlanIds)->get()
                ->mapWithKeys(fn (Vlan $v): array => [$v->vlan_record_id => $v->label()])
                ->all();

        $sites = $siteIds === []
            ? []
            : Site::query()->whereIn('site_id', $siteIds)->pluck('name', 'site_id')->all();

        return [$vlans, $sites];
    }

    /**
     * Record a verdict on one duplicate.
     *
     * "known good" is the reason this exists. Some duplicates are correct on
     * purpose, a load balancer pair or a floating address, and an operator
     * should be able to say so once and stop seeing it.
     */
    public function annotate(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'family' => ['required', 'integer', 'in:4,6'],
            'address' => ['required', 'string', 'max:45'],
            'site_id' => ['nullable', 'integer'],
            'vlan_record_id' => ['nullable', 'integer'],
            'ignored' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        Duplicate::query()->updateOrCreate(
            [
                'family' => (int) $data['family'],
                'address' => $data['address'],
                'site_id' => (int) ($data['site_id'] ?? 0),
                'vlan_record_id' => (int) ($data['vlan_record_id'] ?? 0),
            ],
            [
                'ignored' => $request->boolean('ignored'),
                'note' => $data['note'] ?? null,
                'updated_by' => (int) ($request->user()->user_id ?? 0),
            ]
        );

        return back()->with('success', __('ipam::ipam.annotated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'sites' => SiteDirectory::load()->options(),
            'vlans' => Vlan::query()->orderBy('vlan_id')->limit(500)->get(['vlan_record_id', 'vlan_id', 'name', 'description'])
                ->mapWithKeys(fn (Vlan $v): array => [$v->vlan_record_id => $v->label()]),
        ];
    }
}
