<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\Collision\DuplicateGroup;
use Dercol1\LibrenmsIpam\Import\AddressImporter;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkPlace;
use Dercol1\LibrenmsIpam\Models\NetworkStretch;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanGroup;
use Dercol1\LibrenmsIpam\Web\DuplicatesController;
use Dercol1\LibrenmsIpam\Web\NetworksController;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Declaring a stretched domain, the way an operator does it.
 *
 * The rule itself — the scope a collision is judged in — is held down in
 * StretchedVlansTest and StretchedNetworksTest, through the services. This file
 * is the other half, and it is the half an operator touches: the actions, the
 * candidates they are offered, what the pages show afterwards, and the promises
 * the feature makes that are not about finding a conflict at all.
 *
 * Those promises are the ones worth a test, because they are the ones that cost
 * something to keep. The import never touches a declared domain, or the next
 * polling cycle would undo a decision taken at the desk. Nesting is refused,
 * because a range inside a stretched one is already in it and a second answer to
 * "where does this reach?" is worse than no answer. The places on offer are
 * deliberately few, because offering the whole estate turns a choice into a
 * scroll.
 */
class StretchedDomainsTest extends UnitTestCase
{
    private Site $galazzano;

    private Site $serravalle;

    private Vlan $vlanHere;

    private Vlan $vlanThere;

    private Network $block;

    protected function user(): User
    {
        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        Gate::define('ipam.view', fn ($u, mixed $target = null): bool => true);
        Gate::define('ipam.manage', fn ($u, mixed $target = null): bool => true);

        // the report shows only the occurrences on devices the user may see, and
        // these tests are about the domain, not about permissions
        Gate::policy(Device::class, AlwaysAllowsPolicy::class);
        Gate::policy(Port::class, AlwaysAllowsPolicy::class);

        return $user;
    }

    private function request(string $uri = '/plugin/ipam/x'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }

    /**
     * Two real places, each with a room, and the vlan records the import would
     * have made for one number at each of them.
     */
    private function estate(): void
    {
        $this->galazzano = $this->definedSite('Galazzano', 'Galazzano Sala A');
        $this->serravalle = $this->definedSite('Serravalle', 'Serravalle rack');

        $this->vlanHere = $this->vlan(100, $this->galazzano);
        $this->vlanThere = $this->vlan(100, $this->serravalle);
        $this->block = Network::query()->create(['cidr' => '10.5.0.0/24']);
    }

    private function definedSite(string $name, string $room): Site
    {
        $site = Site::query()->create(['name' => $name, 'auto_created' => false]);
        Location::query()->create(['name' => $room, 'site_id' => $site->site_id]);

        return $site;
    }

    private function vlan(int $vlanId, Site $site): Vlan
    {
        return Vlan::query()->create([
            'vlan_id' => $vlanId,
            'vlan_domain' => 1,
            'site_id' => $site->site_id,
            'name' => "VLAN $vlanId",
        ]);
    }

    /**
     * The same address in the same vlan at both ends of the transport.
     */
    private function theSameAddressAtBothEnds(int $portSeed = 1): void
    {
        foreach ([[$this->vlanHere, $this->galazzano, $portSeed], [$this->vlanThere, $this->serravalle, $portSeed + 1]] as [$vlan, $site, $port]) {
            Address::query()->create([
                'family' => 4, 'address' => '10.5.0.1', 'prefixlen' => 24, 'context_name' => '',
                'port_id' => $port, 'device_id' => $port, 'network_id' => $this->block->network_id,
                'vlan_record_id' => $vlan->vlan_record_id, 'site_id' => $site->site_id,
            ]);
        }
    }

    /**
     * @return list<DuplicateGroup>
     */
    private function findingsFor(string $address): array
    {
        return array_values(array_filter(
            (new DuplicateDetector)->detect(),
            fn ($group): bool => $group->address === $address
        ));
    }

    // ----------------------------------------------------------------- vlans

    public function test_linking_the_two_records_makes_the_vlan_one_domain(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $request = $this->request('/plugin/ipam/vlans/link');
        $request->merge([
            'with' => [(string) $this->vlanThere->vlan_record_id],
            'description' => 'QinQ inside S-VID 400 over the dark fibre',
        ]);

        $response = (new VlansController)->link($request, (int) $this->vlanHere->vlan_record_id);

        $this->assertNotNull($response->getSession()->get('success'), 'and the operator is told it happened');

        $group = VlanGroup::query()->firstOrFail();

        $this->assertSame('QinQ inside S-VID 400 over the dark fibre', $group->description, 'the account of the transport is kept');
        $this->assertSame($group->vlan_group_id, (int) $this->vlanHere->fresh()->vlan_group_id);
        $this->assertSame($group->vlan_group_id, (int) $this->vlanThere->fresh()->vlan_group_id);

        $this->assertCount(1, $this->findingsFor('10.5.0.1'), 'and the same address at both ends is one conflict');
    }

