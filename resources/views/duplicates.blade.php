@extends('layouts.librenmsv1')

@section('title', __('ipam::ipam.page_titles.duplicates'))

@section('content')
    <div class="container-fluid">
        @include('ipam::partials.nav', ['current' => 'duplicates'])

        @include('ipam::partials.stats', ['stats' => [
            [
                'label' => __('ipam::ipam.duplicates.summary_groups'),
                'value' => $counts['groups'] ?? 0,
                'hint' => __('ipam::ipam.duplicates.total_hint', ['count' => $counts['total'] ?? 0]),
            ],
            [
                'label' => __('ipam::ipam.duplicates.summary_crossing'),
                'value' => $counts['crossing'] ?? 0,
                'hint' => (($counts['crossing'] ?? 0) > 0) ? __('ipam::ipam.duplicates.crossing_hint') : '',
            ],
            [
                'label' => __('ipam::ipam.duplicates.unclassified_title'),
                'value' => $unclassifiedTotal,
                'hint' => __('ipam::ipam.duplicates.unclassified_hint_short'),
            ],
            [
                'label' => __('ipam::ipam.sites.title'),
                'value' => count($options['sites']),
                'hint' => $useVlanScope
                    ? __('ipam::ipam.duplicates.scope_vlan_site')
                    : __('ipam::ipam.duplicates.scope_site_only'),
            ],
        ]])

        <x-panel id="ipam-collisions-panel">
            <x-slot name="title">
                <i class="fa fa-clone fa-fw" aria-hidden="true"></i>
                {{ __('ipam::ipam.duplicates.title') }}
                @if (($counts['groups'] ?? 0) > 0)
                    <span class="label label-danger">{{ $counts['groups'] }}</span>
                @endif
            </x-slot>

            <p class="text-muted">{{ __('ipam::ipam.duplicates.intro') }}</p>

            <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:mb-3">
                <form method="get" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2 tw:m-0">
                    @foreach (request()->except(['site_id', 'page']) as $key => $value)
                        @if (is_scalar($value) && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <div class="form-group tw:mb-0">
                        <label class="sr-only" for="dup-vlan">{{ __('ipam::ipam.duplicates.search_vlan') }}</label>
                        <select name="vlan_record_id" id="dup-vlan" class="form-control form-control-sm" onchange="this.form.submit()">
                            <option value="">{{ __('ipam::ipam.duplicates.any_vlan') }}</option>
                            @foreach ($options['vlans'] as $id => $label)
                                <option value="{{ $id }}" @selected((string) request('vlan_record_id') === (string) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check tw:mb-0 tw:mr-2">
                        <label class="tw:mb-0">
                            <input type="checkbox" name="show_known" value="1" @checked(request()->boolean('show_known'))
                                   onchange="this.form.submit()">
                            {{ __('ipam::ipam.duplicates.show_known') }}
                        </label>
                    </div>
                    <button type="submit" class="btn btn-default btn-sm">{{ __('ipam::ipam.networks.filter_described') }}</button>
                </form>

                @include('ipam::partials.site-filter', [
                    'sites' => $options['sites'],
                    'rooms' => $directory->roomCounts(),
                ])
            </div>

            @if ($groups === [])
                <div class="alert alert-success tw:flex tw:items-center tw:gap-2">
                    <i class="fa fa-check-circle fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.duplicates.none') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                        <tr>
                            <th>{{ __('ipam::ipam.duplicates.address') }}</th>
                            <th>{{ __('ipam::ipam.duplicates.site') }}</th>
                            <th>{{ __('ipam::ipam.duplicates.vlan') }}</th>
                            <th class="text-center">{{ __('ipam::ipam.duplicates.count') }}</th>
                            <th>{{ __('ipam::ipam.duplicates.where') }}</th>
                            <th class="text-right">&nbsp;</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($groups as $group)
                            <tr class="{{ $group->ignored ? 'text-muted' : '' }}">
                                <td>
                                    <code class="tw:text-gray-900 tw:dark:text-gray-100">{{ $group->address }}</code>
                                    @if ($group->crossesDevices())
                                        <span class="label label-danger tw:ml-1">{{ __('ipam::ipam.duplicates.crosses_devices') }}</span>
                                    @endif
                                    @if ($group->ignored)
                                        <span class="label label-default tw:ml-1">{{ __('ipam::ipam.duplicates.known_good') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @include('ipam::partials.site-cell', [
                                        'siteId' => $group->siteId,
                                        'siteName' => $siteLabels[$group->siteId] ?? $directory->name($group->siteId),
                                        'rooms' => $directory->rooms($group->siteId),
                                    ])
                                </td>
                                <td>
                                    @if ($group->vlanRecordId !== null)
                                        <span class="tw:font-mono">{{ $vlanLabels[$group->vlanRecordId] ?? '' }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="label label-danger">{{ $group->count() }}</span>
                                </td>
                                <td class="tw:text-xs tw:text-gray-600 tw:dark:text-gray-400">
                                    {{ $group->summary() }}
                                    @if ($group->note)
                                        <div class="tw:italic tw:text-gray-500">
                                            <i class="fa fa-comment" aria-hidden="true"></i> {{ $group->note }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-right" style="white-space: nowrap;">
                                    @can('ipam.manage')
                                        <button type="button" class="btn btn-xs btn-default"
                                                data-toggle="collapse" data-target="#dup-{{ $group->family }}-{{ $group->address }}-{{ $group->siteId }}-{{ $group->vlanRecordId }}">
                                            {{ __('ipam::ipam.duplicates.detail') }}
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                            @can('ipam.manage')
                                @php($dupId = 'dup-' . $group->family . '-' . $group->address . '-' . $group->siteId . '-' . ($group->vlanRecordId ?? 0))
                                <tr id="{{ $dupId }}" class="collapse">
                                    <td colspan="6" style="background-color: rgba(0,0,0,.02);">
                                        <form method="post"
                                              action="{{ \Illuminate\Support\Facades\Route::has('ipam.duplicates.annotate') ? route('ipam.duplicates.annotate') : url('plugin/ipam/duplicates/annotate') }}">
                                            @csrf
                                            <input type="hidden" name="family" value="{{ $group->family }}">
                                            <input type="hidden" name="address" value="{{ $group->address }}">
                                            <input type="hidden" name="site_id" value="{{ $group->siteId }}">
                                            <input type="hidden" name="vlan_record_id" value="{{ $group->vlanRecordId ?? 0 }}">

                                            <div class="row">
                                                <div class="form-group col-md-3">
                                                    <label for="{{ $dupId }}-note">{{ __('ipam::ipam.duplicates.note') }}</label>
                                                    <input type="text" id="{{ $dupId }}-note" name="note" class="form-control form-control-sm"
                                                           value="{{ $group->note }}"
                                                           placeholder="{{ __('ipam::ipam.duplicates.note_placeholder') }}"
                                                           maxlength="255">
                                                </div>
                                                <div class="form-group col-md-4 tw:flex tw:items-end">
                                                    <div class="form-check tw:mb-1">
                                                        <label class="tw:mb-0">
                                                            <input type="checkbox" name="ignored" value="1" @checked($group->ignored)>
                                                            {{ __('ipam::ipam.duplicates.mark_known') }}
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="form-group col-md-5 tw:flex tw:items-end tw:justify-end">
                                                    <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.duplicates.save') }}</button>
                                                </div>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endcan
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panel>

        @if ($unclassified !== [])
            <x-panel id="ipam-unclassified-panel">
                <x-slot name="title">
                    <i class="fa fa-question-circle fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.duplicates.unclassified_title') }}
                    <span class="label label-warning">{{ $unclassifiedTotal }}</span>
                </x-slot>

                <p class="text-muted">{{ __('ipam::ipam.duplicates.unclassified_intro') }}</p>

                <div class="tw:flex tw:flex-wrap tw:gap-1">
                    @foreach ($unclassified as $address)
                        <a href="{{ \Illuminate\Support\Facades\Route::has('ipam.addresses') ? route('ipam.addresses', ['q' => $address->address]) : url('plugin/ipam/addresses?q=' . $address->address) }}"
                           class="tw:font-mono tw:text-xs tw:px-2 tw:py-0.5 tw:border tw:border-gray-300 tw:dark:border-dark-gray-600 tw:rounded hover:tw:bg-gray-100 hover:tw:bg-dark-gray-300">
                            {{ $address->address }}
                        </a>
                    @endforeach
                </div>

                <div class="tw:mt-3">
                    <a href="{{ \Illuminate\Support\Facades\Route::has('ipam.addresses') ? route('ipam.addresses', ['unclassified' => 'yes']) : url('plugin/ipam/addresses?unclassified=yes') }}"
                       class="btn btn-default btn-sm">
                        {{ __('ipam::ipam.addresses.only_unclassified') }}
                    </a>
                </div>
            </x-panel>
        @endif
    </div>
@endsection