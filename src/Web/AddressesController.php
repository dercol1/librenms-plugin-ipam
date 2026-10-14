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
use Dercol1\LibrenmsIpam\Support\ScopeRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                    // 10.14.146.7 as a string would. The function follows the
                    // family of the typed range: INET_ATON on an ipv6 string
                    // answers NULL, which is how a v6 search once matched
                    // nothing at all.
                    $function = $cidr->getFamily() === 4 ? 'INET_ATON' : 'INET6_ATON';

                    $query->where('family', $cidr->getFamily())
                        ->whereRaw("$function(address) BETWEEN $function(?) AND $function(?)", [
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

        if ($request->filled('function_id')) {
            $query->where('net_function_id', (int) $request->input('function_id'));
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', (int) $request->input('device_id'));
        }

        if ($request->input('unclassified') === 'yes') {
            $query->unscoped();
        }

        // never sort on a raw column name, it would be an injection point
        $sortable = [
            // the id columns sort by the ids, which is the order the operator
            // means: by block, by site, by vlan, by owner, by device
            'block' => 'network_id',
            'site' => 'site_id',
            'vlan' => 'vlan_record_id',
            'owner' => 'net_owner_id',
            'function' => 'net_function_id',
            'device' => 'device_id',
            'last_seen' => 'last_seen',
        ];

        $requested = (string) $request->input('order_by');
        $descending = strtolower((string) $request->input('order_direction', 'asc')) === 'desc';
        $direction = $descending ? 'desc' : 'asc';

        if ($requested === 'address') {
            // family first: the binary of a 4 byte and a 16 byte address are
            // not comparable to each other, and mixing them scrambles both.
            // INET6_ATON carries both families in MySQL, and the sqlite stand
            // in the tests registers the same.
            $query->orderBy('family', $direction)
                ->orderByRaw('INET6_ATON(address) ' . $direction);
        } elseif (isset($sortable[$requested]) && $request->filled('order_by')) {
            $query->orderBy($sortable[$requested], $direction);
        } else {
            // the natural order, which the third click on a header returns to
            $query->orderBy('family')->orderByRaw('INET6_ATON(address)');
        }

        return $this->visibleTo($query, $request);
    }

    /**
     * Reclassify a selection of addresses in one move.
     *
     * The selection can be the rows whose boxes were ticked, or every row the
     * current filters match. The second form is what makes the first useful:
     * "everything this owner still claims" is a filter, not a page at a time
     * of ticking. The filter params travel with the form and the same
     * filtered() that builds the list rebuilds the set, so what gets changed
     * is exactly what the operator was looking at — including the device
     * visibility rules.
     *
     * A field left on "leave unchanged" is not written. A field set to
     * "inherit" writes 0, which is the same distinction the row edit makes,
     * because bulk is not a way to accidentally strip a classification
     * nobody asked to strip.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate($this->scopeFieldRules($request) + [
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'select_all_matching' => ['nullable', 'boolean'],
            'pin' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('select_all_matching')) {
            // the same builder, the same filters, the same visibility: what
            // the operator saw is what gets changed
            $query = $this->filtered($request);
        } else {
            $ids = array_values(array_map('intval', $data['ids'] ?? []));

            if ($ids === []) {
                return back()->with('error', __('ipam::ipam.nothing_to_apply'));
            }

            // a selection is explicit intent, so pinned rows are included:
            // the operator can see which rows carry the pin, and asked for
            // these. Visibility still applies: a crafted request cannot reach
            // rows the user may not see.
            $query = $this->visibleTo(
                Address::query()->whereIntegerInRaw('address_record_id', $ids),
                $request
            );
        }

        $attributes = [];

        foreach (['site_id', 'vlan_record_id', 'net_owner_id', 'net_function_id'] as $field) {
            $value = $request->input($field);

            // absent or '' means "leave alone"; 0 means "inherit"
            if ($value === null || $value === '') {
                continue;
            }

            $attributes[$field] = (int) $value;
        }

        if ($attributes === [] && ! $request->boolean('pin')) {
            return back()->with('error', __('ipam::ipam.nothing_to_apply'));
        }

        if ($request->boolean('pin')) {
            $attributes['scope_locked'] = true;
        }

        $count = (clone $query)->toBase()->count();
        $query->toBase()->update($attributes);

        return back()->with('success', __('ipam::ipam.bulk.applied', ['count' => $count]));
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
     * The validation rules for the four classification fields, built per
     * request. One shared builder: 0 means "inherit from the block" and is
     * always valid, anything above it has to name a row that exists.
     *
     * @return array<string, list<string>>
     */
    private function scopeFieldRules(Request $request): array
    {
        return ScopeRules::make([
            'site_id' => ['ipam_sites', 'site_id'],
            'vlan_record_id' => ['ipam_vlans', 'vlan_record_id'],
            'net_owner_id' => ['ipam_net_owners', 'net_owner_id'],
            'net_function_id' => ['ipam_net_functions', 'net_function_id'],
        ], $request);
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

        $data = $request->validate($this->scopeFieldRules($request) + [
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

        // the "--" options of the add form send 0 for every field the
        // operator left on "inherit", which means "from the enclosing block"
        // here too: those values come from the block below, once the row exists
        $data = $request->validate(ScopeRules::make([
            'site_id' => ['ipam_sites', 'site_id'],
            'vlan_record_id' => ['ipam_vlans', 'vlan_record_id'],
            'net_owner_id' => ['ipam_net_owners', 'net_owner_id'],
            'net_function_id' => ['ipam_net_functions', 'net_function_id'],
        ], $request) + [
            'address' => ['required', 'string', 'max:64'],
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

        $data = $request->validate($this->scopeFieldRules($request) + [
            'network_id' => ['required', 'integer', 'exists:ipam_networks,network_id'],
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
