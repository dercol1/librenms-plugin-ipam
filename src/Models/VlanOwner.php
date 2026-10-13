<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who is accountable for a broadcast domain.
 *
 * Its own taxonomy rather than a reuse of NetworkOwner: the person who owns a
 * vlan is not the person who owns the routed range it lives in. Keeping them
 * apart means "who do I call about this broadcast domain" has one answer even
 * when the address plan says otherwise.
 *
 * @property int $vlan_owner_id
 * @property string $name
 * @property string|null $description
 */
class VlanOwner extends BaseModel
{
    protected $table = 'ipam_vlan_owners';

    protected $primaryKey = 'vlan_owner_id';

    protected $fillable = ['name', 'description'];

    /**
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class, 'vlan_owner_id', 'vlan_owner_id');
    }
}
