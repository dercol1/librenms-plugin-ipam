<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;

/**
 * The collision rule, which is the part of the plugin that has to be right.
 *
 * Everything else is bookkeeping. This decides whether a repeated address is a
 * conflict or the design, so it gets tested against the cases that make the
 * distinction hard: the same address in two vlans, in two sites, in two vrfs,
 * and once unclassified.
 */
class CollisionScopeTest extends UnitTestCase
{
    private Site $milano;

    private Site $roma;

    private Vlan $vlan300Milano;

    private Vlan $vlan300Roma;

    private Vlan $vlan301Milano;

    protected function setUp(): void
    {
        parent::setUp();

        $this->milano = Site::query()->create(['name' => 'Milano']);
        $this->roma = Site::query()->create(['name' => 'Roma']);
        $this->vlan300Milano = $this->vlan(300, $this->milano->site_id);
        $this->vlan300Roma = $this->vlan(300, $this->roma->site_id);
        $this->vlan301Milano = $this->vlan(301, $this->milano->site_id);
    }

    private function vlan(int $vlanId, int $siteId): Vlan
    {
        return Vlan::query()->create([
            'vlan_id' => $vlanId,
            'vlan_domain' => 1,
            'site_id' => $siteId,
            'vlan_owner_id' => 0,
            'name' => "VLAN $vlanId",
        ]);
    }

    /**
     * One occurrence of an address, on a port and device of its own.
     *
     * @param  array<string, mixed>  $overrides  columns to set on top of the defaults
     */
    private function address(string $ip, array $overrides = []): Address
    {
        static $nextPort = 1;

        $portId = $nextPort++;

        // built through the model rather than through create()'s mixed array, so
        // the attributes stay the model's own and stay type checked
        $record = new Address(array_merge([
            'family' => 4,
            'address' => $ip,
            'prefixlen' => 24,
            'context_name' => '',
            'port_id' => $portId,
            'device_id' => $portId,
        ], $overrides));

        $record->save();

        return $record;
    }

    public function test_the_same_address_in_two_vlans_is_not_a_collision(): void
    {
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan301Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);

