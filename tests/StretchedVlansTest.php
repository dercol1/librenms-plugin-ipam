<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\Import\VlanImporter;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanGroup;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Dercol1\LibrenmsIpam\Vlans\VlanLinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Vlans carried across sites at layer 2.
 *
 * The plugin reads a vlan per site because that is what the devices report
 * and because 141 of 220 vlan ids are the same number reused locally — two
 * separate domains, correctly kept apart by the site in the collision scope.
 * A stretched vlan is the other case: one broadcast domain riding dark
 * fibre, an E-Line, or QinQ inside an outer 802.1ad tag, arriving at each
 * site as the same vlan. Its per-site records are one domain, and the same
 * address at two of its sites is one conflict, because at layer 2 nothing
 * can own that address twice.
 *
 * Only the operator knows the topology, so the link is a decision made on
 * the vlan page and never touched by the import. These tests hold down both
 * halves: the decision (link, merge, unlink, describe) and the rule that
 * reads it back.
 */
class StretchedVlansTest extends UnitTestCase
{
    protected function user(): User
    {
        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        Gate::define('ipam.view', fn ($u, mixed $target = null): bool => true);
        Gate::define('ipam.manage', fn ($u, mixed $target = null): bool => true);

        return $user;
    }

    /**
     * Two sites, one vlan id at each: the records the import would make.
     */
    private function vlanAt(int $vlanId, Site $site): Vlan
    {
        return Vlan::query()->create([
            'vlan_id' => $vlanId,
            'vlan_domain' => 1,
            'site_id' => $site->site_id,
            'name' => 'carried',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function address(string $ip, array $overrides = []): Address
    {
        // distinct ports: the identity of a row is family, address, prefix,
        // port and vrf, and two occurrences of one address are two ports
        static $nextPort = 1;

        $cidr = Cidr::address($ip);

        $record = Address::query()->create([
            'family' => $cidr->getFamily(),
            'address' => $cidr->uncompressed(),
            'prefixlen' => Cidr::maxPrefixLen($cidr->getFamily()),
            'port_id' => $nextPort++,
            'device_id' => $nextPort,
            'context_name' => '',
        ]);

        foreach ($overrides as $key => $value) {
            $record->setAttribute($key, $value);
        }

        $record->save();

        return $record->refresh();
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function sites(): array
    {
        return [
            Site::query()->create(['name' => 'Galazzano']),
            Site::query()->create(['name' => 'Serravalle']),
        ];
    }

    public function test_the_same_address_at_two_sites_in_one_vlan_is_two_domains_until_linked(): void
    {
        [$galazzano, $serravalle] = $this->sites();

        $here = $this->vlanAt(100, $galazzano);
        $there = $this->vlanAt(100, $serravalle);

        // the same address in both: at this point it is the common case, the
        // same number reused locally, and the site separates the two
        $this->address('10.5.0.1', ['site_id' => $galazzano->site_id, 'vlan_record_id' => $here->vlan_record_id]);
        $this->address('10.5.0.1', ['site_id' => $serravalle->site_id, 'vlan_record_id' => $there->vlan_record_id]);

        $this->assertSame([], (new DuplicateDetector)->detect(), 'two separate broadcast domains, no conflict');
    }

    public function test_linking_the_records_makes_the_two_sites_one_domain(): void
    {
        [$galazzano, $serravalle] = $this->sites();

        $here = $this->vlanAt(100, $galazzano);
        $there = $this->vlanAt(100, $serravalle);

        $this->address('10.5.0.1', ['site_id' => $galazzano->site_id, 'vlan_record_id' => $here->vlan_record_id]);
        $this->address('10.5.0.1', ['site_id' => $serravalle->site_id, 'vlan_record_id' => $there->vlan_record_id]);

        $groupId = (new VlanLinker)->link(
            (int) $here->vlan_record_id,
            [(int) $there->vlan_record_id],
            'QinQ inside S-VID 400 over the dark fibre'
        );

        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups, 'one domain, so the same address at both ends is one conflict');
        $this->assertSame($groupId, $groups[0]->vlanGroupId);
        $this->assertSame(2, $groups[0]->count(), 'both occurrences are named, one from each site');
    }

    public function test_the_transport_account_is_kept_on_the_domain(): void
    {
        [$galazzano, $serravalle] = $this->sites();

        $here = $this->vlanAt(100, $galazzano);
        $there = $this->vlanAt(100, $serravalle);

        $groupId = (new VlanLinker)->link((int) $here->vlan_record_id, [(int) $there->vlan_record_id], null);

        (new VlanLinker)->describe((int) $here->vlan_record_id, 'QinQ inside S-VID 400');

        $this->assertSame('QinQ inside S-VID 400', VlanGroup::query()->find($groupId)->description);
        // the account shows from the other member too: it is the domain's
        $this->assertSame('QinQ inside S-VID 400', $there->fresh()->group->description);
    }

    public function test_unlinking_one_member_leaves_the_domain_for_the_rest(): void
    {
        $third = Site::query()->create(['name' => 'Dogana']);
        [$galazzano, $serravalle] = $this->sites();

        $here = $this->vlanAt(100, $galazzano);
        $there = $this->vlanAt(100, $serravalle);
        $elsewhere = $this->vlanAt(100, $third);

        (new VlanLinker)->link(
            (int) $here->vlan_record_id,
            [(int) $there->vlan_record_id, (int) $elsewhere->vlan_record_id],
            null
        );

        (new VlanLinker)->unlink((int) $elsewhere->vlan_record_id);

        $this->assertSame($here->fresh()->vlan_group_id, $there->fresh()->vlan_group_id, 'the two still linked share the domain');
        $this->assertSame(0, (int) $elsewhere->fresh()->vlan_group_id, 'the one taken out is back to a local vlan');
    }

    public function test_unlinking_the_last_but_one_takes_the_group_with_it(): void
    {
        [$galazzano, $serravalle] = $this->sites();

        $here = $this->vlanAt(100, $galazzano);
        $there = $this->vlanAt(100, $serravalle);

        $groupId = (new VlanLinker)->link((int) $here->vlan_record_id, [(int) $there->vlan_record_id], null);

        (new VlanLinker)->unlink((int) $there->vlan_record_id);

        // a domain of one site is not a domain: the group goes and nothing
        // pretends the vlan is still stretched
        $this->assertNull(VlanGroup::query()->find($groupId));
        $this->assertSame(0, (int) $here->fresh()->vlan_group_id);
    }

    public function test_two_groups_meeting_merge_into_one(): void
    {
        $third = Site::query()->create(['name' => 'Dogana']);
        [$galazzano, $serravalle] = $this->sites();

        $a = $this->vlanAt(100, $galazzano);
        $b = $this->vlanAt(100, $serravalle);
        $c = $this->vlanAt(100, $third);

        // Galazzano + Serravalle linked first, Dogana on its own later: the
        // operator then says Dogana is in the same domain
        $first = (new VlanLinker)->link((int) $a->vlan_record_id, [(int) $b->vlan_record_id], 'fibre');

        (new VlanLinker)->link((int) $c->vlan_record_id, [(int) $a->vlan_record_id], null);

        $groups = VlanGroup::query()->pluck('vlan_group_id')->all();

        $this->assertCount(1, $groups, 'the two groups became one');
        $this->assertSame($first, (int) $c->fresh()->vlan_group_id, 'the newcomer joined the existing domain');
        $this->assertSame('fibre', VlanGroup::query()->find($first)->description, 'the account of the transport survived the merge');
    }

    public function test_the_link_survives_the_import(): void
    {
        [$galazzano, $serravalle] = $this->sites();

        $here = $this->vlanAt(100, $galazzano);
        $there = $this->vlanAt(100, $serravalle);

        $groupId = (new VlanLinker)->link((int) $here->vlan_record_id, [(int) $there->vlan_record_id], null);

        // the importer refreshes what the devices report and never touches the
        // group: the decision is the operator's and the poll cannot undo it
        DB::table('vlans')->insert([
            'device_id' => 1, 'vlan_vlan' => 100, 'vlan_domain' => 1, 'vlan_name' => 'FROM-DEVICE',
        ]);
        DB::table('devices')->where('device_id', 1)->update(['location_id' => null]);

        (new VlanImporter)->import(new ImportResult);

        $this->assertSame($groupId, (int) $here->fresh()->vlan_group_id, 'the link outlived the poll');
        $this->assertSame($groupId, (int) $there->fresh()->vlan_group_id);
    }

    public function test_a_verdict_on_a_stretched_conflict_is_stored_by_domain(): void
    {
        // the annotation has to key on the domain: a vlan record id would be
        // one site's record out of several, and the verdict covers the whole
        // domain or it covers nothing
        [$galazzano, $serravalle] = $this->sites();

        $here = $this->vlanAt(100, $galazzano);
        $there = $this->vlanAt(100, $serravalle);

        $this->address('10.5.0.1', ['site_id' => $galazzano->site_id, 'vlan_record_id' => $here->vlan_record_id]);
        $this->address('10.5.0.1', ['site_id' => $serravalle->site_id, 'vlan_record_id' => $there->vlan_record_id]);

        $groupId = (new VlanLinker)->link((int) $here->vlan_record_id, [(int) $there->vlan_record_id], null);

        // the operator marks the conflict as intended, a stretched gateway
        Duplicate::query()->create([
            'family' => 4,
            'address' => '10.5.0.1',
            'vlan_record_id' => 0,
            'vlan_group_id' => $groupId,
            'site_id' => 0,
            'ignored' => true,
            'note' => 'stretched gateway, on purpose',
        ]);

        // the detector hands the finding over with the verdict attached: the
        // report filters known-good findings out, and this one answers as such
        $groups = (new DuplicateDetector)->detect();

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->ignored, 'the verdict on the domain marks the finding known');
        $this->assertSame('stretched gateway, on purpose', $groups[0]->note);
    }
}
