<?php

namespace Dercol1\LibrenmsIpam;

use Dercol1\LibrenmsIpam\Console\CheckCommand;
use Dercol1\LibrenmsIpam\Console\DuplicatesCommand;
use Dercol1\LibrenmsIpam\Console\FreeCommand;
use Dercol1\LibrenmsIpam\Console\ImportCommand;
use Dercol1\LibrenmsIpam\Console\InstallCommand;
use Dercol1\LibrenmsIpam\Console\SyncCommand;
use Dercol1\LibrenmsIpam\Console\UninstallCommand;
use Dercol1\LibrenmsIpam\Hooks\Menu;
use Dercol1\LibrenmsIpam\Hooks\Page;
use Dercol1\LibrenmsIpam\Hooks\Settings;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Web\AddressesController;
use Dercol1\LibrenmsIpam\Web\DuplicatesController;
use Dercol1\LibrenmsIpam\Web\NetworksController;
use Dercol1\LibrenmsIpam\Web\SitesController;
use Dercol1\LibrenmsIpam\Web\TaxonomiesController;
use Dercol1\LibrenmsIpam\Web\VlansController;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Wires the plugin into LibreNMS.
 *
 * Six pages rather than one, because they answer different questions and are
 * looked at by different people: the map is the reference, the addresses are
 * the lookup, the collisions are the worklist, the sites page is where the
 * grouping the rest depends on is corrected, and the taxonomies page is where
 * the pick lists the classifications draw from are written. Each has its own
 * url so a link can be shared and bookmarked.
 */
class IpamPluginProvider extends ServiceProvider
{
    /** Keep in sync with the plugin name in the plugins table and the view namespace. */
    public const PLUGIN_NAME = 'ipam';

    /** The url prefix of every route this plugin owns. */
    public const PREFIX = 'plugin/ipam';

    public function register(): void
    {
        // strings live in the plugin, the core lang folder is not ours to edit
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', self::PLUGIN_NAME);

        // defaults must exist even when config/ipam.php was never published
        $this->mergeConfigFrom(__DIR__ . '/../config/ipam.php', self::PLUGIN_NAME);
    }

    public function boot(PluginManagerInterface $pluginManager): void
    {
        // A settings hook keeps the plugin listed in the ui. Without any hook
        // LibreNMS considers the plugin to have nothing to show and may drop it.
        $pluginManager->publishHook(self::PLUGIN_NAME, SettingsHook::class, Settings::class);

        // The commands go in before the enabled check, for the same reason the
        // settings hook does. A disabled plugin has to stay operable from the
        // command line, otherwise ipam:uninstall --keep-data disables the plugin
        // and the next ipam:uninstall has no command to run, leaving an operator
        // stuck with tables they cannot remove from here. Every one of them
        // checks for its own tables and says so rather than failing.
        $this->commands([
            InstallCommand::class,
            UninstallCommand::class,
            ImportCommand::class,
            DuplicatesCommand::class,
            CheckCommand::class,
            SyncCommand::class,
            FreeCommand::class,
        ]);

        if (! $pluginManager->pluginEnabled(self::PLUGIN_NAME)) {
            return; // do nothing else while the plugin is disabled
        }

        $this->loadViewsFrom(__DIR__ . '/../resources/views', self::PLUGIN_NAME);

        $this->publishes([
            __DIR__ . '/../config/ipam.php' => config_path('ipam.php'),
        ], 'config');

        $pluginManager->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, Menu::class);
        $pluginManager->publishHook(self::PLUGIN_NAME, SinglePageHook::class, Page::class);

        $this->registerRoutes();

        IpamPermissions::register();

