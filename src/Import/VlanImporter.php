<?php

namespace Dercol1\LibrenmsIpam\Import;

use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanName;
use Illuminate\Support\Facades\DB;

/**
 * Builds the vlan side of the ipam from LibreNMS's vlans table.
 *
 * Three things make this more than a copy.
 *
 * A site. vlans has no site column, the only way from a vlan to a place is
 * vlans.device_id to devices.location_id, and then through LocationResolver to
 * the site that sysLocation rolls up to. Since 141 of 220 vlan ids appear at more
 * than one site, reading that literally produces one ipam vlan per (id, domain,
 * site), and that is right: vlan 100 at the head office and vlan 100 at a branch
 * really are different broadcast domains.
 *
 * Rooms. A site holds several sysLocation strings, one per room, so a vlan that
 * spans a building is seen in more than one of them. It stays a single record
 * and the rooms go into ipam_vlan_locations, which is what keeps two rooms of one
 * site from looking like two separate vlans.
 *
 * Names. The devices report vlan_name through
 * Q-BRIDGE-MIB::dot1qVlanStaticName and LibreNMS stores it in a varchar(64)
 * that the 32 character snmp limit has already filled, 40 rows in this install
 * sit at exactly 32. So vlan_name seeds the description and the real text lives
 * in ipam_vlans.description, which nothing truncates. Every reported name is
 * also kept per device in ipam_vlan_names, so two switches calling one vlan
 * different things is visible instead of being averaged away.
 */
class VlanImporter
{
    /**
     * Shared with the address importer so an address and the vlan it sits in
     * always end up in the same site.
     */
    private LocationResolver $resolver;

    public function __construct(
        private readonly bool $siteFromDevice = true,
        ?LocationResolver $resolver = null,
    ) {
        $this->resolver = $resolver ?? new LocationResolver;
    }

    public function import(ImportResult $result): void
    {
        // the reconciler only touches vlans when this half of the import ran:
        // reconciling against a run that never looked at the vlan table would be
        // deciding on evidence nobody gathered
        $result->vlanImportRan = true;

        $vlans = DB::table('vlans as v')
            ->join('devices as d', 'd.device_id', '=', 'v.device_id')
            ->leftJoin('locations as l', 'l.id', '=', 'd.location_id')
            ->select([
                // vlan_vlan is the 802.1q id, the thing an operator recognises.
                // v.vlan_id is only the primary key of the vlans table, which is
                // unique per device and means nothing to anybody.
                'v.vlan_vlan as vlan_number',
                'v.vlan_domain',
                'v.vlan_name',
                'v.device_id',
                'd.location_id',
                'l.location as location_name',
            ])
            // A vlan with no id is not a vlan, LibreNMS leaves the column
            // nullable and a discovery can produce a zero.
            ->whereNotNull('v.vlan_vlan')
            ->where('v.vlan_vlan', '>', 0)
            ->orderBy('v.vlan_vlan')
            ->orderBy('v.vlan_domain')
            ->orderBy('v.device_id')
            ->get();

        $this->resolver->syncAll($result);

        $seen = [];
        $namesByVlan = [];
        $locationsByVlan = [];

        foreach ($vlans as $row) {
            $siteId = 0;
            $locationId = 0;

            if ($row->location_id !== null) {
                $locationId = $this->resolver->locationId((int) $row->location_id);

                if ($this->siteFromDevice) {
                    $siteId = $this->resolver->siteId($locationId);
                }
            }

            $key = $row->vlan_number . '-' . ($row->vlan_domain ?: 1) . '-' . $siteId;
            $now = now();

            if (! isset($seen[$key])) {
                $record = $this->upsertVlan((int) $row->vlan_number, (int) ($row->vlan_domain ?: 1), $siteId, $row->vlan_name, $now, $result);
                $seen[$key] = true;
                $result->vlansCreated[(int) $row->vlan_number] = ($result->vlansCreated[(int) $row->vlan_number] ?? 0) + 1;

                // the estate still has this one, whichever way it already existed
                $result->seenVlanRecords[(int) $record->vlan_record_id] = true;
            }

            // A vlan spans a site, so the same one is seen in several rooms and
            // stays one record. Which rooms is what this records.
            if ($locationId !== 0) {
                $locationsByVlan[$key . '-' . $locationId] = [
                    'vlan_id' => (int) $row->vlan_number,
                    'domain' => (int) ($row->vlan_domain ?: 1),
                    'site_id' => $siteId,
                    'location_id' => $locationId,
                ];
            }

            // Names are per device: one row per (vlan, device) whatever the site.
            $namesByVlan[$key . '-' . $row->device_id] = [
                'vlan_id' => (int) $row->vlan_number,
                'domain' => (int) ($row->vlan_domain ?: 1),
                'site_id' => $siteId,
                'device_id' => (int) $row->device_id,
                'name' => $row->vlan_name,
                'now' => $now,
            ];
        }

        $this->storeLocations($locationsByVlan);
        $this->storeNames($namesByVlan);

        $this->reportNameConflicts($result);
    }

