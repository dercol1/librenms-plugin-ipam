<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanOwner;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Dercol1\LibrenmsIpam\Support\ScopeRules;
use Dercol1\LibrenmsIpam\Vlans\VlanLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The vlan inventory.
 *
 * A vlan id is not an identity here. 141 of the 220 vlan ids in this install
 * show up at more than one site, so vlan 100 at the head office and vlan 100 at
 * a branch are two rows, each with its own owner and description.
 *
 * The description field is the plugin's own and is not truncated. The devices
 * report a name through Q-BRIDGE-MIB::dot1qVlanStaticName that LibreNMS cuts at
 * 32 characters, which is why that value only seeds the name and the real text
 * is written here.
 */
class VlansController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $this->authorizeView();

        if (SchemaInstaller::missing() !== []) {
            return view('ipam::missing', ['missing' => SchemaInstaller::missing()]);
        }

        $query = Vlan::query()
            ->with(['owner', 'names', 'locations.location', 'group.vlans.site'])
            ->withCount('addresses')
            // room count without loading the rows, for the site column
            ->withCount('locations')
            // a site first, then the id inside it: the reader is looking for
            // "the vlans of this building", not for vlan 100 in the abstract
            ->orderBy('site_id')
            ->orderBy('vlan_id');

        // a vlan ignored by hand stays out of the list unless it is asked for
        $query = Visibility::shown($query, $request);

        if ($request->filled('q')) {
            $search = (string) $request->input('q');

            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('description', 'like', "%$search%")
                    ->orWhere('vlan_id', $search);
            });
        }

        SiteScope::apply($query, $request);

        if ($request->filled('owner_id')) {
            $query->where('vlan_owner_id', (int) $request->input('owner_id'));
        }

        if ($request->filled('described')) {
            $request->input('described') === 'yes'
                ? $query->where(function ($q): void {
                    $q->where('vlan_owner_id', '>', 0)->orWhereNotNull('description');
                })
                : $query->where('vlan_owner_id', 0)->whereNull('description');
        }

        // the sort the headers asked for, on the same whitelist terms as the
        // other lists: a column from the request is looked up, never executed
        $sortable = [
            'vlan' => 'vlan_id',
            'site' => 'site_id',
            'owner' => 'vlan_owner_id',
            'addresses' => 'addresses_count',
        ];

        $requested = (string) $request->input('order_by');

        if (isset($sortable[$requested]) && $request->filled('order_by')) {
            $direction = strtolower((string) $request->input('order_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

            $query->reOrder()->orderBy($sortable[$requested], $direction);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $query->limit(500)->get()->map(fn (Vlan $v): array => $this->row($v))->all(),
            ]);
        }

        $directory = SiteDirectory::load();
        $page = $query->paginate(50)->withQueryString();

        return view('ipam::vlans', [
            'title' => __('ipam::ipam.page_titles.vlans'),
            'vlans' => $page,
            'options' => [
                'sites' => $directory->options(),
                'owners' => VlanOwner::query()->orderBy('name')->pluck('name', 'vlan_owner_id'),
            ],
            'directory' => $directory,
            // the records that could be joined into one domain, keyed by the
            // record they would be joined to: same vlan id and domain, other
            // site, the set the operator picks from when saying "stretched"
            'linkCandidates' => $this->linkCandidates($page->getCollection()->all()),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The sibling records worth offering, keyed by record id.
     *
     * One query for the whole page, not one per row: the siblings of every
     * on-screen record are fetched together and grouped in memory. Only the
     * records already on screen are keyed: a vlan stretched to a site whose
     * record is on another page gets linked from its own row, which is where
     * the operator will be looking at it anyway.
     *
     * @param  list<Vlan>  $records
     * @return array<int, list<array{id: int, site: string}>>|null
     */
    private function linkCandidates(array $records): ?array
    {
        if ($records === []) {
            return null;
        }

        $scope = [];

        foreach ($records as $record) {
            $scope[$record->vlan_id . '-' . $record->vlan_domain][] = (int) $record->vlan_record_id;
        }

        // every record sharing a vlan id and domain with any on-screen one,
        // which is the whole set the link could ever join
        $siblings = Vlan::query()
            ->with('site')
            ->where(function ($query) use ($scope): void {
                foreach ($scope as $key => $ids) {
                    [$vlanId, $domain] = explode('-', $key);

                    $query->orWhere(function ($inner) use ($vlanId, $domain): void {
                        $inner->where('vlan_id', (int) $vlanId)->where('vlan_domain', (int) $domain);
                    });
                }
            })
            ->get();

        $candidates = [];

        foreach ($records as $record) {
            $candidates[(int) $record->vlan_record_id] = $siblings
                ->filter(fn (Vlan $other): bool => $other->vlan_id === $record->vlan_id
                    && $other->vlan_domain === $record->vlan_domain
                    && $other->vlan_record_id !== $record->vlan_record_id
                    && $other->site_id !== $record->site_id)
                ->map(fn (Vlan $other): array => [
                    'id' => (int) $other->vlan_record_id,
                    'site' => (string) ($other->site->name ?? '?'),
                ])
                ->values()
                ->all();
        }

        return $candidates;
    }

    /**
     * Describe one site scoped vlan.
     */
    public function update(Request $request, int $vlanRecordId): RedirectResponse
    {
        $this->authorizeManage();

        $vlan = Vlan::findOrFail($vlanRecordId);

        // 0 is "no site"/"no owner": the "--" options send it, and an
        // unassigned vlan keeps being unassigned unless the operator picks
        // a site, rather than silently adopting the first one in the list
        $data = $request->validate(ScopeRules::make([
            'site_id' => ['ipam_sites', 'site_id'],
            'vlan_owner_id' => ['ipam_vlan_owners', 'vlan_owner_id'],
        ], $request) + [
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'ignored' => ['nullable', 'boolean'],
        ]);

        $vlan->fill([
            'name' => $data['name'] ?? $vlan->name,
            'description' => $data['description'] ?? $vlan->description,
            'site_id' => (int) ($data['site_id'] ?? $vlan->site_id),
            'vlan_owner_id' => (int) ($data['vlan_owner_id'] ?? $vlan->vlan_owner_id),
        ]);

        if (array_key_exists('ignored', $data)) {
            $vlan->ignored = (bool) $data['ignored'];
        }

        // someone has described it by hand, the import leaves it alone now
        $vlan->auto_created = false;
        $vlan->save();

        return back()->with('success', __('ipam::ipam.saved', ['what' => $vlan->label()]));
    }

    /**
     * Record a vlan no device reports.
     *
     * The plan again: a vlan that will exist, or one that exists on hardware
     * LibreNMS does not poll. The row is marked not auto_created, which is the
     * flag the import already consults before touching anything, so what an
     * operator writes down here is theirs.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        // a vlan has to name a site when an operator writes it down; its
        // owner can stay unset, and the "--" option sends 0 for exactly that
        $data = $request->validate(ScopeRules::make([
            'vlan_owner_id' => ['ipam_vlan_owners', 'vlan_owner_id'],
        ], $request) + [
            'vlan_id' => ['required', 'integer', 'min:1', 'max:4094'],
            'vlan_domain' => ['nullable', 'integer', 'min:1'],
            'site_id' => ['required', 'integer', 'min:1', 'exists:ipam_sites,site_id'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $vlanId = (int) $data['vlan_id'];
        $domain = (int) ($data['vlan_domain'] ?? 1);

        $exists = Vlan::query()
            ->where('vlan_id', $vlanId)
            ->where('vlan_domain', $domain)
            ->where('site_id', (int) $data['site_id'])
            ->exists();

        if ($exists) {
            return back()->with('error', __('ipam::ipam.vlans.already_recorded', ['vlan' => $vlanId]));
        }

        Vlan::query()->create([
            'vlan_id' => $vlanId,
            'vlan_domain' => $domain,
            'site_id' => (int) $data['site_id'],
            'vlan_owner_id' => (int) ($data['vlan_owner_id'] ?? 0),
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            // the import fills names on auto_created rows only, so this stays
            // exactly as written
            'auto_created' => false,
        ]);

        return back()->with('success', __('ipam::ipam.created', ['what' => "vlan $vlanId"]));
    }

    /**
     * Mark records as one broadcast domain carried across sites.
     *
     * The candidates are the sibling records: same vlan id, same domain,
     * other sites. Those are exactly the records the import made for the same
     * vlan elsewhere, which is the set an operator chooses among when saying
     * "this vlan is stretched". The transport — fibre, E-Line, the outer
     * 802.1ad tag in QinQ — is a description on the group, because it is a
     * fact no device reports and the operator would otherwise keep nowhere.
     */
    public function link(Request $request, int $vlanRecordId): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'with' => ['required', 'array', 'min:1'],
            'with.*' => ['integer'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = Vlan::query()->findOrFail($vlanRecordId);

        $siblings = Vlan::query()
            ->whereIn('vlan_record_id', array_map('intval', $data['with']))
            ->where('vlan_id', $record->vlan_id)
            ->where('vlan_domain', $record->vlan_domain)
            ->pluck('vlan_record_id')
            ->all();

        if ($siblings === []) {
            return back()->with('error', __('ipam::ipam.vlans.no_siblings'));
        }

        $groupId = (new VlanLinker)->link($vlanRecordId, $siblings, $data['description'] ?? null);

        return back()->with('success', __('ipam::ipam.vlans.linked', [
            'group' => $groupId,
        ]));
    }

    /**
     * Take a record back out of its domain.
     */
    public function unlink(Request $request, int $vlanRecordId): RedirectResponse
    {
        $this->authorizeManage();

        $removed = (new VlanLinker)->unlink($vlanRecordId);

        return back()->with('success', $removed
            ? __('ipam::ipam.vlans.unlinked')
            : __('ipam::ipam.saved', ['what' => 'vlan']));
    }

    /**
     * Keep the operator's account of the transport on the group.
     */
    public function describe(Request $request, int $vlanRecordId): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        (new VlanLinker)->describe($vlanRecordId, (string) ($data['description'] ?? ''));

        return back()->with('success', __('ipam::ipam.annotated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Vlan $vlan): array
    {
        $spread = $vlan->nameSpread();

        return [
            'vlan_record_id' => $vlan->vlan_record_id,
            'vlan_id' => $vlan->vlan_id,
            'vlan_domain' => $vlan->vlan_domain,
            'site' => $vlan->site?->name,
            'owner' => $vlan->owner?->name,
            'name' => $vlan->name,
            'description' => $vlan->description,
            'label' => $vlan->label(),
            'addresses' => $vlan->addresses_count ?? 0,
            'names_reported' => $spread['reported'],
            'names_distinct' => $spread['distinct'],
            'names' => $spread['names'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        // the headline numbers leave ignored vlans out, like every other page
        $total = Vlan::query()->where('ignored', false)->count();
        $described = Vlan::query()->where('ignored', false)->where(function ($q): void {
            $q->where('vlan_owner_id', '>', 0)->orWhereNotNull('description');
        })->count();

        // vlans the devices name inconsistently, which usually means one vlan
        // is doing two jobs nobody has split apart
        $inconsistent = Vlan::query()->where('ignored', false)->get()
            ->filter(fn (Vlan $v): bool => $v->nameSpread()['distinct'] > 1)
            ->count();

        return [
            'total' => $total,
            'described' => $described,
            'inconsistent' => $inconsistent,
            'ids' => (int) DB::table('ipam_vlans')->where('ignored', false)->distinct()->count('vlan_id'),
        ];
    }
}
