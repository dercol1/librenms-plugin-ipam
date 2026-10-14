<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Dercol1\LibrenmsIpam\Web\AddressesController;
use Dercol1\LibrenmsIpam\Web\TaxonomiesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Selection, bulk reclassification and sorting, and the fixes they dragged
 * in with them.
 *
 * The workflow this file pins down is the one the taxonomies page exists to
 * start: an entry says "3 blocks carry me", the link lands on the list
 * carrying exactly those objects, and from there the operator selects and
 * reclassifies in one move instead of scrolling and editing row by row. The
 * selection has to honor the filters, or "everything this owner claims"
 * quietly means "everything, whatever the boxes said".
 *
 * Three older faults are held down here too, all found while wiring this:
 * the exists: rule that refused 0 and made "unset" impossible from any form,
 * the v6 CIDR search that matched nothing because INET_ATON answers NULL for
 * an ipv6 string, and the filter checkboxes that could never be unchecked
 * because a hidden input resubmitted them.
 */
class BulkAndSortTest extends UnitTestCase
{
    protected function user(): User
    {
        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        Gate::define('ipam.view', fn ($u, mixed $target = null): bool => true);
        Gate::define('ipam.manage', fn ($u, mixed $target = null): bool => true);

        // the page hides rows on devices the user may not see, resolved
        // through the Device policy: without a permissive one bound here every
        // device is invisible, the list comes back empty, and the test fails
        // on the fixture rather than on the behaviour
        Gate::policy(Device::class, AlwaysAllowsPolicy::class);
        Gate::policy(Port::class, AlwaysAllowsPolicy::class);

        return $user;
    }

    protected function request(string $uri = '/'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        // bound as the current request too: the sortable headers read
        // request() from the container, and a request that reaches the
        // controller but not the container leaves them always in the
        // "offer ascending" state
        $this->app->instance('request', $request);

        return $request;
    }

    /**
     * An address row, straight in the ipam table.
     *
     * The overrides go through setAttribute rather than through create(): the
     * base is a literal the type checker can read, and the overrides are
     * per-test facts, applied by name.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function address(string $ip, array $overrides = []): Address
    {
        $cidr = Cidr::address($ip);

        $record = Address::query()->create([
            'family' => $cidr->getFamily(),
            'address' => $cidr->uncompressed(),
            'prefixlen' => Cidr::maxPrefixLen($cidr->getFamily()),
            'port_id' => 1,
            'device_id' => 1,
            'context_name' => '',
        ]);

        foreach ($overrides as $key => $value) {
            $record->setAttribute($key, $value);
        }

        $record->save();

        return $record->refresh();
    }

    /**
     * The addresses the list shows, in order, ids trimmed to the address text.
     *
     * @return list<string>
     */
    private function listedOrder(string $query): array
    {
        $request = $this->request('/plugin/ipam/addresses?' . $query);

        $html = (new AddressesController)->index($request)->render();

        preg_match_all('/<code class="tw:text-gray-900 tw:dark:text-gray-100">([^<]+)<\/code>/U', $html, $matches);

        return array_map(
            fn (string $row): string => Cidr::address(trim($row))->compressed(),
            array_slice($matches[1], 0, 10)
        );
    }

    public function test_the_selection_is_reclassified_in_one_move(): void
    {
        $this->user();

        $owner = NetworkOwner::query()->create(['name' => 'Network team']);
        $a = $this->address('10.0.0.1');
        $b = $this->address('10.0.0.2');
        $untouched = $this->address('10.0.0.3');

        $request = $this->request('/plugin/ipam/addresses/bulk');
        $request->merge([
            'ids' => [$a->address_record_id, $b->address_record_id],
            'net_owner_id' => (string) $owner->net_owner_id,
        ]);

        (new AddressesController)->bulk($request);

        $this->assertSame($owner->net_owner_id, $a->fresh()->net_owner_id);
        $this->assertSame($owner->net_owner_id, $b->fresh()->net_owner_id);
        $this->assertSame(0, (int) $untouched->fresh()->net_owner_id, 'rows nobody ticked keep their classification');
    }

