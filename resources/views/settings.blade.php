{{--
    Plugin settings page.

    Core renders it: PluginSettingsController -> plugins.settings, which includes
    this view. It therefore must be a fragment, no @extends here, or the page
    would end up with one html document inside the other.
--}}
@php($url = fn (string $path) => \Illuminate\Support\Facades\Route::has('ipam.' . $path) ? route('ipam.' . $path) : url('plugin/ipam/' . $path))

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($missing))
                <div class="alert alert-warning">
                    <strong>{{ __('ipam::ipam.missing_tables.title') }}</strong>
                    <p class="mb-1">{{ __('ipam::ipam.missing_tables.intro') }}</p>
                    <code>{{ __('ipam::ipam.missing_tables.command') }}</code>
                </div>
            @endif

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>{{ __('ipam::ipam.settings.title') }}</strong>
                </div>
                <div class="panel-body">
                    <p>{{ __('ipam::ipam.settings.intro') }}</p>

                    <form method="post" action="{{ route('plugin.update', ['plugin' => $plugin_name]) }}">
                        @csrf
                        {{-- core validates plugin_active on every settings save --}}
                        <input type="hidden" name="plugin_active" value="{{ $plugin_active }}">

                        <div class="panel panel-default">
                            <div class="panel-heading"><strong>{{ __('ipam::ipam.settings.import_panel') }}</strong></div>
                            <div class="panel-body">
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="import_ipv4"
                                           name="settings[import_ipv4]" value="1"
                                           @checked($defaults['import_ipv4'])>
                                    <label class="form-check-label" for="import_ipv4">{{ __('ipam::ipam.settings.import_ipv4') }}</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="import_ipv6"
                                           name="settings[import_ipv6]" value="1"
                                           @checked($defaults['import_ipv6'])>
                                    <label class="form-check-label" for="import_ipv6">{{ __('ipam::ipam.settings.import_ipv6') }}</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="import_inactive_ports"
                                           name="settings[import_inactive_ports]" value="1"
                                           @checked($defaults['import_inactive_ports'])>
                                    <label class="form-check-label" for="import_inactive_ports">
                                        {{ __('ipam::ipam.settings.import_inactive_ports') }}
                                    </label>
                                    <div class="text-muted small ml-4">{{ __('ipam::ipam.settings.import_inactive_hint') }}</div>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="site_from_device"
                                           name="settings[site_from_device]" value="1"
                                           @checked($defaults['site_from_device'])>
                                    <label class="form-check-label" for="site_from_device">
                                        {{ __('ipam::ipam.settings.site_from_device') }}
                                    </label>
                                    <div class="text-muted small ml-4">{{ __('ipam::ipam.settings.site_from_device_hint') }}</div>
                                </div>

                                <div class="form-group mt-3">
                                    <label for="exclude_ranges">{{ __('ipam::ipam.settings.exclude_ranges') }}</label>
                                    <textarea class="form-control" id="exclude_ranges" name="settings[exclude_ranges]"
                                              rows="6">{{ $defaults['exclude_ranges'] }}</textarea>
                                    <div class="text-muted small">{{ __('ipam::ipam.settings.exclude_ranges_hint') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="panel panel-default">
                            <div class="panel-heading"><strong>{{ __('ipam::ipam.settings.scope_panel') }}</strong></div>
                            <div class="panel-body">
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="use_vlan_scope"
                                           name="settings[use_vlan_scope]" value="1"
                                           @checked($defaults['use_vlan_scope'])>
                                    <label class="form-check-label" for="use_vlan_scope">{{ __('ipam::ipam.settings.use_vlan_scope') }}</label>
                                    <div class="text-muted small ml-4">{{ __('ipam::ipam.settings.use_vlan_scope_hint') }}</div>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="ignore_unscoped_duplicates"
                                           name="settings[ignore_unscoped_duplicates]" value="1"
                                           @checked($defaults['ignore_unscoped_duplicates'])>
                                    <label class="form-check-label" for="ignore_unscoped_duplicates">
                                        {{ __('ipam::ipam.settings.ignore_unscoped_duplicates') }}
                                    </label>
                                    <div class="text-muted small ml-4">{{ __('ipam::ipam.settings.ignore_unscoped_duplicates_hint') }}</div>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="unique_per_scope"
                                           name="settings[unique_per_scope]" value="1"
                                           @checked($defaults['unique_per_scope'])>
                                    <label class="form-check-label" for="unique_per_scope">{{ __('ipam::ipam.settings.unique_per_scope') }}</label>
                                    <div class="text-muted small ml-4">{{ __('ipam::ipam.settings.unique_per_scope_hint') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="panel panel-default">
                            <div class="panel-heading"><strong>{{ __('ipam::ipam.settings.pages_panel') }}</strong></div>
                            <div class="panel-body">
                                <div class="form-group">
                                    <label for="per_page">{{ __('ipam::ipam.settings.per_page') }}</label>
                                    <input type="number" class="form-control" id="per_page" name="settings[per_page]"
                                           value="{{ $defaults['per_page'] }}" min="10" max="500">
                                </div>

                                <p>
                                    <a href="{{ $url('networks') }}" class="btn btn-default btn-sm">{{ __('ipam::ipam.nav.networks') }}</a>
                                    <a href="{{ $url('vlans') }}" class="btn btn-default btn-sm">{{ __('ipam::ipam.nav.vlans') }}</a>
                                    <a href="{{ $url('addresses') }}" class="btn btn-default btn-sm">{{ __('ipam::ipam.nav.addresses') }}</a>
                                    <a href="{{ $url('duplicates') }}" class="btn btn-default btn-sm">{{ __('ipam::ipam.nav.duplicates') }}</a>
                                </p>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    </form>

                    <div class="alert alert-info mt-3">
                        {{ __('ipam::ipam.settings.saved') }}
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>{{ __('ipam::ipam.settings.manage_panel') }}</strong>
                </div>
                <div class="panel-body">
                    <p>{{ __('ipam::ipam.settings.commands_hint') }}</p>
                    <pre class="mb-0"><code>lnms ipam:install        {{ __('ipam::ipam.settings.install') }}
lnms ipam:import         {{ __('ipam::ipam.settings.import') }}
lnms ipam:duplicates     {{ __('ipam::ipam.settings.duplicates') }}
lnms ipam:duplicates --unclassified
lnms ipam:check          {{ __('ipam::ipam.settings.check') }}</code></pre>

                    <hr>

                    <p class="text-muted mb-0">{{ __('ipam::ipam.settings.validator_warning') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>