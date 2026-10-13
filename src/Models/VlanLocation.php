<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place inside a site where a vlan was reported.
 *
 * A vlan spans a site, so the same broadcast domain is seen in several rooms and
 * must stay one record. This row says which rooms, without multiplying the vlan.
 *
 * @property int $vlan_location_id
 * @property int $vlan_record_id
 * @property int $location_id
 */
class VlanLocation extends BaseModel
{
    protected $table = 'ipam_vlan_locations';

    protected $primaryKey = 'vlan_location_id';

    protected $fillable = ['vlan_record_id', 'location_id'];

    protected function casts(): array
    {
        return [
            'vlan_record_id' => 'int',
            'location_id' => 'int',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
}
