{{--
    A column header that sorts, in three states.

    Clicking cycles by count: the first click sorts ascending, the second
    descending, the third goes back to the page's natural order. The third
    state exists because "sort by this" and "stop sorting" are different
    wishes, and the only honest way to tell them apart is which click made
    them. A header that is not the active one offers ascending, the way into
    the cycle.

    The link carries every other query parameter, so a sort never throws away
    the filters an operator set; the page number is dropped, because page 3 of
    the old order is nothing in the new one.

    $column is the key the controller whitelists, $label the visible text.
--}}
@php
    $active = (string) request('order_by') === $column;
    $descending = strtolower((string) request('order_direction', 'asc')) === 'desc';

    $params = request()->except(['order_by', 'order_direction', 'page']);

    if (! $active) {
        $params['order_by'] = $column;
        $params['order_direction'] = 'asc';
    } elseif (! $descending) {
        $params['order_by'] = $column;
        $params['order_direction'] = 'desc';
    }
    // active and descending: both keys stay out, and the link is the natural order

    $sortUrl = request()->url() . '?' . http_build_query($params);
@endphp
<a href="{{ $sortUrl }}"
   class="tw:no-underline"
   style="color: inherit; display: inline-block; width: 100%;">
    {{ $label }}
    @if ($active)
        <i class="fa-solid fa-caret-{{ $descending ? 'down' : 'up' }} tw:ml-0.5" aria-hidden="true"></i>
    @endif
</a>