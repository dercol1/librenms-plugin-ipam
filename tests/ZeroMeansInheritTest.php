<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\User;
use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanOwner;
use Dercol1\LibrenmsIpam\Web\AddressesController;
use Dercol1\LibrenmsIpam\Web\NetworksController;
use Dercol1\LibrenmsIpam\Web\SitesController;
use Dercol1\LibrenmsIpam\Web\TaxonomiesController;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

/**
 * Zero as "inherit", and the refusal an operator can actually see.
 *
 * Every "--" option in the plugin's forms submits 0, because 0 is the value
 * that means "inherit from the block" or "none". A plain exists: rule
 * refuses it — no row carries key 0 — so editing one field and leaving the
 * rest alone was refused wholesale, and since the plugin rendered neither
 * its flash messages nor the validation errors, the refusal was invisible:
 * the page came back exactly as it was, with nothing said. That was the
 * network map editing an auto-created block and saving nothing.
 */
class ZeroMeansInheritTest extends UnitTestCase
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
     * The form as the browser sends it: one field set, the others left on
     * their "--" option, which submits 0.
     */
    public function test_editing_an_auto_block_setting_only_the_site_saves(): void
    {
        $this->user();

        $site = Site::query()->create(['name' => 'Galazzano']);

        // the block as the import leaves it: auto created, nothing described
        $block = Network::query()->create([
            'cidr' => '172.31.240.0/20',
            'auto_created' => true,
        ]);

        $request = $this->request('/plugin/ipam/networks');
        $request->merge([
            'network_id' => (string) $block->network_id,
            'site_id' => (string) $site->site_id,
            // the three fields left alone, exactly as the "--" options send them
            'net_owner_id' => '0',
            'net_function_id' => '0',
            'vlan_id' => '',
            'description' => '',
            'ignored' => false,
        ]);

        (new NetworksController)->save($request);

        $fresh = $block->fresh();

        $this->assertSame($site->site_id, $fresh->site_id, 'the site the operator set was received');
        $this->assertSame(0, (int) $fresh->net_owner_id, 'the fields left on inherit stay inherit');
    }

    public function test_a_made_up_site_id_is_still_refused(): void
    {
        $this->user();

        $block = Network::query()->create(['cidr' => '10.0.0.0/8']);

        $request = $this->request('/plugin/ipam/networks');
        $request->merge([
            'network_id' => (string) $block->network_id,
            'site_id' => '424242',
            'net_owner_id' => '0',
            'net_function_id' => '0',
            'vlan_id' => '',
            'description' => '',
            'ignored' => false,
        ]);

        $this->expectException(ValidationException::class);

        (new NetworksController)->save($request);
    }

    public function test_adding_an_address_with_only_the_address_set_still_works(): void
    {
        $this->user();

        // the add form with everything on "--": inherit from the block
        $request = $this->request('/plugin/ipam/addresses');
        $request->merge([
            'address' => '10.99.0.50',
            'site_id' => '0',
            'vlan_record_id' => '0',
            'net_owner_id' => '0',
            'net_function_id' => '0',
            'description' => '',
        ]);

        (new AddressesController)->store($request);

        $record = Address::query()->where('address', '10.99.0.50')->first();

        $this->assertNotNull($record, 'a row with everything inherited was created, not refused');
        $this->assertSame(0, (int) $record->site_id);
    }

    public function test_a_vlan_without_a_site_keeps_no_site(): void
    {
        $this->user();

        // the importer creates vlans at site 0 when no device location says
        // better; editing one must not quietly adopt the first site in the
        // dropdown
        Site::query()->create(['name' => 'Alpha']);
        $vlan = Vlan::query()->create(['vlan_id' => 100, 'site_id' => 0]);

        $request = $this->request('/plugin/ipam/vlans/' . $vlan->vlan_record_id);
        $request->merge([
            'name' => $vlan->name,
            'description' => 'edited',
            'site_id' => '0',
            'vlan_owner_id' => '0',
            'ignored' => false,
        ]);

        (new VlansController)->update($request, (int) $vlan->vlan_record_id);

        $this->assertSame(0, (int) $vlan->fresh()->site_id, 'site 0 is a choice, not something to be corrected');
        $this->assertSame('edited', $vlan->fresh()->description);
    }

    public function test_editing_a_vlan_setting_only_the_owner_saves(): void
    {
        $this->user();

        $site = Site::query()->create(['name' => 'Galazzano']);
        $owner = VlanOwner::query()->create(['name' => 'Wireless']);
        $vlan = Vlan::query()->create(['vlan_id' => 200, 'site_id' => $site->site_id]);

        $request = $this->request('/plugin/ipam/vlans/' . $vlan->vlan_record_id);
        $request->merge([
            'name' => 'users',
            'description' => null,
            'site_id' => (string) $site->site_id,
            'vlan_owner_id' => (string) $owner->vlan_owner_id,
            'ignored' => false,
        ]);

        (new VlansController)->update($request, (int) $vlan->vlan_record_id);

        $this->assertSame($owner->vlan_owner_id, (int) $vlan->fresh()->vlan_owner_id);
    }

    /**
     * The half of the fix that makes the other half matter: what the last
     * action answered has to be on the page, or a refused form is
     * indistinguishable from a working one.
     */
    public function test_the_pages_show_what_the_last_action_answered(): void
    {
        $this->user();

        // an action that refuses: renaming a site onto a live name
        $holder = Site::query()->create(['name' => 'Galazzano']);
        Location::query()->create(['name' => 'a room', 'site_id' => $holder->site_id]);
        $other = Site::query()->create(['name' => 'Elsewhere']);

        $request = $this->request('/plugin/ipam/sites/' . $other->site_id);
        $request->merge(['name' => 'Galazzano', 'description' => null, 'address' => null]);

        (new SitesController)->update($request, (int) $other->site_id);

        // the next page render reads the same session the redirect wrote to
        $html = (new SitesController)->index($this->request('/plugin/ipam/sites'))->render();

        $this->assertStringContainsString('already exists and it holds data', $html, 'the refusal is on the page');
        $this->assertStringContainsString('alert alert-danger', $html);
    }

    public function test_a_successful_action_is_shown_too(): void
    {
        $this->user();

        $request = $this->request('/plugin/ipam/taxonomies/net-owner');
        $request->merge(['name' => 'Network team', 'description' => null]);

        (new TaxonomiesController)->store($request, 'net-owner');

        $html = (new TaxonomiesController)->index($this->request('/plugin/ipam/taxonomies'))->render();

        $this->assertStringContainsString('alert alert-success', $html, 'the confirmation is on the page');
    }

    public function test_a_validation_refusal_lists_what_it_refused(): void
    {
        $this->user();

        $request = $this->request('/plugin/ipam/taxonomies/net-owner');
        $request->merge(['name' => '', 'description' => null]);

        try {
            (new TaxonomiesController)->store($request, 'net-owner');
            $this->fail('the validation should have refused an empty name');
        } catch (ValidationException $exception) {
            // the framework's own flow flashes this bag to the session and the
            // ShareErrorsFromSession middleware turns it into $errors on the
            // next request. This render has no middleware, so the bag is
            // shared the way that middleware would.
            $bag = new MessageBag($exception->errors());
            $viewBag = new ViewErrorBag;
            $viewBag->put('default', $bag);

            $this->app['view']->share('errors', $viewBag);

            $html = (new TaxonomiesController)->index($this->request('/plugin/ipam/taxonomies'))->render();

            $this->assertStringContainsString('The form was refused:', $html, 'the page names what happened');
            $this->assertStringContainsString('name field is required', $html, 'and what it refused it for');
        }
    }
}
