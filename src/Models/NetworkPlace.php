<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place a stretched subnet reaches.
 *
 * A place is either a whole site — the segment gets to every room of it — or a
 * single room, which is the case a site cannot express: a subnet carried to
 * one cabinet of a building is not at the building. Both are needed, which is
 * why the kind is spelled out rather than guessed from the id.
 *
 * @property int $stretch_place_id
 * @property int $stretch_id
 * @property string $place_type
 * @property int $place_id
 */
class NetworkPlace extends BaseModel
{
    protected $table = 'ipam_network_places';

    protected $primaryKey = 'stretch_place_id';

    protected $fillable = ['stretch_id', 'place_type', 'place_id'];

    public const SITE = 'site';

    public const LOCATION = 'location';

    protected function casts(): array
    {
        return [
            'stretch_id' => 'int',
            'place_id' => 'int',
        ];
    }

    /**
     * @return BelongsTo<NetworkStretch, $this>
     */
    public function stretch(): BelongsTo
    {
        return $this->belongsTo(NetworkStretch::class, 'stretch_id', 'stretch_id');
    }

    /**
     * "site:12" — the spelling a form sends and the key a candidate list uses.
     */
    public function key(): string
    {
        return $this->place_type . ':' . $this->place_id;
    }

    /**
     * The place as a person would name it.
     */
    public function placeName(): string
    {
        if ($this->place_type === self::SITE) {
            return (string) (Site::query()->find($this->place_id)->name ?? '?');
        }

        return (string) (Location::query()->find($this->place_id)->name ?? '?');
    }

    /**
     * Is an occurrence at this site, or in this room, inside the place?
     *
     * An occurrence carries both, so matching either is what "the segment gets
     * to this place" means: a room inside a listed site, or the site a listed
     * room belongs to.
     */
    public function matches(int $siteId, int $locationId): bool
    {
        if ($this->place_type === self::SITE) {
            return $this->place_id === $siteId;
        }

        return $this->place_id === $locationId;
    }
}
