<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A site: one of the places the estate consists of.
 *
 * A site is an authoring decision, never read off a device. Devices report a
 * sysLocation, and the same building arrives under a different string from every
 * switch in it, so the strings are grouped into sites by an operator.
 *
 * The site is the unit that separates two scopes in duplicate detection, which
 * makes the grouping a correctness question and not a presentational one.
 *
 * @property int $site_id
 * @property string $name
 * @property string|null $description
 * @property string|null $address
 * @property bool $auto_created
 */
class Site extends BaseModel
{
    protected $table = 'ipam_sites';

    protected $primaryKey = 'site_id';

    protected function casts(): array
    {
        return [
            'auto_created' => 'bool',
        ];
    }

    /**
     * Is this a real place or a placeholder?
     *
     * A placeholder is what LocationResolver makes for a sysLocation nobody has
     * filed yet: a site named after its own single room, so an address always
     * had somewhere to land. It is not a destination anybody chose, and things
     * an operator states about real places — a subnet that reaches this site —
     * are not offered one.
     */
    public function isDefined(): bool
    {
        return ! $this->auto_created;
    }

    protected $fillable = ['name', 'description', 'address', 'auto_created'];

    /**
     * The sysLocation strings that roll up into this site.
     *
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'site_id', 'site_id');
    }

    /**
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class, 'site_id', 'site_id');
    }

    /**
     * @return HasMany<Network, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class, 'site_id', 'site_id');
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'site_id', 'site_id');
    }

    /**
     * The location names, for showing inside a site row.
     *
     * @return list<string>
     */
    public function locationNames(): array
    {
        return $this->locations->pluck('name')->map(fn ($name) => (string) $name)->values()->all();
    }
}
