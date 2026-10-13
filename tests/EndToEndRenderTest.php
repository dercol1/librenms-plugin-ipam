<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The pages through the router, with the provider booted.
 *
 * PageRenderTest calls the controllers directly, which misses the route
 * middleware. These go through the router, so the auth and the manage gate are
 * exercised the way a request would.
 */
class EndToEndRenderTest extends ProviderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // wrapped so a throwing page names its cause in the failure message
        // instead of answering 500 and saying nothing
        $this->app->instance(
            ExceptionHandler::class,
            new RecordingExceptionHandler($this->app[ExceptionHandler::class]),
        );

        // The auth middleware redirects to the login route by name. This app
        // does not load LibreNMS's routes/web.php, so the name has to exist for
        // the signed out case to redirect instead of throwing a RouteNotFound,
        // which would arrive as a 500 and hide what the test is about.
        Route::get('/login', fn () => 'login')->name('login');

        // one user, allowed everything, so the test is about routing and
        // rendering rather than about permission setup, which PageRenderTest
        // and IpamPermissions cover separately
        Gate::define(IpamPermissions::VIEW, fn ($u = null, $t = null): bool => true);
        Gate::define(IpamPermissions::MANAGE, fn ($u = null, $t = null): bool => true);
        Gate::policy(Device::class, AlwaysAllowsPolicy::class);
        Gate::policy(Port::class, AlwaysAllowsPolicy::class);

        $user = new User(['username' => 'tester', 'realname' => 'tester']);
        $user->user_id = 1;
        auth()->setUser($user);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /**
     * @return list<array{0: string}>
     */
    public static function pageProvider(): array
    {
        return [
            ['networks'],
            ['vlans'],
            ['addresses'],
            ['duplicates'],
            ['sites'],
            ['taxonomies'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_a_page_answers_for_a_signed_in_user(string $page): void
    {
        // built into a full http kernel call, so middleware runs
        $kernel = $this->app[Kernel::class];
        $request = Request::create("/plugin/ipam/$page", 'GET');

        $response = $kernel->handle($request);

        if ($response->getStatusCode() !== 200) {
            // the exception handler swallows the cause and answers 500; the
            // test's message alone would say "it failed" and not why
            $exception = $this->app[ExceptionHandler::class];
            $this->fail("$page answered {$response->getStatusCode()}: " . $this->lastExceptionMessage());
        }

        $this->assertSame(200, $response->getStatusCode(), "$page should answer 200");
    }

    /**
     * The message of the last exception the handler saw, for a failure message
     * that names the cause instead of hiding behind the status code.
     */
    private function lastExceptionMessage(): string
    {
        $reporter = $this->app[ExceptionHandler::class];

        return match (true) {
            $reporter instanceof RecordingExceptionHandler => $reporter->lastMessage(),
            default => 'unknown',
        };
    }

    public function test_a_page_is_refused_when_signed_out(): void
    {
        // The whole point of the auth middleware. The exception handler is left
        // as the app's own here, because an unauthenticated request is expected
        // to be redirected and a 500 would mean something else broke on the way.
        $kernel = $this->app[Kernel::class];

        auth()->forgetUser();
        $this->app['session']->flush();

        $response = $kernel->handle(Request::create('/plugin/ipam/networks', 'GET'));

        $this->assertContains(
            $response->getStatusCode(),
            [302, 401],
            'signed out must not reach the page'
        );
    }

    public function test_a_write_is_refused_without_the_manage_permission(): void
    {
        Gate::define(IpamPermissions::MANAGE, fn ($u = null, $t = null): bool => false);

        $kernel = $this->app[Kernel::class];

        $response = $kernel->handle(
            Request::create('/plugin/ipam/networks', 'POST', ['cidr' => '10.0.0.0/8'])
        );

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'a read only user must not be able to describe a block'
        );
    }

    public function test_the_menu_entry_points_at_the_network_map(): void
    {
        $view = view(IpamPluginProvider::PLUGIN_NAME . '::menu', [
            'label' => 'IPAM',
            'url' => IpamPluginProvider::url(),
            'collisions' => 3,
        ])->render();

        $this->assertStringContainsString(IpamPluginProvider::PREFIX, $view);
        $this->assertStringContainsString('badge', $view, 'the collision count is shown');
    }

    public function test_the_menu_entry_omits_the_count_when_it_is_unknown(): void
    {
        // null means the tables are not installed, and a "0" badge there would
        // claim there are no duplicates when really nothing has been checked
        $view = view(IpamPluginProvider::PLUGIN_NAME . '::menu', [
            'label' => 'IPAM',
            'url' => IpamPluginProvider::url(),
            'collisions' => null,
        ])->render();

        $this->assertStringNotContainsString('badge', $view);
    }
}
