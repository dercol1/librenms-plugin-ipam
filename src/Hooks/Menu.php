<?php

namespace Dercol1\LibrenmsIpam\Hooks;

use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Settings;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * The plugin's entry in the Plugins menu.
 *
 * The return value is the [view, data] tuple LibreNMS expects: the menu layout
 * includes the view with that data, so the view itself renders the <a> and the
 * label and url arrive as variables.
 *
 * authorize() takes no User argument on purpose. The plugin manager injects an
 * empty App\Models\User here, not the signed in one, so asking that instance
 * what it may do answers no for everybody. The Gate resolves the real user from
 * the session itself.
 */
class Menu implements MenuEntryHook
{
    public function authorize(): bool
    {
        return IpamPermissions::canView();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName, array $settings): array
    {
        return [
            IpamPluginProvider::PLUGIN_NAME . '::menu',
            [
                'label' => __('ipam::ipam.menu'),
                'url' => IpamPluginProvider::url(),
                // a count in the menu saves opening the page to find out
                'collisions' => IpamPermissions::canView() ? $this->collisionCount($settings) : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function collisionCount(array $settings): ?int
    {
        try {
            return Settings::fromArray($settings)->detector()->count();
        } catch (\Throwable) {
            // the tables may not be installed yet, a menu item must not take
            // the whole page down over a badge
            return null;
        }
    }
}
