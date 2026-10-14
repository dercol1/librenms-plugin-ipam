@extends('layouts.librenmsv1')

@section('title', __('ipam::ipam.page_titles.taxonomies'))

@section('content')
    <div class="container-fluid">
        @include('ipam::partials.nav', ['current' => 'taxonomies'])

        {{-- what the last action answered, and what validation refused: without this a refused form looks exactly like a working one --}}
        @include('ipam::partials.notices')

        <x-panel id="ipam-taxonomies-intro-panel">
            <x-slot name="title">
                <i class="fa fa-tags fa-fw" aria-hidden="true"></i>
                {{ __('ipam::ipam.taxonomies.title') }}
            </x-slot>

            <p class="text-muted mb-0">{{ __('ipam::ipam.taxonomies.intro') }}</p>
        </x-panel>

        {{-- one panel per list: the three answer different questions and are
             read separately, and one table with a "type" column would suggest
             the entries are interchangeable, which they are not --}}
        @foreach ($lists as $key => $list)
            <x-panel id="ipam-{{ $key }}-panel">
                <x-slot name="title">
                    <i class="fa {{ $key === 'vlan-owner' ? 'fa-network-wired' : 'fa-tags' }} fa-fw" aria-hidden="true"></i>
                    {{ $list['title'] }}
                    @if (count($list['entries']) > 0)
                        <span class="label label-default">{{ count($list['entries']) }}</span>
                    @endif
                </x-slot>

                <p class="text-muted">{{ $list['intro'] }}</p>

                <div class="table-responsive">
                    <table class="table table-sm table-hover table-striped">
                        <thead>
                        <tr>
                            <th>@include('ipam::partials.sortable', ['column' => 'name', 'label' => __('ipam::ipam.taxonomies.name')])</th>
                            <th>{{ __('ipam::ipam.taxonomies.description') }}</th>
                            <th class="text-right">@include('ipam::partials.sortable', ['column' => 'usage', 'label' => $list['used_by']])</th>
                            @if ($canManage)
                                <th></th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($list['entries'] as $entry)
                            @php
                                // where each badge lands: the page that carries the
                                // objects, carrying the filter that picks exactly
                                // them out
                                $filter = match (true) {
                                    $key === 'net-function' => 'function_id',
                                    $key === 'vlan-owner' => 'owner_id',
                                    default => 'owner_id',
                                };
                            @endphp
                            <tr>
                                <td><strong>{{ $entry['name'] }}</strong></td>
                                <td class="tw:text-sm">{{ $entry['description'] }}</td>
                                <td class="text-right">
                                    @foreach ($entry['usage'] as $what => $count)
                                        @php
                                            // blocks and addresses carry a function by
                                            // its own column, vlans carry their owner.
                                            // route(), not url(): url() appends its
                                            // second argument as path segments, which
                                            // turned the filter into addresses/1
                                            $target = match ($what) {
                                                'blocks' => 'networks',
                                                'addresses' => 'addresses',
                                                'vlans' => 'vlans',
                                            };

                                            $usageUrl = \Illuminate\Support\Facades\Route::has('ipam.' . $target)
                                                ? route('ipam.' . $target, [$filter => $entry['id']])
                                                : url('plugin/ipam/' . $target . '?' . $filter . '=' . $entry['id']);
                                        @endphp
                                        @if ($count > 0)
                                            <a href="{{ $usageUrl }}"
                                               class="label label-info"
                                               title="{{ __('ipam::ipam.taxonomies.show_usage') }}">
                                                {{ number_format($count) }} {{ __($what === 'addresses' ? 'ipam::ipam.taxonomies.usage_addresses' : ($what === 'blocks' ? 'ipam::ipam.taxonomies.usage_blocks' : 'ipam::ipam.taxonomies.usage_vlans')) }}
                                            </a>
                                        @endif
                                    @endforeach
                                    @if (array_sum($entry['usage']) === 0)
                                        <span class="label label-success">{{ __('ipam::ipam.taxonomies.usage_free') }}</span>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="text-right" style="white-space: nowrap;">
                                        <button type="button" class="btn btn-xs btn-default"
                                                data-toggle="collapse" data-target="#tax-{{ $key }}-{{ $entry['id'] }}">
                                            {{ __('ipam::ipam.taxonomies.edit') }}
                                        </button>

                                        @if (array_sum($entry['usage']) === 0)
                                            {{-- only offered when nothing points at the entry: the form would
                                                 be refused server side anyway, and a button that always
                                                 answers "no" is a lie told in advance --}}
                                            <form method="post"
                                                  action="{{ \Illuminate\Support\Facades\Route::has('ipam.taxonomies.destroy') ? route('ipam.taxonomies.destroy', ['taxonomy' => $key, 'id' => $entry['id']]) : url('plugin/ipam/taxonomies/' . $key . '/' . $entry['id']) }}"
                                                  style="display: inline;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-xs btn-danger">
                                                    {{ __('ipam::ipam.taxonomies.delete') }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                            @if ($canManage)
                                <tr id="tax-{{ $key }}-{{ $entry['id'] }}" class="collapse">
                                    <td colspan="4" style="background-color: rgba(0,0,0,.02);">
                                        <form method="post"
                                              action="{{ \Illuminate\Support\Facades\Route::has('ipam.taxonomies.update') ? route('ipam.taxonomies.update', ['taxonomy' => $key, 'id' => $entry['id']]) : url('plugin/ipam/taxonomies/' . $key . '/' . $entry['id']) }}"
                                              class="tw:flex tw:items-end tw:flex-wrap tw:gap-2">
                                            @csrf
                                            @method('PUT')
                                            <div class="form-group tw:mb-0">
                                                <label class="sr-only" for="tax-name-{{ $key }}-{{ $entry['id'] }}">{{ __('ipam::ipam.taxonomies.name') }}</label>
                                                <input type="text" id="tax-name-{{ $key }}-{{ $entry['id'] }}" name="name" class="form-control form-control-sm"
                                                       value="{{ $entry['name'] }}" maxlength="128" required style="width: 14rem;">
                                            </div>
                                            <div class="form-group tw:mb-0" style="min-width: 18rem; flex: 1;">
                                                <label class="sr-only" for="tax-desc-{{ $key }}-{{ $entry['id'] }}">{{ __('ipam::ipam.taxonomies.description') }}</label>
                                                <input type="text" id="tax-desc-{{ $key }}-{{ $entry['id'] }}" name="description" class="form-control form-control-sm"
                                                       value="{{ $entry['description'] }}" maxlength="255">
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">{{ __('ipam::ipam.networks.save') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 4 : 3 }}" class="text-center text-muted">
                                    {{ __('ipam::ipam.taxonomies.empty') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($canManage)
                    <hr>

                    <form method="post"
                          action="{{ \Illuminate\Support\Facades\Route::has('ipam.taxonomies.store') ? route('ipam.taxonomies.store', ['taxonomy' => $key]) : url('plugin/ipam/taxonomies/' . $key) }}"
                          class="tw:flex tw:items-end tw:flex-wrap tw:gap-2">
                        @csrf
                        <div class="form-group tw:mb-0">
                            <label class="sr-only" for="add-{{ $key }}">{{ __('ipam::ipam.taxonomies.name') }}</label>
                            <input type="text" id="add-{{ $key }}" name="name" class="form-control form-control-sm"
                                   placeholder="{{ __('ipam::ipam.taxonomies.name') }}" maxlength="128" required style="width: 14rem;">
                        </div>
                        <div class="form-group tw:mb-0" style="min-width: 18rem; flex: 1;">
                            <label class="sr-only" for="add-desc-{{ $key }}">{{ __('ipam::ipam.taxonomies.description') }}</label>
                            <input type="text" id="add-desc-{{ $key }}" name="description" class="form-control form-control-sm"
                                   placeholder="{{ __('ipam::ipam.taxonomies.description') }}" maxlength="255">
                        </div>
                        <button type="submit" class="btn btn-default btn-sm">
                            <i class="fa fa-plus" aria-hidden="true"></i>
                            {{ __('ipam::ipam.taxonomies.add') }}
                        </button>
                    </form>
                @endif
            </x-panel>
        @endforeach
    </div>
@endsection