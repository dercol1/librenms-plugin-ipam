@extends('layouts.librenmsv1')

@section('title', __('ipam::ipam.page_titles.sites'))

@section('content')
    <div class="container-fluid">
        @include('ipam::partials.nav', ['current' => 'sites'])

        {{-- what the last action answered, and what validation refused: without this a refused form looks exactly like a working one --}}
        @include('ipam::partials.notices')

        @include('ipam::partials.stats', ['stats' => [
            [
                'label' => __('ipam::ipam.sites.title'),
                'value' => $summary['sites'],
                'hint' => __('ipam::ipam.sites.name'),
            ],
            [
                'label' => __('ipam::ipam.sites.locations'),
                'value' => $summary['locations'],
                'hint' => __('ipam::ipam.sites.rooms_count', ['count' => $summary['grouped']]),
            ],
            [
                'label' => __('ipam::ipam.sites.vlans'),
                'value' => $summary['vlans'],
                'hint' => __('ipam::ipam.sites.vlan_hint'),
            ],
            [
                'label' => __('ipam::ipam.sites.unassigned'),
                'value' => $unassigned->count(),
                'hint' => __('ipam::ipam.sites.unassigned_hint'),
            ],
        ]])

        @if ($suggestions !== [])
            <x-panel id="ipam-suggestions-panel">
                <x-slot name="title">
                    <i class="fa fa-lightbulb fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.sites.suggest_title') }}
                    <span class="label label-warning">{{ count($suggestions) }}</span>
                </x-slot>

                <p class="text-muted">{{ __('ipam::ipam.sites.suggest_intro') }}</p>

                @foreach ($suggestions as $index => $suggestion)
                    <div class="panel panel-default">
                        <div class="panel-heading tw:flex tw:items-center tw:justify-between tw:flex-wrap tw:gap-2">
                            <span>
                                <strong>{{ $suggestion['name'] }}</strong>
                                <span class="text-muted small tw:ml-2">{{ $suggestion['reason'] }}</span>
                            </span>
                            @if ($canManage)
                                <form method="post"
                                      action="{{ \Illuminate\Support\Facades\Route::has('ipam.sites.suggestion') ? route('ipam.sites.suggestion') : url('plugin/ipam/sites/suggestion') }}"
                                      class="tw:flex tw:items-center tw:gap-2 tw:m-0">
                                    @csrf
                                    @foreach ($suggestion['site_ids'] as $siteId)
                                        <input type="hidden" name="site_ids[]" value="{{ $siteId }}">
                                    @endforeach
                                    <label class="sr-only" for="suggest-name-{{ $index }}">{{ __('ipam::ipam.sites.name') }}</label>
                                    <input type="text" id="suggest-name-{{ $index }}" name="name" class="form-control form-control-sm"
                                           value="{{ $suggestion['name'] }}" maxlength="128" style="width: 14rem;"
                                           title="{{ __('ipam::ipam.sites.suggest_accept') }}">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class="fa fa-compress" aria-hidden="true"></i>
                                        {{ __('ipam::ipam.sites.suggest_accept') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                        <div class="panel-body">
                            <ul class="tw:list-none tw:mb-0 tw:grid tw:grid-cols-1 sm:tw:grid-cols-2 tw:gap-2">
                                @foreach ($suggestion['locations'] as $location)
                                    <li class="tw:text-sm">
                                        <i class="fa fa-map-marker tw:text-gray-400" aria-hidden="true"></i>
                                        <code>{{ $location['name'] }}</code>
                                        <span class="text-muted small tw:ml-1">{{ __('ipam::ipam.sites.name') }}: {{ $location['site'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endforeach
            </x-panel>
        @else
            <x-panel id="ipam-sites-panel">
                <x-slot name="title">
                    <i class="fa fa-lightbulb fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.sites.suggest_title') }}
                </x-slot>
                <p class="text-muted mb-0">{{ __('ipam::ipam.sites.no_suggestions') }}</p>
            </x-panel>
        @endif

        <x-panel id="ipam-sites-list-panel">
            <x-slot name="title">
                <i class="fa fa-building fa-fw" aria-hidden="true"></i>
                {{ __('ipam::ipam.sites.title') }}
            </x-slot>

            <p class="text-muted">{{ __('ipam::ipam.sites.intro') }}</p>

            <form method="get" class="form-inline tw:mb-3">
                <div class="form-group mr-2">
                    <input type="text" name="q" class="form-control form-control-sm"
                           value="{{ request('q') }}"
                           placeholder="{{ __('ipam::ipam.sites.locations') }}">
                </div>
                <button type="submit" class="btn btn-default btn-sm">{{ __('ipam::ipam.networks.filter_described') }}</button>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped">
                    <thead>
                    <tr>
                        <th>{{ __('ipam::ipam.sites.name') }}</th>
                        <th>{{ __('ipam::ipam.sites.locations') }}</th>
                        <th class="text-right">{{ __('ipam::ipam.sites.vlans') }}</th>
                        <th class="text-right">{{ __('ipam::ipam.sites.addresses') }}</th>
                        <th class="text-right">{{ __('ipam::ipam.sites.networks') }}</th>
                        @if ($canManage)
                            <th></th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($sites as $row)
                        @php($site = $row['site'])
                        <tr>
                            <td>
                                <strong>{{ $site->name }}</strong>
                                @if ($site->description)
                                    <div class="text-muted small">{{ $site->description }}</div>
                                @endif
                            </td>
                            <td>
                                @forelse ($row['locations'] as $location)
                                    <span class="label label-default" title="{{ $location->name }}">{{ $location->name }}</span>
                                @empty
                                    <span class="text-muted small">{{ __('ipam::ipam.sites.no_locations') }}</span>
                                @endforelse
                            </td>
                            <td class="text-right">{{ number_format($row['vlans']) }}</td>
                            <td class="text-right">{{ number_format($row['addresses']) }}</td>
                            <td class="text-right">{{ number_format($row['networks']) }}</td>
                            @if ($canManage)
                                <td class="text-right" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-xs btn-default"
                                            data-toggle="collapse" data-target="#site-{{ $site->site_id }}">
                                        {{ __('ipam::ipam.sites.edit') }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                        @if ($canManage)
                            <tr id="site-{{ $site->site_id }}" class="collapse">
                                <td colspan="6" style="background-color: rgba(0,0,0,.02);">
                                    <form method="post"
                                          action="{{ \Illuminate\Support\Facades\Route::has('ipam.sites.update') ? route('ipam.sites.update', $site->site_id) : url('plugin/ipam/sites/' . $site->site_id) }}"
                                          class="tw:flex tw:items-end tw:flex-wrap tw:gap-3">
                                        @csrf
                                        @method('PUT')
                                        <div class="form-group tw:mb-0">
                                            <label for="site-name-{{ $site->site_id }}">{{ __('ipam::ipam.sites.name') }}</label>
                                            <input type="text" id="site-name-{{ $site->site_id }}" name="name" class="form-control form-control-sm"
                                                   value="{{ $site->name }}" maxlength="128" required>
                                        </div>
                                        <div class="form-group tw:mb-0" style="min-width: 16rem; flex: 1;">
                                            <label for="site-desc-{{ $site->site_id }}">{{ __('ipam::ipam.sites.description') }}</label>
                                            <input type="text" id="site-desc-{{ $site->site_id }}" name="description" class="form-control form-control-sm"
                                                   value="{{ $site->description }}" maxlength="255">
                                        </div>
                                        <div class="form-group tw:mb-0" style="min-width: 14rem; flex: 1;">
                                            <label for="site-addr-{{ $site->site_id }}">{{ __('ipam::ipam.sites.address') }}</label>
                                            <input type="text" id="site-addr-{{ $site->site_id }}" name="address" class="form-control form-control-sm"
                                                   value="{{ $site->address }}" maxlength="255">
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.sites.save') }}</button>
                                    </form>

                                    @if ($row['locations']->isNotEmpty())
                                        <hr>
                                        <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.sites.move_location') }}</div>
                                        <div class="tw:flex tw:flex-wrap tw:gap-2 tw:mt-1">
                                            @foreach ($row['locations'] as $location)
                                                <form method="post"
                                                      action="{{ \Illuminate\Support\Facades\Route::has('ipam.sites.move-location') ? route('ipam.sites.move-location') : url('plugin/ipam/sites/move-location') }}"
                                                      class="tw:flex tw:items-center tw:gap-1 tw:m-0">
                                                    @csrf
                                                    <input type="hidden" name="location_id" value="{{ $location->location_id }}">
                                                    <code class="tw:text-xs">{{ $location->name }}</code>
                                                    <select name="site_id" class="form-control form-control-sm" onchange="this.form.submit()">
                                                        @foreach ($siteOptions as $id => $name)
                                                            <option value="{{ $id }}" @selected($id === $location->site_id)>{{ $name }}</option>
                                                        @endforeach
                                                    </select>
                                                </form>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">{{ __('ipam::ipam.sites.no_locations') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canManage)
                <hr>
                <form method="post" action="{{ \Illuminate\Support\Facades\Route::has('ipam.sites.store') ? route('ipam.sites.store') : url('plugin/ipam/sites') }}">
                    @csrf
                    <div class="form-inline">
                        <div class="form-group mr-2">
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="{{ __('ipam::ipam.sites.name') }}" maxlength="128" required>
                        </div>
                        <button type="submit" class="btn btn-default btn-sm">
                            <i class="fa fa-plus" aria-hidden="true"></i>
                            {{ __('ipam::ipam.sites.new') }}
                        </button>
                    </div>
                </form>
            @endif
        </x-panel>

        @if ($unassigned->isNotEmpty())
            <x-panel id="ipam-unassigned-panel">
                <x-slot name="title">
                    <i class="fa fa-question-circle fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.sites.unassigned') }}
                    <span class="label label-warning">{{ $unassigned->count() }}</span>
                </x-slot>
                <p class="text-muted">{{ __('ipam::ipam.sites.no_site') }}</p>
                @foreach ($unassigned as $location)
                    <code class="tw:mr-2">{{ $location->name }}</code>
                @endforeach
            </x-panel>
        @endif

        @if ($emptySites->isNotEmpty())
            <x-panel id="ipam-empty-sites-panel">
                <x-slot name="title">
                    <i class="fa fa-trash fa-fw" aria-hidden="true"></i>
                    {{ __('ipam::ipam.sites.empty_title') }}
                    <span class="label label-default">{{ $emptySites->count() }}</span>
                </x-slot>
                <p class="text-muted">{{ __('ipam::ipam.sites.empty_intro') }}</p>
                @if ($canManage)
                    <table class="table table-sm table-striped">
                        <thead>
                        <tr>
                            <th>{{ __('ipam::ipam.sites.name') }}</th>
                            <th class="text-right">&nbsp;</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($emptySites as $site)
                            <tr>
                                <td>{{ $site->name }}</td>
                                <td class="text-right">
                                    <form method="post"
                                          action="{{ \Illuminate\Support\Facades\Route::has('ipam.sites.destroy') ? route('ipam.sites.destroy', $site->site_id) : url('plugin/ipam/sites/' . $site->site_id) }}"
                                          style="display: inline;"
                                          onsubmit="return confirm(@js(__('ipam::ipam.networks.delete')));">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-danger">
                                            {{ __('ipam::ipam.sites.delete_empty') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <ul>
                        @foreach ($emptySites as $site)
                            <li>{{ $site->name }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-panel>
        @endif
    </div>
@endsection