        $this->assertCount(0, (new DuplicateDetector)->detect());
    }

    public function test_the_same_address_at_two_sites_is_not_a_collision(): void
    {
        // a gateway address repeated at every branch is normal addressing
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Roma->vlan_record_id, 'site_id' => $this->roma->site_id]);

        $this->assertCount(0, (new DuplicateDetector)->detect());
    }

    public function test_the_same_vlan_id_at_two_sites_is_two_records(): void
    {
        // this is why the vlan key carries the site
        $this->assertNotSame(
            $this->vlan300Milano->vlan_record_id,
            $this->vlan300Roma->vlan_record_id
        );
        $this->assertSame(300, $this->vlan300Roma->vlan_id);
    }

    public function test_the_same_address_twice_in_one_vlan_and_site_is_a_collision(): void
    {
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.1', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertSame('10.0.0.1', $groups[0]->address);
        $this->assertSame(2, $groups[0]->count());
    }

    public function test_three_occurrences_count_as_one_collision(): void
    {
        foreach (range(1, 3) as $_unused) {
            $this->address('10.0.0.5', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        }

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]->count());
    }

    public function test_ipv6_collisions_are_found_too(): void
    {
        foreach (range(1, 2) as $_unused) {
            $this->address('2001:db8::1', [
                'family' => 6,
                'prefixlen' => 64,
                'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
                'site_id' => $this->milano->site_id,
            ]);
        }

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->isIpv6());
    }

    public function test_the_same_ipv6_address_in_the_compressed_and_expanded_form_collides(): void
    {
        // LibreNMS stores expanded, a hand written note is likely compressed.
        // If these were compared as strings they would look like two addresses.
        $this->address('2001:0db8:0000:0000:0000:0000:0000:0001', [
            'family' => 6,
            'prefixlen' => 64,
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
        ]);
        $this->address('2001:0db8:0000:0000:0000:0000:0000:0001', [
            'family' => 6,
            'prefixlen' => 64,
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
        ]);

        $this->assertCount(1, (new DuplicateDetector)->detect());
    }

    public function test_an_unclassified_address_is_never_reported_as_a_collision(): void
    {
        // nothing to compare it against, so it goes to the queue instead
        $this->address('10.0.0.9');
        $this->address('10.0.0.9');

        $detector = new DuplicateDetector;

        $this->assertCount(0, $detector->detect());
        $this->assertSame(2, $detector->unclassifiedCount());
    }

    public function test_the_same_address_twice_at_one_site_without_a_vlan_is_a_collision(): void
    {
        // a site is a place on its own: two occurrences of one address there
        // clash whether or not anything says which vlan they were on. The
        // query once required a vlan as well and hid every one of these — on
        // the real data that was 41 findings, among them a wlan gateway
        // carried by three access points.
        $this->address('10.0.0.9', ['site_id' => $this->milano->site_id]);
        $this->address('10.0.0.9', ['site_id' => $this->milano->site_id]);

        $this->assertCount(1, (new DuplicateDetector)->detect());
    }

    public function test_the_same_address_twice_in_one_vlan_without_a_site_is_a_collision(): void
    {
        // and the mirror case: a vlan is a broadcast domain of its own, so the
        // same address twice inside it clashes even before anyone says where
        // that vlan lives
        $this->address('10.0.0.9', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id]);
        $this->address('10.0.0.9', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id]);

        $this->assertCount(1, (new DuplicateDetector)->detect());
    }

    public function test_an_unclassified_duplicate_is_still_reported_when_asked_for(): void
    {
        // neither vlan nor site, so by default there is nothing to compare it
        // against. Turning that off treats them as one shared scope, which is
        // noisier but does catch a duplicate hiding in an unclassified range.
        $this->address('10.0.0.9');
        $this->address('10.0.0.9');

        $detector = new DuplicateDetector(ignoreUnscoped: false);

        $this->assertCount(1, $detector->detect());
    }

    public function test_dropping_the_vlan_from_the_scope_makes_two_vlans_collide(): void
    {
        $this->address('10.0.0.2', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.2', ['vlan_record_id' => $this->vlan301Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);

        $this->assertCount(0, (new DuplicateDetector)->detect());

        // for a site that runs overlapping vlans nobody tracks
        $detector = new DuplicateDetector(useVlanScope: false);
        $this->assertCount(1, $detector->detect());
    }

    public function test_a_cross_device_collision_is_called_out(): void
    {
        $this->address('10.0.0.3', [
            'device_id' => 11,
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
        ]);
        $this->address('10.0.0.3', [
            'device_id' => 22,
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
        ]);

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->crossesDevices(), 'two devices claim the address');
        $this->assertSame(2, $groups[0]->deviceCount());
    }

    public function test_one_device_using_an_address_twice_is_not_a_cross_device_clash(): void
    {
        // a management mistake on one box, a different problem from two systems
        // fighting over the same address
        $this->address('10.0.0.4', [
            'device_id' => 11,
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
        ]);
        $this->address('10.0.0.4', [
            'device_id' => 11,
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
        ]);

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertFalse($groups[0]->crossesDevices());
    }

    public function test_a_marked_duplicate_keeps_its_note_and_its_ignored_flag(): void
    {
        $this->address('10.0.0.7', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.7', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);

        Duplicate::query()->create([
            'family' => 4,
            'address' => '10.0.0.7',
            'vlan_record_id' => $this->vlan300Milano->vlan_record_id,
            'site_id' => $this->milano->site_id,
            'ignored' => true,
            'note' => 'floating address, both switches are supposed to answer',
        ]);

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->ignored);
        $this->assertStringContainsString('floating address', (string) $groups[0]->note);
    }

    public function test_the_site_filter_narrows_the_report(): void
    {
        $this->address('10.0.0.8', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.8', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.1.8', ['vlan_record_id' => $this->vlan300Roma->vlan_record_id, 'site_id' => $this->roma->site_id]);
        $this->address('10.0.1.8', ['vlan_record_id' => $this->vlan300Roma->vlan_record_id, 'site_id' => $this->roma->site_id]);

        $this->assertCount(2, (new DuplicateDetector)->detect());
        $this->assertCount(1, (new DuplicateDetector)->detect($this->roma->site_id));
    }

    public function test_an_address_never_collides_with_a_different_one(): void
    {
        $this->address('10.0.0.10', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);
        $this->address('10.0.0.11', ['vlan_record_id' => $this->vlan300Milano->vlan_record_id, 'site_id' => $this->milano->site_id]);

        $this->assertCount(0, (new DuplicateDetector)->detect());
    }
}
