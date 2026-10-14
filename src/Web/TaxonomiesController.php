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
use Illuminate\Support\Collection;
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
     * Sorted the way the request asks: the headers cycle ascending, then
     * descending, then back to the natural order, and the three lists follow
     * the same choice so the page moves as one rather than as three
     * unrelated tables.
     *
     * @return array<string, mixed>
     */
    private function lists(): array
    {
        [$order, $descending] = $this->requestedOrder();

        $netOwners = $this->ordered(NetworkOwner::query()->orderBy('name')->get(), $order, $descending, 'net-owner');
        $netFunctions = $this->ordered(NetworkFunction::query()->orderBy('name')->get(), $order, $descending, 'net-function');
        $vlanOwners = $this->ordered(VlanOwner::query()->orderBy('name')->get(), $order, $descending, 'vlan-owner');

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
     * The sort the headers asked for: a column and a direction, or the natural
     * order when the cycle has come back around.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function requestedOrder(): array
    {
        $order = (string) request('order_by');

        $known = ['name', 'usage'];

        if (! in_array($order, $known, true)) {
            return [null, false];
        }

        return [$order, strtolower((string) request('order_direction', 'asc')) === 'desc'];
    }

    /**
     * Sort one list's entries by the requested column, usages included: the
     * usage counts are per entry, so they are attached before the sort.
     *
     * The bound is the union of the three models rather than the generic one:
     * sorting reads $entry->name, and the generic Model has no such property,
     * which is phpstan saying the bound has to promise what the body does.
     *
     * @template TModel of NetworkOwner|NetworkFunction|VlanOwner
     *
     * @param  Collection<int, TModel>  $entries
     * @param  string  $taxonomy  the key the urls and the usage counts are built from
     * @return Collection<int, TModel>
     */
    private function ordered(Collection $entries, ?string $order, bool $descending, string $taxonomy): Collection
    {
        if ($order === 'usage') {
            return $entries->sortBy(
                fn ($entry): int => array_sum($this->usageFor($taxonomy, (int) $this->entryKey($taxonomy, $entry))),
                $descending ? SORT_DESC : SORT_ASC
            )->values();
        }

        if ($descending) {
            return $entries->sortByDesc(fn ($entry): string => (string) $entry->name)->values();
        }

        // natural order, and ascending name: the same thing here
        return $entries->values();
    }

    /**
     * The primary key of one entry, whichever list it is from.
     */
    private function entryKey(string $taxonomy, object $entry): int
    {
        return match ($taxonomy) {
            'net-owner' => (int) $entry->net_owner_id,
            'net-function' => (int) $entry->net_function_id,
            'vlan-owner' => (int) $entry->vlan_owner_id,
            default => 0,
        };
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

        // firstOrCreate would answer "created" while handing back an existing
        // entry and dropping the description the operator typed. The name is
        // freed instead: an unused holder is absorbed, a used one is refused.
        $release = $this->releaseName($taxonomy, $data['name'], 0);

        if ($release === 'taken') {
            return back()->with('error', __('ipam::ipam.taxonomies.name_taken', ['name' => $data['name']]));
        }

        $model::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $message = $release === 'released'
            ? __('ipam::ipam.taxonomies.name_released', ['name' => $data['name']])
            : __('ipam::ipam.created', ['what' => $data['name']]);

        return back()->with('success', $message);
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

        // Names are unique per list, so a rename onto a taken name has to be
        // answered here rather than by a 500 from the database constraint —
        // the same lesson the sites page learned. An unused holder is a
        // leftover from before the list had a page of its own, and the
        // operator renaming onto it is saying the two are the same thing: it
        // is absorbed. A used one is refused, because only the operator can
        // decide whether the two really are.
        $release = $this->releaseName($taxonomy, $data['name'], (int) $id);

        if ($release === 'taken') {
            return back()->with('error', __('ipam::ipam.taxonomies.name_taken', ['name' => $data['name']]));
        }

        $entry->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
        $entry->save();

        $message = $release === 'released'
            ? __('ipam::ipam.taxonomies.name_released', ['name' => $data['name']])
            : __('ipam::ipam.saved', ['what' => $data['name']]);

        return back()->with('success', $message);
    }

    /**
     * Free a name an operator wants for another entry of the same list.
     *
     * The same three states as a site name: free when nobody holds it,
     * released when an entry nothing points at held it and was removed,
     * taken when a used entry holds it and only the operator can decide.
     */
    private function releaseName(string $taxonomy, string $name, int $keepId): string
    {
        $model = $this->modelFor($taxonomy);

        if ($model === null) {
            return 'taken';
        }

        $holder = $model::query()
            ->where('name', $name)
            ->where($this->keyFor($taxonomy), '!=', $keepId)
            ->first();

        if ($holder === null) {
            return 'free';
        }

        $id = (int) $holder->{$this->keyFor($taxonomy)};

        if (array_sum($this->usageFor($taxonomy, $id)) === 0) {
            $holder->delete();

            return 'released';
        }

        return 'taken';
    }

    /**
     * The primary key column of one list, the way rename and release both
     * have to spell it.
     */
    private function keyFor(string $taxonomy): string
    {
        return match ($taxonomy) {
            'net-owner' => 'net_owner_id',
            'net-function' => 'net_function_id',
            'vlan-owner' => 'vlan_owner_id',
            default => 'id',
        };
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
     * The keys are the short names the view turns into links and labels: the
     * drill-down from a badge is "show me the blocks that carry this owner",
     * and the page they land on is decided by exactly this key.
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
            'blocks' => (int) DB::table('ipam_networks')->where('net_owner_id', $id)->count(),
            'addresses' => (int) DB::table('ipam_addresses')->where('net_owner_id', $id)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function netFunctionUsage(int $id): array
    {
        return [
            'blocks' => (int) DB::table('ipam_networks')->where('net_function_id', $id)->count(),
            'addresses' => (int) DB::table('ipam_addresses')->where('net_function_id', $id)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function vlanOwnerUsage(int $id): array
    {
        return [
            'vlans' => (int) DB::table('ipam_vlans')->where('vlan_owner_id', $id)->count(),
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
                $parts[] = number_format($count) . ' ' . __($this->usageLabelKey($what));
            }
        }

        return implode(' + ', $parts);
    }

    /**
     * The lang key behind a usage word, shared by the message and the badges.
     */
    private function usageLabelKey(string $what): string
    {
        return match ($what) {
            'blocks' => 'ipam::ipam.taxonomies.usage_blocks',
            'addresses' => 'ipam::ipam.taxonomies.usage_addresses',
            'vlans' => 'ipam::ipam.taxonomies.usage_vlans',
            default => $what,
        };
    }
}