    public function test_select_all_matching_takes_exactly_what_the_filters_match(): void
    {
        $this->user();

        $old = NetworkOwner::query()->create(['name' => 'Old owner']);
        $new = NetworkOwner::query()->create(['name' => 'New owner']);

        $mine = [
            $this->address('10.0.0.1', ['net_owner_id' => $old->net_owner_id]),
            $this->address('10.0.0.2', ['net_owner_id' => $old->net_owner_id]),
            $this->address('10.0.0.3', ['net_owner_id' => $old->net_owner_id]),
        ];

        // a row the filter does not match: same owner, different site
        $elsewhere = $this->address('10.0.0.4', ['net_owner_id' => $old->net_owner_id, 'site_id' => 999]);

        // and a row the owner filter does not match at all
        $someoneElses = $this->address('10.0.0.5', ['net_owner_id' => 0]);

        $request = $this->request('/plugin/ipam/addresses/bulk');
        // the filters the list was showing, replayed with the form: the owner
        // it was drilled down on, and the site the list was narrowed to
        $request->merge([
            'select_all_matching' => '1',
            'owner_id' => (string) $old->net_owner_id,
            'site_id' => '0',
            'net_owner_id' => (string) $new->net_owner_id,
        ]);

        (new AddressesController)->bulk($request);

        foreach ($mine as $row) {
            $this->assertSame($new->net_owner_id, $row->fresh()->net_owner_id, $row->address . ' matched the filter, so it moved');
        }

        $this->assertSame($old->net_owner_id, $elsewhere->fresh()->net_owner_id, 'the site filter kept this one out: it matched the owner but not the site');
        $this->assertSame(0, (int) $someoneElses->fresh()->net_owner_id, 'no owner to begin with, still none');
    }

    public function test_a_field_left_alone_is_not_written_and_zero_means_inherit(): void
    {
        $this->user();

        $owner = NetworkOwner::query()->create(['name' => 'Network team']);
        $site = Site::query()->create(['name' => 'Milano']);
        $vlan = Vlan::query()->create(['vlan_id' => 300, 'site_id' => $site->site_id]);

        $row = $this->address('10.0.0.1', [
            'net_owner_id' => $owner->net_owner_id,
            'site_id' => $site->site_id,
        ]);

        $request = $this->request('/plugin/ipam/addresses/bulk');
        $request->merge([
            'ids' => [$row->address_record_id],
            // site and vlan stay on "leave unchanged": absent means untouched
            'net_owner_id' => '0', // inherit: the owner is cleared
        ]);

        (new AddressesController)->bulk($request);

        $fresh = $row->fresh();

        $this->assertSame(0, (int) $fresh->net_owner_id, '0 is the operator saying inherit, and it is written');
        $this->assertSame($site->site_id, $fresh->site_id, 'a field left alone keeps its value');
        $this->assertSame(0, (int) $fresh->vlan_record_id);
    }

    public function test_the_selection_can_be_pinned(): void
    {
        $this->user();

        $a = $this->address('10.0.0.1');

        $request = $this->request('/plugin/ipam/addresses/bulk');
        $request->merge([
            'ids' => [$a->address_record_id],
            'pin' => '1',
        ]);

        (new AddressesController)->bulk($request);

        $this->assertTrue($a->fresh()->scope_locked);
    }

    public function test_a_bulk_with_nothing_chosen_is_refused(): void
    {
        $this->user();

        $a = $this->address('10.0.0.1');

        $request = $this->request('/plugin/ipam/addresses/bulk');
        $request->merge(['ids' => [$a->address_record_id]]);

        $response = (new AddressesController)->bulk($request);

        $this->assertSame(
            'Nothing was selected to apply.',
            $response->getSession()->get('error'),
        );
        $this->assertFalse($a->fresh()->scope_locked, 'nothing was written');
    }

