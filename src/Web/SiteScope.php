<?php

namespace Dercol1\LibrenmsIpam\Web;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Narrows a query to one site, or to the rows that have none.
 *
 * Every list page carries the same site selector, and the selector sends the
 * same three signals, which mean different things:
 *
 *   absent  every site, which is the default and shows everything
 *   0       the rows with no site at all, which is where the unclassified sit
 *   -1      the same, spelled the way a select can send it: 0 already means
 *           "unset" in a filter, and an unset filter cannot be told from an
 *           explicit one by value alone
 *   n       exactly that site
 *
 * site_id is 0 by default on every row, so "no site" is a real value that has
 * to be asked for explicitly. Treating a missing filter as "site 0" would show
 * nothing at all, which looks exactly like a broken page.
 *
 * The method is generic over the model on purpose: the networks, vlans and
 * addresses pages all filter on the same column, and three copies of one
 * condition is how one of them ends up behaving differently.
 */
final class SiteScope
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, Request $request): Builder
    {
        if (! $request->has('site_id')) {
            return $query;
        }

        // -1 is how the select spells "the rows with no site", which is also 0
        $siteId = (int) $request->input('site_id');

        return $query->where('site_id', $siteId === -1 ? 0 : $siteId);
    }
}
