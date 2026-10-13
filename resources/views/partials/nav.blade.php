{{--
    The ipam page navigation.

    x-option-bar is core's own sub navigation, the one that renders "Foo » a | b | c"
    with the current item marked by .pagemenu-selected. Using it rather than a
    hand-rolled <ul class="nav nav-tabs"> is what makes these pages look like the
    rest of LibreNMS instead of like a bolt-on.

    Route names are checked before being resolved: routes can be cached from
    before the plugin was installed, and asking for an unknown name throws, which
    would take the whole page down over a link.
--}}
@php
    $current = $current ?? 'networks';
    $link = fn (string $name): string => \Illuminate\Support\Facades\Route::has('ipam.' . $name)
        ? route('ipam.' . $name)
        : url('plugin/ipam/' . $name);

    $pages = [
        'networks' => ['text' => __('ipam::ipam.nav.networks'), 'icon' => 'fa-sitemap'],
        'vlans' => ['text' => __('ipam::ipam.nav.vlans'), 'icon' => 'fa-network-wired'],
        'addresses' => ['text' => __('ipam::ipam.nav.addresses'), 'icon' => 'fa-list'],
        'duplicates' => ['text' => __('ipam::ipam.nav.duplicates'), 'icon' => 'fa-clone'],
        'sites' => ['text' => __('ipam::ipam.nav.sites'), 'icon' => 'fa-building'],
        'taxonomies' => ['text' => __('ipam::ipam.nav.taxonomies'), 'icon' => 'fa-tags'],
    ];

    // array_map only carries the value, and the route name is the key, so the
    // options are built with a loop rather than a map
    $options = [];

    foreach ($pages as $key => $page) {
        $options[$key] = [
            'link' => $link($key),
            'text' => $page['text'],
            'icon' => $page['icon'],
        ];
    }
@endphp

<x-option-bar
    :name="__('ipam::ipam.menu')"
    :options="$options"
    :selected="$current"
/>