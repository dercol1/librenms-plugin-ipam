<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Sites\SiteService;
use Dercol1\LibrenmsIpam\Web\SitesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The grouping of locations into sites.
 *
 * This is the part of the plugin that is a decision rather than a report. Ten
 * sysLocation strings can name one building, and whether they do is a human
 * judgement. What the code has to get right is narrower and matters more:
 * suggesting groups without hiding any, and applying an accepted one without
 * leaving the data in two minds about which site a vlan belongs to.
 */
class SiteGroupingTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLocation('Galazzano', 'Galazzano');
        $this->seedLocation('Galazzano Sala B', 'Galazzano Sala B');
        $this->seedLocation('SSIS Galazzano (Sala server A)', 'SSIS Galazzano (Sala server A)');
        $this->seedLocation('Piazzetta del Titano', 'Piazzetta del Titano');
        $this->seedLocation('Piazzetta del Titano, Citta, 47890', 'Piazzetta del Titano, Citta, 47890');
        $this->seedLocation('Roma', 'Roma');
        // a coordinate string names no place, and must not group with anything
        $this->seedLocation('[43.93995208329548, 12.449556043363364]', '[43.93995208329548, 12.449556043363364]');
    }

    /**
     * A sysLocation, and the site named after it, which is what the import does
     * with a string nobody has filed yet.
     */
    private function seedLocation(string $name, string $siteName): Location
    {
        $site = Site::query()->firstOrCreate(['name' => $siteName]);

        return Location::query()->create(['name' => $name, 'site_id' => $site->site_id]);
    }

    public function test_locations_sharing_a_place_name_are_proposed_together(): void
    {
        $suggestions = (new SiteService)->suggest();

        $names = array_map(fn (array $s): string => $s['name'], $suggestions);

        $this->assertContains('galazzano', $names, 'the three Galazzano spellings are one proposal');
        $this->assertContains('piazzetta', $names, 'the two Piazzetta spellings are one proposal');
        $this->assertNotContains('roma', $names, 'Roma stands alone and is not proposed');
    }

    public function test_a_proposal_names_the_sites_it_would_merge(): void
    {
        $suggestions = (new SiteService)->suggest();
        $galazzano = array_values(array_filter(
            $suggestions,
            fn (array $s): bool => $s['name'] === 'galazzano'
        ))[0];

        $this->assertSame(
            ['Galazzano', 'Galazzano Sala B', 'SSIS Galazzano (Sala server A)'],
            $galazzano['sites'],
            'the proposal says which sites disappear, in a stable order'
        );
        $this->assertCount(3, $galazzano['locations']);
    }

    public function test_a_merge_moves_the_locations_and_the_data(): void
    {
        $keep = Site::query()->where('name', 'Galazzano')->value('site_id');
        $drop = Site::query()->where('name', 'Galazzano Sala B')->value('site_id');

        $vlan = Vlan::query()->create(['vlan_id' => 100, 'site_id' => $drop, 'name' => 'users']);
        $address = Address::query()->create([
            'family' => 4,
            'address' => '10.0.0.5',
            'prefixlen' => 24,
            'context_name' => '',
            'site_id' => $drop,
            'network_id' => 0,
            'device_id' => 1,
            'port_id' => 1,
        ]);

        $moved = (new SiteService)->merge($keep, $drop);

        $this->assertSame(1, $moved['ipam_locations'], 'the location moved');
        $this->assertSame(1, $moved['ipam_addresses']);

        $this->assertSame($keep, Location::query()->where('name', 'Galazzano Sala B')->value('site_id'));
        $this->assertSame($keep, $address->fresh()->site_id);
        $this->assertSame($keep, $vlan->fresh()->site_id);

        // the emptied site is gone, otherwise it shows in every dropdown
        $this->assertNull(Site::query()->find($drop));
    }

    public function test_a_merge_collapses_the_same_vlan_seen_in_two_rooms(): void
    {
        // vlan 100 exists in the keeping site and in the folded one: after the
        // merge it is one broadcast domain, not two records
        $keep = Site::query()->where('name', 'Galazzano')->value('site_id');
        $drop = Site::query()->where('name', 'Galazzano Sala B')->value('site_id');

        Vlan::query()->create(['vlan_id' => 100, 'site_id' => $keep, 'name' => 'users']);
        $loser = Vlan::query()->create(['vlan_id' => 100, 'site_id' => $drop, 'name' => 'users']);

        $address = Address::query()->create([
            'family' => 4,
            'address' => '10.0.0.5',
            'prefixlen' => 24,
            'context_name' => '',
            'site_id' => $drop,
            'vlan_record_id' => $loser->vlan_record_id,
            'network_id' => 0,
            'device_id' => 1,
            'port_id' => 1,
        ]);

        $moved = (new SiteService)->merge($keep, $drop);

        $this->assertSame(1, $moved['ipam_vlans_merged'], 'the two records became one');
        $this->assertSame(1, $moved['ipam_addresses_repointed']);

        $this->assertSame(1, Vlan::query()->where('vlan_id', 100)->where('site_id', $keep)->count());
        $this->assertSame(
            $keep,
            $address->fresh()->vlan->site_id,
            'the address follows the surviving vlan record, and lands in the merged site'
        );
    }

    public function test_an_empty_site_after_a_merge_is_offered_for_deletion(): void
    {
        $keep = Site::query()->where('name', 'Galazzano')->value('site_id');
        $drop = Site::query()->where('name', 'Galazzano Sala B')->value('site_id');

        (new SiteService)->merge($keep, $drop);

        $empty = (new SiteService)->emptySites();

        $this->assertFalse($empty->contains('site_id', $keep), 'the surviving site is not empty, it holds the location');
        $this->assertNull(Site::query()->find($drop), 'the folded site was deleted outright');
    }

    public function test_the_sites_page_renders_the_estate(): void
    {
        $this->user();

        $html = (new SitesController)->index($this->request('/plugin/ipam/sites'))->render();

        $this->assertStringContainsString('Galazzano', $html);
        $this->assertStringContainsString('Piazzetta del Titano', $html);
        // the proposal panel is what makes the page worth having
        $this->assertStringContainsString(__('ipam::ipam.sites.suggest_title'), $html);
    }

    /**
     * A signed in user with every gate allowed.
     *
     * The same helper PageRenderTest has. It is duplicated rather than hoisted
     * into UnitTestCase because the policies it binds (Device, Port) belong to
     * the pages that follow an address through to its device, and reading it
     * there says what the render test is setting up.
     */
    protected function user(): User
    {
        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;

        auth()->setUser($user);

        Gate::define(IpamPermissions::VIEW, fn ($u, mixed $target = null): bool => true);
        Gate::define(IpamPermissions::MANAGE, fn ($u, mixed $target = null): bool => true);

        return $user;
    }

    protected function request(string $uri = '/'): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => auth()->user());

        return $request;
    }
}
