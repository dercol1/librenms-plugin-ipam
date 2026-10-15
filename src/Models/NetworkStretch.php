<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One subnet carried across places at layer 2.
 *
 * A vlan stretched across sites is a broadcast domain; a network stretched
 * across them is a subnet that exists unchanged at more than one of them, which
 * is what a QinQ service vlan, a dark fibre between two cabinets or an E-Line
 * gives you. The block itself is still one row — the range does not change — so
 * what says "this is here and there too" is this.
 *
 * Which places it reaches is on the places table, and what carries it is the
 * description here. Neither is something a device reports: no port says which
 * fibre its vlan comes back on, and the operator would otherwise keep the fact
 * in a spreadsheet. So this is a decision, made once, and the import never
 * touches it.
 *
 * Inside a domain the site stops being the scope: the same address in the same
 * vlan at two of the places is one conflict, because one subnet carried at
 * layer 2 has one copy of every address. See the collision detector.
 *
 * @property int $stretch_id
 * @property int $network_id
 * @property string|null $name
 * @property string|null $description
 */
class NetworkStretch extends BaseModel
{
    protected $table = 'ipam_network_stretches';

    protected $primaryKey = 'stretch_id';

    protected $fillable = ['network_id', 'name', 'description'];

    protected function casts(): array
    {
        return [
            'network_id' => 'int',
        ];
    }

    /**
     * The block this domain is about.
     *
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_id', 'network_id');
    }

    /**
     * @return HasMany<NetworkPlace, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(NetworkPlace::class, 'stretch_id', 'stretch_id');
    }

    /**
     * The places, written out for a report: "Galazzano + Armadio A13".
     *
     * @return list<string>
     */
    public function placeNames(): array
    {
        return $this->places
            ->map(fn (NetworkPlace $place): string => $place->placeName())
            ->values()
            ->all();
    }

    /**
     * Does an address seen at this site or in this room sit in the domain?
     *
     * The address side of the same question the places table answers for a
     * human: a place is a site or a room, and an occurrence carries both, so it
     * is in the domain when either one of them is listed.
     */
    public function holds(int $siteId, int $locationId): bool
    {
        return $this->places->contains(fn (NetworkPlace $place): bool => $place->matches($siteId, $locationId));
    }
}
