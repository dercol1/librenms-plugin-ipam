<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkPlace;
use Dercol1\LibrenmsIpam\Models\NetworkStretch;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Networks\NetworkStretcher;
use Illuminate\Support\Facades\DB;

/**
 * Subnets that exist unchanged at more than one place.
 *
 * A vlan stretched across sites is a broadcast domain; a network stretched
 * across them is one subnet carried at layer 2, and the collision rule has to
 * see it as the single thing it is: the same address, in the same vlan, at two
 * ends of the same fibre is one conflict, while the same address in two
 * different vlans inside that subnet is two broadcast domains and no conflict
 * at all.
 *
 * Which places may be chosen is half the feature: a site is only offered when
 * an operator defined it, because the others are the resolver's placeholders,
 * and a room is offered when it carries the range and belongs to no site.
 */
class StretchedNetworksTest extends UnitTestCase
{
    private Site $milano;

    private Site $roma;

    private Vlan $vlan300Milano;

    private Vlan $vlan300Roma;

    private Vlan $vlan301Milano;

    private Network $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->milano = $this->definedSite('Milano');
        $this->roma = $this->definedSite('Roma');
        $this->vlan300Milano = $this->vlan(300, $this->milano);
        $this->vlan300Roma = $this->vlan(300, $this->roma);
        $this->vlan301Milano = $this->vlan(301, $this->milano);
        $this->block = $this->block('10.0.0.0/24');
    }

    /**
     * A site an operator built: at least one room, and not a placeholder.
     */
    private function definedSite(string $name): Site
    {
        $site = Site::query()->create(['name' => $name, 'auto_created' => false]);

        Location::query()->create(['name' => $name . ' sala 1', 'site_id' => $site->site_id]);

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

    private function block(string $cidr, int $parentId = 0): Network
    {
        return Network::query()->create(['cidr' => $cidr, 'parent_id' => $parentId]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function address(string $ip, array $overrides = []): Address
    {
        static $nextPort = 1;

        $portId = $nextPort++;

        $record = new Address(array_merge([
            'family' => 4,
            'address' => $ip,
            'prefixlen' => 24,
            'context_name' => '',
            'port_id' => $portId,
            'device_id' => $portId,
            'network_id' => $this->block->network_id,
        ], $overrides));

        $record->save();

        return $record;
    }

    /**
     * The same address in vlan 300 at both sites, inside the block.
     */
    private function theSameAddressAtBothEnds(): void
    {
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Roma->vlan_record_id, 'site_id' => $this->roma->site_id]);
    }

    public function test_a_subnet_at_two_sites_is_two_domains_until_it_is_stretched(): void
    {
        $this->theSameAddressAtBothEnds();

        $this->assertCount(0, (new DuplicateDetector)->detect());
    }

    public function test_stretching_the_subnet_makes_the_two_ends_one_conflict(): void
    {
        $this->theSameAddressAtBothEnds();

        [$stretchId] = (new NetworkStretcher)->stretch(
            $this->block,
            ['site:' . $this->milano->site_id, 'site:' . $this->roma->site_id],
            'QinQ inside S-VID 400 over the dark fibre'
        );

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups, 'one subnet carried at layer 2, so one copy of every address');
        $this->assertSame($stretchId, $groups[0]->stretchId);
        $this->assertSame(300, $groups[0]->vlanNumber, 'the scope is the vlan, by number across the two records');
        $this->assertCount(2, $groups[0]->occurrences);
    }

    public function test_two_different_vlans_inside_a_stretched_subnet_are_two_domains(): void
    {
        // the same address, in two vlans, at two ends: two broadcast domains
        // reusing one subnet is a design, not a mistake
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan301Milano->vlan_record_id, 'site_id' => $this->roma->site_id]);

        (new NetworkStretcher)->stretch(
            $this->block,
            ['site:' . $this->milano->site_id, 'site:' . $this->roma->site_id],
            null
        );

        $this->assertCount(0, (new DuplicateDetector)->detect());
    }

    public function test_a_place_outside_the_domain_keeps_its_own_scope(): void
    {
        // the subnet reaches Milano and Roma; a third site running the same
        // address is not part of what the operator declared
        $napoli = $this->definedSite('Napoli');
        $this->vlan(300, $napoli);

        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.1', [
            'vlan_record_id' => Vlan::query()->where('vlan_id', 300)->where('site_id', $napoli->site_id)->value('vlan_record_id'),
            'site_id' => $napoli->site_id,
        ]);

        (new NetworkStretcher)->stretch(
            $this->block,
            ['site:' . $this->milano->site_id, 'site:' . $this->roma->site_id],
            null
        );

        $this->assertCount(0, (new DuplicateDetector)->detect(), 'Napoli was never declared as a place');
    }

    public function test_taking_the_statement_back_ends_the_conflict(): void
    {
        $this->theSameAddressAtBothEnds();

        $stretcher = new NetworkStretcher;
        $stretcher->stretch($this->block, ['site:' . $this->milano->site_id, 'site:' . $this->roma->site_id], null);

        $this->assertCount(1, (new DuplicateDetector)->detect());

        $stretcher->unstretch($this->block->fresh());

        $this->assertCount(0, (new DuplicateDetector)->detect(), 'the site is the scope again');
        $this->assertSame(0, (int) $this->block->fresh()->stretch_id);
        $this->assertSame(0, NetworkStretch::query()->count(), 'a domain nobody declares any more is not kept');
    }

    public function test_a_described_range_inside_a_stretched_one_is_in_the_domain(): void
    {
        // the /24 was described after the /16 was stretched: it is inside the
        // domain without anybody saying so again
        $wide = $this->block('10.0.0.0/16');
        $narrow = $this->block('10.0.1.0/24', (int) $wide->network_id);

        (new NetworkStretcher)->stretch($wide, ['site:' . $this->milano->site_id], null);

        $this->assertSame((int) $wide->fresh()->stretch_id, (int) $narrow->fresh()->stretch_id);
    }

    public function test_a_range_inside_a_stretched_one_is_not_stretched_again(): void
    {
        $wide = $this->block('10.1.0.0/16');
        $narrow = $this->block('10.1.1.0/24', (int) $wide->network_id);

        (new NetworkStretcher)->stretch($wide, ['site:' . $this->milano->site_id], null);

        [$id, $refusal] = (new NetworkStretcher)->stretch($narrow, ['site:' . $this->roma->site_id], null);

        $this->assertSame(0, $id);
        $this->assertStringContainsString('10.1.0.0/16', (string) $refusal, 'the refusal names the range it is already inside');
    }

    public function test_a_domain_can_be_given_a_room_instead_of_a_whole_site(): void
    {
        $room = Location::query()->create(['name' => 'Armadio A13', 'site_id' => 0]);

        $this->address('10.0.0.1', [
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
            'location_id' => $room->location_id,
        ]);

        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Roma->vlan_record_id, 'site_id' => $this->roma->site_id]);

        (new NetworkStretcher)->stretch(
            $this->block,
            ['location:' . $room->location_id, 'site:' . $this->roma->site_id],
            null
        );

        $this->assertCount(1, (new DuplicateDetector)->detect(), 'the room is the place, and it carries the range');
    }

    public function test_only_defined_sites_with_rooms_are_offered(): void
    {
        $placeholder = Site::query()->create(['name' => 'Armadio A14', 'auto_created' => true]);
        Location::query()->create(['name' => 'Armadio A14', 'site_id' => $placeholder->site_id]);

        $empty = Site::query()->create(['name' => 'Sede vuota', 'auto_created' => false]);

        $keys = array_column((new NetworkStretcher)->candidates($this->block), 'key');

        $this->assertContains('site:' . $this->milano->site_id, $keys);
        $this->assertContains('site:' . $this->roma->site_id, $keys);
        $this->assertNotContains('site:' . $placeholder->site_id, $keys, 'a placeholder is not somewhere a segment goes');
        $this->assertNotContains('site:' . $empty->site_id, $keys, 'a site with no room holds nothing to reach');
    }

    public function test_an_unassigned_room_carrying_the_range_is_offered(): void
    {
        $room = Location::query()->create(['name' => 'Armadio A13', 'site_id' => 0]);

        $this->address('10.0.0.7', [
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
            'location_id' => $room->location_id,
        ]);

        $rooms = (new NetworkStretcher)->roomsCarrying([$this->block]);

        $keys = array_column((new NetworkStretcher)->candidates($this->block, $rooms[(int) $this->block->network_id] ?? []), 'key');

        $this->assertContains('location:' . $room->location_id, $keys);
    }

    public function test_the_places_already_on_the_domain_come_back_ticked(): void
    {
        $stretcher = new NetworkStretcher;

        [$stretchId] = $stretcher->stretch($this->block, ['site:' . $this->milano->site_id, 'site:' . $this->roma->site_id], null);

        $candidates = $stretcher->candidates($this->block->fresh());
        $ticked = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['taken']));

        $this->assertCount(2, $ticked, 'editing the list starts from what is there');
        $this->assertSame(
            $stretchId,
            (int) $this->block->fresh()->stretch_id,
            'and the places row and the block agree on which domain it is'
        );
    }

    public function test_a_verdict_on_the_domain_silences_the_finding(): void
    {
        $this->theSameAddressAtBothEnds();

        [$stretchId] = (new NetworkStretcher)->stretch(
            $this->block,
            ['site:' . $this->milano->site_id, 'site:' . $this->roma->site_id],
            null
        );

        Duplicate::query()->create([
            'family' => 4,
            'address' => '10.0.0.1',
            'vlan_record_id' => 0,
            'vlan_group_id' => 0,
            'stretch_id' => $stretchId,
            'site_id' => 0,
            'ignored' => true,
            'note' => 'the gateway of the stretched subnet, on purpose',
        ]);

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->ignored, 'the report filters it out as known');
        $this->assertSame('the gateway of the stretched subnet, on purpose', $groups[0]->note);
    }

    /**
     * The place kind has to travel as a value, never as a word in the sql.
     *
     * sqlite is more forgiving than mysql about an identifier it cannot
     * resolve, so the suite running green here would have said nothing: on the
     * real install `place_type = site` is mysql reading `site` as a column name
     * and the whole collision report dying with "Unknown column 'site' in
     * 'WHERE'". A whereColumn with a word in it emits that word unquoted, so the
     * shape of the statement is the thing worth holding down.
     */
    public function test_the_place_kind_is_bound_rather_than_written_into_the_sql(): void
    {
        $this->theSameAddressAtBothEnds();

        (new NetworkStretcher)->stretch(
            $this->block,
            ['site:' . $this->milano->site_id, 'site:' . $this->roma->site_id],
            null
        );

        $captured = [];

        DB::listen(function ($query) use (&$captured): void {
            $captured[] = [$query->sql, $query->bindings];
        });

        (new DuplicateDetector)->detect();

        // the statement that reads the places: the scope sub-select, which also
        // picks the places of a group up afterwards and would match on the table
        // name alone
        $stretches = array_values(array_filter(
            $captured,
            fn (array $query): bool => str_contains($query[0], 'ipam_network_places')
                && str_contains($query[0], 'place_type')
        ));

        $this->assertNotEmpty($stretches, 'the domain membership is part of the query');

        foreach ($stretches as [$sql, $bindings]) {
            $bindings = array_map('strval', $bindings);
            $this->assertStringNotContainsString('place_type = site', $sql, 'the kind is a value, not an identifier');
            $this->assertStringNotContainsString('place_type = location', $sql);
            $this->assertContains('site', $bindings, 'and it is carried as a binding');
            $this->assertContains('location', $bindings);
        }
    }

    public function test_a_domain_with_no_places_reports_nothing(): void
    {
        // a domain nobody finished declaring must not turn every occurrence of
        // an address into a duplicate of itself
        $this->theSameAddressAtBothEnds();

        $stretcher = new NetworkStretcher;

        [$stretchId] = $stretcher->stretch($this->block, ['site:' . $this->milano->site_id], null);

        NetworkPlace::query()->where('stretch_id', $stretchId)->delete();

        $this->assertCount(0, (new DuplicateDetector)->detect());
    }
}
