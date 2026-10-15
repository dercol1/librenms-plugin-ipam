<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanLocation;
use Dercol1\LibrenmsIpam\Models\VlanName;
use Dercol1\LibrenmsIpam\Sites\SiteService;
use Dercol1\LibrenmsIpam\Vlans\VlanCollapser;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Two records of one vlan at one site.
 *
 * The key (id, domain, site) is the collision scope, so a pair of them is not a
 * cosmetic duplicate: it is one broadcast domain stored twice, which splits the
 * addresses that should collide into two findings that do not. It arrives two
 * ways — a merge of two sites, and a record dragged by hand onto a site that
 * already reports that vlan — and the answer is the same both times: they become
 * one record, and everything pointing at the loser follows it.
 */
class VlanCollapseTest extends UnitTestCase
{
    private Site $here;

    private Site $there;

    protected function setUp(): void
    {
        parent::setUp();

        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        Gate::define('ipam.view', fn ($u, mixed $target = null): bool => true);
        Gate::define('ipam.manage', fn ($u, mixed $target = null): bool => true);

        $this->here = Site::query()->create(['name' => 'Galazzano']);
        $this->there = Site::query()->create(['name' => 'Serravalle']);
    }

    private function request(): Request
    {
        $request = Request::create('/plugin/ipam/vlans');
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }

    private function vlan(int $vlanId, Site $site, int $domain = 1): Vlan
    {
        return Vlan::query()->create([
            'vlan_id' => $vlanId,
            'vlan_domain' => $domain,
            'site_id' => $site->site_id,
            'auto_created' => true,
        ]);
    }

    public function test_moving_a_vlan_onto_a_site_that_already_has_it_folds_the_two_into_one(): void
    {
        $moving = $this->vlan(100, $this->here);
        $staying = $this->vlan(100, $this->there);

        $request = $this->request();
        $request->merge([
            'vlan_record_id' => (string) $moving->vlan_record_id,
            'site_id' => (string) $this->there->site_id,
            'vlan_owner_id' => '0',
            'name' => 'CLIENT',
            'description' => 'la vlan dei client',
        ]);

        $response = (new VlansController)->update($request, (int) $moving->vlan_record_id);

        $this->assertNotNull($response->getSession()->get('success'), 'and it is said so, not left to be inferred');
        $this->assertSame(1, Vlan::query()->where('vlan_id', 100)->count(), 'one vlan, one record per site');

        $survivor = Vlan::query()->where('vlan_id', 100)->firstOrFail();

        $this->assertSame($staying->vlan_record_id, $survivor->vlan_record_id, 'the record that was already there survives');
        $this->assertSame('la vlan dei client', $survivor->description, 'what the operator typed is not lost with the row');
        $this->assertFalse($survivor->auto_created, 'and the vlan is theirs from now on');
    }

    public function test_the_occurrences_follow_the_record_that_survives(): void
    {
        $moving = $this->vlan(101, $this->here);
        $staying = $this->vlan(101, $this->there);

        Address::query()->create([
            'family' => 4, 'address' => '10.60.0.1', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 1, 'device_id' => 1, 'vlan_record_id' => $moving->vlan_record_id,
        ]);

        $request = $this->request();
        $request->merge([
            'site_id' => (string) $this->there->site_id,
            'vlan_owner_id' => '0',
            'name' => '',
            'description' => '',
        ]);

        (new VlansController)->update($request, (int) $moving->vlan_record_id);

        $this->assertSame(
            $staying->vlan_record_id,
            (int) Address::query()->where('address', '10.60.0.1')->value('vlan_record_id'),
            'an address classified into the row that went away is reclassified into the one that stayed'
        );
    }

    public function test_the_rooms_and_the_reported_names_follow_too(): void
    {
        $moving = $this->vlan(102, $this->here);
        $staying = $this->vlan(102, $this->there);

        $roomHere = Location::query()->create(['name' => 'Galazzano sala A', 'site_id' => $this->here->site_id]);
        $roomThere = Location::query()->create(['name' => 'Serravalle sala A', 'site_id' => $this->there->site_id]);

        VlanLocation::query()->create(['vlan_record_id' => $moving->vlan_record_id, 'location_id' => $roomHere->location_id]);
        VlanLocation::query()->create(['vlan_record_id' => $staying->vlan_record_id, 'location_id' => $roomThere->location_id]);

        VlanName::query()->create(['vlan_record_id' => $moving->vlan_record_id, 'device_id' => 1, 'name' => 'CLIENT']);
        VlanName::query()->create(['vlan_record_id' => $staying->vlan_record_id, 'device_id' => 2, 'name' => 'CLIENT-SW']);

        (new VlanCollapser)->collapse($staying, $moving);

        $rooms = VlanLocation::query()->where('vlan_record_id', $staying->vlan_record_id)->pluck('location_id');

        $this->assertCount(2, $rooms, 'the rooms the two records were seen in are one list');
        $this->assertCount(0, VlanLocation::query()->where('vlan_record_id', $moving->vlan_record_id)->get());

        // one name per device, and the disagreement between switches is still visible
        $this->assertSame(
            [1 => 'CLIENT', 2 => 'CLIENT-SW'],
            VlanName::query()->where('vlan_record_id', $staying->vlan_record_id)
                ->pluck('name', 'device_id')->all()
        );
    }

