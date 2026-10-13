{{--
    The warning panel itself: the plugin tables are not there yet.

    A fragment with no layout, because two different pages include it:
    the controllers render it through missing.blade.php, which extends the core
    layout, and the plugin's Page hook renders it directly inside
    plugins.settings, which already has the layout of its own. Extending the
    layout from here would nest one html document inside another on the second
    path.
--}}
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-warning">
                <div class="panel-heading">
                    <strong>{{ __('ipam::ipam.missing_tables.title') }}</strong>
                </div>
                <div class="panel-body">
                    <p>{{ __('ipam::ipam.missing_tables.intro') }}</p>
                    <pre class="mb-3"><code>{{ __('ipam::ipam.missing_tables.command') }}</code></pre>

                    @if (! empty($missing))
                        <p class="text-muted mb-0">{{ implode(', ', $missing) }}</p>
                    @endif

                    <hr>

                    <p class="mb-0 text-muted">{{ __('ipam::ipam.missing_tables.extra') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>