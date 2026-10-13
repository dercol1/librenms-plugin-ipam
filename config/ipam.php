<?php

/*
 * Defaults for the ipam plugin.
 *
 * Every value can be overridden from the web ui, Plugins -> ipam -> Settings.
 * The web ui stores what you pick in the database (the plugins.settings column),
 * so this file only carries the defaults: a value saved from the browser wins
 * over this file, anything left untouched falls back to it.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Import
    |--------------------------------------------------------------------------
    |
    | Which LibreNMS address tables feed the ipam database, and whether a
    | deleted or disabled port still counts as an occurrence of its address.
    |
    | A deleted port is a port the user removed from monitoring. Its addresses
    | usually are gone from the network too, so by default they do not take
    | part in duplicate detection and are dropped at import time.
    |
    */

    'import_ipv4' => true,
    'import_ipv6' => true,
    'import_inactive_ports' => false,

    /*
    |--------------------------------------------------------------------------
    | Excluded ranges
    |--------------------------------------------------------------------------
    |
    | Addresses inside these ranges are imported but hidden: they exist on
    | every device (loopback, link-local, multicast) and say nothing about
    | the estate, while filling the address list and faking collisions:
    | 127.0.0.1 on three devices reads as a three-way conflict.
    |
    | Hidden is not deleted. The pages have a "show hidden" toggle and a
    | hidden row can be unhidden from its edit form, which the import then
    | respects: hidden is only ever set when a row is created.
    |
    | Comma or newline separated CIDRs. Anything that does not parse is
    | dropped, not fatal.
    |
    */

    'exclude_ranges' => "127.0.0.0/8\n0.0.0.0/32\n169.254.0.0/16\n224.0.0.0/4\n255.255.255.255/32\n::1/128\n::/128\nfe80::/10\nff00::/8",

    /*
    |--------------------------------------------------------------------------
    | Site inference
    |--------------------------------------------------------------------------
    |
    | An address gets its site from the network block that contains it, and a
    | block without a site falls back to the site of the device the address was
    | seen on. Turn the fallback off to keep the device out of it entirely, so
    | that only a block or a manual assignment can classify an address.
    |
    */

    'site_from_device' => true,

    /*
    |--------------------------------------------------------------------------
    | Duplicate detection
    |--------------------------------------------------------------------------
    |
    | An address repeated inside the same (vlan, site) scope is a duplicate.
    | Turn the vlan off and only the site separates two occurrences, which is
    | useful when a site runs several overlapping vlan ranges nobody tracks.
    |
    | An occurrence with no vlan and no site cannot be placed in a scope, so it
    | is never compared to anything and always lands in the unclassified queue.
    | Set ignore_unscoped_duplicates to false to have those reported anyway,
    | which is noisier but catches duplicates hiding in unclassified networks.
    |
    */

    'use_vlan_scope' => true,
    'ignore_unscoped_duplicates' => true,

    /*
    |--------------------------------------------------------------------------
    | Uniqueness
    |--------------------------------------------------------------------------
    |
    | One row per address, or one row per address and scope? An address that
    | legitimately exists in two vlan ids or two sites is not a duplicate, so
    | the scope has to be part of the key for the database to be able to hold
    | it. Turning this off collapses the address to a single row and the first
    | scope wins, which is only sensible for a network that does not reuse
    | addresses at all.
    |
    */

    'unique_per_scope' => true,

    /*
    |--------------------------------------------------------------------------
    | Paging
    |--------------------------------------------------------------------------
    */

    'per_page' => 50,
];
