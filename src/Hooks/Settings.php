<?php

namespace Dercol1\LibrenmsIpam\Hooks;

use App\Models\Plugin;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Settings as IpamSettings;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * The settings page: what gets imported, and how duplicates are judged.
 *
 * This hook is also what keeps the plugin listed in the ui, LibreNMS considers
 * a plugin with no hooks to have nothing to show and drops it.
 *
 * authorize() takes no User argument on purpose: the plugin manager injects an
 * empty App\Models\User, not the signed in one, so asking that instance what it
 * may do answers no for everybody. The Gate resolves the real user from the
 * session itself.
 */
class Settings implements SettingsHook
{
    public function authorize(): bool
    {
        return IpamPermissions::canManage();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        $plugin = IpamPluginProvider::PLUGIN_NAME;
        $model = Plugin::where('plugin_name', $plugin)->first();
        $resolved = IpamSettings::fromArray($settings);
        $missing = SchemaInstaller::missing();

        return [
            // the keys core expects, see PluginSettingsController
            'title' => trans('plugins.settings_page', ['plugin' => $plugin]),
            'plugin_name' => $plugin,
            'plugin_id' => $model?->plugin_id,
            // core validates plugin_active on every settings save, so the form
            // has to carry the value already stored
            'plugin_active' => $model === null ? 1 : (int) $model->plugin_active,
            'content_view' => "$plugin::settings",
            'settings' => $settings,

            'missing' => $missing,
            'defaults' => [
                'import_ipv4' => $resolved->importIpv4(),
                'import_ipv6' => $resolved->importIpv6(),
                'import_inactive_ports' => $resolved->importInactivePorts(),
                'site_from_device' => $resolved->siteFromDevice(),
                'use_vlan_scope' => $resolved->useVlanScope(),
                'ignore_unscoped_duplicates' => $resolved->ignoreUnscopedDuplicates(),
                'unique_per_scope' => $resolved->uniquePerScope(),
                'per_page' => $resolved->perPage(),
                // The parsed ranges, one per line, so the field shows what the
                // import will actually act on rather than the raw string as
                // saved: a dropped typo has to be visible here.
                'exclude_ranges' => $resolved->excludeRangesText(),
            ],
            'routes' => IpamPluginProvider::routeMap(),
        ];
    }
}
