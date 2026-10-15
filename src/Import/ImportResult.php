<?php

namespace Dercol1\LibrenmsIpam\Import;

/**
 * What one import run did.
 *
 * Returned rather than logged so the same numbers can go to the web ui, to a
 * command line, and to the tests.
 */
class ImportResult
{
    public int $seen = 0;

    public int $inserted = 0;

    public int $updated = 0;

    public int $removed = 0;

    /** @var array<int, int> network ids created, by prefix length */
    public array $networksCreated = [];

    /** @var array<int, int> vlan ids created, by vlan id */
    public array $vlansCreated = [];

    public int $unscoped = 0;

    /** addresses imported but ignored, because they sit in an excluded range */
    public int $ignored = 0;

    public int $addressesSeen = 0;

    /**
     * The blocks this run walked over, by id.
     *
     * A block the import passed on its way to an address is a block the estate
     * still has, and the reconciler needs to tell those from the ones nothing
     * points at any more.
     *
     * @var array<int, bool>
     */
    public array $seenNetworks = [];

    /**
     * The vlan records this run resolved an occurrence to, by id.
     *
     * Both halves of the import feed it: the vlan table names the records, and
     * so does an address that resolved to one through its port or its block. A
     * record is alive if either saw it, because both are evidence the estate
     * still has it.
     *
     * @var array<int, bool>
     */
    public array $seenVlanRecords = [];

    /**
     * The sysLocation strings LibreNMS knows, by name.
     *
     * A room is matched on the exact string a device reported, so this is the
     * only way to tell one the estate still has from a row nothing will ever
     * refresh again.
     *
     * @var array<string, bool>
     */
    public array $seenLocations = [];

    /** the vlan import ran in this session, so vlans can be reconciled */
    public bool $vlanImportRan = false;

    /** rows the import no longer owns and handed to the operator */
    public int $handedOver = 0;

    /** blocks nothing points at any more */
    public int $networksRemoved = 0;

    /** vlans nothing points at any more */
    public int $vlansRemoved = 0;

    /** rooms LibreNMS no longer knows, and the placeholders they left behind */
    public int $locationsRemoved = 0;

    public int $sitesRemoved = 0;

    public float $seconds = 0.0;

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        ksort($this->networksCreated);
        ksort($this->vlansCreated);

        return [
            'seen' => $this->seen,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'removed' => $this->removed,
            'addresses' => $this->addressesSeen,
            'handed_over' => $this->handedOver,
            'networks_removed' => $this->networksRemoved,
            'vlans_removed' => $this->vlansRemoved,
            'locations_removed' => $this->locationsRemoved,
            'sites_removed' => $this->sitesRemoved,
            'unscoped' => $this->unscoped,
            'ignored' => $this->ignored,
            'networks_created' => $this->networksCreated,
            'vlans_created' => $this->vlansCreated,
            'seconds' => round($this->seconds, 3),
            'warnings' => $this->warnings,
        ];
    }
}
