<?php

namespace Dercol1\LibrenmsIpam\Models;

use App\Models\BaseModel;
use Dercol1\LibrenmsIpam\Support\Cidr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A network block: one node of the range tree.
 *
 * The tree is what makes a wide segment describable. Set an owner and a site on
 * the 10.0.0.0/8 and every address below it inherits both, so a 10.14.146.0/24
 * only needs to be described where it differs.
 *
 * packed_start/packed_end are the binary range, kept in step with cidr by the
 * model, so "which block holds this address" is an indexable range scan.
 *
 * @property int $network_id
 * @property int $parent_id
 * @property string $cidr
 * @property int $family
 * @property string $network
 * @property int $prefixlen
 * @property string $packed_start
 * @property string $packed_end
 * @property string|null $description
 * @property int $site_id
 * @property int $location_id
 * @property int $vlan_id
 * @property int $net_owner_id
 * @property int $net_function_id
 * @property bool $auto_created
 * @property bool $ignored
 * @property int $stretch_id
 */
class Network extends BaseModel
{
    protected $table = 'ipam_networks';

    protected $primaryKey = 'network_id';

    protected $fillable = [
        'parent_id',
        'cidr',
        'family',
        'network',
        'prefixlen',
        'description',
        'site_id',
        'location_id',
        'vlan_id',
        'net_owner_id',
        'net_function_id',
        'auto_created',
        'ignored',
        'stretch_id',
    ];

    protected function casts(): array
    {
        return [
            'family' => 'int',
            'prefixlen' => 'int',
            'site_id' => 'int',
            'location_id' => 'int',
            'vlan_id' => 'int',
            'net_owner_id' => 'int',
            'net_function_id' => 'int',
            'auto_created' => 'bool',
            'ignored' => 'bool',
            'stretch_id' => 'int',
        ];
    }

    /**
     * Keep the derived columns in step with cidr, or refuse the write.
     *
     * Without this a hand-edited cidr would leave the range columns describing
     * the old network, and containment tests would silently answer for the
     * wrong block. Refusing beats writing something inconsistent, and it beats
     * letting the caller believe a block was renamed when it was not.
     */
    protected static function booted(): void
    {
        static::saving(function (Network $network): void {
            $cidr = Cidr::parse((string) $network->cidr, true);

            if ($cidr === null) {
                throw new InvalidArgumentException(
                    "'{$network->cidr}' is not a network this plugin can index"
                );
            }

            $network->family = $cidr->getFamily();
            $network->prefixlen = $cidr->getPrefixLen();
            $network->network = $cidr->network();
            $network->packed_start = $cidr->packedStart();
            $network->packed_end = $cidr->packedEnd();
        });
    }

    public function toCidr(): ?Cidr
    {
        return Cidr::parse((string) $this->cidr, true);
    }

    /**
     * @return BelongsTo<Network, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'network_id');
    }

    /**
     * @return HasMany<Network, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'network_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }

    /**
     * The room this segment is homed in, when that is finer than the site.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    /**
     * The domain this range is carried across, when it is.
     *
     * @return BelongsTo<NetworkStretch, $this>
     */
    public function stretch(): BelongsTo
    {
        return $this->belongsTo(NetworkStretch::class, 'stretch_id', 'stretch_id');
    }

    /**
     * Has anybody said anything about this range?
     *
     * Asked of the content and not of the auto_created flag, on purpose. The
     * flag answers "whose row is this", and an import that finds a range the
     * operator had written down takes it back: from that moment the poll owns it
     * again. But a description, an owner, a site are words somebody typed, and
     * those words do not become the import's because a device started reporting
     * the range again. They are what the reconciler protects instead of
     * deleting.
     *
     * The name of the vlan is not in this list for either model: there it is
     * seeded from the device, so its presence says nothing about an operator.
     */
    public function isDescribed(): bool
    {
        return ! empty($this->description)
            || (int) $this->site_id > 0
            || (int) $this->location_id > 0
            || (int) $this->vlan_id > 0
            || (int) $this->net_owner_id > 0
            || (int) $this->net_function_id > 0
            || (bool) $this->ignored;
    }

