{{--
    The option lists a page's forms need, rendered once.

    An edit row can carry five selects, and the vlan list alone runs to hundreds
    of entries. Rendering them per row multiplied out to forty thousand options
    and a seven megabyte page, most of it the same markup repeated fifty times.

    So the lists live here, once, inside <template> elements, and the selects
    start empty carrying only data-fill and data-selected. A small script in
    partials/fill copies the options in when the row is expanded, and marks the
    current value. Nothing is fetched, nothing is duplicated, and a row that is
    never expanded costs nothing at all.

    $options is the same array the controllers hand the page.
--}}
<template id="ipam-options-sites">
    @foreach ($options['sites'] ?? [] as $id => $name)
        <option value="{{ $id }}">{{ $name }}</option>
    @endforeach
</template>

<template id="ipam-options-vlans">
    @foreach ($options['vlans'] ?? [] as $id => $label)
        <option value="{{ $id }}">{{ $label }}</option>
    @endforeach
</template>

<template id="ipam-options-owners">
    @foreach ($options['owners'] ?? [] as $id => $name)
        <option value="{{ $id }}">{{ $name }}</option>
    @endforeach
</template>

<template id="ipam-options-functions">
    @foreach ($options['functions'] ?? [] as $id => $name)
        <option value="{{ $id }}">{{ $name }}</option>
    @endforeach
</template>

<template id="ipam-options-networks">
    @foreach ($options['networks'] ?? [] as $id => $cidr)
        <option value="{{ $id }}">{{ $cidr }}</option>
    @endforeach
</template>