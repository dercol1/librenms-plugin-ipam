<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\NetworkFunction;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\VlanOwner;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The pick lists the other pages choose from: owners and functions.
 *
 * A block, an address and a vlan all carry an owner, and a block and an
 * address carry a function too, but until this page existed the lists were
 * write-only: the dropdowns offered whatever happened to be in the tables and
 * nothing could put an entry into them except the database. On the install
 * this was written against, all three lists were empty, so three columns of
 * the classification had no values anyone could set.
 *
 * Deleting is refused while an entry is in use, and the refusal says where it
 * is used, because the alternative — deleting it and leaving every block that
 * carried it silently unowned — is exactly the kind of quiet damage a
 * reference without a foreign key makes possible.
 */
class TaxonomiesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeView();

        if (SchemaInstaller::missing() !== []) {
            return view('ipam::missing', ['missing' => SchemaInstaller::missing()]);
        }

        return view('ipam::taxonomies', [
            'title' => __('ipam::ipam.page_titles.taxonomies'),
            'lists' => $this->lists(),
            'canManage' => IpamPermissions::canManage(),
        ]);
    }

    /**
     * The three lists with the usage counts that decide what can be deleted.
     *
     * @return array<string, mixed>
     */
    private function lists(): array
    {
        $netOwners = NetworkOwner::query()->orderBy('name')->get();
        $netFunctions = NetworkFunction::query()->orderBy('name')->get();
        $vlanOwners = VlanOwner::query()->orderBy('name')->get();

        return [
            'net-owner' => [
                'title' => __('ipam::ipam.taxonomies.net_owner_title'),
                'intro' => __('ipam::ipam.taxonomies.net_owner_intro'),
                'used_by' => __('ipam::ipam.taxonomies.used_by_blocks_addresses'),
                'entries' => $netOwners->map(fn (NetworkOwner $owner): array => [
                    'id' => (int) $owner->net_owner_id,
                    'name' => (string) $owner->name,
                    'description' => $owner->description,
                    'usage' => $this->netOwnerUsage((int) $owner->net_owner_id),
                ])->all(),
            ],
            'net-function' => [
                'title' => __('ipam::ipam.taxonomies.net_function_title'),
                'intro' => __('ipam::ipam.taxonomies.net_function_intro'),
                'used_by' => __('ipam::ipam.taxonomies.used_by_blocks_addresses'),
                'entries' => $netFunctions->map(fn (NetworkFunction $function): array => [
                    'id' => (int) $function->net_function_id,
                    'name' => (string) $function->name,
                    'description' => $function->description,
                    'usage' => $this->netFunctionUsage((int) $function->net_function_id),
                ])->all(),
            ],
            'vlan-owner' => [
                'title' => __('ipam::ipam.taxonomies.vlan_owner_title'),
                'intro' => __('ipam::ipam.taxonomies.vlan_owner_intro'),
                'used_by' => __('ipam::ipam.taxonomies.used_by_vlans'),
                'entries' => $vlanOwners->map(fn (VlanOwner $owner): array => [
                    'id' => (int) $owner->vlan_owner_id,
                    'name' => (string) $owner->name,
                    'description' => $owner->description,
                    'usage' => $this->vlanOwnerUsage((int) $owner->vlan_owner_id),
                ])->all(),
            ],
        ];
    }

    /**
     * Add an entry to one of the lists.
     */
    public function store(Request $request, string $taxonomy): RedirectResponse
    {
        $this->authorizeManage();

        $model = $this->modelFor($taxonomy);

        if ($model === null) {
            return back()->with('error', __('ipam::ipam.taxonomies.unknown_list'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // firstOrCreate rather than create: two people typing the same owner on
        // the same afternoon should not end up with two entries whose dropdown
        // then shows one thing twice
        $model::query()->firstOrCreate(
            ['name' => $data['name']],
            ['description' => $data['description'] ?? null]
        );

        return back()->with('success', __('ipam::ipam.created', ['what' => $data['name']]));
    }

    public function update(Request $request, string $taxonomy, int $id): RedirectResponse
    {
        $this->authorizeManage();

        $model = $this->modelFor($taxonomy);

        if ($model === null) {
            return back()->with('error', __('ipam::ipam.taxonomies.unknown_list'));
        }

        $entry = $model::query()->find($id);

        if ($entry === null) {
            return back();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $entry->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
        $entry->save();

        return back()->with('success', __('ipam::ipam.saved', ['what' => $data['name']]));
    }

    /**
     * Remove an entry nobody points at.
     */
    public function destroy(Request $request, string $taxonomy, int $id): RedirectResponse
    {
        $this->authorizeManage();

        $model = $this->modelFor($taxonomy);

        if ($model === null) {
            return back()->with('error', __('ipam::ipam.taxonomies.unknown_list'));
        }

        $entry = $model::query()->find($id);

        if ($entry === null) {
            return back();
        }

        $usage = $this->usageFor($taxonomy, $id);

        if (array_sum($usage) > 0) {
            // a reference without a foreign key is exactly why this has to be
            // refused by counting rather than by the engine
            return back()->with('error', __('ipam::ipam.taxonomies.in_use', [
                'name' => $entry->name,
                'where' => $this->usageText($usage),
            ]));
        }

        $name = $entry->name;
        $entry->delete();

        return back()->with('success', __('ipam::ipam.deleted', ['what' => $name]));
    }

    /**
     * The model behind one of the lists, as the routes spell it.
     *
     * The union is spelled out rather than reduced to a common base: the three
     * models share the shape — a key, a name, a description — but share no
     * parent, and a class-string of the generic Model would leave every later
     * $entry->name unprovable. The union keeps each concrete.
     *
     * @return class-string<NetworkOwner>|class-string<NetworkFunction>|class-string<VlanOwner>|null
     */
    private function modelFor(string $taxonomy): ?string
    {
        return match ($taxonomy) {
            'net-owner' => NetworkOwner::class,
            'net-function' => NetworkFunction::class,
            'vlan-owner' => VlanOwner::class,
            default => null,
        };
    }

    /**
     * How many things would lose their classification if this entry went.
     *
     * @return array<string, int>
     */
    private function usageFor(string $taxonomy, int $id): array
    {
        return match ($taxonomy) {
            'net-owner' => $this->netOwnerUsage($id),
            'net-function' => $this->netFunctionUsage($id),
            'vlan-owner' => $this->vlanOwnerUsage($id),
            default => [],
        };
    }

    /**
     * @return array<string, int>
     */
    private function netOwnerUsage(int $id): array
    {
        return [
            __('ipam::ipam.taxonomies.usage_blocks') => (int) DB::table('ipam_networks')->where('net_owner_id', $id)->count(),
            __('ipam::ipam.taxonomies.usage_addresses') => (int) DB::table('ipam_addresses')->where('net_owner_id', $id)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function netFunctionUsage(int $id): array
    {
        return [
            __('ipam::ipam.taxonomies.usage_blocks') => (int) DB::table('ipam_networks')->where('net_function_id', $id)->count(),
            __('ipam::ipam.taxonomies.usage_addresses') => (int) DB::table('ipam_addresses')->where('net_function_id', $id)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function vlanOwnerUsage(int $id): array
    {
        return [
            __('ipam::ipam.taxonomies.usage_vlans') => (int) DB::table('ipam_vlans')->where('vlan_owner_id', $id)->count(),
        ];
    }

    /**
     * A readable "N blocks and M addresses", for the refusal message.
     *
     * @param  array<string, int>  $usage
     */
    private function usageText(array $usage): string
    {
        $parts = [];

        foreach ($usage as $what => $count) {
            if ($count > 0) {
                $parts[] = number_format($count) . ' ' . $what;
            }
        }

        return implode(' + ', $parts);
    }
}
