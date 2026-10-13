<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use App\Models\Device;
use App\Models\Port;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address, seen on one port.
 *
 * This is the table duplicate detection reads. An address configured on two
 * ports is two rows, and whether that is a conflict or the design depends
 * entirely on their scope: the same address in two vlans at one site is fine,
 * the same address twice inside one (vlan, site) is not.
 *
 * The natural key mirrors LibreNMS's Ipv4Address::getCompositeKey(), so the same
 * address reported on the same port in two vrfs stays two rows, exactly as
 * LibreNMS stores it.
 *
 * @property int $address_record_id
 * @property int $family
 * @property string $address
 * @property int $prefixlen
 * @property int $port_id
 * @property int $device_id
 * @property string $context_name
 * @property int $network_id
 * @property int $vlan_record_id
 * @property int $site_id
 * @property int $location_id
 * @property int $net_owner_id
 * @property int $net_function_id
 * @property bool $scope_locked
 * @property bool $ignored
 * @property string|null $description
 * @property string|null $first_seen
 * @property string|null $last_seen
 */
class Address extends BaseModel
{
    protected $table = 'ipam_addresses';

    protected $primaryKey = 'address_record_id';

    protected $fillable = [
        'family',
        'address',
        'prefixlen',
        'port_id',
        'device_id',
        'context_name',
        'network_id',
        'vlan_record_id',
        'site_id',
        'location_id',
        'net_owner_id',
        'net_function_id',
        'scope_locked',
        'ignored',
        'description',
        'first_seen',
        'last_seen',
    ];

    protected function casts(): array
    {
        return [
            'family' => 'int',
            'prefixlen' => 'int',
            'port_id' => 'int',
            'device_id' => 'int',
            'network_id' => 'int',
            'vlan_record_id' => 'int',
            'site_id' => 'int',
            'location_id' => 'int',
            'net_owner_id' => 'int',
            'net_function_id' => 'int',
            'scope_locked' => 'bool',
            'ignored' => 'bool',
        ];
    }

    /**
     * The address, always with its prefix length.
     */
    public function label(): string
    {
        return $this->address . '/' . $this->prefixlen;
    }

    public function toCidr(): ?Cidr
    {
        return Cidr::address((string) $this->address, true);
    }

    /**
     * Has this occurrence been placed in a scope at all?
     *
     * An occurrence with neither vlan nor site cannot be compared to anything,
     * because there is nothing to compare against. Those go to the unclassified
     * queue instead of the duplicate report.
     */
    public function isUnscoped(): bool
    {
        return $this->vlan_record_id === 0 && $this->site_id === 0;
    }

    /**
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_id', 'network_id');
    }

    /**
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class, 'vlan_record_id', 'vlan_record_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }

    /**
     * The sysLocation this occurrence was reported at, which is finer than the
     * site: several location strings can roll up into one site.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    /**
     * @return BelongsTo<NetworkOwner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(NetworkOwner::class, 'net_owner_id', 'net_owner_id');
    }

    /**
     * @return BelongsTo<NetworkFunction, $this>
     */
    public function function(): BelongsTo
    {
        return $this->belongsTo(NetworkFunction::class, 'net_function_id', 'net_function_id');
    }

    public function device(): ?Device
    {
        return $this->device_id === 0 ? null : Device::find($this->device_id);
    }

    public function port(): ?Port
    {
        return $this->port_id === 0 ? null : Port::find($this->port_id);
    }

    /**
     * Occurrences with neither a vlan nor a site: everything the import could
     * not place, and therefore everything still waiting for an operator.
     */
    /**
     * @param  Builder<Address>  $query
     * @return Builder<Address>
     */
    public function scopeUnscoped(Builder $query): Builder
    {
        return $query->where('vlan_record_id', 0)->where('site_id', 0);
    }

    /**
     * LibreNMS stores v6 expanded and the two forms must compare equal, so the
     * match is done on the address the model holds, not on a raw column.
     *
     * @param  Builder<Address>  $query
     * @return Builder<Address>
     */
    public function scopeMatchingAddress(Builder $query, Cidr $address): Builder
    {
        return $query
            ->where('family', $address->getFamily())
            ->where('address', $address->uncompressed());
    }
}