    public function test_a_verdict_on_the_record_that_goes_away_follows_the_conflict(): void
    {
        $moving = $this->vlan(103, $this->here);
        $staying = $this->vlan(103, $this->there);

        Duplicate::query()->create([
            'family' => 4, 'address' => '10.61.0.1', 'vlan_record_id' => $moving->vlan_record_id,
            'site_id' => 0, 'ignored' => true, 'note' => 'previsto',
        ]);

        (new VlanCollapser)->collapse($staying, $moving);

        $verdict = Duplicate::query()->where('address', '10.61.0.1')->first();

        $this->assertNotNull($verdict);
        $this->assertSame($staying->vlan_record_id, $verdict->vlan_record_id);
        $this->assertSame('previsto', $verdict->note);
    }

    public function test_two_verdicts_on_one_finding_become_one(): void
    {
        $moving = $this->vlan(104, $this->here);
        $staying = $this->vlan(104, $this->there);

        foreach ([[$moving, 'from the loser'], [$staying, 'from the winner']] as [$record, $note]) {
            Duplicate::query()->create([
                'family' => 4, 'address' => '10.62.0.1', 'vlan_record_id' => $record->vlan_record_id,
                'site_id' => 0, 'ignored' => true, 'note' => $note,
            ]);
        }

        (new VlanCollapser)->collapse($staying, $moving);

        $this->assertSame(1, Duplicate::query()->where('address', '10.62.0.1')->count(), 'one conflict, one verdict');
        $this->assertSame(
            'from the winner',
            Duplicate::query()->where('address', '10.62.0.1')->value('note'),
            'and it is the one written about the record that survived'
        );
    }

    public function test_a_stretched_domain_survives_the_fold(): void
    {
        $moving = $this->vlan(105, $this->here);
        $staying = $this->vlan(105, $this->there);

        $groupId = DB::table('ipam_vlan_groups')->insertGetId([
            'name' => 'vlan 105', 'description' => 'fibra', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $moving->update(['vlan_group_id' => $groupId]);

        (new VlanCollapser)->collapse($staying, $moving);

        $this->assertSame($groupId, (int) $staying->fresh()->vlan_group_id, 'a domain does not go away with the row');
    }

    public function test_two_domains_meeting_become_one(): void
    {
        $moving = $this->vlan(106, $this->here);
        $staying = $this->vlan(106, $this->there);

        $first = DB::table('ipam_vlan_groups')->insertGetId(['name' => 'a', 'created_at' => now(), 'updated_at' => now()]);
        $second = DB::table('ipam_vlan_groups')->insertGetId(['name' => 'b', 'created_at' => now(), 'updated_at' => now()]);

        $moving->update(['vlan_group_id' => $first]);
        $staying->update(['vlan_group_id' => $second]);

        (new VlanCollapser)->collapse($staying, $moving);

        $this->assertSame(1, DB::table('ipam_vlan_groups')->count(), 'the operator said they are one thing');
        $this->assertSame($first, (int) $staying->fresh()->vlan_group_id);
    }

    public function test_a_site_merge_keeps_two_domains_of_the_same_id_apart(): void
    {
        $drop = Site::query()->create(['name' => 'Armadio A13,A12']);

        // vlan 999 domain 1 and vlan 999 domain 2 are two broadcast domains,
        // and folding one site into another must not eat one of them
        $first = $this->vlan(999, $this->here, 1);
        $second = $this->vlan(999, $drop, 2);

        $moved = (new SiteService)->merge((int) $this->here->site_id, (int) $drop->site_id);

        $this->assertSame(0, $moved['ipam_vlans_merged'], 'their keys never collided');

        $this->assertSame(1, Vlan::query()->where('vlan_id', 999)->where('vlan_domain', 1)->count());
        $this->assertSame(1, Vlan::query()->where('vlan_id', 999)->where('vlan_domain', 2)->count());
        $this->assertSame(
            $this->here->site_id,
            (int) $second->fresh()->site_id,
            'and the one that moved with the site is the one that moved'
        );
        $this->assertNotNull(Vlan::query()->find($second->vlan_record_id), 'the record itself was never touched');
    }

    public function test_a_site_merge_collapses_the_records_that_really_do_collide(): void
    {
        $described = $this->vlan(500, $this->there, 1);
        $described->update(['description' => 'quella che ho descritto', 'auto_created' => false]);
        $auto = $this->vlan(500, $this->here, 1);

        Address::query()->create([
            'family' => 4, 'address' => '10.63.0.1', 'prefixlen' => 24, 'context_name' => '',
            'port_id' => 1, 'device_id' => 1, 'vlan_record_id' => $auto->vlan_record_id,
        ]);

        $moved = (new SiteService)->merge((int) $this->here->site_id, (int) $this->there->site_id);

        $this->assertSame(1, $moved['ipam_vlans_merged']);
        $this->assertSame(1, Vlan::query()->where('vlan_id', 500)->count());

        $survivor = Vlan::query()->where('vlan_id', 500)->firstOrFail();

        $this->assertSame($described->vlan_record_id, $survivor->vlan_record_id, 'the record somebody worked on survives');
        $this->assertSame('quella che ho descritto', $survivor->description);
        $this->assertSame(
            $survivor->vlan_record_id,
            (int) Address::query()->where('address', '10.63.0.1')->value('vlan_record_id')
        );
    }

    public function test_two_records_the_import_made_stay_the_imports(): void
    {
        $moving = $this->vlan(107, $this->here);
        $staying = $this->vlan(107, $this->there);

        (new VlanCollapser)->collapse($staying, $moving);

        $this->assertTrue($staying->fresh()->auto_created, 'nothing was typed, so it is still the import\'s vlan');
    }
}
