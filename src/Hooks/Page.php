<?php

namespace Dercol1\LibrenmsIpam\Hooks;

use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Dercol1\LibrenmsIpam\Sync\Alignment;
use Illuminate\Support\Facades\DB;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * The page behind the plugin's own button in Plugin Admin.
 *
 * It is a directory of the five real pages rather than a page of its own,
 * because the pages answer different questions and none of them is the natural
 * landing point for everyone: the map is the reference, the collisions are the
 * worklist, the sites page is where the grouping is corrected. What an operator
 * wants before committing to one is the numbers, so that is what this shows.
 *
 * The view is a fragment: core renders it inside plugins.settings, which already
 * extends the layout, so a full page here would nest one html document inside
 * another.
 */
class Page implements SinglePageHook
{
    public function authorize(): bool
    {
        return IpamPermissions::canView();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        $missing = SchemaInstaller::missing();

        if ($missing !== []) {
            // The numbers would all be zero and the links would lead to pages
            // that say the same thing, so the operator gets the one action that
            // matters instead of a directory of nothing. The fragment, not
            // missing.blade.php: that one extends the layout, and this view is
            // included inside plugins.settings, which already has one.
            return [
                'content_view' => IpamPluginProvider::PLUGIN_NAME . '::partials.missing-panel',
                'missing' => $missing,
            ];
        }

        return [
            'content_view' => IpamPluginProvider::PLUGIN_NAME . '::landing',
            'pages' => $this->pages($settings),
            'next_step' => $this->nextStep(),
            'alignment' => Alignment::check(Settings::fromArray($settings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array{url: string, label: string, icon: string, count: ?int, tone: ?string, hint: string}>
     */
    private function pages(array $settings): array
    {
        $route = fn (string $name): string => IpamPluginProvider::routeMap()[IpamPluginProvider::PLUGIN_NAME . '.' . $name] ?? url(IpamPluginProvider::PREFIX . '/' . $name);

        // The landing numbers describe the estate in front of the reader, so
        // ignored rows stay out of them, exactly like on every other page:
        // loopback is on every device and is not 48 collisions' worth of news.
        $unclassified = (int) DB::table('ipam_addresses')
            ->where('site_id', 0)
            ->where('vlan_record_id', 0)
            ->where('ignored', false)
            ->count();

        $described = (int) DB::table('ipam_networks')
            ->where('ignored', false)
            ->where(function ($query): void {
                $query->where('site_id', '>', 0)->orWhere('net_owner_id', '>', 0);
            })
            ->count();

        return [
            [
                'url' => $route('networks'),
                'label' => __('ipam::ipam.nav.networks'),
                'icon' => 'fa-sitemap',
                'count' => (int) DB::table('ipam_networks')->count(),
                'tone' => 'label-info',
                'hint' => __('ipam::ipam.landing.blocks_hint', ['count' => $described]),
            ],
            [
                'url' => $route('vlans'),
                'label' => __('ipam::ipam.nav.vlans'),
                'icon' => 'fa-network-wired',
                'count' => (int) DB::table('ipam_vlans')->count(),
                'tone' => 'label-info',
                'hint' => __('ipam::ipam.landing.vlans_hint'),
            ],
            [
                'url' => $route('addresses'),
                'label' => __('ipam::ipam.nav.addresses'),
                'icon' => 'fa-list',
                'count' => (int) DB::table('ipam_addresses')->count(),
                'tone' => 'label-info',
                'hint' => __('ipam::ipam.landing.unclassified_hint', ['count' => $unclassified]),
            ],
            [
                'url' => $route('duplicates'),
                'label' => __('ipam::ipam.nav.duplicates'),
                'icon' => 'fa-clone',
                'count' => $this->collisionCount($settings),
                'tone' => $this->collisionCount($settings) > 0 ? 'label-danger' : 'label-success',
                'hint' => __('ipam::ipam.landing.collisions_hint'),
            ],
            [
                'url' => $route('sites'),
                'label' => __('ipam::ipam.nav.sites'),
                'icon' => 'fa-building',
                'count' => (int) DB::table('ipam_sites')->count(),
                'tone' => 'label-info',
                'hint' => __('ipam::ipam.landing.sites_hint'),
            ],
            [
                'url' => $route('taxonomies'),
                'label' => __('ipam::ipam.nav.taxonomies'),
                'icon' => 'fa-tags',
                // owners and functions together: what the dropdowns can offer
                'count' => (int) DB::table('ipam_net_owners')->count()
                    + (int) DB::table('ipam_net_functions')->count()
                    + (int) DB::table('ipam_vlan_owners')->count(),
                'tone' => 'label-info',
                'hint' => __('ipam::ipam.landing.taxonomies_hint'),
            ],
        ];
    }

    /**
     * The one thing that most needs doing, if there is one.
     *
     * @return array{text: string, tone: string}|null
     */
    private function nextStep(): ?array
    {
        $imported = (int) DB::table('ipam_addresses')->where('ignored', false)->count();

        if ($imported === 0) {
            return [
                'text' => __('ipam::ipam.landing.run_import'),
                'tone' => 'alert-warning',
            ];
        }

        // loopback and friends are unclassified by nature and are already
        // hidden; leaving them in would make the landing say there is work
        // waiting when there is none
        $unclassified = (int) DB::table('ipam_addresses')
            ->where('site_id', 0)
            ->where('vlan_record_id', 0)
            ->where('ignored', false)
            ->count();

        if ($unclassified > 0) {
            return [
                'text' => __('ipam::ipam.landing.describe_blocks', ['count' => $unclassified]),
                'tone' => 'alert-info',
            ];
        }

        return null;
    }

    /**
     * The collision count on exactly the terms the collisions page judges them.
     *
     * Reused from the detector rather than re-written here: this file once
     * carried its own copy of the scope rule, the copy drifted when the rule
     * gained the ignored filter, and the landing claimed 48 collisions on a
     * day the report said none. One rule, one place.
     *
     * @param  array<string, mixed>  $settings
     */
    private function collisionCount(array $settings): int
    {
        try {
            return Settings::fromArray($settings)->detector()->count();
        } catch (\Throwable) {
            // the tables may not be installed yet, and a directory of pages
            // must not die over a badge
            return 0;
        }
    }
}
