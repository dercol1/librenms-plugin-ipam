{{--
    The panel behind the plugin's Page button in Plugin Admin.

    A fragment, deliberately: plugins.settings includes this inside its own
    layout, so a full page here would nest one html document inside another.
    The five real pages live under their own urls and this is the directory of
    them, with the numbers an operator wants before clicking: how much is
    imported, how much is still unclassified, and whether anything collides.

    The counts are passed in by the Page hook; nothing here queries, so the
    button stays cheap even on a large install.
--}}
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>{{ __('ipam::ipam.menu') }}</strong>
                </div>
                <div class="panel-body">
                    <p class="text-muted">{{ __('ipam::ipam.landing.intro') }}</p>

                    <div class="row">
                        @foreach ($pages as $page)
                            <div class="col-sm-6 col-md-4 tw:mb-3">
                                <a href="{{ $page['url'] }}" class="panel panel-default tw:block hover:tw:bg-gray-100 dark:hover:tw:bg-dark-gray-300"
                                   style="text-decoration: none;">
                                    <div class="panel-body">
                                        <div class="tw:flex tw:items-center tw:justify-between">
                                            <span>
                                                <i class="fa {{ $page['icon'] }} fa-fw fa-lg" aria-hidden="true"></i>
                                                <strong>{{ $page['label'] }}</strong>
                                            </span>
                                            @if ($page['count'] !== null)
                                                <span class="label {{ $page['tone'] ?? 'label-default' }}">{{ number_format($page['count']) }}</span>
                                            @endif
                                        </div>
                                        <div class="tw:text-xs tw:text-gray-500 tw:mt-1">{{ $page['hint'] }}</div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>

                    @if (! empty($next_step))
                        <div class="alert {{ $next_step['tone'] ?? 'alert-info' }}">
                            <i class="fa fa-info-circle" aria-hidden="true"></i>
                            {{ $next_step['text'] }}
                        </div>
                    @endif

                    @if (! empty($alignment))
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <strong>{{ __('ipam::ipam.sync.title') }}</strong>
                                @if ($alignment['in_sync'])
                                    <span class="label label-success pull-right">{{ __('ipam::ipam.sync.in_sync') }}</span>
                                @else
                                    <span class="label label-warning pull-right">{{ __('ipam::ipam.sync.out_of_sync') }}</span>
                                @endif
                            </div>
                            <div class="panel-body">
                                <p class="text-muted">{{ __('ipam::ipam.sync.intro') }}</p>
                                <div class="row">
                                    <div class="col-sm-3">
                                        <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.sync.addresses_librenms') }}</div>
                                        <div class="tw:text-2xl tw:font-semibold">{{ number_format($alignment['addresses_in_librenms']) }}</div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.sync.addresses_ipam') }}</div>
                                        <div class="tw:text-2xl tw:font-semibold">{{ number_format($alignment['addresses_in_ipam']) }}</div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.sync.not_imported') }}</div>
                                        <div class="tw:text-2xl tw:font-semibold {{ $alignment['addresses_not_imported'] > 0 ? 'tw:text-amber-600' : '' }}">{{ number_format($alignment['addresses_not_imported']) }}</div>
                                    </div>
                                    <div class="col-sm-3">
                                        <div class="tw:text-xs tw:uppercase tw:tracking-wide tw:text-gray-500">{{ __('ipam::ipam.sync.gone') }}</div>
                                        <div class="tw:text-2xl tw:font-semibold {{ $alignment['addresses_gone'] > 0 ? 'tw:text-amber-600' : '' }}">{{ number_format($alignment['addresses_gone']) }}</div>
                                    </div>
                                </div>
                                @if (! $alignment['in_sync'])
                                    <p class="text-muted small tw:mt-2">{{ __('ipam::ipam.sync.fix') }}</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>