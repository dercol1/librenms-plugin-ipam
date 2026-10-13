<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\IpamPluginProvider;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Base for the tests that need the plugin's provider to have booted.
 *
 * The provider asks for LibreNMS's PluginManagerInterface, which only core binds
 * it, so a stand-in stands in here. What the provider registers, routes and
 * views included, is what these tests are about.
 */
abstract class ProviderTestCase extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingPluginManager::reset();

        $this->app->bind(
            PluginManagerInterface::class,
            fn (): PluginManagerInterface => new RecordingPluginManager
        );

        $this->app->register(IpamPluginProvider::class, force: true);

        // Testbench registers a package provider after the router has already
        // been built, and the route collection's name index is only rebuilt by
        // the router when it is swapped. Without this the routes are all there
        // but Route::has() answers no for every one of them, and a view that
        // guards its links with has() silently falls back to plain urls.
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /**
     * The hook interfaces the provider published.
     *
     * @return list<string>
     */
    protected function publishedHooks(): array
    {
        return array_column(RecordingPluginManager::$published, 'hook');
    }

    /**
     * The hook implementation classes the provider published.
     *
     * @return list<class-string>
     */
    protected function publishedClasses(): array
    {
        return array_column(RecordingPluginManager::$published, 'class');
    }
}
