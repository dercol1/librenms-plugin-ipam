<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('ipam::networks', NetworksPage::data($this->storedSettings(), $request));
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

        $data = $request->validate([
            'network_id' => ['nullable', 'integer', 'exists:ipam_networks,network_id'],
            'cidr' => ['required_without:network_id', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:ipam_sites,site_id'],
            'vlan_id' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'net_owner_id' => ['nullable', 'integer', 'exists:ipam_net_owners,net_owner_id'],
            'net_function_id' => ['nullable', 'integer', 'exists:ipam_net_functions,net_function_id'],
            'ignored' => ['nullable', 'boolean'],
        ]);

        // 0 means "inherit from above", an absent field means "leave alone"
        $scope = [
            'site_id' => (int) ($data['site_id'] ?? 0),
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

        $data = $request->validate([
            'cidr' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:ipam_sites,site_id'],
            'vlan_id' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'net_owner_id' => ['nullable', 'integer', 'exists:ipam_net_owners,net_owner_id'],
            'net_function_id' => ['nullable', 'integer', 'exists:ipam_net_functions,net_function_id'],
            'ignored' => ['nullable', 'boolean'],
        ]);

        $cidr = Cidr::parse($data['cidr']);

        if ($cidr === null) {
            return back()->withInput()->with('error', __('ipam::ipam.bad_cidr', ['cidr' => $data['cidr']]));
        }

        $created = [];

        foreach (Cidr::ladder($cidr->getFamily()) as $prefixLen) {
            if ($prefixLen > $cidr->getPrefixLen()) {
                continue;
            }

            $candidate = $cidr->withPrefixLen($prefixLen);

            if (! Network::query()->where('cidr', $candidate->cidr())->exists()) {
                Network::query()->create([
                    'cidr' => $candidate->cidr(),
                    'auto_created' => false,
                ]);

                $created[] = $candidate->cidr();
            }
        }

        // The typed block gets the form's values, whether it was just created or
        // had been there all along: filling the form and finding the block
        // already exists should not silently drop what was typed.
        $block = Network::query()->where('cidr', $cidr->cidr())->first();

        if ($block !== null) {
            $block->fill([
                'site_id' => (int) ($data['site_id'] ?? 0),
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
        $cidr = $block->cidr;

        // children keep working, they just fall back to the block above
        Network::query()->where('parent_id', $networkId)->update(['parent_id' => $block->parent_id]);
        $block->delete();

        return back()->with('success', __('ipam::ipam.deleted', ['what' => $cidr]));
    }
}
