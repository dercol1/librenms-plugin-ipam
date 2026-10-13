<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who a network range belongs to. A flat taxonomy on purpose: the "pertinenza"
 * of a range is a name, and giving those names a tree of their own would be a
 * second hierarchy to explain next to the network one.
 *
 * @property int $net_owner_id
 * @property string $name
 * @property string|null $description
 */
class NetworkOwner extends BaseModel
{
    protected $table = 'ipam_net_owners';

    protected $primaryKey = 'net_owner_id';

    protected $fillable = ['name', 'description'];

    /**
     * @return HasMany<Network, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class, 'net_owner_id', 'net_owner_id');
    }
}
