<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use App\Models\Device;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One name for a vlan, as reported by one device.
 *
 * LibreNMS reads Q-BRIDGE-MIB::dot1qVlanStaticName and truncates at 32
 * characters, so the same vlan routinely arrives under several names. Keeping
 * them all per device is what turns "these two switches disagree about vlan
 * 300" from an invisible detail into something an operator can see.
 *
 * @property int $vlan_name_id
 * @property int $vlan_record_id
 * @property int $device_id
 * @property string|null $name
 * @property int|null $reported_at
 */
class VlanName extends BaseModel
{
    protected $table = 'ipam_vlan_names';

    protected $primaryKey = 'vlan_name_id';

    protected $fillable = ['vlan_record_id', 'device_id', 'name', 'reported_at'];

    protected function casts(): array
    {
        return [
            'vlan_record_id' => 'int',
            'device_id' => 'int',
            'reported_at' => 'int',
        ];
    }

    /**
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class, 'vlan_record_id', 'vlan_record_id');
    }

    /**
     * The LibreNMS device, so the report can show which switch said what.
     */
    public function device(): ?Device
    {
        return $this->device_id === 0 ? null : Device::find($this->device_id);
    }
}