    /**
     * @param  Builder<Network>  $query
     * @return Builder<Network>
     */
    public function scopeDescribed(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->where(function (Builder $text): void {
                $text->whereNotNull('description')->where('description', '!=', '');
            })
                ->orWhere('site_id', '>', 0)
                ->orWhere('location_id', '>', 0)
                ->orWhere('vlan_id', '>', 0)
                ->orWhere('net_owner_id', '>', 0)
                ->orWhere('net_function_id', '>', 0);
        });
    }

    /**
     * The blocks nobody has said anything about: the ones the import built.
     *
     * The mirror of scopeDescribed, and the negation written out rather than
     * wrapped in a NOT, so a row with a description of two spaces or a null
     * counts as nobody having said anything.
     *
     * @param  Builder<Network>  $query
     * @return Builder<Network>
     */
    public function scopeUndescribed(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->where(function (Builder $text): void {
                $text->whereNull('description')->orWhere('description', '=', '');
            })
                ->where('site_id', 0)
                ->where('location_id', 0)
                ->where('vlan_id', 0)
                ->where('net_owner_id', 0)
                ->where('net_function_id', 0);
        });
    }

    /**
     * Is this block the operator's rather than the import's?
     *
     * The distinction decides what may be done to it: anything the import owns
     * is only hideable, because the next poll would bring it back and deleting
     * it would just be a slower way of hiding it. A block someone wrote down —
     * typed in, or handed over after its addresses vanished — can go for good.
     */
    public function isManual(): bool
    {
        return ! $this->auto_created;
    }

    /**
     * @return BelongsTo<NetworkOwner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(NetworkOwner::class, 'net_owner_id', 'net_owner_id');
    }

    /**
     * @return BelongsTo<NetworkFunction, $this>
     */
    public function function(): BelongsTo
    {
        return $this->belongsTo(NetworkFunction::class, 'net_function_id', 'net_function_id');
    }

    /**
     * The widest block containing $address, or null when nothing matches.
     *
     * "Widest" is wrong wording, it means the most specific: the /24 wins over
     * the /8 it sits in. The query is a range scan over the packed columns and
     * orders by prefixlen so the deepest match comes first.
     */
    public static function enclosing(Cidr $address): ?self
    {
        return self::query()
            ->where('family', $address->getFamily())
            ->where('packed_start', '<=', $address->packed())
            ->where('packed_end', '>=', $address->packed())
            ->orderByDesc('prefixlen')
            ->first();
    }

    /**
     * Walk up the tree until $finder returns something other than 0.
     *
     * This is the inheritance: an address or a child block asking for a site
     * asks this block first, then its parent, and so on to the root. The first
     * block that has one wins, so a child overrides its parent by being set.
     *
     * A cycle would loop forever, so the walk is bounded by the block count.
     */
    public function inherit(string $column): int
    {
        $seen = [];
        $current = $this;

        while ($current !== null && ! isset($seen[$current->network_id])) {
            $seen[$current->network_id] = true;

            $value = (int) ($current->$column ?? 0);

            if ($value !== 0) {
                return $value;
            }

            $current = $current->parent_id === 0 ? null : self::find($current->parent_id);
        }

        return 0;
    }

    /**
     * Is this block, or any block above it, ignored?
     *
     * A different walk from inherit(): hidden is boolean, so "the first block
     * up the chain that says something" is not the rule — any hidden ancestor
     * quiets everything below it. That is what makes ignoring 127.0.0.0/8 clear
     * the whole loopback family in one action.
     */
    public function ignoredInChain(): bool
    {
        $seen = [];
        $current = $this;

        while ($current !== null && ! isset($seen[$current->network_id])) {
            $seen[$current->network_id] = true;

            // getAttribute, deliberately: $model->hidden is a real Eloquent
            // property (the attributes kept out of array/json output), so inside
            // a model method it returns that list instead of the column. The
            // column has to be asked for by name.
            if ((bool) $current->getAttribute('ignored')) {
                return true;
            }

            $current = $current->parent_id === 0 ? null : self::find($current->parent_id);
        }

        return false;
    }

    /**
     * This block and every block below it, ids only.
     *
     * Used by the hide action: hiding a block hides the subtree, and unhiding
     * one has to undo exactly what hiding did, or the two stop being opposites.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [(int) $this->network_id];
        $frontier = [(int) $this->network_id];

        while ($frontier !== []) {
            $children = array_map(
                fn ($id): int => (int) $id,
                self::query()->whereIn('parent_id', $frontier)->pluck('network_id')->all()
            );

            $frontier = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /**
     * Ignore this block, the blocks below it, and the addresses under them.
     *
     * Eagerly, on the action itself, so that "hidden" stays one plain column at
     * query time everywhere else: no page and no report has to walk the block
     * tree to work out whether an address is in a hidden range.
     */
    public function ignoreSubtree(): void
    {
        $ids = $this->subtreeIds();

        self::query()->whereIn('network_id', $ids)->update(['ignored' => true]);
        Address::query()->whereIn('network_id', $ids)->update(['ignored' => true]);
    }

    /**
     * The exact opposite of ignoreSubtree, over the exact same set.
     */
    public function unignoreSubtree(): void
    {
        $ids = $this->subtreeIds();

        self::query()->whereIn('network_id', $ids)->update(['ignored' => false]);
        Address::query()->whereIn('network_id', $ids)->update(['ignored' => false]);
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'network_id', 'network_id');
    }

    /**
     * The vlan records carrying this block's declared id, at any site.
     *
     * A block declares a bare 802.1q id, which is not a vlan_record_id, so this
     * cannot be a belongsTo: one id has one record per site, and which of them
     * applies depends on the site the address was resolved to.
     *
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class, 'vlan_id', 'vlan_id');
    }
}
