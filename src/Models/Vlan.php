<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A broadcast domain, scoped to one site.
 *
 * A vlan id alone is not an identity here. In a typical install 141 of 220 vlan
 * ids show up at more than one site, and vlan 100 at the head office and vlan
 * 100 at a branch are two unrelated broadcast domains. The unique key is
 * therefore (vlan_id, vlan_domain, site_id).
 *
 * @property int $vlan_record_id
 * @property int $vlan_id
 * @property int $vlan_domain
 * @property int $site_id
 * @property int $vlan_owner_id
 * @property string|null $name
 * @property string|null $description
 * @property bool $auto_created
 * @property bool $ignored
 * @property int $vlan_group_id
 */
class Vlan extends BaseModel
{
    protected $table = 'ipam_vlans';

    protected $primaryKey = 'vlan_record_id';

    // auto_created belongs here: the importer creates rows through create(),
    // and without it in the fillable list every imported vlan would come out
    // marked as hand described, so the next import would walk over it
    protected $fillable = [
        'vlan_id',
        'vlan_domain',
        'site_id',
        'vlan_owner_id',
        'name',
        'description',
        'auto_created',
        'ignored',
        'vlan_group_id',
    ];

    protected function casts(): array
    {
        return [
            'vlan_id' => 'int',
            'vlan_domain' => 'int',
            'site_id' => 'int',
            'vlan_owner_id' => 'int',
            'auto_created' => 'bool',
            'ignored' => 'bool',
            'vlan_group_id' => 'int',
        ];
    }

    /**
     * The layer 2 domain this record belongs to when the vlan is carried
     * across sites, null when it is a vlan of one site.
     *
     * @return BelongsTo<VlanGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(VlanGroup::class, 'vlan_group_id', 'vlan_group_id');
    }

    /**
     * The label to show: the operator's description wins, then the name we
     * seeded from the device, then something unmistakable.
     */
    /**
     * Is this record the operator's rather than the import's?
     *
     * Same rule as a block: the import owns what it created, so those are only
     * hideable — the next poll recreates them. A vlan someone typed in, or one
     * the import handed over after it stopped being reported, can be deleted.
     */
    public function isManual(): bool
    {
        return ! $this->auto_created;
    }

    public function label(): string
    {
        if (! empty($this->description)) {
            return (string) $this->description;
        }

        if (! empty($this->name)) {
            return (string) $this->name;
        }

        return "VLAN {$this->vlan_id}";
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }

    /**
     * @return BelongsTo<VlanOwner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(VlanOwner::class, 'vlan_owner_id', 'vlan_owner_id');
    }

    /**
     * @return HasMany<VlanName, $this>
     */
    public function names(): HasMany
    {
        return $this->hasMany(VlanName::class, 'vlan_record_id', 'vlan_record_id');
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'vlan_record_id', 'vlan_record_id');
    }

    /**
     * How many devices report a name for this vlan, and how many of those
     * disagree with the name we stored.
     *
     * Disagreement is normal, different teams name things differently. It is
     * worth surfacing because in this install 24 (vlan, site) pairs disagree
     * outright, which usually means the vlan serves two purposes that nobody
     * has separated yet.
     *
     * @return array{reported: int, distinct: int, names: Collection<int, string>}
     */
    public function nameSpread(): array
    {
        /** @var Collection<int, string> $names */
        $names = $this->names->pluck('name')
            ->filter()
            ->map(fn (?string $name): string => (string) $name)
            ->unique()
            ->values();

        return [
            'reported' => $this->names->count(),
            'distinct' => $names->count(),
            'names' => $names,
        ];
    }

    /**
     * The rooms of the site this vlan was reported in.
     *
     * @return HasMany<VlanLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(VlanLocation::class, 'vlan_record_id', 'vlan_record_id');
    }

    /**
     * The location names, for showing inside a vlan row.
     *
     * @return list<string>
     */
    public function locationNames(): array
    {
        return Location::query()
            ->whereIn('location_id', $this->locations->pluck('location_id'))
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
