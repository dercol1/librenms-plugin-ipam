<?php

namespace Dercol1\LibrenmsIpam\Web;

use App\Models\Plugin;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Shared behaviour for the plugin pages.
 *
 * The two permissions are checked here rather than in each action. Reading and
 * changing the ipam are separate on purpose: the people who run the network day
 * to day should be able to look up an address without being able to rewrite the
 * classification of a range.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;

    protected function authorizeView(): void
    {
        abort_unless(IpamPermissions::canView(), 403, __('ipam::ipam.denied'));
    }

    protected function authorizeManage(): void
    {
        abort_unless(IpamPermissions::canManage(), 403, __('ipam::ipam.denied'));
    }

    /**
     * The settings the web ui stored for the plugin.
     *
     * @return array<string, mixed>
     */
    protected function storedSettings(?Request $request = null): array
    {
        $stored = Plugin::query()
            ->where('plugin_name', IpamPluginProvider::PLUGIN_NAME)
            ->value('settings');

        return is_array($stored) ? $stored : [];
    }
}