    public function test_unsetting_a_classification_from_the_row_form_now_works(): void
    {
        // the exists: rule once refused the 0 that "inherit" sends, which made
        // "take this owner off" impossible from every form on the page
        $this->user();

        $owner = NetworkOwner::query()->create(['name' => 'Network team']);
        $row = $this->address('10.0.0.1', ['net_owner_id' => $owner->net_owner_id]);

        $request = $this->request('/plugin/ipam/addresses/' . $row->address_record_id);
        $request->merge([
            'site_id' => 0,
            'vlan_record_id' => 0,
            'net_owner_id' => 0,
            'net_function_id' => 0,
            'lock' => false,
        ]);

        (new AddressesController)->update($request, (int) $row->address_record_id);

        $this->assertSame(0, (int) $row->fresh()->net_owner_id, 'the owner came off');
    }

    public function test_a_hand_made_nonexistent_id_is_still_refused(): void
    {
        // the 0 being valid must not have opened the door to arbitrary ids
        $this->user();

        $row = $this->address('10.0.0.1');

        $request = $this->request('/plugin/ipam/addresses/bulk');
        $request->merge([
            'ids' => [$row->address_record_id],
            'net_owner_id' => '424242',
        ]);

        $this->expectException(ValidationException::class);
        (new AddressesController)->bulk($request);
    }

    /**
     * Sorting: three clicks, three states.
     */
    public function test_the_address_column_sorts_three_ways(): void
    {
        $this->user();

        // created out of order on purpose: 2, 10, 1
        $this->address('10.0.0.2');
        $this->address('10.0.0.10');
        $this->address('10.0.0.1');

        // natural order: numeric, so 1, 2, 10 — not the lexical 1, 10, 2
        $this->assertSame(['10.0.0.1', '10.0.0.2', '10.0.0.10'], $this->listedOrder(''));
        // first click: ascending, the same order stated outright
        $this->assertSame(['10.0.0.1', '10.0.0.2', '10.0.0.10'], $this->listedOrder('order_by=address&order_direction=asc'));
        // second click: descending
        $this->assertSame(['10.0.0.10', '10.0.0.2', '10.0.0.1'], $this->listedOrder('order_by=address&order_direction=desc'));
    }

    public function test_sorting_by_owner_groups_the_rows(): void
    {
        $this->user();

        $alpha = NetworkOwner::query()->create(['name' => 'Alpha']);
        $beta = NetworkOwner::query()->create(['name' => 'Beta']);

        $this->address('10.0.0.1', ['net_owner_id' => $beta->net_owner_id]);
        $this->address('10.0.0.2', ['net_owner_id' => $alpha->net_owner_id]);
        $this->address('10.0.0.3', ['net_owner_id' => $beta->net_owner_id]);

        // ascending by owner id puts alpha's row first, and the two beta rows
        // together: grouping is what sorting by a classification is for
        $this->assertSame(
            ['10.0.0.2', '10.0.0.1', '10.0.0.3'],
            $this->listedOrder('order_by=owner&order_direction=asc')
        );
    }

