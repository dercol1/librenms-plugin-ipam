{{--
    The hint that closes the loop on an empty pick list.

    An Owner or Function dropdown with nothing in it answers no question except
    "why can I not set this", and the answer lives on another page. Shown only
    while the list is empty: once an operator has written their first entry the
    pointer stops being useful and starts being noise.

    $which selects the list: 'owners', 'functions' or 'vlan-owners'.
--}}
@php
    $taxonomiesUrl = \Illuminate\Support\Facades\Route::has('ipam.taxonomies')
        ? route('ipam.taxonomies')
        : url('plugin/ipam/taxonomies');
@endphp

<div class="alert alert-info">
    <i class="fa fa-info-circle" aria-hidden="true"></i>
    {{ __('ipam::ipam.taxonomies.empty_hint') }}
    <a href="{{ $taxonomiesUrl }}">{{ __('ipam::ipam.nav.taxonomies') }}</a>.
</div>