        AboutCommand::add('ipam plugin', fn (): array => [
            'Version' => self::version(),
            'Plugin pages' => self::url(),
        ]);
    }

    /**
     * The plugin's own pages.
     *
     * Registered here rather than in routes/web.php, which is core. Each one is
     * behind the matching gate: reading the map is ipam.view, changing a
     * classification is ipam.manage.
     *
     * Nothing is registered when the routes are cached: the cached collection
     * already holds them (it was built with this provider booted) and adding to
     * a compiled collection throws. Re-run route:cache after an update, which
     * daily.sh does anyway, since it starts with optimize:clear.
     */
    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'auth'])
            ->prefix(self::PREFIX)
            ->name(self::PLUGIN_NAME . '.')
            ->group(function (): void {
                Route::get('networks', [NetworksController::class, 'index'])->name('networks');
                Route::get('vlans', [VlansController::class, 'index'])->name('vlans');
                Route::get('addresses', [AddressesController::class, 'index'])->name('addresses');
                Route::get('duplicates', [DuplicatesController::class, 'index'])->name('duplicates');
                Route::get('sites', [SitesController::class, 'index'])->name('sites');
                Route::get('taxonomies', [TaxonomiesController::class, 'index'])->name('taxonomies');

                // everything that writes is behind ipam.manage
                Route::middleware(['can:' . IpamPermissions::MANAGE])->group(function (): void {
                    Route::post('networks', [NetworksController::class, 'save'])->name('networks.save');
                    Route::post('networks/ladder', [NetworksController::class, 'createWithParents'])->name('networks.ladder');
                    Route::delete('networks/{networkId}', [NetworksController::class, 'destroy'])->name('networks.destroy');
                    Route::post('networks/{networkId}/stretch', [NetworksController::class, 'stretch'])->name('networks.stretch');
                    Route::post('networks/{networkId}/unstretch', [NetworksController::class, 'unstretch'])->name('networks.unstretch');
                    Route::post('networks/{networkId}/stretch/describe', [NetworksController::class, 'describeStretch'])->name('networks.stretch.describe');

                    Route::post('vlans/{vlanRecordId}', [VlansController::class, 'update'])->name('vlans.update');
                    Route::delete('vlans/{vlanRecordId}', [VlansController::class, 'destroy'])->name('vlans.destroy');
                    Route::post('vlans/{vlanRecordId}/room', [VlansController::class, 'room'])->name('vlans.room');

                    Route::put('addresses/{addressId}', [AddressesController::class, 'update'])->name('addresses.update');
                    Route::delete('addresses/{addressId}', [AddressesController::class, 'destroy'])->name('addresses.destroy');
                    Route::post('addresses', [AddressesController::class, 'store'])->name('addresses.store');
                    Route::post('addresses/block', [AddressesController::class, 'applyToBlock'])->name('addresses.block');
                    Route::post('addresses/bulk', [AddressesController::class, 'bulk'])->name('addresses.bulk');

                    Route::post('vlans', [VlansController::class, 'store'])->name('vlans.store');
                    Route::post('vlans/{vlanRecordId}/link', [VlansController::class, 'link'])->name('vlans.link');
                    Route::post('vlans/{vlanRecordId}/unlink', [VlansController::class, 'unlink'])->name('vlans.unlink');
                    Route::post('vlans/{vlanRecordId}/describe', [VlansController::class, 'describe'])->name('vlans.describe');

                    Route::post('duplicates/annotate', [DuplicatesController::class, 'annotate'])->name('duplicates.annotate');

                    Route::post('sites', [SitesController::class, 'store'])->name('sites.store');
                    Route::put('sites/{siteId}', [SitesController::class, 'update'])->name('sites.update');
                    Route::delete('sites/{siteId}', [SitesController::class, 'destroy'])->name('sites.destroy');
                    Route::post('sites/move-location', [SitesController::class, 'moveLocation'])->name('sites.move-location');
                    Route::post('sites/suggestion', [SitesController::class, 'acceptSuggestion'])->name('sites.suggestion');

                    Route::post('taxonomies/{taxonomy}', [TaxonomiesController::class, 'store'])->name('taxonomies.store');
                    Route::put('taxonomies/{taxonomy}/{id}', [TaxonomiesController::class, 'update'])->name('taxonomies.update');
                    Route::delete('taxonomies/{taxonomy}/{id}', [TaxonomiesController::class, 'destroy'])->name('taxonomies.destroy');
                });
            });
    }

    /**
     * The base url of the plugin pages.
     */
    public static function url(): string
    {
        return url(self::PREFIX);
    }

    /**
     * Urls of the routes that take no parameters, keyed by route name.
     *
     * Routes with a path parameter are left out on purpose: a name in this map
     * has to be callable with no arguments, and `ipam.vlans.update` needs a vlan
     * id, so calling it here throws.
     *
     * @return array<string, string>
     */
    public static function routeMap(): array
    {
        $names = ['networks', 'vlans', 'addresses', 'duplicates', 'sites', 'taxonomies'];

        $map = [];

        foreach ($names as $name) {
            $key = self::PLUGIN_NAME . '.' . $name;

            $map[$key] = Route::has($key)
                ? route($key)
                // a view must not die because a route is missing: routes can be
                // cached from before the plugin was installed
                : url(self::PREFIX . '/' . $name);
        }

        return $map;
    }

    public static function version(): string
    {
        $composer = __DIR__ . '/../composer.json';

        if (! is_readable($composer)) {
            return 'unknown';
        }

        $contents = json_decode((string) file_get_contents($composer), true);

        return is_array($contents) ? ($contents['name'] ?? 'unknown') : 'unknown';
    }
}
