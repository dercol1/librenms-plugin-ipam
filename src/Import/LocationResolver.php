<?php

namespace Dercol1\LibrenmsIpam\Import;

use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Walks a LibreNMS sysLocation to the site it belongs to.
 *
 * This is where grouping happens. Devices do not report a site, they report a
 * free text sysLocation, and the same building arrives under a different string
 * from every switch in it. In this install 38 strings describe about five
 * places: "Galazzano", "Galazzano Sala B", "Galazzano, (Sala server A)" and
 * "SSIS Galazzano (Sala server A)" are one site, and so are five spellings of
 * "Piazzetta del Titano".
 *
 * So the two steps are kept apart:
 *
 *   location  is what a device said, matched exactly, never guessed at
 *   site      is the rollup, and only an operator decides it
 *
 * A sysLocation nobody has filed yet gets a site of its own, so nothing is lost
 * and every address is classifiable straight away. Filing it under a real site
 * afterwards is one action, and because the site is the scope a collision is
 * judged in, doing so is what reconciles two rooms of one building.
 *
 * Shared by both importers on purpose: an address and the vlan it sits in have
 * to end up in the same site, and two resolvers would eventually disagree.
 */
class LocationResolver
{
    /** @var array<int, int> LibreNMS location id to ipam location id */
    private array $locations = [];

    /** @var array<int, int> ipam location id to site id */
    private array $sites = [];

    /**
     * The ipam location for a LibreNMS location id, created on first sight.
     *
     * Returns 0 for a device with no location at all, which is a different thing
     * from a device whose location nobody has filed yet.
     */
    public function locationId(int $librenmsLocationId): int
    {
        if ($librenmsLocationId <= 0) {
            return 0;
        }

        if (isset($this->locations[$librenmsLocationId])) {
            return $this->locations[$librenmsLocationId];
        }

        $name = DB::table('locations')->where('id', $librenmsLocationId)->value('location');

        if ($name === null) {
            return 0;
        }

        $existing = Location::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $this->locations[$librenmsLocationId] = (int) $existing->location_id;
        }

        // A site of its own, so the data is usable before anyone has filed it,
        // and flagged as the placeholder it is: nobody chose this place, it is
        // the sysLocation with a name on it, and it is not offered where an
        // operator has to pick a real one.
        $site = Site::query()->firstOrCreate(['name' => $name], ['auto_created' => true]);

        $location = Location::query()->create([
            'name' => $name,
            'site_id' => $site->site_id,
        ]);

        return $this->locations[$librenmsLocationId] = (int) $location->location_id;
    }

    /**
     * The site a LibreNMS location rolls up to, or 0 when it is unplaced.
     */
    public function siteIdForLocation(int $librenmsLocationId): int
    {
        $locationId = $this->locationId($librenmsLocationId);

        return $locationId === 0 ? 0 : $this->siteId($locationId);
    }

    /**
     * The site an ipam location rolls up to.
     */
    public function siteId(int $locationId): int
    {
        if ($locationId <= 0) {
            return 0;
        }

        if (isset($this->sites[$locationId])) {
            return $this->sites[$locationId];
        }

        return $this->sites[$locationId] = (int) Location::query()
            ->where('location_id', $locationId)
            ->value('site_id');
    }

    /**
     * Make sure every LibreNMS location has a row, whether or not a device
     * currently sits in it.
     *
     * Called once per vlan import so the Sites page shows the whole estate,
     * including the places that are empty right now. What it saw is published
     * to the result, because a room LibreNMS has dropped is a row nobody will
     * ever refresh again and has to be reconciled on purpose.
     */
    public function syncAll(?ImportResult $result = null): int
    {
        $created = 0;

        foreach (DB::table('locations')->get(['id', 'location']) as $row) {
            // the estate still has this room, whatever else it has lost: this is
            // the evidence the reconciler uses on the ones nobody names now
            if ($result !== null) {
                $result->seenLocations[(string) $row->location] = true;
            }

            if (Location::query()->where('name', $row->location)->exists()) {
                continue;
            }

            try {
                $site = Site::query()->firstOrCreate(['name' => $row->location], ['auto_created' => true]);

                Location::query()->create([
                    'name' => $row->location,
                    'site_id' => $site->site_id,
                ]);

                $created++;
            } catch (\Throwable $e) {
                // two locations can round-trip to the same string and the name is
                // unique. Losing one row only means a few addresses stay
                // unclassified, so this is logged rather than fatal.
                Log::warning("ipam: could not file the location '{$row->location}': " . $e->getMessage());
            }
        }

        return $created;
    }

    /**
     * Drop the caches, after an operator has moved a location to another site.
     */
    public function forget(): void
    {
        $this->locations = [];
        $this->sites = [];
    }
}
