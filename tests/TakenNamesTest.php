<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\NetworkOwner;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Sites\SiteService;
use Dercol1\LibrenmsIpam\Web\SitesController;
use Dercol1\LibrenmsIpam\Web\TaxonomiesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Names that are already taken.
 *
 * Site names are unique, and so are taxonomy names, and for a while the only
 * thing enforcing that was the database constraint: renaming a site onto a
 * name another site held came back as a 500 from MySQL, with a stack trace and
 * no message an operator could act on. These are the three answers a rename
 * has to be able to give instead — free, absorbed, refused — and the cleanup
 * that keeps empty name-only sites from accumulating in the first place.
 */
class TakenNamesTest extends UnitTestCase
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

    protected function request(string $uri = '/'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }

    /**
     * A site holding a location, so it is "live", and a site holding nothing
     * but its name, which is the residue a merge or a move leaves behind.
     */
    private function liveSite(string $name): Site
    {
        $site = Site::query()->create(['name' => $name]);

        Location::query()->create(['name' => $name . ' room', 'site_id' => $site->site_id]);

        return $site;
    }

    private function zombieSite(string $name): Site
    {
        return Site::query()->create(['name' => $name]);
    }

    /**
     * The rename that produced a 500 on a real install: the target name
     * already belongs to another site.
     */
    public function test_renaming_onto_a_live_sites_name_is_refused_not_a_500(): void
    {
        $this->user();

        $holder = $this->liveSite('AZIENDA1-Sede');
        $renaming = $this->liveSite('Old coordinates');

        $request = $this->request('/plugin/ipam/sites/' . $renaming->site_id);
        $request->merge(['name' => 'AZIENDA1-Sede', 'description' => null, 'address' => null]);

        $response = (new SitesController)->update($request, (int) $renaming->site_id);

        $this->assertNotNull(Site::query()->find($holder->site_id), 'the holder is intact');
        $this->assertNotNull(Site::query()->find($renaming->site_id), 'the site being renamed is intact');

        $this->assertStringContainsString(
            'already exists and it holds data',
            (string) $response->getSession()->get('error'),
            'the operator is told what to do about it, not shown a stack trace',
        );
    }

    public function test_renaming_onto_a_leftovers_name_absorbs_it(): void
    {
        $this->user();

        // the residue: a name left behind by a merge, holding nothing
        $this->zombieSite('Galazzano Sala B');

        $real = $this->liveSite('Galazzano');
        $real->name = 'Galazzano';
        $real->save();

        $request = $this->request('/plugin/ipam/sites/' . $real->site_id);
        $request->merge(['name' => 'Galazzano Sala B', 'description' => 'the whole building', 'address' => null]);

        $response = (new SitesController)->update($request, (int) $real->site_id);

        $this->assertSame('Galazzano Sala B', $real->fresh()->name, 'the rename went through');
        $this->assertSame('the whole building', $real->fresh()->description, 'the description from the same form went with it');

        $this->assertSame(1, Site::query()->where('name', 'Galazzano Sala B')->count(), 'exactly one site by that name now, not a constraint violation');
        $this->assertStringContainsString('An empty site that held the name', (string) $response->getSession()->get('success'));
    }

    public function test_renaming_a_site_to_its_own_name_is_not_a_collision(): void
    {
        $this->user();

        $site = $this->liveSite('Galazzano');

        $request = $this->request('/plugin/ipam/sites/' . $site->site_id);
        $request->merge(['name' => 'Galazzano', 'description' => 'just editing the description', 'address' => null]);

        $response = (new SitesController)->update($request, (int) $site->site_id);

        $this->assertNull($response->getSession()->get('error'), 'keeping your own name is not taking someone else\'s');
        $this->assertSame('just editing the description', $site->fresh()->description);
    }

    public function test_moving_the_last_location_out_removes_the_emptied_site(): void
    {
        $this->user();

        $left = Site::query()->create(['name' => 'Old place']);
        $location = Location::query()->create(['name' => 'the only room', 'site_id' => $left->site_id]);
        $target = $this->liveSite('New place');

        $result = (new SiteService)->moveLocation((int) $location->location_id, (int) $target->site_id);

        $this->assertTrue($result['moved']);
        $this->assertNotNull($result['emptied'], 'the caller is told which site was removed');
        $this->assertSame('Old place', $result['emptied']->name);
        $this->assertNull(Site::query()->find($left->site_id), 'a name with nothing under it does not linger');
    }

    public function test_moving_one_location_out_keeps_a_site_that_still_holds_things(): void
    {
        $this->user();

        $site = Site::query()->create(['name' => 'Busy place']);
        $location = Location::query()->create(['name' => 'room one', 'site_id' => $site->site_id]);
        Location::query()->create(['name' => 'room two', 'site_id' => $site->site_id]);
        $target = $this->liveSite('Elsewhere');

        $result = (new SiteService)->moveLocation((int) $location->location_id, (int) $target->site_id);

        $this->assertTrue($result['moved']);
        $this->assertNull($result['emptied'], 'the site still holds a room');
        $this->assertNotNull(Site::query()->find($site->site_id));
    }

    public function test_a_site_with_only_a_collision_note_is_not_empty(): void
    {
        // the note on a marked collision belongs to the data it describes, so
        // a site holding one is not residue to sweep away
        $service = new SiteService;
        $site = $this->zombieSite('Noted place');

        DB::table('ipam_duplicates')->insert([
            'family' => 4, 'address' => '10.0.0.1', 'vlan_record_id' => 0,
            'site_id' => $site->site_id, 'ignored' => false, 'note' => 'floating pair',
            'updated_by' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($service->isEmpty($site));

        // and it is not offered by the empty-sites sweep either
        $this->assertFalse($service->emptySites()->contains('site_id', $site->site_id));
    }

    /**
     * The same three answers on the taxonomy lists, whose names are unique
     * the same way.
     */
    public function test_renaming_an_owner_onto_a_used_name_is_refused(): void
    {
        $this->user();

        $holder = NetworkOwner::query()->create(['name' => 'AZIENDA0']);
        Network::query()->create(['cidr' => '10.0.0.0/8', 'net_owner_id' => $holder->net_owner_id]);
        $other = NetworkOwner::query()->create(['name' => 'Someone else']);

        $request = $this->request('/plugin/ipam/taxonomies/net-owner/' . $other->net_owner_id);
        $request->merge(['name' => 'AZIENDA0', 'description' => null]);

        $response = (new TaxonomiesController)->update($request, 'net-owner', (int) $other->net_owner_id);

        $this->assertNotNull(NetworkOwner::query()->find($other->net_owner_id), 'the rename did not happen');
        $this->assertStringContainsString('something still uses it', (string) $response->getSession()->get('error'));
    }

    public function test_renaming_an_owner_onto_an_unused_leftover_absorbs_it(): void
    {
        $this->user();

        // an entry from before the page existed, still unused
        NetworkOwner::query()->create(['name' => 'Old spelling']);
        $entry = NetworkOwner::query()->create(['name' => 'Current']);

        $request = $this->request('/plugin/ipam/taxonomies/net-owner/' . $entry->net_owner_id);
        $request->merge(['name' => 'Old spelling', 'description' => 'the one true name']);

        (new TaxonomiesController)->update($request, 'net-owner', (int) $entry->net_owner_id);

        $this->assertSame(1, NetworkOwner::query()->where('name', 'Old spelling')->count(), 'exactly one, not a constraint violation');
        $this->assertSame('the one true name', $entry->fresh()->description);
    }

    public function test_adding_an_owner_whose_name_is_taken_by_a_used_entry_is_refused(): void
    {
        $this->user();

        $holder = NetworkOwner::query()->create(['name' => 'AZIENDA0']);
        Network::query()->create(['cidr' => '10.0.0.0/8', 'net_owner_id' => $holder->net_owner_id]);

        $request = $this->request('/plugin/ipam/taxonomies/net-owner');
        $request->merge(['name' => 'AZIENDA0']);

        $response = (new TaxonomiesController)->store($request, 'net-owner');

        $this->assertSame(1, NetworkOwner::query()->where('name', 'AZIENDA0')->count());
        $this->assertStringContainsString('something still uses it', (string) $response->getSession()->get('error'));
    }
}
