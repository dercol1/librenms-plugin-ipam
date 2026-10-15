<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkStretch;
use Dercol1\LibrenmsIpam\Networks\NetworkStretcher;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Dercol1\LibrenmsIpam\Support\ScopeRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The network map: the tree of blocks, and how to describe one.
 *
 * A description set on a block is inherited by everything under it, which is
 * what makes a wide segment describable in one place. The form says so
 * explicitly, because the alternative reading, "this exact range", is the one
 * that makes the tree pointless.
 *
 * The list itself is built by NetworksPage, shared with the plugin's own page
 * hook. Two builders for one view drifted apart once already, the hook said
 * "networks" and the controller said "blocks", and the controller path rendered
 * an empty page.
 */
class NetworksController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $this->authorizeView();

        if (SchemaInstaller::missing() !== []) {
            return view('ipam::missing', ['missing' => SchemaInstaller::missing()]);
        }

        if ($request->wantsJson()) {
            return response()->json($this->tree($request));
        }

        $data = NetworksPage::data($this->storedSettings(), $request);

        // The domain each block is in and the places it could be stretched to,
        // for the page as it stands: one query for the domains, one for their
        // places, and the candidate list built from what the whole page shares.
        $networks = $data['networks'];
        // the paginator's own items, not the whole filtered set: the candidate
        // list is built for what is on screen
        $blocks = $networks instanceof LengthAwarePaginator
            ? $networks->getCollection()->all()
            : iterator_to_array(is_iterable($networks) ? $networks : []);

        return view('ipam::networks', $data + [
            'stretches' => $this->stretchData($blocks),
        ]);
    }

    /**
     * What the edit row of each block needs to talk about layer 2.
     *
     * Batched for the page, never per row: the vlans page once spent fifty
     * queries on fifty rows for the same reason, and the page hook and the
     * controller are the same code path here so both pay it at once.
     *
     * @param  list<Network>  $blocks
     * @return array<int, array{domain: ?array{id: int, name: ?string, description: ?string, places: list<string>}, candidates: list<array{key: string, type: string, id: int, name: string, rooms: int, taken: bool}>}>
     */
    private function stretchData(array $blocks): array
    {
        if ($blocks === []) {
            return [];
        }

        $ids = array_map(fn (Network $block): int => (int) $block->network_id, $blocks);

        $domains = NetworkStretch::query()
            ->whereIn('stretch_id', array_values(array_filter(array_map(
                fn (Network $block): int => (int) $block->stretch_id,
                $blocks
            ))))
            ->with('places')
            ->get()
            ->keyBy('stretch_id');

        $stretcher = new NetworkStretcher;
        $rooms = $stretcher->roomsCarrying($blocks);
        $out = [];

        foreach ($blocks as $block) {
            $domain = (int) $block->stretch_id > 0 ? $domains->get((int) $block->stretch_id) : null;

            $out[(int) $block->network_id] = [
                'domain' => $domain === null ? null : [
                    'id' => (int) $domain->stretch_id,
                    'name' => $domain->name,
                    'description' => $domain->description,
                    'places' => $domain->placeNames(),
                ],
                'candidates' => $stretcher->candidates(
                    $block,
                    $rooms[(int) $block->network_id] ?? []
                ),
            ];
        }

        return $out;
    }

    /**
     * The whole tree, for the expandable browser.
     *
     * @return array<string, mixed>
     */
    private function tree(Request $request): array
    {
        return [
            'data' => $this->filtered($request)
                ->limit(2000)
                ->get()
                ->map(fn (Network $block): array => $this->node($block))
                ->all(),
        ];
    }

    /**
     * The block list, narrowed by whichever filters are present.
     */
    /**
     * @return Builder<Network>
     */
    private function filtered(Request $request): Builder
    {
        $query = NetworksPage::query($request);

        // the json tree needs the child count to know what can be expanded, and
        // the site relation to name it
        $query->withCount('children')->with('site');

        return $query;
    }

    /**
     * One block, as the json tree wants it.
     *
     * @return array<string, mixed>
     */
    private function node(Network $block): array
    {
        return [
            'network_id' => $block->network_id,
            'cidr' => $block->cidr,
            'parent_id' => $block->parent_id,
            'prefixlen' => $block->prefixlen,
            'addresses' => (int) DB::table('ipam_addresses')
                ->where('network_id', $block->network_id)
                ->distinct()
                ->count('address'),
            'site' => $block->site?->name,
            'vlan_id' => $block->vlan_id,
            'owner' => $block->owner?->name,
            'function' => $block->function?->name,
            'description' => $block->description,
            'auto_created' => $block->auto_created,
            'described' => $block->site_id > 0 || $block->net_owner_id > 0
                || $block->net_function_id > 0 || ! empty($block->description),
        ];
    }

    /**
     * Create a block, or describe an existing one.
     */
    public function save(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        if (SchemaInstaller::missing() !== []) {
            return back()->with('error', __('ipam::ipam.not_installed'));
        }

        // 0 is "inherit from the block above", and every "--" option in the
        // form sends exactly it: the rules are built so it passes, or an
        // operator editing one field and leaving the others alone would have
        // the whole submit refused with nothing shown for it
        $data = $request->validate(ScopeRules::make([
            'site_id' => ['ipam_sites', 'site_id'],
            'location_id' => ['ipam_locations', 'location_id'],
            'net_owner_id' => ['ipam_net_owners', 'net_owner_id'],
            'net_function_id' => ['ipam_net_functions', 'net_function_id'],
        ], $request) + [
            'network_id' => ['nullable', 'integer', 'exists:ipam_networks,network_id'],
            'cidr' => ['required_without:network_id', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'vlan_id' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'ignored' => ['nullable', 'boolean'],
        ]);

        // 0 means "inherit from above", an absent field means "leave alone"
        $scope = [
            'site_id' => (int) ($data['site_id'] ?? 0),
            'location_id' => (int) ($data['location_id'] ?? 0),
            'vlan_id' => (int) ($data['vlan_id'] ?? 0),
            'net_owner_id' => (int) ($data['net_owner_id'] ?? 0),
            'net_function_id' => (int) ($data['net_function_id'] ?? 0),
        ];

        if (! empty($data['network_id'])) {
            $block = Network::findOrFail($data['network_id']);
            $block->fill($scope);

            if (array_key_exists('description', $data)) {
                $block->description = $data['description'];
            }

            if (array_key_exists('ignored', $data)) {
                $block->ignored = (bool) $data['ignored'];
            }

            $block->save();

            // Eagerly, not at query time: ignoring a block means every address
            // under it goes quiet too, and doing it here is what keeps the flag
            // a plain indexed column everywhere else. Un-ignore undoes exactly
            // the same set, so the two remain opposites.
            $block->ignored
                ? $block->ignoreSubtree()
                : $block->unignoreSubtree();

            return back()->with('success', __('ipam::ipam.saved', ['what' => $block->cidr]));
        }

        $cidr = Cidr::parse((string) $data['cidr']);

        if ($cidr === null) {
            return back()
                ->withInput()
                ->with('error', __('ipam::ipam.bad_cidr', ['cidr' => (string) $data['cidr']]));
        }

        // an operator writing a block down is describing it, not discovering it
        $block = Network::query()->firstOrCreate(
            ['cidr' => $cidr->cidr()],
            array_merge($scope, [
                'description' => $data['description'] ?? null,
                'auto_created' => false,
                'ignored' => (bool) ($data['ignored'] ?? false),
                'location_id' => $scope['location_id'],
                // typed inside a stretched range: it is in that domain the same
                // way a range the import discovers there would be
                'stretch_id' => Network::enclosing($cidr)?->inherit('stretch_id') ?? 0,
            ])
        );

        if ($block->ignored) {
            $block->ignoreSubtree();
        }

        return back()->with('success', __('ipam::ipam.saved', ['what' => $cidr->cidr()]));
    }

    /**
     * Create a block and, at the same time, the wider ones it sits in.
     *
     * The values in the form belong to the block being typed, not to the
     * scaffold above it: an operator adding 10.14.146.0/24 with an owner means
     * that owner on the /24, and the /16 and /8 above stay untouched for the
     * next person to describe.
     */
    public function createWithParents(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        // 0 is "inherit" on the same terms as the row edit: the "--" options
        // of this form send it for every field the operator left alone
        $data = $request->validate(ScopeRules::make([
            'site_id' => ['ipam_sites', 'site_id'],
            'location_id' => ['ipam_locations', 'location_id'],
            'net_owner_id' => ['ipam_net_owners', 'net_owner_id'],
            'net_function_id' => ['ipam_net_functions', 'net_function_id'],
        ], $request) + [
            'cidr' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'vlan_id' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'ignored' => ['nullable', 'boolean'],
        ]);

        $cidr = Cidr::parse($data['cidr']);

        if ($cidr === null) {
            return back()->withInput()->with('error', __('ipam::ipam.bad_cidr', ['cidr' => $data['cidr']]));
        }

        $created = [];
        // the same inheritance the import applies, and the same direction: a
        // block typed below a stretched one is inside its domain, while the
        // wider blocks the ladder walks through are not — a domain covers a
        // range and everything under it, never what is above it. So the flag
        // arrives from the block handled just before, widest first.
        $parentStretchId = 0;

        foreach (Cidr::ladder($cidr->getFamily()) as $prefixLen) {
            if ($prefixLen > $cidr->getPrefixLen()) {
                continue;
            }

            $candidate = $cidr->withPrefixLen($prefixLen);
            $existing = Network::query()->where('cidr', $candidate->cidr())->first();

            if ($existing === null) {
                Network::query()->create([
                    'cidr' => $candidate->cidr(),
                    'auto_created' => false,
                    'stretch_id' => $parentStretchId,
                ]);

                $created[] = $candidate->cidr();

                continue;
            }

            $parentStretchId = (int) $existing->stretch_id;
        }

        // The typed block gets the form's values, whether it was just created or
        // had been there all along: filling the form and finding the block
        // already exists should not silently drop what was typed.
        $block = Network::query()->where('cidr', $cidr->cidr())->first();

        if ($block !== null) {
            $block->fill([
                'site_id' => (int) ($data['site_id'] ?? 0),
                'location_id' => (int) ($data['location_id'] ?? 0),
                'vlan_id' => (int) ($data['vlan_id'] ?? 0),
                'net_owner_id' => (int) ($data['net_owner_id'] ?? 0),
                'net_function_id' => (int) ($data['net_function_id'] ?? 0),
            ]);

            if (array_key_exists('description', $data)) {
                $block->description = $data['description'];
            }

            if (array_key_exists('ignored', $data)) {
                $block->ignored = (bool) $data['ignored'];
            }

            $block->auto_created = false;
            $block->save();

            $block->ignored ? $block->ignoreSubtree() : $block->unignoreSubtree();
        }

        return back()->with('success', $created === []
            ? __('ipam::ipam.nothing_created')
            : __('ipam::ipam.created', ['what' => implode(', ', $created)]));
    }

    public function destroy(Request $request, int $networkId): RedirectResponse
    {
        $this->authorizeManage();

        $block = Network::findOrFail($networkId);

        // The import owns what it created: those rows come back on the next poll,
        // so deleting one is only a slower way of hiding it. What can be deleted
        // is what the operator owns — typed in, or handed over by an import after
        // LibreNMS stopped reporting it.
        if (! $block->isManual()) {
            return back()->with('error', __('ipam::ipam.networks.delete_imported'));
        }

        $addresses = (int) $block->addresses()->count();
        $children = (int) $block->children()->count();

        if ($addresses > 0 || $children > 0) {
            // the map is a tree: removing a block with data under it would leave
            // every address pointing at nothing, and re-homing them is a decision
            // about where they belong, not a side effect of a delete
            return back()->with('error', __('ipam::ipam.networks.delete_in_use', [
                'cidr' => $block->cidr,
                'addresses' => $addresses,
                'blocks' => $children,
            ]));
        }

        $cidr = $block->cidr;

        if ((int) $block->stretch_id > 0) {
            (new NetworkStretcher)->unstretch($block);
        }

        $block->delete();

        return back()->with('success', __('ipam::ipam.deleted', ['what' => $cidr]));
    }

    /**
     * Say that this range is one subnet at the ticked places.
     *
     * The places are the operator's real ones, and the account of how it gets
     * there goes on the domain: no device reports which fibre a vlan comes back
     * on, so without this the fact lives nowhere and the collision rule has
     * nothing to read.
     */
    public function stretch(Request $request, int $networkId): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'places' => ['nullable', 'array'],
            'places.*' => ['string', 'max:32'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $block = Network::findOrFail($networkId);

        [$stretchId, $refusal] = (new NetworkStretcher)->stretch(
            $block,
            $data['places'] ?? [],
            $data['description'] ?? null
        );

        if ($stretchId === 0) {
            return back()->with('error', (string) $refusal);
        }

        $places = count($data['places'] ?? []);

        return back()->with('success', __('ipam::ipam.networks.stretch_saved', ['count' => $places]));
    }

    /**
     * Take the statement back: the domain and its places go, and every block
     * that was only in it because of the range above is released.
     */
    public function unstretch(Request $request, int $networkId): RedirectResponse
    {
        $this->authorizeManage();

        $block = Network::findOrFail($networkId);

        if (! (new NetworkStretcher)->unstretch($block)) {
            return back()->with('error', __('ipam::ipam.networks.not_stretched'));
        }

        return back()->with('success', __('ipam::ipam.networks.stretch_unstretched'));
    }

    /**
     * The account of the transport, kept on the domain so every block inside it
     * shows it.
     */
    public function describeStretch(Request $request, int $networkId): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $block = Network::findOrFail($networkId);

        if (! (new NetworkStretcher)->describe($block, (string) ($data['description'] ?? ''))) {
            return back()->with('error', __('ipam::ipam.networks.not_stretched'));
        }

        return back()->with('success', __('ipam::ipam.saved', ['what' => $block->cidr]));
    }
}
