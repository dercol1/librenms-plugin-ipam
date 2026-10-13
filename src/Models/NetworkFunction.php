<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a network range is for: management, storage, voice, a customer circuit.
 *
 * Separate from NetworkOwner on purpose. "who owns it" and "what it is for"
 * are different questions, and the same owner runs ranges with very different
 * purposes.
 *
 * @property int $net_function_id
 * @property string $name
 * @property string|null $description
 */
class NetworkFunction extends BaseModel
{
    protected $table = 'ipam_net_functions';

    protected $primaryKey = 'net_function_id';

    protected $fillable = ['name', 'description'];

    /**
     * @return HasMany<Network, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class, 'net_function_id', 'net_function_id');
    }
}
