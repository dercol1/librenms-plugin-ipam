<?php

namespace Dercol1\LibrenmsIpam\Vlans;

use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanGroup;
use Illuminate\Support\Collection;

/**
 * Which per-site vlan records are, in fact, one broadcast domain.
 *
 * A vlan carried across sites at layer 2 — dark fibre, an E-Line, or QinQ
 * inside an outer 802.1ad tag — is reported by the devices of each site under
 * that site, so the import makes one record per site. That is the right
 * reading for the common case, the same number reused locally, and it is the
 * wrong reading for a stretched vlan: there the per-site records describe one
 * domain, the same subnet, and the same address at two sites is a real
 * conflict, because at layer 2 nothing can own it twice.
 *
 * Only an operator knows the topology, so the link is a decision, made once,
 * on the vlan page: "this record and those are one domain". The import never
 * touches the group, so the decision survives every poll.
 *
 * The collision rule reads the group back: inside a group, the vlan alone is
 * the scope and the site stops separating occurrences. Everything else in the
 * plugin — the per-site lists, the room records, the inheritance from blocks —
 * keeps working per record, because the sites are still where the devices are.
 */
class VlanLinker
{
    /**
     * Link records into one domain.
     *
     * The records may already carry groups of their own: two groups meeting
     * here mean the operator has just said those groups are one thing, so
     * they merge, and the descriptions of both are kept on the survivor.
     *
     * @param  list<int>  $withRecordIds  the other records that share this domain
     * @return int the group every record now belongs to
     */
    public function link(int $recordId, array $withRecordIds, ?string $description): int
    {
        $record = Vlan::query()->findOrFail($recordId);

        $all = Vlan::query()
            ->whereKey($recordId)
            ->orWhereIn('vlan_record_id', $withRecordIds)
            ->get();

        $groups = $all->pluck('vlan_group_id')->filter()->unique()->values();

        // the group the merged domain is recorded under
        $target = $this->targetGroup($groups, $record, $description);

        Vlan::query()
            ->whereIn('vlan_record_id', $all->pluck('vlan_record_id'))
            ->update(['vlan_group_id' => $target->vlan_group_id]);

        return (int) $target->vlan_group_id;
    }

    /**
     * Take one record back out of its domain.
     *
     * The group survives with the others: a domain spanning three sites does
     * not stop spanning two because one of them was unlinked. A group left
     * with a single member is a domain of one site, which is no domain at
     * all, so it goes.
     */
    public function unlink(int $recordId): bool
    {
        $record = Vlan::query()->find($recordId);

        if ($record === null || (int) $record->vlan_group_id === 0) {
            return false;
        }

        $groupId = (int) $record->vlan_group_id;

        $record->update(['vlan_group_id' => 0]);

        $remaining = Vlan::query()->where('vlan_group_id', $groupId)->count();

        if ($remaining <= 1) {
            // the group no longer stretches: whatever is left is a plain
            // per-site vlan, and the group outlived its purpose
            Vlan::query()->where('vlan_group_id', $groupId)->update(['vlan_group_id' => 0]);
            VlanGroup::query()->whereKey($groupId)->delete();
        }

        return true;
    }

    /**
     * The operator's account of the transport, kept on the group so every
     * member shows it: "QinQ inside S-VID 400 over the Galazzano fibre".
     */
    public function describe(int $recordId, string $description): bool
    {
        $record = Vlan::query()->find($recordId);

        if ($record === null || (int) $record->vlan_group_id === 0) {
            return false;
        }

        VlanGroup::query()
            ->whereKey((int) $record->vlan_group_id)
            ->update(['description' => $description]);

        return true;
    }

    /**
     * The group a set of records and existing groups folds into.
     *
     * @param  Collection<int, int>  $groups
     */
    private function targetGroup(Collection $groups, Vlan $record, ?string $description): VlanGroup
    {
        if ($groups->isNotEmpty()) {
            // reuse the first group and fold the others into it
            $target = VlanGroup::query()->findOrFail($groups->first());

            if ($description !== null && $description !== '') {
                $target->update(['description' => $description]);
            }

            foreach ($groups->slice(1) as $other) {
                Vlan::query()->where('vlan_group_id', $other)->update(['vlan_group_id' => $target->vlan_group_id]);
                VlanGroup::query()->whereKey($other)->delete();
            }

            return $target->refresh();
        }

        return VlanGroup::query()->create([
            'name' => 'vlan ' . $record->vlan_id,
            'description' => $description,
        ]);
    }
}