    /**
     * Record which rooms of the site a vlan was seen in.
     *
     * @param  array<string, array{vlan_id: int, domain: int, site_id: int, location_id: int}>  $rows
     */
    private function storeLocations(array $rows): void
    {
        foreach ($rows as $row) {
            $record = Vlan::query()
                ->where('vlan_id', $row['vlan_id'])
                ->where('vlan_domain', $row['domain'])
                ->where('site_id', $row['site_id'])
                ->first();

            if ($record === null) {
                continue;
            }

            DB::table('ipam_vlan_locations')->updateOrInsert(
                ['vlan_record_id' => $record->vlan_record_id, 'location_id' => $row['location_id']],
                []
            );
        }
    }

    /**
     * Create the ipam vlan, or leave an existing one alone.
     *
     * Only auto_created records are touched. Once an operator has filled in a
     * description or an owner, the import must not walk over it, and that is
     * exactly the case auto_created exists for.
     *
     * Returns the record either way: the caller has to say it saw it, and that
     * is what keeps the reconciler from treating a vlan a device still reports
     * as one the estate has lost.
     */
    private function upsertVlan(int $vlanId, int $domain, int $siteId, ?string $name, mixed $now, ImportResult $result): Vlan
    {
        $existing = Vlan::query()
            ->where('vlan_id', $vlanId)
            ->where('vlan_domain', $domain)
            ->where('site_id', $siteId)
            ->first();

        if ($existing !== null) {
            // Reported again by a device: the record is the import's again.
            // Which is what lets the device fill a name we never got, and what
            // hands the row back to the reconciler's reckoning later. Nothing
            // the operator typed is touched here — description and owner are
            // never written by the import, and what they wrote is what protects
            // the row from being deleted as residue.
            $wasTaken = ! $existing->auto_created;
            $existing->auto_created = true;

            // Fill a name we never got, but never overwrite a real description.
            $named = empty($existing->name) && ! empty($name);

            if ($named) {
                $existing->name = $name;
            }

            if ($wasTaken || $named) {
                $existing->save();
            }

            if ($wasTaken) {
                $result->handedBack++;
            }

            return $existing;
        }

        return Vlan::query()->create([
            'vlan_id' => $vlanId,
            'vlan_domain' => $domain,
            'site_id' => $siteId,
            'vlan_owner_id' => 0,
            // Seed only. description stays null for a human to write.
            'name' => $name,
            'description' => null,
            'auto_created' => true,
        ]);
    }

    /**
     * Record every name each device reported, so disagreements stay visible.
     *
     * @param  array<string, array{vlan_id: int, domain: int, site_id: int, device_id: int, name: ?string, now: mixed}>  $rows
     */
    private function storeNames(array $rows): void
    {
        $byRecord = [];

        foreach ($rows as $row) {
            $record = Vlan::query()
                ->where('vlan_id', $row['vlan_id'])
                ->where('vlan_domain', $row['domain'])
                ->where('site_id', $row['site_id'])
                ->first();

            if ($record === null) {
                continue;
            }

            $byRecord[$record->vlan_record_id][$row['device_id']] = [
                'name' => $row['name'],
                'reported_at' => now()->timestamp,
            ];
        }

        foreach ($byRecord as $recordId => $devices) {
            // one insert per vlan, the row count is small enough
            foreach ($devices as $deviceId => $data) {
                VlanName::query()->updateOrCreate(
                    ['vlan_record_id' => $recordId, 'device_id' => $deviceId],
                    ['name' => $data['name'], 'reported_at' => $data['reported_at']]
                );
            }
        }
    }

    /**
     * Note the vlans the devices name inconsistently.
     *
     * This is a warning, not an error: naming a vlan differently on two switches
     * is normal in a shop with several teams. But 24 such pairs in this install
     * is the kind of thing that turns into a duplicate address later, so it is
     * worth having in the log.
     */
    private function reportNameConflicts(ImportResult $result): void
    {
        $conflicts = Vlan::query()
            ->with('names')
            ->get()
            ->filter(function (Vlan $vlan): bool {
                $distinct = $vlan->names->pluck('name')->filter()->unique();

                return $distinct->count() > 1;
            });

        foreach ($conflicts as $vlan) {
            $result->warnings[] = sprintf(
                'vlan %d at site %s is named %d different ways: %s',
                $vlan->vlan_id,
                $vlan->site_id ?: 'unassigned',
                $vlan->names->pluck('name')->filter()->unique()->count(),
                $vlan->names->pluck('name')->filter()->unique()->implode(', ')
            );
        }
    }
}
