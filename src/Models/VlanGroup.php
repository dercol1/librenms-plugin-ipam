<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One broadcast domain carried across sites at layer 2.
 *
 * Some vlans do not stop at a site: a stretched vlan rides dark fibre, an
 * E-Line, or rides inside an outer 802.1ad tag with QinQ, and arrives at the
 * next site as the same broadcast domain. The devices report it under their
 * own site, so the import makes one record per site — the right reading for
 * the 141 of 220 vlan ids that are merely the same number reused locally —
 * and this is what says which of those records are, in fact, one domain.
 *
 * The group holds no topology of its own: which vlan records belong to it is
 * decided by the operator and stored on the records. What it does carry is
 * the operator's own account of the transport — "QinQ inside S-VID 400 over
 * the Galazzano–Serravalle fibre" — which is exactly the kind of fact no
 * device reports and an operator will otherwise keep nowhere.
 *
 * @property int $vlan_group_id
 * @property string|null $name
 * @property string|null $description
 */
class VlanGroup extends BaseModel
{
    protected $table = 'ipam_vlan_groups';

    protected $primaryKey = 'vlan_group_id';

    protected $fillable = ['name', 'description'];

    /**
     * The per-site records that together are this domain.
     *
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class, 'vlan_group_id', 'vlan_group_id');
    }

    /**
     * The sites this domain spans, by the records that carry it.
     *
     * @return list<string>
     */
    public function siteNames(): array
    {
        return Site::query()
            ->whereIn('site_id', $this->vlans->pluck('site_id'))
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }
}
