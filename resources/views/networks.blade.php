@extends('layouts.librenmsv1')

@section('title', __('ipam::ipam.page_titles.networks'))

@section('content')
    <div class="container-fluid">
        @include('ipam::partials.nav', ['current' => 'networks'])

        {{-- what the last action answered, and what validation refused: without this a refused form looks exactly like a working one --}}
        @include('ipam::partials.notices')
        @include('ipam::partials.options')
        @include('ipam::partials.fill')

        @include('ipam::partials.stats', ['stats' => [
            ['label' => __('ipam::ipam.networks.blocks'), 'value' => $summary['blocks'] ?? 0],
            [
                'label' => __('ipam::ipam.networks.described_count'),
                'value' => $summary['described'] ?? 0,
                'hint' => ($summary['blocks'] ?? 0) - ($summary['described'] ?? 0) > 0
                    ? trans_choice('ipam::ipam.networks.still_todo', ($summary['blocks'] ?? 0) - ($summary['described'] ?? 0), [
                        'count' => ($summary['blocks'] ?? 0) - ($summary['described'] ?? 0),
                    ])
                    : '',
            ],
            ['label' => __('ipam::ipam.networks.address_count'), 'value' => $summary['addresses'] ?? 0],
            [
                'label' => __('ipam::ipam.sites.title'),
                'value' => count($options['sites']),
                'hint' => __('ipam::ipam.sites.all_sites'),
            ],
        ]])

        <x-panel id="ipam-networks-panel">
            <x-slot name="title">
                <i class="fa fa-sitemap fa-fw" aria-hidden="true"></i>
                {{ __('ipam::ipam.networks.title') }}
            </x-slot>

            <p class="text-muted">{{ __('ipam::ipam.networks.intro') }}</p>
            @if ($options['owners'] === [] || $options['functions'] === [])
                @include('ipam::partials.empty-taxonomy')
            @endif

            <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:mb-3">
                <form method="get" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:m-0">
                    @foreach (request()->except(['q', 'page', 'described', 'show_hidden']) as $key => $value)
                        @if (is_scalar($value) && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="net-q">{{ __('ipam::ipam.networks.search') }}</label>
                        <input type="text" id="net-q" name="q" class="form-control form-control-sm"
                               value="{{ request('q') }}"
                               placeholder="{{ __('ipam::ipam.networks.search') }}">
                    </div>
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="net-desc">{{ __('ipam::ipam.networks.all') }}</label>
                        <select name="described" id="net-desc" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.networks.all') }}</option>
                            <option value="yes" @selected(request('described') === 'yes')>{{ __('ipam::ipam.networks.only_described') }}</option>
                            <option value="no" @selected(request('described') === 'no')>{{ __('ipam::ipam.networks.only_undescribed') }}</option>
                        </select>
                    </div>
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="net-owner">{{ __('ipam::ipam.networks.owner') }}</label>
                        <select name="owner_id" id="net-owner" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.networks.owner') }}: {{ __('ipam::ipam.networks.all') }}</option>
                            @foreach ($options['owners'] as $id => $name)
                                <option value="{{ $id }}" @selected((string) request('owner_id') === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="net-function">{{ __('ipam::ipam.networks.function') }}</label>
                        <select name="function_id" id="net-function" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.networks.function') }}: {{ __('ipam::ipam.networks.all') }}</option>
                            @foreach ($options['functions'] as $id => $name)
                                <option value="{{ $id }}" @selected((string) request('function_id') === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check tw:mb-0 tw:mr-2">
                        <label class="tw:mb-0" title="{{ __('ipam::ipam.hidden_hint') }}">
                            <input type="checkbox" name="show_hidden" value="1" @checked(request()->boolean('show_hidden'))
                                   onchange="this.form.submit()">
                            {{ __('ipam::ipam.show_hidden') }}
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
                <table class="table table-sm table-hover table-striped">
                    <thead>
                    <tr>
                        <th>@include('ipam::partials.sortable', ['column' => 'cidr', 'label' => __('ipam::ipam.networks.cidr')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'site', 'label' => __('ipam::ipam.networks.site')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'owner', 'label' => __('ipam::ipam.networks.owner')])</th>
                        <th>@include('ipam::partials.sortable', ['column' => 'function', 'label' => __('ipam::ipam.networks.function')])</th>
                        <th class="text-right">@include('ipam::partials.sortable', ['column' => 'addresses', 'label' => __('ipam::ipam.networks.addresses')])</th>
                        <th>{{ __('ipam::ipam.networks.description') }}</th>
                        @can('ipam.manage')
                            <th></th>
                        @endcan
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($networks ?? [] as $block)
                        <tr>
                            <td>
                                <code class="tw:text-gray-900 tw:dark:text-gray-100">{{ $block->cidr }}</code>
                                @if ($block->ignored)
                                    <span class="label label-default tw:ml-1">{{ __('ipam::ipam.hidden') }}</span>
                                @elseif ($block->auto_created)
                                    <span class="label label-default tw:ml-1">{{ __('ipam::ipam.networks.auto') }}</span>
                                @else
                                    <span class="label label-info tw:ml-1">{{ __('ipam::ipam.networks.described') }}</span>
                                @endif
                            </td>
                            <td>
                                @include('ipam::partials.site-cell', [
                                    'siteId' => $block->site_id,
                                    'siteName' => $directory->name((int) $block->site_id),
                                    'rooms' => $directory->rooms((int) $block->site_id),
                                ])
                                @if ($block->vlan_id)
                                    <div class="tw:text-xs tw:text-gray-500">
                                        {{ __('ipam::ipam.networks.vlan') }} {{ $block->vlan_id }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $block->owner?->name ?? '-' }}</td>
                            <td>{{ $block->function?->name ?? '-' }}</td>
                            <td class="text-right">{{ number_format($block->addresses_count ?? 0) }}</td>
                            <td class="tw:text-sm">{{ $block->description }}</td>
                            @can('ipam.manage')
                                <td class="text-right" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-xs btn-default"
                                            data-toggle="collapse" data-target="#edit-{{ $block->network_id }}">
                                        {{ __('ipam::ipam.networks.edit') }}
                                    </button>
                                </td>
                            @endcan
                        </tr>
                        @can('ipam.manage')
                            <tr id="edit-{{ $block->network_id }}" class="collapse">
                                <td colspan="7" style="background-color: rgba(0,0,0,.02);">
                                    <form method="post"
                                          action="{{ \Illuminate\Support\Facades\Route::has('ipam.networks.save') ? route('ipam.networks.save') : url('plugin/ipam/networks') }}">
                                        @csrf
                                        <input type="hidden" name="network_id" value="{{ $block->network_id }}">

                                        <div class="row">
                                            <div class="form-group col-md-3">
                                                <label>{{ __('ipam::ipam.networks.site') }}</label>
                                                <select name="site_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-sites" data-selected="{{ $block->site_id }}">
                                                    <option value="0">-- {{ __('ipam::ipam.networks.inherit_hint') }} --</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.networks.vlan') }}</label>
                                                <input type="number" name="vlan_id" class="form-control form-control-sm"
                                                       min="1" max="4094" value="{{ $block->vlan_id ?: '' }}">
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.networks.owner') }}</label>
                                                <select name="net_owner_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-owners" data-selected="{{ $block->net_owner_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.networks.function') }}</label>
                                                <select name="net_function_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-functions" data-selected="{{ $block->net_function_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-3">
                                                <label>{{ __('ipam::ipam.networks.description') }}</label>
                                                <input type="text" name="description" class="form-control form-control-sm"
                                                       value="{{ $block->description }}" maxlength="255">
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>&nbsp;</label>
                                                <div class="form-check">
                                                    <label class="tw:mb-0" title="{{ __('ipam::ipam.hidden_hint') }}">
                                                        <input type="checkbox" name="ignored" value="1" @checked($block->ignored)>
                                                        {{ __('ipam::ipam.hidden') }}
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <p class="text-muted small">
                                            <i class="fa fa-info-circle" aria-hidden="true"></i>
                                            {{ __('ipam::ipam.networks.inherit_hint') }}
                                        </p>

                                        <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.networks.save') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endcan
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                {{ __('ipam::ipam.not_installed') }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if (is_object($networks ?? null) && method_exists($networks, 'links'))
                {!! $networks->links() !!}
            @endif

            @can('ipam.manage')
                <hr>

                <p class="tw:font-bold">{{ __('ipam::ipam.networks.add_title') }}</p>
                <p class="text-muted small">{{ __('ipam::ipam.networks.add_intro') }}</p>

                <form method="post"
                      action="{{ \Illuminate\Support\Facades\Route::has('ipam.networks.ladder') ? route('ipam.networks.ladder') : url('plugin/ipam/networks/ladder') }}">
                    @csrf
                    <div class="row">
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.networks.cidr') }}</label>
                            <input type="text" name="cidr" class="form-control form-control-sm"
                                   placeholder="10.14.146.0/24" required maxlength="64">
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.networks.site') }}</label>
                            <select name="site_id" class="form-control form-control-sm">
                                <option value="0">-- {{ __('ipam::ipam.networks.inherit_hint') }} --</option>
                                @foreach ($options['sites'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-1">
                            <label>{{ __('ipam::ipam.networks.vlan') }}</label>
                            <input type="number" name="vlan_id" class="form-control form-control-sm"
                                   min="1" max="4094">
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.networks.owner') }}</label>
                            <select name="net_owner_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['owners'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.networks.function') }}</label>
                            <select name="net_function_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['functions'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>{{ __('ipam::ipam.networks.description') }}</label>
                            <input type="text" name="description" class="form-control form-control-sm" maxlength="255">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        {{ __('ipam::ipam.networks.with_parents') }}
                    </button>
                </form>
            @endcan
        </x-panel>
    </div>
@endsection