    public function test_a_crafted_key_cannot_pull_in_a_record_of_another_vlan(): void
    {
        $this->user();
        $this->estate();

        $stranger = $this->vlan(101, $this->serravalle);

        $request = $this->request('/plugin/ipam/vlans/link');
        $request->merge(['with' => [(string) $stranger->vlan_record_id]]);

        $response = (new VlansController)->link($request, (int) $this->vlanHere->vlan_record_id);

        $this->assertNotNull($response->getSession()->get('error'), 'the form is built from the siblings, and only those are accepted');
        $this->assertSame(0, VlanGroup::query()->count());
        $this->assertSame(0, (int) $this->vlanHere->fresh()->vlan_group_id);
    }

    public function test_unlinking_takes_the_domain_back_apart(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $this->linkBoth();

        $this->assertCount(1, $this->findingsFor('10.5.0.1'));

        $response = (new VlansController)->unlink(
            $this->request('/plugin/ipam/vlans/unlink'),
            (int) $this->vlanHere->vlan_record_id
        );

        $this->assertNotNull($response->getSession()->get('success'));
        $this->assertCount(0, $this->findingsFor('10.5.0.1'), 'the site is the scope again');
    }

    public function test_a_domain_of_one_site_is_not_a_domain(): void
    {
        $this->user();
        $this->estate();

        $third = $this->definedSite('Dogana', 'Dogana rack');
        $there = $this->vlan(100, $third);

        $request = $this->request('/plugin/ipam/vlans/link');
        $request->merge(['with' => [(string) $there->vlan_record_id]]);

        (new VlansController)->link($request, (int) $this->vlanHere->vlan_record_id);
        (new VlansController)->unlink($this->request('/plugin/ipam/vlans/unlink'), (int) $there->vlan_record_id);

        // a domain reaching one site reaches nowhere: the group goes with it,
        // rather than a vlan wearing a stretched badge
        $this->assertSame(0, VlanGroup::query()->count());
        $this->assertSame(0, (int) $this->vlanHere->fresh()->vlan_group_id);
    }

    public function test_the_account_of_the_transport_is_editable_from_any_member(): void
    {
        $this->user();
        $this->estate();

        $this->linkBoth('fibra');

        $request = $this->request('/plugin/ipam/vlans/describe');
        $request->merge(['description' => 'QinQ inside S-VID 400, E-Line']);

        (new VlansController)->describe($request, (int) $this->vlanThere->vlan_record_id);

        $description = VlanGroup::query()->firstOrFail()->description;

        $this->assertSame('QinQ inside S-VID 400, E-Line', $description);
        $this->assertSame(1, VlanGroup::query()->count(), 'editing it from a member does not make a second one');
    }

    public function test_two_groups_meeting_end_up_as_one(): void
    {
        $this->user();
        $this->estate();

        $third = $this->definedSite('Dogana', 'Dogana rack');
        $dogana = $this->vlan(100, $third);

        $this->linkBoth('fibra');

        $request = $this->request('/plugin/ipam/vlans/link');
        $request->merge(['with' => [(string) $this->vlanHere->vlan_record_id]]);

        (new VlansController)->link($request, (int) $dogana->vlan_record_id);

        $this->assertSame(1, VlanGroup::query()->count(), 'the operator has just said they are one thing');
        $this->assertSame(
            VlanGroup::query()->firstOrFail()->vlan_group_id,
            (int) $dogana->fresh()->vlan_group_id
        );
    }

    public function test_the_import_never_touches_a_declared_domain(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $this->linkBoth('fibra');

        // fresh(): the linker writes the rows in bulk, so the model in memory
        // still holds the value from before the link
        $groupId = (int) $this->vlanHere->fresh()->vlan_group_id;

        // a full run, the kind daily.sh does on every polling cycle
        (new AddressImporter)->import(new ImportResult);

        $this->assertSame($groupId, (int) $this->vlanHere->fresh()->vlan_group_id);
        $this->assertSame($groupId, (int) $this->vlanThere->fresh()->vlan_group_id);
        $this->assertSame(1, VlanGroup::query()->count());
        $this->assertCount(1, $this->findingsFor('10.5.0.1'), 'so the conflict it silences stays silenced');
    }

