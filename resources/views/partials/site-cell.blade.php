{{--
    A site, and the rooms it holds, in one table cell.

    This is the piece that makes the grouping legible. A row saying only
    "Galazzano" hides that ten sysLocation strings rolled up into it, and the
    reader cannot tell a deliberate grouping from a site that happens to have one
    room. So the site name carries its room count, and the rooms themselves are
    available underneath.

    $siteId is the site id, $rooms is a list of location names (strings, already
    loaded), $link is whether to turn the name into a link to the sites page.

    A location list is capped: past a handful the cell stops being a summary and
    starts being a wall, and the rest is one click away on the sites page.
--}}
@php
    $rooms = $rooms ?? [];
    $roomCount = count($rooms);
    $shown = array_slice($rooms, 0, 3);
    $siteUrl = \Illuminate\Support\Facades\Route::has('ipam.sites')
        ? route('ipam.sites', array_filter(['site_id' => ($siteId ?: null)]))
        : url('plugin/ipam/sites');
@endphp

@if (! $siteId)
    <span class="text-muted" title="{{ __('ipam::ipam.sites.no_site') }}">
        <i class="fa fa-question-circle" aria-hidden="true"></i>
        {{ __('ipam::ipam.sites.no_site') }}
    </span>
@else
    <div>
        @if ($link ?? true)
            <a href="{{ $siteUrl }}" class="tw:font-medium">{{ $siteName ?? '' }}</a>
        @else
            <span class="tw:font-medium">{{ $siteName ?? '' }}</span>
        @endif

        @if ($roomCount > 0)
            <span class="label label-default" title="{{ implode(', ', $rooms) }}">
                {{ trans_choice('ipam::ipam.sites.rooms_in_site', $roomCount, ['count' => $roomCount]) }}
            </span>
        @endif

        @if ($shown !== [])
            <div class="tw:text-xs tw:text-gray-500 tw:dark:text-gray-400 tw:mt-0.5">
                @foreach ($shown as $room)
                    <span class="tw:mr-1"><i class="fa fa-map-marker tw:text-gray-400" aria-hidden="true"></i>{{ $room }}</span>
                @endforeach
                @if ($roomCount > count($shown))
                    <a href="{{ $siteUrl }}" title="{{ implode(', ', $rooms) }}">+{{ $roomCount - count($shown) }}</a>
                @endif
            </div>
        @endif
    </div>
@endif