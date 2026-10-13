<?php

namespace Dercol1\LibrenmsIpam\Web;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Leaves ignored rows out unless the page was asked to show them.
 *
 * Every list page carries the same "show hidden" toggle, because the point of
 * ignoring is not that the rows stop existing: loopback is on every device and
 * an operator may still want to look at it. The default is the quiet list, and
 * ?show_hidden=yes is the honest look underneath.
 *
 * A page that filters by hand — ignore this address, ignore that block — applies it
 * immediately and eagerly, so the flag never has to be derived at query time
 * from a tree walk over the blocks. The flag stays a plain column, which is what
 * keeps the collision report a single indexed query.
 */
final class Visibility
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function shown(Builder $query, Request $request): Builder
    {
        if ($request->boolean('show_hidden')) {
            return $query;
        }

        return $query->where('ignored', false);
    }
}
