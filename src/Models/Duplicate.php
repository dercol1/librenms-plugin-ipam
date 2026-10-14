<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator's note on one duplicate.
 *
 * Only the annotation lives here. The duplicate list itself is recomputed from
 * ipam_addresses on every read, so a note can never mask a conflict that has
 * since changed, and a stale row can never hide a new one.
 *
 * A known-good duplicate is marked ignored with the reason, so it stops being
 * reported while staying on record.
 *
 * @property int $duplicate_id
 * @property int $family
 * @property string $address
 * @property int $vlan_record_id
 * @property int $site_id
 * @property int $vlan_group_id
 * @property bool $ignored
 * @property string|null $note
 * @property int $updated_by
 */
class Duplicate extends BaseModel
{
    protected $table = 'ipam_duplicates';

    protected $primaryKey = 'duplicate_id';

    protected $fillable = ['family', 'address', 'vlan_record_id', 'site_id', 'vlan_group_id', 'ignored', 'note', 'updated_by'];

    protected function casts(): array
    {
        return [
            'family' => 'int',
            'vlan_record_id' => 'int',
            'site_id' => 'int',
            'ignored' => 'bool',
            'updated_by' => 'int',
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
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }
}
