<?php

namespace Dercol1\LibrenmsIpam\Import;

use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Network;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanName;
use Dercol1\LibrenmsIpam\Sites\SiteService;
use Illuminate\Support\Facades\DB;

/**
 * What to do about the rows LibreNMS no longer reports.
 *
 * The import creates a row out of something a device reported, so when the
 * device stops reporting it the row is an orphan — but what an orphan means
 * depends on whether anything still hangs off it, and that is the whole rule:
 *
 *   nothing hangs off it   the row was scaffolding. A /24 that existed because
 *                          one address was in it, and that address is gone: the
 *                          range is not part of the estate any more, so the row
 *                          goes with it.
 *
 *   something hangs off it the row is a description now. Addresses still sit in
 *                          the range, or narrower ranges still hang below it,
 *                          and deleting it would take the map with it. So it is
 *                          handed over: auto_created becomes false, the import
 *                          stops treating it as its own, and from that moment it
 *                          behaves exactly like one an operator typed in — it
 *                          can be hidden or deleted, by a person, on purpose.
 *
 * Both halves only ever touch rows the import owns. Anything an operator wrote,
 * and anything they have since edited, is left alone whatever the poll says.
 *
 * And neither half runs on thin evidence: an import that read no address at all,
 * or no vlan at all, is not proof that the estate emptied, it is what a
 * LibreNMS with nothing discovered yet looks like. Deleting the map on that
 * would be the worst thing this class could do, so the run reports the doubt
 * instead of acting on it.
 */
class Reconciler
{
    public function run(ImportResult $result): void
    {
        $this->blocks($result);
        $this->vlans($result);
        $this->locations($result);
    }

    /**
     * Ranges the devices no longer have an address in.
     */
    private function blocks(ImportResult $result): void
    {
        if ($result->seen === 0) {
            $result->warnings[] = 'no address was read, so nothing was taken away or handed over: '
                . 'an import that sees nothing is not evidence that the ranges are gone';

            return;
        }

        $ids = Network::query()
            ->where('auto_created', true)
            ->whereNotIn('network_id', array_keys($result->seenNetworks))
            ->pluck('network_id')
            ->all();

        foreach ($ids as $id) {
            $block = Network::query()->find($id);

            if ($block === null) {
                continue;
            }

            // a domain is an operator's statement about layer 2, and no poll
            // will ever confirm it. It is not the import's to take away.
            if ((int) $block->stretch_id > 0) {
                continue;
            }

            $inUse = Address::query()->where('network_id', $block->network_id)->exists()
                || Network::query()->where('parent_id', $block->network_id)->exists();

            if ($inUse) {
                $block->auto_created = false;
                $block->save();
                $result->handedOver++;

                continue;
            }

            $block->delete();
            $result->networksRemoved++;
        }
    }

    /**
     * Rooms LibreNMS no longer knows.
     *
     * A room is the sysLocation string itself, matched exactly, so a row for a
     * string the devices stopped reporting is a row nothing will ever refresh
     * again. It goes when nothing hangs off it — no occurrence reported from it,
     * no vlan seen in it. When the site that held it is a placeholder and has
     * nothing left either, it goes with the room: the site existed to give that
     * one string somewhere to land.
     *
     * A room a vlan was *seen* in keeps its row, even with no address in it,
     * because a vlan that spans a building is recorded against the rooms it was
     * reported in and that is evidence of something real.
     */
    private function locations(ImportResult $result): void
    {
        if ($result->seenLocations === []) {
            $result->warnings[] = 'no location was read, so no room was taken away: '
                . 'an import that sees nothing is not evidence that the estate emptied';

            return;
        }

        $stale = Location::query()
            ->whereNotIn('name', array_keys($result->seenLocations))
            ->pluck('location_id')
            ->all();

        foreach ($stale as $locationId) {
            $inUse = Address::query()->where('location_id', $locationId)->exists()
                || DB::table('ipam_vlan_locations')->where('location_id', $locationId)->exists();

            if ($inUse) {
                continue;
            }

            $siteId = (int) Location::query()->whereKey($locationId)->value('site_id');

            Location::query()->whereKey($locationId)->delete();
            $result->locationsRemoved++;

            if ($siteId === 0) {
                continue;
            }

            $site = Site::query()->find($siteId);

            // only a placeholder goes: a site an operator built is theirs to
            // keep even when it is empty right now, which the empty-sites panel
            // on the Sites page is there to offer
            if ($site !== null && $site->auto_created && (new SiteService)->isEmpty($site)) {
                $site->delete();
                $result->sitesRemoved++;
            }
        }
    }

    /**
     * Vlans the devices no longer report.
     */
    private function vlans(ImportResult $result): void
    {
        if (! $result->vlanImportRan) {
            return;
        }

        if ($result->seenVlanRecords === []) {
            $result->warnings[] = 'no vlan was read, so no vlan was taken away or handed over: '
                . 'an import that sees nothing is not evidence that they are gone';

            return;
        }

        $ids = Vlan::query()
            ->where('auto_created', true)
            ->whereNotIn('vlan_record_id', array_keys($result->seenVlanRecords))
            ->pluck('vlan_record_id')
            ->all();

        foreach ($ids as $id) {
            $vlan = Vlan::query()->find($id);

            if ($vlan === null || (int) $vlan->vlan_group_id > 0) {
                continue;
            }

            if (Address::query()->where('vlan_record_id', $id)->exists()) {
                $vlan->auto_created = false;
                $vlan->save();
                $result->handedOver++;

                continue;
            }

            // the names and the rooms on it are all about devices that no
            // longer report this vlan, so they leave with it
            VlanName::query()->where('vlan_record_id', $id)->delete();
            DB::table('ipam_vlan_locations')->where('vlan_record_id', $id)->delete();
            $vlan->delete();
            $result->vlansRemoved++;
        }
    }
}