    public function test_the_candidates_are_the_sibling_records_and_nothing_else(): void
    {
        $this->user();
        $this->estate();

        $dogana = $this->vlan(100, $this->definedSite('Dogana', 'Dogana rack'));
        $other = $this->vlan(101, $this->serravalle);
        $sameSite = $this->vlan(102, $this->galazzano);

        $html = $this->renderVlans();

        foreach ([$this->vlanThere, $dogana] as $sibling) {
            $this->assertStringContainsString(
                'value="' . $sibling->vlan_record_id . '"',
                $html,
                'the same vlan at another site is offered, however many there are'
            );
        }

        foreach ([$other, $sameSite] as $notASibling) {
            $this->assertStringNotContainsString(
                'value="' . $notASibling->vlan_record_id . '"',
                $html,
                'a different vlan, or the same number at the same site, is not'
            );
        }
    }

    // -------------------------------------------------------------- networks

    public function test_declaring_a_subnet_stretched_makes_the_two_ends_one_conflict(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $request = $this->request('/plugin/ipam/networks/stretch');
        $request->merge([
            'places' => ['site:' . $this->galazzano->site_id, 'site:' . $this->serravalle->site_id],
            'description' => 'QinQ inside S-VID 400, dark fibre',
        ]);

        $response = (new NetworksController)->stretch($request, (int) $this->block->network_id);

        $this->assertNotNull($response->getSession()->get('success'));
        $this->assertCount(2, NetworkPlace::query()->get(), 'both places are on the domain');

        $findings = $this->findingsFor('10.5.0.1');

        $this->assertCount(1, $findings, 'one subnet carried at layer 2, so one copy of every address');
        $this->assertGreaterThan(0, $findings[0]->stretchId);
        $this->assertSame(2, $findings[0]->count(), 'and both occurrences are named, one from each end');
    }

    public function test_stretching_needs_a_place_to_stretch_to(): void
    {
        $this->user();
        $this->estate();

        $request = $this->request('/plugin/ipam/networks/stretch');
        $request->merge(['places' => []]);

        $response = (new NetworksController)->stretch($request, (int) $this->block->network_id);

        $this->assertNotNull($response->getSession()->get('error'), 'a range stretched nowhere is not stretched');
        $this->assertSame(0, NetworkStretch::query()->count());
    }

    public function test_a_range_inside_a_stretched_one_is_not_stretched_again(): void
    {
        $this->user();
        $this->estate();

        $narrow = Network::query()->create(['cidr' => '10.5.0.0/25', 'parent_id' => $this->block->network_id]);

        $this->stretch(['site:' . $this->galazzano->site_id]);

        $request = $this->request('/plugin/ipam/networks/stretch');
        $request->merge(['places' => ['site:' . $this->serravalle->site_id]]);

        $response = (new NetworksController)->stretch($request, (int) $narrow->network_id);

        $this->assertNotNull($response->getSession()->get('error'));
        $this->assertStringContainsString(
            '10.5.0.0/24',
            (string) $response->getSession()->get('error'),
            'the refusal names the range it is already inside'
        );
        $this->assertSame(1, NetworkStretch::query()->count());
    }

    public function test_taking_a_subnet_back_releases_the_whole_subtree(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $narrow = Network::query()->create(['cidr' => '10.5.0.0/25', 'parent_id' => $this->block->network_id]);

        $this->stretch(['site:' . $this->galazzano->site_id, 'site:' . $this->serravalle->site_id]);

        $stretchId = (int) $this->block->fresh()->stretch_id;

        $this->assertSame($stretchId, (int) $narrow->fresh()->stretch_id, 'a block inside a stretched one is in it');

        (new NetworksController)->unstretch(
            $this->request('/plugin/ipam/networks/unstretch'),
            (int) $this->block->network_id
        );

        $this->assertSame(0, NetworkStretch::query()->count());
        $this->assertSame(0, (int) $narrow->fresh()->stretch_id, 'and everything only in it because of the one above');
        $this->assertCount(0, $this->findingsFor('10.5.0.1'));
    }

    public function test_only_real_places_are_offered_for_a_subnet(): void
    {
        $this->user();
        $this->estate();

        // a placeholder nobody has filed yet, and a site with no room under it
        $placeholder = Site::query()->create(['name' => 'Armadio A14', 'auto_created' => true]);
        Location::query()->create(['name' => 'Armadio A14', 'site_id' => $placeholder->site_id]);
        $empty = Site::query()->create(['name' => 'Sede futura', 'auto_created' => false]);

        $html = $this->renderNetworks();

        $this->assertStringContainsString('value="site:' . $this->galazzano->site_id . '"', $html, 'a real place is offered');
        $this->assertStringContainsString('value="site:' . $this->serravalle->site_id . '"', $html);
        $this->assertStringNotContainsString(
            'value="site:' . $placeholder->site_id . '"',
            $html,
            'a placeholder is a sysLocation with a name on it, not somewhere a segment goes'
        );
        $this->assertStringNotContainsString(
            'value="site:' . $empty->site_id . '"',
            $html,
            'and a site with no room holds nothing to reach'
        );
    }

