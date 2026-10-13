<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A LibreNMS sysLocation string, and the site it belongs to.
 *
 * The devices report a sysLocation, and there are far more of those than there
 * are real places. This install has 38 of them describing about five sites:
 * "Galazzano", "Galazzano Sala B", "Galazzano, (Sala server A)" and
 * "SSIS Galazzano (Sala server A)" are all the same building, reported by
 * different devices with different strings.
 *
 * So a location is not a site. It is one of the strings that roll up into one,
 * and an operator decides the rollup from the Sites page. Everything that is
 * compared for duplicate detection is compared by site, not by location, which
 * is exactly why the rollup matters: without it the same vlan in two rooms of
 * one building would look like two broadcast domains and never be reconciled.
 *
 * @property int $location_id
 * @property int $site_id
 * @property string $name
 */
class Location extends BaseModel
{
    protected $table = 'ipam_locations';

    protected $primaryKey = 'location_id';

    protected $fillable = ['site_id', 'name'];

    protected function casts(): array
    {
        return ['site_id' => 'int'];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'location_id', 'location_id');
    }

    /**
     * The site this location rolls up to, or 0 when nobody has decided yet.
     */
    public function siteIdOrZero(): int
    {
        return (int) $this->site_id;
    }
}
