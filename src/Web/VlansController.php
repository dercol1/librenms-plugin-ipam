<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanOwner;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
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
            ->with(['owner', 'names', 'locations.location'])
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

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $query->limit(500)->get()->map(fn (Vlan $v): array => $this->row($v))->all(),
            ]);
        }

        $directory = SiteDirectory::load();

        return view('ipam::vlans', [
            'title' => __('ipam::ipam.page_titles.vlans'),
            'vlans' => $query->paginate(50)->withQueryString(),
            'options' => [
                'sites' => $directory->options(),
                'owners' => VlanOwner::query()->orderBy('name')->pluck('name', 'vlan_owner_id'),
            ],
            'directory' => $directory,
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Describe one site scoped vlan.
     */
    public function update(Request $request, int $vlanRecordId): RedirectResponse
    {
        $this->authorizeManage();

        $vlan = Vlan::findOrFail($vlanRecordId);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'site_id' => ['nullable', 'integer', 'exists:ipam_sites,site_id'],
            'vlan_owner_id' => ['nullable', 'integer', 'exists:ipam_vlan_owners,vlan_owner_id'],
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

        $data = $request->validate([
            'vlan_id' => ['required', 'integer', 'min:1', 'max:4094'],
            'vlan_domain' => ['nullable', 'integer', 'min:1'],
            'site_id' => ['required', 'integer', 'exists:ipam_sites,site_id'],
            'vlan_owner_id' => ['nullable', 'integer', 'exists:ipam_vlan_owners,vlan_owner_id'],
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
