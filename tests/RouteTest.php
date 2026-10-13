<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Illuminate\Support\Facades\Route;

/**
 * The routes the plugin registers.
 *
 * A view builds its form actions out of route names. If a route is renamed or
 * forgotten, the page still renders and only breaks when somebody presses Save,
 * so the names are pinned here.
 *
 * Route names double as the permission expressions: every route carries
 * can:ipam.manage or it does not, and getting that wrong either exposes a save
 * button to a read only user or hides the pages from everyone.
 */
class RouteTest extends ProviderTestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}> name to [verb, writes]
     */
    private function routes(): array
    {
        return [
            'ipam.networks' => ['GET', false],
            'ipam.vlans' => ['GET', false],
            'ipam.addresses' => ['GET', false],
            'ipam.duplicates' => ['GET', false],

            'ipam.networks.save' => ['POST', true],
            'ipam.networks.ladder' => ['POST', true],
            'ipam.networks.destroy' => ['DELETE', true],
            'ipam.vlans.update' => ['POST', true],
            'ipam.addresses.update' => ['PUT', true],
            'ipam.addresses.block' => ['POST', true],
            'ipam.duplicates.annotate' => ['POST', true],
        ];
    }

    public function test_every_route_is_registered_with_the_right_verb(): void
    {
        foreach ($this->routes() as $name => [$verb, $_unused]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "route $name is not registered");
            $this->assertContains($verb, $route->methods(), "route $name should answer $verb");
        }
    }

    public function test_every_write_route_is_behind_the_manage_gate(): void
    {
        foreach ($this->routes() as $name => [$verb, $writes]) {
            $route = Route::getRoutes()->getByName($name);

            $middleware = $route->gatherMiddleware();

            if ($writes) {
                // without this a page would expose a form that saves as anyone
                $this->assertContains(
                    'can:ipam.manage',
                    $middleware,
                    "$name changes data and must sit behind ipam.manage"
                );
            } else {
                $this->assertNotContains(
                    'can:ipam.manage',
                    $middleware,
                    "$name only reads, it should not need the manage permission"
                );
            }
        }
    }

    public function test_every_route_is_behind_authentication(): void
    {
        foreach (array_keys($this->routes()) as $name) {
            $this->assertContains(
                'auth',
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                "$name must not be reachable while signed out"
            );
        }
    }

    public function test_every_route_lives_under_the_plugin_prefix(): void
    {
        foreach (array_keys($this->routes()) as $name) {
            $this->assertStringStartsWith(
                IpamPluginProvider::PREFIX,
                Route::getRoutes()->getByName($name)->uri(),
                "$name should be under " . IpamPluginProvider::PREFIX
            );
        }
    }

    public function test_the_read_routes_do_not_need_the_manage_permission(): void
    {
        // reading the map is ipam.view, not ipam.manage: an operator who may look
        // up an address should not be able to rewrite the classification
        foreach (['ipam.networks', 'ipam.vlans', 'ipam.addresses', 'ipam.duplicates'] as $name) {
            $this->assertNotContains(
                'can:ipam.manage',
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                "$name only reads"
            );
        }
    }

    public function test_the_route_map_falls_back_to_plain_urls(): void
    {
        // a view must not die because a route is missing: routes can be cached
        // from before the plugin was installed
        $map = IpamPluginProvider::routeMap();

        $this->assertNotSame([], $map);

        foreach ($map as $name => $url) {
            $this->assertIsString($url);
            $this->assertNotSame('', $url, "$name resolved to nothing");
        }
    }
}
