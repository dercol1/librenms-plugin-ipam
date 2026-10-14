<?php

namespace Dercol1\LibrenmsIpam\Support;

use Illuminate\Http\Request;

/**
 * Validation rules for a column that points at another plugin table.
 *
 * The classification columns — site, vlan, owner, function — are 0 when the
 * row inherits from its block, and 0 is a real value the forms send on
 * purpose: the "--" option of every select is exactly it. A plain exists:
 * rule refuses it, because no row carries key 0, and the whole submit dies
 * in validation the moment an operator edits one field and leaves the rest
 * alone.
 *
 * That is not a hypothetical: editing an auto-created block on the network
 * map and setting only its site saved nothing and said nothing, because the
 * owner and function selects came back as 0. The page showed no error, since
 * the plugin rendered none of its messages at all.
 *
 * Written once, here, because the rule was re-broken three times by being
 * re-typed in each controller. Every classification field goes through this.
 */
final class ScopeRules
{
    /**
     * The rules for one set of pointing fields, built per request.
     *
     * The field map is [field => [table, key]]. A value above 0 must name a
     * row; 0 is "inherit" and is always valid.
     *
     * @param  array<string, array{0: string, 1: string}>  $fields
     * @return array<string, list<string>>
     */
    public static function make(array $fields, Request $request): array
    {
        $rules = [];

        foreach ($fields as $field => [$table, $key]) {
            $rules[$field] = ['nullable', 'integer', 'min:0'];

            if ((int) $request->input($field) > 0) {
                $rules[$field][] = "exists:$table,$key";
            }
        }

        return $rules;
    }
}
