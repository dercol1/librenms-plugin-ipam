{{--
    The url a form should post to.

    Route::has() takes only a name — extra arguments make it read an offset of
    type array — and the plugin's routes are not always registered: the page hook
    renders the same views in contexts that never went through the router, and
    the tests render controllers directly. Falling back to the plain url keeps
    the form usable there and in any install where the route table has not been
    rebuilt yet.

    Usage:

        @include('ipam::partials.action', [
            'route' => 'ipam.networks.stretch',
            'params' => [$block->network_id],
            'fallback' => 'plugin/ipam/networks/' . $block->network_id . '/stretch',
        ])
--}}
@php
    $ipamHasRoute = ! empty($route) && \Illuminate\Support\Facades\Route::has($route);
@endphp
{{ $ipamHasRoute ? route($route, $params ?? []) : url($fallback ?? '/') }}
