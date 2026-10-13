<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Console\CheckCommand;
use Dercol1\LibrenmsIpam\Console\DuplicatesCommand;
use Dercol1\LibrenmsIpam\Console\ImportCommand;
use Dercol1\LibrenmsIpam\Console\InstallCommand;
use Dercol1\LibrenmsIpam\Hooks\Menu;
use Dercol1\LibrenmsIpam\Hooks\Page;
use Dercol1\LibrenmsIpam\Hooks\Settings;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\View\FileViewFinder;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * What the provider wires into LibreNMS.
 *
 * The hooks matter for more than tidiness: LibreNMS drops a plugin it thinks has
 * nothing to show, and the settings hook is what keeps it listed.
 */
class ProviderTest extends ProviderTestCase
{
    public function test_it_publishes_a_settings_hook(): void
    {
        // without this LibreNMS considers the plugin to have nothing to show
        $this->assertContains(SettingsHook::class, $this->publishedHooks());
        $this->assertContains(Settings::class, $this->publishedClasses());
    }

    public function test_it_publishes_a_menu_and_a_page_hook(): void
    {
        $this->assertContains(MenuEntryHook::class, $this->publishedHooks());
        $this->assertContains(SinglePageHook::class, $this->publishedHooks());
        $this->assertContains(Menu::class, $this->publishedClasses());
        $this->assertContains(Page::class, $this->publishedClasses());
    }

    public function test_the_settings_hook_is_published_before_the_enable_check(): void
    {
        // the order matters: a disabled plugin still has to appear in Plugin
        // Admin, otherwise there is no way to switch it back on
        $hooks = $this->publishedHooks();

        $this->assertSame(SettingsHook::class, $hooks[0]);
    }

    public function test_it_registers_the_commands(): void
    {
        $all = $this->app[Kernel::class]->all();

        // keyed by the name artisan will answer to, which is what the
        // documentation tells an operator to type
        $available = array_map(
            fn (object $command): string => (string) $command->getName(),
            $all
        );

        foreach ([InstallCommand::class, ImportCommand::class, DuplicatesCommand::class, CheckCommand::class] as $class) {
            $name = (new $class)->getName();

            $this->assertContains($name, $available, "lnms $name should be available");
        }
    }

    public function test_it_registers_the_view_namespace(): void
    {
        // The provider's boot() is what registers the namespace, and in this app the
        // base class registered it too, so asserting exists() would pass even if
        // the provider did nothing. Look at where the finder points the
        // namespace instead, which is only true of the provider.
        $finder = view()->getFinder();

        $this->assertInstanceOf(FileViewFinder::class, $finder);

        $hints = $finder->getHints();

        $this->assertArrayHasKey(IpamPluginProvider::PLUGIN_NAME, $hints);
        $this->assertSame(
            realpath(dirname(__DIR__) . '/resources/views'),
            realpath((string) $hints[IpamPluginProvider::PLUGIN_NAME][0])
        );
    }

    public function test_it_merges_the_default_settings(): void
    {
        // the config file was never published, so mergeConfigFrom is the only
        // reason config('ipam.*') answers at all
        $this->assertIsArray(config('ipam'));
        $this->assertTrue(config('ipam.use_vlan_scope'));
    }

    public function test_the_plugin_name_is_short_enough_for_the_database(): void
    {
        // plugins.plugin_name is varchar(60) with a unique index, and the name is
        // also the view namespace
        $this->assertLessThanOrEqual(60, strlen(IpamPluginProvider::PLUGIN_NAME));
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', IpamPluginProvider::PLUGIN_NAME);
    }

    public function test_the_base_url_points_at_the_networks_page(): void
    {
        $this->assertStringEndsWith(IpamPluginProvider::PREFIX, IpamPluginProvider::url());
    }

    public function test_the_page_hook_hands_core_a_view_that_exists(): void
    {
        // The hook's return value is what plugins.settings does
        // @include($content_view) with. Core falls back to plugins.missing when
        // the key is absent, which is how this plugin's Page button once showed
        // "this plugin has no page" while the pages worked fine from their own
        // urls. So the key has to be there, and the view has to exist.
        $data = (new Page)->handle(IpamPluginProvider::PLUGIN_NAME, []);

        $this->assertArrayHasKey('content_view', $data, 'without it core renders plugins.missing');
        $this->assertTrue(view()->exists($data['content_view']), "{$data['content_view']} does not exist");
    }

    public function test_the_page_hook_names_a_fragment_when_the_tables_are_missing(): void
    {
        // missing.blade.php extends the core layout, because the controllers
        // render it as a page of its own. The hook is rendered inside
        // plugins.settings, which already has the layout, so it has to name the
        // panel fragment instead, or one html document lands inside another.
        SchemaInstaller::uninstall();

        $data = (new Page)->handle(IpamPluginProvider::PLUGIN_NAME, []);

        $this->assertSame(
            IpamPluginProvider::PLUGIN_NAME . '::partials.missing-panel',
            $data['content_view']
        );
    }
}
