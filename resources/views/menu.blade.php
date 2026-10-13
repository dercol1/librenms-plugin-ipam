{{--
    One entry in the Plugins menu, included by the core menu layout as
    <li>@include($view, $data)</li>, so this renders the link itself and no <li>.

    $collisions is a count of duplicated addresses, or null when the ipam tables
    are not installed yet. A null badge is shown as nothing rather than as 0,
    which would claim there are no duplicates when really nothing has been
    checked yet.
--}}
<a href="{{ $url }}" title="{{ __('ipam::ipam.page_titles.networks') }}">
    <i class="fa fa-sitemap fa-fw fa-lg" aria-hidden="true"></i>
    {{ $label }}
    @if ($collisions !== null && $collisions > 0)
        <span class="badge bg-danger">{{ $collisions }}</span>
    @endif
</a>