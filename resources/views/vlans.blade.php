@extends('layouts.librenmsv1')

@section('title', __('ipam::ipam.page_titles.vlans'))

@section('content')
    <div class="container-fluid">
        @include('ipam::partials.nav', ['current' => 'vlans'])
        @include('ipam::partials.options')
        @include('ipam::partials.fill')

        @include('ipam::partials.stats', ['stats' => [
            ['label' => __('ipam::ipam.vlans.summary_total'), 'value' => $summary['total'] ?? 0],
            [
                'label' => __('ipam::ipam.vlans.summary_ids'),
                'value' => $summary['ids'] ?? 0,
                'hint' => (($summary['total'] ?? 0) > ($summary['ids'] ?? 0))
                    ? __('ipam::ipam.vlans.ids_repeated')
                    : '',
            ],
            ['label' => __('ipam::ipam.vlans.summary_described'), 'value' => $summary['described'] ?? 0],
            [
                'label' => __('ipam::ipam.vlans.summary_inconsistent'),
                'value' => $summary['inconsistent'] ?? 0,
                'hint' => ($summary['inconsistent'] ?? 0) > 0
                    ? __('ipam::ipam.vlans.inconsistent_hint')
                    : __('ipam::ipam.vlans.naming_consistent'),
            ],
        ]])

        <x-panel id="ipam-vlans-panel">
            <x-slot name="title">
                <i class="fa fa-network-wired fa-fw" aria-hidden="true"></i>
                {{ __('ipam::ipam.vlans.title') }}
            </x-slot>

            <p class="text-muted">{{ __('ipam::ipam.vlans.intro') }}</p>
            @if ($options['owners'] === [])
                @include('ipam::partials.empty-taxonomy')
            @endif

            <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:mb-3">
                <form method="get" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:m-0">
                    @foreach (request()->except(['q', 'page']) as $key => $value)
                        @if (is_scalar($value) && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="vlan-q">{{ __('ipam::ipam.vlans.search') }}</label>
                        <input type="text" id="vlan-q" name="q" class="form-control form-control-sm"
                               value="{{ request('q') }}"
                               placeholder="{{ __('ipam::ipam.vlans.search') }}">
                    </div>
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="vlan-desc">{{ __('ipam::ipam.vlans.described') }}</label>
                        <select name="described" id="vlan-desc" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.networks.all') }}</option>
                            <option value="yes" @selected(request('described') === 'yes')>{{ __('ipam::ipam.networks.only_described') }}</option>
                            <option value="no" @selected(request('described') === 'no')>{{ __('ipam::ipam.networks.only_undescribed') }}</option>
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
                        <th class="text-center" style="width: 5rem;">{{ __('ipam::ipam.vlans.id') }}</th>
                        <th>{{ __('ipam::ipam.vlans.site') }}</th>
                        <th>{{ __('ipam::ipam.vlans.name') }}</th>
                        <th>{{ __('ipam::ipam.vlans.owner') }}</th>
                        <th>{{ __('ipam::ipam.vlans.description') }}</th>
                        <th class="text-right">{{ __('ipam::ipam.vlans.addresses') }}</th>
                        @can('ipam.manage')
                            <th></th>
                        @endcan
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($vlans ?? [] as $vlan)
                        @php($spread = $vlan->nameSpread())
                        <tr>
                            <td class="text-center">
                                <span class="tw:font-mono tw:text-base tw:font-semibold">{{ $vlan->vlan_id }}</span>
                                @if ($vlan->ignored)
                                    <div><span class="label label-default">{{ __('ipam::ipam.hidden') }}</span></div>
                                @elseif ($vlan->vlan_domain > 1)
                                    <div class="tw:text-xs tw:text-gray-500">{{ __('ipam::ipam.vlans.domain') }} {{ $vlan->vlan_domain }}</div>
                                @endif
                            </td>
                            <td>
                                @include('ipam::partials.site-cell', [
                                    'siteId' => $vlan->site_id,
                                    'siteName' => $directory->name((int) $vlan->site_id),
                                    'rooms' => $vlan->locationNames(),
                                ])
                            </td>
                            <td>
                                {{ $vlan->name ?? '-' }}
                                @if ($spread['distinct'] > 1)
                                    <div>
                                        <span class="label label-warning" title="{{ $spread['names']->implode(', ') }}">
                                            {{ trans_choice('ipam::ipam.vlans.names_differ', $spread['distinct'], ['count' => $spread['distinct']]) }}
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td>{{ $vlan->owner?->name ?? '-' }}</td>
                            <td class="tw:text-sm">{{ $vlan->description }}</td>
                            <td class="text-right">{{ number_format($vlan->addresses_count ?? 0) }}</td>
                            @can('ipam.manage')
                                <td class="text-right" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-xs btn-default"
                                            data-toggle="collapse" data-target="#vlan-{{ $vlan->vlan_record_id }}">
                                        {{ __('ipam::ipam.vlans.edit') }}
                                    </button>
                                </td>
                            @endcan
                        </tr>
                        @can('ipam.manage')
                            <tr id="vlan-{{ $vlan->vlan_record_id }}" class="collapse">
                                <td colspan="7" style="background-color: rgba(0,0,0,.02);">
                                    <form method="post"
                                          action="{{ \Illuminate\Support\Facades\Route::has('ipam.vlans.update') ? route('ipam.vlans.update', $vlan->vlan_record_id) : url('plugin/ipam/vlans/' . $vlan->vlan_record_id) }}">
                                        @csrf
                                        <div class="row">
                                            <div class="form-group col-md-3">
                                                <label>{{ __('ipam::ipam.vlans.site') }}</label>
                                                <select name="site_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-sites" data-selected="{{ $vlan->site_id }}">
                                                </select>
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label>{{ __('ipam::ipam.vlans.owner') }}</label>
                                                <select name="vlan_owner_id" class="form-control form-control-sm"
                                                        data-fill="ipam-options-owners" data-selected="{{ $vlan->vlan_owner_id }}">
                                                    <option value="0">--</option>
                                                </select>
                                            </div>
                                            <div class="form-group col-md-3">
                                                <label>{{ __('ipam::ipam.vlans.name') }}</label>
                                                <input type="text" name="name" class="form-control form-control-sm"
                                                       value="{{ $vlan->name }}" maxlength="255">
                                            </div>
                                            <div class="form-group col-md-4">
                                                <label>{{ __('ipam::ipam.vlans.description') }}</label>
                                                <input type="text" name="description" class="form-control form-control-sm"
                                                       value="{{ $vlan->description }}" maxlength="1000">
                                            </div>
                                            <div class="form-group col-md-1">
                                                <label>&nbsp;</label>
                                                <div class="form-check">
                                                    <label class="tw:mb-0" title="{{ __('ipam::ipam.hidden_hint') }}">
                                                        <input type="checkbox" name="ignored" value="1" @checked($vlan->ignored)>
                                                        {{ __('ipam::ipam.hidden') }}
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <p class="text-muted small">
                                            <i class="fa fa-info-circle" aria-hidden="true"></i>
                                            {{ __('ipam::ipam.vlans.name_hint') }}
                                        </p>

                                        <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.vlans.save') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endcan
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted">{{ __('ipam::ipam.not_installed') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if (is_object($vlans ?? null) && method_exists($vlans, 'links'))
                {!! $vlans->links() !!}
            @endif
        </x-panel>

        @can('ipam.manage')
            <x-panel id="ipam-add-vlan-panel">
                <x-slot name="title">
                    <i class="fa fa-plus fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.vlans.add_title') }}
                </x-slot>

                <p class="text-muted">{{ __('ipam::ipam.vlans.add_intro') }}</p>

                <form method="post"
                      action="{{ \Illuminate\Support\Facades\Route::has('ipam.vlans.store') ? route('ipam.vlans.store') : url('plugin/ipam/vlans') }}">
                    @csrf
                    <div class="row">
                        <div class="form-group col-md-1">
                            <label>{{ __('ipam::ipam.vlans.id') }}</label>
                            <input type="number" name="vlan_id" class="form-control form-control-sm"
                                   min="1" max="4094" required>
                        </div>
                        <div class="form-group col-md-1">
                            <label>{{ __('ipam::ipam.vlans.domain') }}</label>
                            <input type="number" name="vlan_domain" class="form-control form-control-sm"
                                   min="1" value="1">
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.vlans.site') }}</label>
                            <select name="site_id" class="form-control form-control-sm" required>
                                <option value="">--</option>
                                @foreach ($options['sites'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>{{ __('ipam::ipam.vlans.owner') }}</label>
                            <select name="vlan_owner_id" class="form-control form-control-sm">
                                <option value="0">--</option>
                                @foreach ($options['owners'] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>{{ __('ipam::ipam.vlans.name') }}</label>
                            <input type="text" name="name" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <div class="form-group col-md-3">
                            <label>{{ __('ipam::ipam.vlans.description') }}</label>
                            <input type="text" name="description" class="form-control form-control-sm" maxlength="1000">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.vlans.add') }}</button>
                </form>

                <p class="text-muted small tw:mt-2">
                    <i class="fa fa-info-circle" aria-hidden="true"></i>
                    {{ __('ipam::ipam.vlans.add_hint') }}
                </p>
            </x-panel>
        @endcan
    </div>
@endsection