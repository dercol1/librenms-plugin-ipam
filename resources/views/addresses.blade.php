@extends('layouts.librenmsv1')

@section('title', __('ipam::ipam.page_titles.addresses'))

@section('content')
    <div class="container-fluid">
        @include('ipam::partials.nav', ['current' => 'addresses'])

        {{-- what the last action answered, and what validation refused: without this a refused form looks exactly like a working one --}}
        @include('ipam::partials.notices')
        @include('ipam::partials.options')
        @include('ipam::partials.fill')

        @include('ipam::partials.stats', ['stats' => [
            ['label' => __('ipam::ipam.addresses.summary_total'), 'value' => $summary['total'] ?? 0],
            [
                'label' => __('ipam::ipam.addresses.summary_unclassified'),
                'value' => $summary['unclassified'] ?? 0,
                'hint' => ($summary['unclassified'] ?? 0) > 0
                    ? __('ipam::ipam.addresses.unclassified_hint')
                    : '',
            ],
            [
                'label' => __('ipam::ipam.addresses.ipv4'),
                'value' => $summary['ipv4'] ?? 0,
            ],
            [
                'label' => __('ipam::ipam.addresses.ipv6'),
                'value' => $summary['ipv6'] ?? 0,
            ],
        ]])

        <x-panel id="ipam-addresses-panel">
            <x-slot name="title">
                <i class="fa fa-list fa-fw" aria-hidden="true"></i>
                {{ __('ipam::ipam.addresses.title') }}
            </x-slot>

            <p class="text-muted">{{ __('ipam::ipam.addresses.intro') }}</p>
            @if ($options['owners'] === [] || $options['functions'] === [])
                @include('ipam::partials.empty-taxonomy')
            @endif

            <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:mb-3">
                <form method="get" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:m-0">
                    {{-- every field this form carries by itself stays out of the
                         hidden echo: a checkbox that is unchecked is absent from
                         the submit, and a hidden input with the same name would
                         put the filter back and make it impossible to clear --}}
                    @foreach (request()->except(['q', 'page', 'unclassified', 'show_hidden']) as $key => $value)
                        @if (is_scalar($value) && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="addr-q">{{ __('ipam::ipam.addresses.search') }}</label>
                        <input type="text" id="addr-q" name="q" class="form-control form-control-sm"
                               value="{{ request('q') }}"
                               placeholder="{{ __('ipam::ipam.addresses.search') }}"
                               style="min-width: 18rem;">
                    </div>
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="addr-family">{{ __('ipam::ipam.addresses.family') }}</label>
                        <select name="family" id="addr-family" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.addresses.any_family') }}</option>
                            <option value="4" @selected(request('family') === '4')>{{ __('ipam::ipam.addresses.ipv4') }}</option>
                            <option value="6" @selected(request('family') === '6')>{{ __('ipam::ipam.addresses.ipv6') }}</option>
                        </select>
                    </div>
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="addr-owner">{{ __('ipam::ipam.addresses.owner') }}</label>
                        <select name="owner_id" id="addr-owner" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.addresses.owner') }}: {{ __('ipam::ipam.networks.all') }}</option>
                            @foreach ($options['owners'] as $id => $name)
                                <option value="{{ $id }}" @selected((string) request('owner_id') === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="addr-function">{{ __('ipam::ipam.addresses.function') }}</label>
                        <select name="function_id" id="addr-function" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.addresses.function') }}: {{ __('ipam::ipam.networks.all') }}</option>
                            @foreach ($options['functions'] as $id => $name)
                                <option value="{{ $id }}" @selected((string) request('function_id') === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check tw:mb-0 tw:mr-2">
                        <label class="tw:mb-0">
                            <input type="checkbox" name="unclassified" value="yes" @checked(request('unclassified') === 'yes')
                                   onchange="this.form.submit()">
                            {{ __('ipam::ipam.addresses.only_unclassified') }}
                        </label>
                    </div>
                    <div class="form-check tw:mb-0 tw:mr-2">
                        <label class="tw:mb-0" title="{{ __('ipam::ipam.hidden_hint') }}">
                            <input type="checkbox" name="show_hidden" value="1" @checked(request()->boolean('show_hidden'))
                                   onchange="this.form.submit()">
                            {{ __('ipam::ipam.show_hidden') }}
                            @if (($summary['ignored'] ?? 0) > 0)
                                <span class="label label-default">{{ number_format($summary['ignored']) }}</span>
                            @endif
                        </label>
                    </div>
                    <button type="submit" class="btn btn-default btn-sm">{{ __('ipam::ipam.networks.filter_described') }}</button>
                </form>

                @include('ipam::partials.site-filter', [
                    'sites' => $options['sites'],
                    'rooms' => $directory->roomCounts(),
                ])
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped" id="ipam-addresses-table">
                    <thead>
                    <tr>
                        @can('ipam.manage')
                            <th style="width: 2rem;">
                                <input type="checkbox" id="ipam-select-page" title="{{ __('ipam::ipam.addresses.select_all') }}"
                                       class="ipam-row-selector">
                            </th>
                        @endcan
                        <th>@include('ipam::partials.sortable', ['column' => 'address', 'label' => __('ipam::ipam.addresses.address')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'block', 'label' => __('ipam::ipam.addresses.block')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'site', 'label' => __('ipam::ipam.addresses.site')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'vlan', 'label' => __('ipam::ipam.addresses.vlan')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'owner', 'label' => __('ipam::ipam.addresses.owner')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'function', 'label' => __('ipam::ipam.addresses.function')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'device', 'label' => __('ipam::ipam.addresses.device')])</th>
                        <th>{{ __('ipam::ipam.addresses.state') }}</th>
                        @can('ipam.manage')
                            <th></th>
                        @endcan
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($addresses ?? [] as $address)
                        <tr>
                            @can('ipam.manage')
                                <td>
                                    <input type="checkbox" name="ids[]" value="{{ $address->address_record_id }}"
                                           class="ipam-row-selector" form="ipam-bulk-form">
                                </td>
                            @endcan
                            <td>
                                <code class="tw:text-gray-900 tw:dark:text-gray-100">{{ $address->address }}</code>
                                <span class="text-muted tw:text-xs">/{{ $address->prefixlen }}</span>
                                @if ($address->context_name)
                                    <span class="label label-default tw:ml-1" title="{{ __('ipam::ipam.addresses.context') }}">
                                        {{ $address->context_name }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if ($address->network)
                                    <code class="tw:text-xs">{{ $address->network->cidr }}</code>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                @include('ipam::partials.site-cell', [
                                    'siteId' => $address->site_id,
                                    'siteName' => $directory->name((int) $address->site_id),
                                    // an address carries one location, not a group,
                                    // so the room is named outright
                                    'rooms' => $address->location ? [$address->location->name] : [],
                                ])
                            </td>
                            <td>
                                @if ($address->vlan)
                                    <span class="tw:font-mono">{{ $address->vlan->vlan_id }}</span>
                                    @if ($address->vlan->name)
                                        <div class="tw:text-xs tw:text-gray-500">{{ $address->vlan->name }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>{{ $address->owner?->name ?? '-' }}</td>
                            <td>{{ $address->function?->name ?? '-' }}</td>
                            <td>
                                @php($addressDevice = $address->device())
                                @if ($addressDevice && \Illuminate\Support\Facades\Gate::forUser(request()->user())->allows('view', $addressDevice))
                                    <a href="{{ url('device/' . $address->device_id) }}">{{ $addressDevice->display }}</a>
                                @elseif ($address->device_id > 0)
                                    {{-- hidden by permission: showing the name here would
                                         leak what is on a device the user cannot open --}}
                                    <span class="text-muted">&mdash;</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                                @php($addressPort = $address->port())
                                @if ($addressPort?->ifName && $addressDevice)
                                    <div class="tw:text-xs tw:text-gray-500">{{ $addressPort->ifName }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($address->ignored)
                                    <span class="label label-default" title="{{ __('ipam::ipam.hidden_hint') }}">{{ __('ipam::ipam.hidden') }}</span>
                                @endif
                                @if ($address->scope_locked)
                                    <span class="label label-info">{{ __('ipam::ipam.addresses.pinned') }}</span>
                                @elseif ($address->isUnscoped())
                                    <span class="label label-warning">{{ __('ipam::ipam.addresses.unclassified') }}</span>
                                @else
                                    <span class="label label-success">{{ __('ipam::ipam.addresses.classified') }}</span>
                                @endif
                            </td>
                            @can('ipam.manage')
                                <td class="text-right" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-xs btn-default"
                                            data-toggle="collapse" data-target="#addr-{{ $address->address_record_id }}">
                                        {{ __('ipam::ipam.addresses.edit') }}
                                    </button>
                                </td>
                            @endcan
                        </tr>
                        @can('ipam.manage')
                            <tr id="addr-{{ $address->address_record_id }}" class="collapse">
                                <td colspan="10" style="background-color: rgba(0,0,0,.02);">
                                    <form method="post"
                                          action="{{ \Illuminate\Support\Facades\Route::has('ipam.addresses.update') ? route('ipam.addresses.update', $address->address_record_id) : url('plugin/ipam/addresses/' . $address->address_record_id) }}">
                                        @csrf
                                        @method('PUT')
                                        <div class="row">
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.addresses.site') }}</label>
                                                <select name="site_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-sites" data-selected="{{ $address->site_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.networks.room') }}</label>
                                                <select name="location_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-rooms" data-selected="{{ $address->location_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.addresses.vlan') }}</label>
                                                <select name="vlan_record_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-vlans" data-selected="{{ $address->vlan_record_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.addresses.owner') }}</label>
                                                <select name="net_owner_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-owners" data-selected="{{ $address->net_owner_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.addresses.function') }}</label>
                                                <select name="net_function_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-functions" data-selected="{{ $address->net_function_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-3">
                                                <label>{{ __('ipam::ipam.addresses.description') }}</label>
                                                <input type="text" name="description" class="form-control form-control-sm"
                                                       value="{{ $address->description }}" maxlength="255">
                                            </div>
                                            <div class="form-group col-md-1">
                                                <label>&nbsp;</label>
                                                <div class="form-check">
                                                    <label class="tw:mb-0" title="{{ __('ipam::ipam.addresses.lock') }}">
                                                        <input type="checkbox" name="lock" value="1" @checked($address->scope_locked)>
                                                        {{ __('ipam::ipam.addresses.lock_short') }}
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <label class="tw:mb-0" title="{{ __('ipam::ipam.hidden_hint') }}">
                                                        <input type="checkbox" name="ignored" value="1" @checked($address->ignored)>
                                                        {{ __('ipam::ipam.hidden') }}
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.addresses.save') }}</button>
                                    </form>

                                    @if ($address->isManual())
                                        <form method="post"
                                              action="@include('ipam::partials.action', ['route' => 'ipam.addresses.destroy', 'params' => [$address->address_record_id], 'fallback' => 'plugin/ipam/addresses/' . $address->address_record_id])"
                                              onsubmit="return confirm('{{ __('ipam::ipam.addresses.delete_confirm') }}');"
                                              class="tw:mt-2">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-xs">{{ __('ipam::ipam.addresses.delete') }}</button>
                                            <span class="text-muted small tw:ml-1">{{ __('ipam::ipam.addresses.delete_hint') }}</span>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endcan
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted">{{ __('ipam::ipam.not_installed') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if (is_object($addresses ?? null) && method_exists($addresses, 'links'))
                {!! $addresses->links() !!}
            @endif

            @can('ipam.manage')
                {{-- the selection bar: what is ticked, and the way to take every
                     row the filters match instead of ticking by hand --}}
                <div class="panel panel-default tw:mt-3" id="ipam-bulk-bar">
                    <div class="panel-body tw:flex tw:flex-wrap tw:items-center tw:gap-3">
                        <span class="label label-info" id="ipam-selected-count">{{ __('ipam::ipam.addresses.selected_count', ['count' => 0]) }}</span>

                        <span class="text-muted tw:text-sm">{{ __('ipam::ipam.bulk.intro') }}</span>

                        <label class="form-check tw:mb-0 tw:ml-auto" title="{{ __('ipam::ipam.addresses.select_all_matching') }}">
                            <input type="checkbox" name="select_all_matching" value="1" form="ipam-bulk-form">
                            {{ __('ipam::ipam.addresses.select_all_matching') }}
                        </label>
                    </div>
                </div>
            @endcan
        </x-panel>

        <x-panel id="ipam-free-panel">
            <x-slot name="title">
                <i class="fa fa-search fa-fw" aria-hidden="true"></i>
                {{ __('ipam::ipam.free.title') }}
            </x-slot>

            <p class="text-muted">{{ __('ipam::ipam.free.intro') }}</p>

            <form method="get" class="form-inline">
                @foreach (request()->except(['free', 'page']) as $key => $value)
                    @if (is_scalar($value) && $value !== '')
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <div class="form-group mr-2">
                    <label class="sr-only" for="free-cidr">{{ __('ipam::ipam.free.range') }}</label>
                    <input type="text" id="free-cidr" name="free" class="form-control form-control-sm"
                           value="{{ request('free') }}"
                           placeholder="10.14.146.0/24" style="min-width: 16rem;">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.free.search') }}</button>
            </form>

            @if (($free ?? null) !== null)
                <hr>
                <div class="row">
                    <div class="col-md-3">
                        <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.free.in_use') }}</div>
                        <div class="tw:text-2xl tw:font-semibold">{{ number_format($free['used_count']) }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.free.free_count') }}</div>
                        <div class="tw:text-2xl tw:font-semibold tw:text-green-600">{{ number_format($free['free_count']) }}</div>
                    </div>
                    <div class="col-md-6">
                        @if ($free['suggestions'] !== [])
                            <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.free.suggestions') }}</div>
                            @foreach ($free['suggestions'] as $address)
                                <code class="tw:mr-2 tw:text-base">{{ $address }}</code>
                            @endforeach
                        @else
                            <div class="text-muted">{{ __('ipam::ipam.free.nothing_free') }}</div>
                        @endif
                    </div>
                </div>

                @if ($free['gaps'] !== [])
                    <div class="table-responsive tw:mt-3">
                        <table class="table table-sm table-condensed table-striped">
                            <thead>
                            <tr>
                                <th>{{ __('ipam::ipam.free.from') }}</th>
                                <th>{{ __('ipam::ipam.free.to') }}</th>
                                <th class="text-right">{{ __('ipam::ipam.free.how_many') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($free['gaps'] as $gap)
                                <tr>
                                    <td><code>{{ $gap['start'] }}</code></td>
                                    <td><code>{{ $gap['end'] }}</code></td>
                                    <td class="text-right">{{ number_format($gap['count']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </x-panel>

        @can('ipam.manage')
            <x-panel id="ipam-bulk-panel">
                <x-slot name="title">
                    <i class="fa fa-check-double fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.bulk.title') }}
                </x-slot>

                <form method="post"
                      action="{{ \Illuminate\Support\Facades\Route::has('ipam.addresses.bulk') ? route('ipam.addresses.bulk') : url('plugin/ipam/addresses/bulk') }}"
                      id="ipam-bulk-form">
                    @csrf

                    {{-- the filters the list is showing, replayed on the server:
                         "every address matching the filters" has to mean the
                         same rows the operator was looking at, and the same
                         builder that made the list is the only honest way to
                         rebuild that set --}}
                    @foreach (['q', 'family', 'site_id', 'vlan_record_id', 'network_id', 'owner_id', 'function_id', 'unclassified', 'show_hidden'] as $filterKey)
                        @if (is_scalar(request($filterKey)) && request($filterKey) !== '')
                            <input type="hidden" name="{{ $filterKey }}" value="{{ request($filterKey) }}">
                        @endif
                    @endforeach

                    <div class="row">
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.site') }}</label>
                            <select name="site_id" class="form-control form-control-sm">
                                <option value="">{{ __('ipam::ipam.bulk.leave_unchanged') }}</option>
                                <option value="0">{{ __('ipam::ipam.bulk.inherit') }}</option>
                                @foreach ($options['sites'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.vlan') }}</label>
                            <select name="vlan_record_id" class="form-control form-control-sm">
                                <option value="">{{ __('ipam::ipam.bulk.leave_unchanged') }}</option>
                                <option value="0">{{ __('ipam::ipam.bulk.inherit') }}</option>
                                @foreach ($options['vlans'] as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.owner') }}</label>
                            <select name="net_owner_id" class="form-control form-control-sm">
                                <option value="">{{ __('ipam::ipam.bulk.leave_unchanged') }}</option>
                                <option value="0">{{ __('ipam::ipam.bulk.inherit') }}</option>
                                @foreach ($options['owners'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.function') }}</label>
                            <select name="net_function_id" class="form-control form-control-sm">
                                <option value="">{{ __('ipam::ipam.bulk.leave_unchanged') }}</option>
                                <option value="0">{{ __('ipam::ipam.bulk.inherit') }}</option>
                                @foreach ($options['functions'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>&nbsp;</label>
                            <div class="form-check">
                                <label class="tw:mb-0" title="{{ __('ipam::ipam.bulk.pin') }}">
                                    <input type="checkbox" name="pin" value="1">
                                    {{ __('ipam::ipam.addresses.lock_short') }}
                                </label>
                            </div>
                        </div>
                        <div class="form-group col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.bulk.apply') }}</button>
                        </div>
                    </div>
                </form>
            </x-panel>

            {{-- selection: the page checkbox, the counter, and nothing more.
                 jQuery is already on the page, and the two behaviours are one
                 line each --}}
            @push('scripts')
                <script>
                    $(function () {
                        var rows = '#ipam-addresses-table input.ipam-row-selector';

                        function countSelected() {
                            return $(rows + ':checked').length;
                        }

                        function showCount() {
                            var label = {{ \Illuminate\Support\Js::from(__('ipam::ipam.addresses.selected_count', ['count' => ':count'])) }};
                            $('#ipam-selected-count').text(label.replace(':count', countSelected()));
                        }

                        // the header box ticks the page, and only the page:
                        // "everything" is a different checkbox with a server
                        // side meaning, and conflating the two would change
                        // rows the operator never saw
                        $('#ipam-select-page').on('change', function () {
                            $(rows).prop('checked', this.checked);
                            showCount();
                        });

                        $(rows).on('change', showCount);
                        showCount();
                    });
                </script>
            @endpush
        @endcan

        @can('ipam.manage')
            <x-panel id="ipam-add-address-panel">
                <x-slot name="title">
                    <i class="fa fa-plus fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.addresses.add_title') }}
                </x-slot>

                <p class="text-muted">{{ __('ipam::ipam.addresses.add_intro') }}</p>

                <form method="post"
                      action="{{ \Illuminate\Support\Facades\Route::has('ipam.addresses.store') ? route('ipam.addresses.store') : url('plugin/ipam/addresses') }}">
                    @csrf
                    <div class="row">
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.address') }}</label>
                            <input type="text" name="address" class="form-control form-control-sm"
                                   placeholder="10.14.146.37" required maxlength="64">
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.site') }}</label>
                            <select name="site_id" class="form-control form-control-sm">
                                <option value="0">-- {{ __('ipam::ipam.networks.inherit_hint') }} --</option>
                                @foreach ($options['sites'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.networks.room') }}</label>
                            <select name="location_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['rooms'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.vlan') }}</label>
                            <select name="vlan_record_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['vlans'] as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.owner') }}</label>
                            <select name="net_owner_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['owners'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.function') }}</label>
                            <select name="net_function_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['functions'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.description') }}</label>
                            <input type="text" name="description" class="form-control form-control-sm" maxlength="255">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.addresses.add') }}</button>
                </form>

                <p class="text-muted small tw:mt-2">
                    <i class="fa fa-info-circle" aria-hidden="true"></i>
                    {{ __('ipam::ipam.addresses.add_hint') }}
                </p>
            </x-panel>
        @endcan

        @can('ipam.manage')
            <x-panel id="ipam-apply-block-panel">
                <x-slot name="title">
                    <i class="fa fa-paint-roller fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.addresses.apply_to_block') }}
                </x-slot>

                <p class="text-muted">{{ __('ipam::ipam.addresses.apply_intro') }}</p>

                <form method="post"
                      action="{{ \Illuminate\Support\Facades\Route::has('ipam.addresses.block') ? route('ipam.addresses.block') : url('plugin/ipam/addresses/block') }}">
                    @csrf
                    <div class="row">
                        <div class="form-group col-md-3">
                            <label>{{ __('ipam::ipam.networks.cidr') }}</label>
                            <select name="network_id" class="form-control form-control-sm" required>
                                <option value="">--</option>
                                @foreach ($options['networks'] as $id => $cidr)
                                    <option value="{{ $id }}">{{ $cidr }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.site') }}</label>
                            <select name="site_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['sites'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.networks.room') }}</label>
                            <select name="location_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['rooms'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.vlan') }}</label>
                            <select name="vlan_record_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['vlans'] as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.owner') }}</label>
                            <select name="net_owner_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['owners'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.addresses.function') }}</label>
                            <select name="net_function_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['functions'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-1">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-default btn-sm">
                                {{ __('ipam::ipam.addresses.apply') }}
                            </button>
                        </div>
                    </div>
                    <div class="form-check">
                        <label class="tw:mb-0">
                            <input type="checkbox" name="only_unclassified" value="1" checked>
                            {{ __('ipam::ipam.addresses.only_unclassified') }}
                        </label>
                    </div>
                </form>
            </x-panel>
        @endcan

    </div>
@endsection