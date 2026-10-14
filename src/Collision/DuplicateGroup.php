<?php

namespace Dercol1\LibrenmsIpam\Collision;

use Dercol1\LibrenmsIpam\Models\Address;
use Illuminate\Support\Collection;

/**
 * One duplicate: an address appearing more than once inside a single scope.
 *
 * Not an Eloquent model, because it is a reading rather than a stored thing. It
 * is recomputed from ipam_addresses every time, so it cannot go stale the way a
 * cached snapshot would.
 */
class DuplicateGroup
{
    /**
     * @param  int  $family  4 or 6
     * @param  string  $address  the duplicated address, in uncompressed form
     * @param  int  $siteId  the site both occurrences claim to be in, 0 unknown
     * @param  int|null  $vlanRecordId  the vlan, or null when vlan scoping is off
     * @param  int  $vlanGroupId  the layer 2 domain when the vlan is stretched across sites, 0 otherwise
     * @param  Collection<int, Address>  $occurrences
     */
    public function __construct(
        public readonly int $family,
        public readonly string $address,
        public readonly int $siteId,
        public readonly ?int $vlanRecordId,
        public readonly int $vlanGroupId,
        public readonly mixed $occurrences,
        public readonly ?string $note = null,
        public readonly bool $ignored = false,
    ) {}

    public function count(): int
    {
        return $this->occurrences->count();
    }

    /**
     * How many different devices claim this address. Two is already a conflict;
     * anything higher means a bigger mess than a stray DHCP lease.
     */
    public function deviceCount(): int
    {
        return $this->occurrences->pluck('device_id')->filter()->unique()->count();
    }

    /**
     * Does the clash cross devices, or is one device using the address twice?
     *
     * Worth telling apart: a device with the address on two ports is usually a
     * management mistake on one box, while two devices claiming it means the
     * address is live on the network somewhere it should not be.
     */
    public function crossesDevices(): bool
    {
        return $this->deviceCount() > 1;
    }

    public function isIpv6(): bool
    {
        return $this->family === 6;
    }

    /**
     * A short human summary for the table and for the command line.
     */
    public function summary(): string
    {
        $where = $this->occurrences
            ->map(function (Address $occurrence): string {
                $device = $occurrence->device();
                $hostname = $device?->display ?: $device?->hostname ?: "device {$occurrence->device_id}";
                $port = $occurrence->port();

                $interface = $port === null
                    ? "port {$occurrence->port_id}"
                    : ($port->ifName ?: $port->ifDescr ?: "port {$occurrence->port_id}");

                return "$hostname $interface";
            })
            ->unique()
            ->implode(', ');

        return $where;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'address' => $this->address,
            'site_id' => $this->siteId,
            'vlan_record_id' => $this->vlanRecordId,
            'vlan_group_id' => $this->vlanGroupId,
            'occurrences' => $this->count(),
            'devices' => $this->deviceCount(),
            'crosses_devices' => $this->crossesDevices(),
            'ignored' => $this->ignored,
            'note' => $this->note,
            'where' => $this->summary(),
        ];
    }
}