    public function test_a_room_carrying_the_range_and_belonging_to_no_site_is_offered(): void
    {
        $this->user();
        $this->estate();

        $room = Location::query()->create(['name' => 'Armadio A13', 'site_id' => 0]);

        // and it has to carry the range: a room that has never seen an address in
        // it is not somewhere this subnet goes
        $html = $this->renderNetworks();
        $this->assertStringNotContainsString('value="location:' . $room->location_id . '"', $html);

        Address::query()->create([
            'family' => 4, 'address' => '10.5.0.9', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 3, 'device_id' => 3, 'network_id' => $this->block->network_id,
            'location_id' => $room->location_id, 'site_id' => 0,
        ]);

        $html = $this->renderNetworks();

        $this->assertStringContainsString(
            'value="location:' . $room->location_id . '"',
            $html,
            'a room with no site is exactly the kind of place a segment does get carried to'
        );
    }

    public function test_the_places_already_on_the_domain_come_back_ticked(): void
    {
        $this->user();
        $this->estate();

        $this->stretch(['site:' . $this->galazzano->site_id, 'site:' . $this->serravalle->site_id]);

        $html = $this->renderNetworks();

        $this->assertMatchesRegularExpression(
            '/value="site:' . $this->galazzano->site_id . '"\s+checked/',
            $html,
            'editing the list starts from what is there, not from what the heuristics still recognise'
        );
    }

    public function test_the_import_never_touches_a_declared_subnet(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $this->stretch(['site:' . $this->galazzano->site_id, 'site:' . $this->serravalle->site_id], 'fibra');

        $stretchId = (int) $this->block->fresh()->stretch_id;

        (new AddressImporter)->import(new ImportResult);

        $this->assertSame($stretchId, (int) $this->block->fresh()->stretch_id);
        $this->assertSame(2, NetworkPlace::query()->count());
        $this->assertCount(1, $this->findingsFor('10.5.0.1'), 'and the conflict it made is still found');
    }

    public function test_a_verdict_on_a_stretched_subnet_is_stored_by_the_domain(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $this->stretch(['site:' . $this->galazzano->site_id, 'site:' . $this->serravalle->site_id]);
        $stretchId = (int) $this->block->fresh()->stretch_id;

        $request = $this->request('/plugin/ipam/duplicates/annotate');
        $request->merge([
            'family' => 4, 'address' => '10.5.0.1', 'site_id' => 0,
            'vlan_record_id' => 0, 'vlan_group_id' => 0, 'stretch_id' => $stretchId,
            'ignored' => 1, 'note' => 'il gateway del circuito, una volta sola',
        ]);

        (new DuplicatesController)->annotate($request);

        $verdict = Duplicate::query()->where('stretch_id', $stretchId)->firstOrFail();

        $this->assertSame('il gateway del circuito, una volta sola', $verdict->note);
        $this->assertTrue($verdict->ignored);
        $this->assertTrue($this->findingsFor('10.5.0.1')[0]->ignored, 'and the report filters it out as known');
    }

    public function test_the_report_says_which_domain_a_finding_is_in(): void
    {
        $this->user();
        $this->estate();
        $this->theSameAddressAtBothEnds();

        $this->stretch(
            ['site:' . $this->galazzano->site_id, 'site:' . $this->serravalle->site_id],
            'QinQ inside S-VID 400'
        );

        $request = $this->request('/plugin/ipam/duplicates');
        $html = (new DuplicatesController)->index($request)->render();

        $this->assertStringContainsString('10.5.0.0/24 (Galazzano + Serravalle)', $html);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $places
     */
    private function stretch(array $places, ?string $description = null): void
    {
        $request = $this->request('/plugin/ipam/networks/stretch');
        $request->merge(array_filter(['places' => $places, 'description' => $description]));

        (new NetworksController)->stretch($request, (int) $this->block->network_id);
    }

    private function linkBoth(string $description = 'fibra'): void
    {
        $request = $this->request('/plugin/ipam/vlans/link');
        $request->merge(['with' => [(string) $this->vlanThere->vlan_record_id], 'description' => $description]);

        (new VlansController)->link($request, (int) $this->vlanHere->vlan_record_id);
    }

    private function renderVlans(): string
    {
        $request = $this->request('/plugin/ipam/vlans');
        $this->app->instance('request', $request);

        return (new VlansController)->index($request)->render();
    }

    private function renderNetworks(): string
    {
        $request = $this->request('/plugin/ipam/networks');
        $this->app->instance('request', $request);

        return (new NetworksController)->index($request)->render();
    }
}