    public function test_an_order_by_the_whitelist_does_not_know_is_ignored(): void
    {
        $this->user();

        $this->address('10.0.0.2');
        $this->address('10.0.0.1');

        // a hand made order_by is never a column name to execute: it falls
        // back to the natural order rather than to an error or to the string
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2'],
            $this->listedOrder('order_by=description_of_some_kind')
        );
    }

    public function test_the_v6_cidr_search_finds_rows_again(): void
    {
        // INET_ATON answers NULL for an ipv6 string, so a v6 range in the
        // search box once matched nothing at all
        $this->user();

        $this->address('2001:db8:1::5');
        $this->address('2001:db8:2::5');
        $this->address('2001:db8:1::9');

        $request = $this->request('/plugin/ipam/addresses?q=2001:db8:1::/64');

        $html = (new AddressesController)->index($request)->render();

        $this->assertStringContainsString('2001:0db8:0001:0000:0000:0000:0000:0005', $html);
        $this->assertStringContainsString('2001:0db8:0001:0000:0000:0000:0000:0009', $html);
        $this->assertStringNotContainsString('2001:0db8:0002', $html, 'the range outside the search stays out');
    }

    public function test_the_header_cycle_is_ascending_then_descending_then_natural(): void
    {
        $this->user();

        $this->address('10.0.0.2');
        $this->address('10.0.0.1');

        // the hrefs in the markup carry & as an entity, because blade
        // escapes attributes: the needles have to say it the same way
        $first = (new AddressesController)->index($this->request('/plugin/ipam/addresses'))->render();
        $this->assertStringContainsString('order_by=address&amp;order_direction=asc', $first, 'the first click offers ascending');

        // ascending active: the header offers descending, marked with the caret
        $second = (new AddressesController)->index($this->request('/plugin/ipam/addresses?order_by=address&order_direction=asc'))->render();
        $this->assertStringContainsString('order_by=address&amp;order_direction=desc', $second, 'the second click offers descending');
        $this->assertStringContainsString('fa-caret-up', $second, 'the active ascending header is marked');

        // descending active: the header offers the natural order back, which is
        // a link with no order keys at all
        $third = (new AddressesController)->index($this->request('/plugin/ipam/addresses?order_by=address&order_direction=desc'))->render();
        $this->assertStringContainsString('fa-caret-down', $third, 'the active descending header is marked');
        $this->assertStringNotContainsString('order_by=address&amp;order_direction=asc', $third, 'the address header no longer points at ascending');
        $this->assertStringNotContainsString('order_by=address&amp;order_direction=desc', $third, 'nor at descending: the cycle came back around');
    }

    public function test_the_taxonomies_page_offers_the_drill_down_links(): void
    {
        $this->user();

        $owner = NetworkOwner::query()->create(['name' => 'Network team']);
        $this->address('10.0.0.1', ['net_owner_id' => $owner->net_owner_id]);
        Network::query()->create([
            'cidr' => '10.0.0.0/8', 'net_owner_id' => $owner->net_owner_id,
        ]);

        $html = (new TaxonomiesController)
            ->index($this->request('/plugin/ipam/taxonomies'))
            ->render();

        // the badge is the link that lands on exactly those objects
        $this->assertStringContainsString('plugin/ipam/networks?owner_id=' . $owner->net_owner_id, $html);
        $this->assertStringContainsString('plugin/ipam/addresses?owner_id=' . $owner->net_owner_id, $html);
    }

    public function test_a_filter_checkbox_can_actually_be_cleared(): void
    {
        // the hidden echo of the query string once carried the checkbox
        // fields too, so unchecking them resubmitted the same value and the
        // filter never came off
        $this->user();

        $request = $this->request('/plugin/ipam/addresses?unclassified=yes&show_hidden=1');
        $html = (new AddressesController)->index($request)->render();

        // the filter form may carry the visible checkbox, but not a second,
        // hidden copy of it with the same name: that copy is what made
        // unchecking the box change nothing. Other forms on the page echo the
        // same fields hidden on purpose, to keep the filters while doing their
        // own thing, so the assertion is scoped to the filter form's markup.
        $this->assertSame(
            1,
            preg_match('/<form method="get"[^>]*>.*?<\/form>/s', $html, $filterForm),
            'the filter form is the first get form on the page'
        );
        $this->assertStringNotContainsString('type="hidden" name="unclassified"', $filterForm[0]);
        $this->assertStringNotContainsString('type="hidden" name="show_hidden"', $filterForm[0]);
        $this->assertStringContainsString('name="unclassified"', $filterForm[0], 'the visible checkbox is still there');
    }
}
