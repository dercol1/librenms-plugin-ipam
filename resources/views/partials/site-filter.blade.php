{{--
    The site selector every list page carries.

    A site is the scope a collision is judged in, so picking one is not a display
    preference: it changes what is compared against what. The "no site" entry is
    offered on its own because that is where the unclassified addresses sit, and
    they are the ones an operator most often wants to look at next.

    $rooms maps site id to how many rooms it holds, so the size of the site is
    visible without opening it.
--}}
@php
    $currentSite = (int) request('site_id');
    $rooms = $rooms ?? [];
@endphp

<form method="get" class="form-inline tw:mb-3 tw:flex tw:flex-wrap tw:items-center tw:gap-2">
    {{-- every other filter is kept, so narrowing the site does not throw away a
         search that was already typed --}}
    @foreach (request()->except(['site_id', 'page']) as $key => $value)
        @if (is_scalar($value) && $value !== '')
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach

    <div class="form-group tw:mb-0">
        <label class="sr-only" for="filter-site">{{ __('ipam::ipam.sites.name') }}</label>
        <select name="site_id" id="filter-site" class="form-control form-control-sm" onchange="this.form.submit()">
            <option value="0" @selected($currentSite === 0)>
                {{ __('ipam::ipam.sites.all_sites') }}
            </option>
            @foreach ($sites as $siteId => $siteName)
                <option value="{{ $siteId }}" @selected($currentSite === $siteId)>
                    {{ $siteName }}@isset($rooms[$siteId]) ({{ $rooms[$siteId] }})@endif
                </option>
            @endforeach
            <option value="-1" @selected($currentSite === -1)>
                {{ __('ipam::ipam.sites.no_site') }}
            </option>
        </select>
    </div>
</form>