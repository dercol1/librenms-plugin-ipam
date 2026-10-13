<?php

namespace Dercol1\LibrenmsIpam\Web;

use App\Facades\Permissions;
use App\Models\Device;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkFunction;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The address list, and the form that corrects a classification.
 *
 * Reading is filtered hard: an address is only shown to someone who can see the
 * device behind it, using the same device permission list LibreNMS uses
 * everywhere else. Without that the ipam would leak the shape of the network to
 * a user restricted to a few devices.
 */
class AddressesController extends Controller
{
    /**
     * The return type carries RedirectResponse because a free-range query that
     * does not parse is sent back with the error rather than answered with a
     * page: the operator typed a range, and the answer belongs next to the box
     * they typed it in.
     */
    public function index(Request $request): View|JsonResponse|RedirectResponse
    {
        $this->authorizeView();

        if (SchemaInstaller::missing() !== []) {
            return view('ipam::missing', ['missing' => SchemaInstaller::missing()]);
        }

        $query = $this->filtered($request)
            ->with(['network', 'vlan', 'location', 'owner', 'function']);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $query->limit((int) $request->input('limit', 200))
                    ->get()
                    ->map(fn (Address $address): array => $this->row($address))
                    ->values()
                    ->all(),
            ]);
        }

        $perPage = Settings::fromArray($this->storedSettings($request))->perPage();
        $directory = SiteDirectory::load();

        // The free-address tool: ?free=10.14.146.0/24 answers "what can I hand
        // out in here", which is the lookup the search box cannot do.
        $finderResult = null;

        if ($request->filled('free')) {
            $range = Cidr::parse((string) $request->input('free'), true);

            if ($range === null) {
                return back()->with('error', __('ipam::ipam.bad_cidr', ['cidr' => (string) $request->input('free')]));
            }

            $finderResult = (new FreeAddressFinder)->find($range);
        }

        return view('ipam::addresses', [
            'title' => __('ipam::ipam.page_titles.addresses'),
            'addresses' => $query->paginate($perPage)->withQueryString(),
            'options' => $this->options(),
            'directory' => $directory,
            'free' => $finderResult,
            'summary' => $this->summary(),
            'perPage' => $perPage,
        ]);
    }

    /**
     * Search, narrowed by whichever filters are present.
     *
     * @return Builder<Address>
     *
     * A cidr in the search box matches the whole range, which is how an
     * operator asks "what is on this subnet" without spelling out a prefix
     * filter.
     */
    private function filtered(Request $request): Builder
    {
        $query = Address::query();

        // ignored rows stay out of the list unless the page was asked to show
        // them, so the everyday list is the estate rather than every device's
        // loopback
        $query = Visibility::shown($query, $request);

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));

            if (str_contains($search, '/')) {
                $cidr = Cidr::parse($search, true);

                if ($cidr !== null) {
                    // binary comparison so 10.14.146.0/24 does not also match
                    // 10.14.146.7 as a string would
                    $query->where('family', $cidr->getFamily())
                        ->whereRaw('INET_ATON(address) BETWEEN INET_ATON(?) AND INET_ATON(?)', [
                            (string) inet_ntop($cidr->packedStart()),
                            (string) inet_ntop($cidr->packedEnd()),
                        ]);
                }
            } else {
                $query->where('address', 'like', "$search%");
            }
        }

        if ($request->filled('family')) {
            $query->where('family', (int) $request->input('family'));
        }

        SiteScope::apply($query, $request);

        if ($request->filled('vlan_record_id')) {
            $query->where('vlan_record_id', (int) $request->input('vlan_record_id'));
        }

        if ($request->filled('network_id')) {
            $query->where('network_id', (int) $request->input('network_id'));
        }

        if ($request->filled('owner_id')) {
            $query->where('net_owner_id', (int) $request->input('owner_id'));
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', (int) $request->input('device_id'));
        }

        if ($request->input('unclassified') === 'yes') {
            $query->unscoped();
        }

        if ($request->filled('order_by')) {
            // never sort on a raw column name, it would be an injection point
            $sortable = [
                'address' => DB::raw('INET_ATON(address)'),
                'site' => 'site_id',
                'vlan' => 'vlan_record_id',
                'device' => 'device_id',
                'last_seen' => 'last_seen',
            ];

            $orderBy = (string) $request->input('order_by');
            $direction = strtolower((string) $request->input('order_direction', 'asc')) === 'desc' ? 'desc' : 'asc';
            $query->orderBy($sortable[$orderBy] ?? 'address', $direction);
        } else {
            // numeric, not lexical: 10.14.9.1 has to sort before 10.14.10.1
            $query->orderBy('family')->orderByRaw('INET_ATON(address)');
        }

        return $this->visibleTo($query, $request);
    }

    /**
     * Hide addresses on devices the user may not see.
     *
     * Same rule LibreNMS applies to its own port tables, so the ipam cannot be
     * used to work out what is on a device the user has no access to.
     */
    /**
     * @param  Builder<Address>  $query
     * @return Builder<Address>
     */
    private function visibleTo(Builder $query, Request $request): Builder
    {
        $user = $request->user();

        if ($user === null || Gate::forUser($user)->allows('viewAll', Device::class)) {
            return $query;
        }

        return $query->whereIntegerInRaw('device_id', Permissions::devicesForUser($user));
    }

    /**
     * Override the scope of one occurrence.
     *
     * Setting scope_locked is what makes the change stick: the next import
     * refreshes what it saw but leaves a pinned classification alone.
     */
    public function update(Request $request, int $addressId): RedirectResponse
    {
        $this->authorizeManage();

        $address = Address::findOrFail($addressId);

        $data = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:ipam_sites,site_id'],
            'vlan_record_id' => ['nullable', 'integer', 'exists:ipam_vlans,vlan_record_id'],
            'net_owner_id' => ['nullable', 'integer', 'exists:ipam_net_owners,net_owner_id'],
            'net_function_id' => ['nullable', 'integer', 'exists:ipam_net_functions,net_function_id'],
            'description' => ['nullable', 'string', 'max:255'],
            'lock' => ['nullable', 'boolean'],
            'ignored' => ['nullable', 'boolean'],
        ]);

        // 0 = inherit, which is the reason the columns are 0 and not null
        $address->site_id = (int) ($data['site_id'] ?? 0);
        $address->vlan_record_id = (int) ($data['vlan_record_id'] ?? 0);
        $address->net_owner_id = (int) ($data['net_owner_id'] ?? 0);
        $address->net_function_id = (int) ($data['net_function_id'] ?? 0);

        if (array_key_exists('description', $data)) {
            $address->description = $data['description'];
        }

        if (array_key_exists('ignored', $data)) {
            // never re-derived by the import once the row exists, so this is
            // the operator's word and it stays that way
            $address->ignored = (bool) $data['ignored'];
        }

        $address->scope_locked = (bool) ($data['lock'] ?? false);
        $address->save();

        return back()->with('success', __('ipam::ipam.saved', ['what' => $address->label()]));
    }

    /**
     * Record an address LibreNMS does not poll.
     *
     * The use case is the plan, not the discovery: a firewall inside an
     * address nobody has plugged in yet, a reserve that is being handed out,
     * the gateway of a range that has not gone live. If it only ever showed
     * what a device had already reported, the ipam would describe the past.
     *
     * The row is pinned on creation. An import would otherwise remove it at
     * the first run, because removal works by "not seen in LibreNMS", and a
     * planned address is by definition not seen yet.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'address' => ['required', 'string', 'max:64'],
            'site_id' => ['nullable', 'integer', 'exists:ipam_sites,site_id'],
            'vlan_record_id' => ['nullable', 'integer', 'exists:ipam_vlans,vlan_record_id'],
            'net_owner_id' => ['nullable', 'integer', 'exists:ipam_net_owners,net_owner_id'],
            'net_function_id' => ['nullable', 'integer', 'exists:ipam_net_functions,net_function_id'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $cidr = Cidr::address($data['address'], true);

        if ($cidr === null) {
            return back()->withErrors(['address' => __('ipam::ipam.bad_cidr', ['cidr' => $data['address']])]);
        }

        $prefixLen = Cidr::maxPrefixLen($cidr->getFamily());

        // A row already carrying this address on a real port is the live one; a
        // manual row is port_id 0, so the two never collide on the unique key.
        $existing = Address::query()
            ->where('family', $cidr->getFamily())
            ->where('address', $cidr->uncompressed())
            ->where('port_id', 0)
            ->where('context_name', '')
            ->first();

        if ($existing !== null) {
            return back()->with('error', __('ipam::ipam.addresses.already_recorded', ['address' => $cidr->compressed()]));
        }

        // The enclosing block, when there is one, answers the same way import
        // would: the block's site, owner and function, and the address keeps
        // whatever the operator typed on top of them.
        $block = Network::enclosing($cidr);

        $address = new Address([
            'family' => $cidr->getFamily(),
            'address' => $cidr->uncompressed(),
            'prefixlen' => $prefixLen,
            'port_id' => 0,
            'device_id' => 0,
            'context_name' => '',
            'network_id' => $block === null ? 0 : (int) $block->network_id,
            'site_id' => (int) ($data['site_id'] ?? ($block?->inherit('site_id') ?? 0)),
            'vlan_record_id' => (int) ($data['vlan_record_id'] ?? 0),
            'net_owner_id' => (int) ($data['net_owner_id'] ?? ($block?->inherit('net_owner_id') ?? 0)),
            'net_function_id' => (int) ($data['net_function_id'] ?? ($block?->inherit('net_function_id') ?? 0)),
            'description' => $data['description'] ?? null,
            'scope_locked' => true,
            'ignored' => false,
            'first_seen' => now(),
            'last_seen' => now(),
        ]);

        $address->save();

        return back()->with('success', __('ipam::ipam.created', ['what' => $cidr->compressed()]));
    }

    /**
     * Apply a site and an owner to a whole block at once.
     *
     * The move that actually scales: one action to classify a range and
     * everything in it, instead of a row at a time.
     */
    public function applyToBlock(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'network_id' => ['required', 'integer', 'exists:ipam_networks,network_id'],
            'site_id' => ['nullable', 'integer', 'exists:ipam_sites,site_id'],
            'vlan_record_id' => ['nullable', 'integer', 'exists:ipam_vlans,vlan_record_id'],
            'net_owner_id' => ['nullable', 'integer', 'exists:ipam_net_owners,net_owner_id'],
            'net_function_id' => ['nullable', 'integer', 'exists:ipam_net_functions,net_function_id'],
            'only_unclassified' => ['nullable', 'boolean'],
        ]);

        $block = Network::findOrFail($data['network_id']);

        $query = Address::query()
            ->where('network_id', $block->network_id)
            ->where('scope_locked', false);

        if ($request->boolean('only_unclassified')) {
            $query->unscoped();
        }

        $attributes = array_filter([
            'site_id' => (int) ($data['site_id'] ?? 0),
            'vlan_record_id' => (int) ($data['vlan_record_id'] ?? 0),
            'net_owner_id' => (int) ($data['net_owner_id'] ?? 0),
            'net_function_id' => (int) ($data['net_function_id'] ?? 0),
        ], fn (int $value): bool => $value !== 0);

        if ($attributes === []) {
            return back()->with('error', __('ipam::ipam.nothing_to_apply'));
        }

        $count = $query->update($attributes);

        return back()->with('success', __('ipam::ipam.applied', [
            'count' => $count,
            'what' => $block->cidr,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Address $address): array
    {
        $device = $address->device();
        $port = $address->port();

        return [
            'address_record_id' => $address->address_record_id,
            'address' => $address->address,
            'prefixlen' => $address->prefixlen,
            'label' => $address->label(),
            'network' => $address->network?->cidr,
            'site' => $address->site?->name,
            'location' => $address->location?->name,
            'vlan' => $address->vlan?->label(),
            'owner' => $address->owner?->name,
            'function' => $address->function?->name,
            'device' => $device?->display,
            'device_id' => $address->device_id,
            'interface' => $port?->ifName,
            'unscoped' => $address->isUnscoped(),
            'locked' => $address->scope_locked,
            'description' => $address->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'sites' => SiteDirectory::load()->options(),
            'vlans' => Vlan::query()->orderBy('vlan_id')->get(['vlan_record_id', 'vlan_id', 'site_id', 'name', 'description'])
                ->mapWithKeys(fn (Vlan $v): array => [$v->vlan_record_id => $v->label() . ($v->site_id ? '' : ' (no site)')]),
            'owners' => NetworkOwner::query()->orderBy('name')->pluck('name', 'net_owner_id'),
            'functions' => NetworkFunction::query()->orderBy('name')->pluck('name', 'net_function_id'),
            'networks' => Network::query()->orderBy('prefixlen')->get(['network_id', 'cidr', 'prefixlen'])
                ->mapWithKeys(fn (Network $n): array => [$n->network_id => $n->cidr]),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            // The headline numbers describe the estate in front of the reader,
            // so ignored rows stay out of them and are counted separately: a
            // block of loopback addresses is not 40 addresses worth of news.
            'total' => Address::query()->where('ignored', false)->count(),
            'unclassified' => Address::query()->unscoped()->where('ignored', false)->count(),
            'locked' => Address::query()->where('scope_locked', true)->where('ignored', false)->count(),
            'ipv4' => Address::query()->where('family', 4)->where('ignored', false)->count(),
            'ipv6' => Address::query()->where('family', 6)->where('ignored', false)->count(),
            'ignored' => Address::query()->where('ignored', true)->count(),
        ];
    }
}
