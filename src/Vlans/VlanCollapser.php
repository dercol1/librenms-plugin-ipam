<?php

namespace Dercol1\LibrenmsIpam\Vlans;

use Dercol1\LibrenmsIpam\Models\Address;
use Dercol1\LibrenmsIpam\Models\Duplicate;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Models\VlanLocation;
use Dercol1\LibrenmsIpam\Models\VlanName;

/**
 * Two records of one vlan at one site, folded into one.
 *
 * A vlan record is keyed by (id, domain, site), and that key is not decoration:
 * the collision scope is judged on it, so two records of vlan 100 at the same
 * site would be two broadcast domains where there is only one, and the same
 * address on each side would never be reported as the conflict it is.
 *
 * Two very different things can produce that pair, and both of them mean the
 * same thing — one domain, seen twice:
 *
 *   a merge of two sites    two rooms of one building reported the same vlan,
 *                           and the operator is saying they are one place;
 *   a vlan moved by hand    the record is dragged onto a site that already
 *                           reports that vlan.
 *
 * So the pair is never refused and never left as an exception from the database.
 * One record survives and everything pointing at the loser follows it: the
 * occurrences classified into it, the rooms it was seen in, the name each device
 * gave it, the domain it belonged to, and the verdicts on its collisions. What
 * the loser loses is nothing the survivor does not already have, because the
 * operator's typed words are carried over rather than dropped with the row.
 */
class VlanCollapser
{
    /**
     * @param  array<string, mixed>  $typed  what the operator just wrote on the loser
     * @return array{vlan: Vlan, addresses: int, rooms: int, names: int, verdicts: int}
     */
    public function collapse(Vlan $winner, Vlan $loser, array $typed = []): array
    {
        $this->carryDomain($winner, $loser);
        $this->carryWords($winner, $loser, $typed);

        $addresses = (int) Address::query()
            ->where('vlan_record_id', $loser->vlan_record_id)
            ->update(['vlan_record_id' => $winner->vlan_record_id]);

        $rooms = $this->carryRooms($winner, $loser);
        $names = $this->carryNames($winner, $loser);
        $verdicts = $this->carryVerdicts($winner, $loser);

        $loser->delete();

        return [
            'vlan' => $winner->fresh(),
            'addresses' => $addresses,
            'rooms' => $rooms,
            'names' => $names,
            'verdicts' => $verdicts,
        ];
    }

    /**
     * A stretched domain the loser was part of is not dropped with it.
     *
     * Two domains meeting means the operator has just said those records are
     * one thing, so they merge the same way two records are linked: the first
     * group wins, the other folds into it.
     */
    private function carryDomain(Vlan $winner, Vlan $loser): void
    {
        $loserGroup = (int) $loser->vlan_group_id;
        $winnerGroup = (int) $winner->vlan_group_id;

        if ($loserGroup === 0) {
            return;
        }

        if ($winnerGroup === 0) {
            $winner->vlan_group_id = $loserGroup;

            return;
        }

        if ($winnerGroup !== $loserGroup) {
            (new VlanLinker)->link(
                (int) $winner->vlan_record_id,
                [(int) $loser->vlan_record_id],
                null
            );
        }
    }

    /**
     * The words the operator just typed are the newest ones there are.
     *
     * Only the fields they actually filled in: an empty box means "leave what
     * is there", which is what every other form in the plugin means by it, and
     * the survivor may well have a description of its own that is worth more
     * than a blank one.
     */
    /**
     * @param  array<string, mixed>  $typed
     */
    private function carryWords(Vlan $winner, Vlan $loser, array $typed): void
    {
        foreach (['name', 'description'] as $field) {
            if (! empty($typed[$field])) {
                $winner->{$field} = $typed[$field];
            }
        }

        if (! empty($typed['vlan_owner_id'])) {
            $winner->vlan_owner_id = (int) $typed['vlan_owner_id'];
        }

        if (array_key_exists('ignored', $typed)) {
            $winner->ignored = (bool) $typed['ignored'];
        }

        // "described by hand" belongs to the vlan, not to the row that happened
        // to survive: whoever typed something just now, or a loser that was
        // already somebody's, makes the survivor theirs too. Two records the
        // import made stay the import's.
        if ($typed !== [] || ! $loser->auto_created) {
            $winner->auto_created = false;
        }

        $winner->save();
    }

    /**
     * The rooms the two records were seen in are one list.
     *
     * A vlan spanning a building is recorded against the rooms it was reported
     * in, and "reported in two rooms" is the normal case rather than something
     * to clean up: the rooms follow the winner instead of being deleted.
     */
    private function carryRooms(Vlan $winner, Vlan $loser): int
    {
        $rooms = VlanLocation::query()->where('vlan_record_id', $loser->vlan_record_id)->get();

        foreach ($rooms as $room) {
            VlanLocation::query()->firstOrCreate([
                'vlan_record_id' => $winner->vlan_record_id,
                'location_id' => $room->location_id,
            ]);
        }

        VlanLocation::query()->where('vlan_record_id', $loser->vlan_record_id)->delete();

        return $rooms->count();
    }

    /**
     * One name per device, and a disagreement between devices stays visible.
     */
    private function carryNames(Vlan $winner, Vlan $loser): int
    {
        $names = VlanName::query()->where('vlan_record_id', $loser->vlan_record_id)->get();

        foreach ($names as $name) {
            VlanName::query()->updateOrCreate(
                ['vlan_record_id' => $winner->vlan_record_id, 'device_id' => $name->device_id],
                ['name' => $name->name, 'reported_at' => $name->reported_at]
            );
        }

        VlanName::query()->where('vlan_record_id', $loser->vlan_record_id)->delete();

        return $names->count();
    }

    /**
     * A verdict on a collision of the record that goes away.
     *
     * It follows the winner, unless the winner already has a verdict on that
     * same finding: two notes for one conflict are not two verdicts, and the one
     * already there is the one the operator wrote about the record that
     * survives.
     */
    private function carryVerdicts(Vlan $winner, Vlan $loser): int
    {
        $verdicts = Duplicate::query()->where('vlan_record_id', $loser->vlan_record_id)->get();

        foreach ($verdicts as $verdict) {
            $same = Duplicate::query()
                ->where('family', $verdict->family)
                ->where('address', $verdict->address)
                ->where('site_id', $verdict->site_id)
                ->where('vlan_group_id', $verdict->vlan_group_id)
                ->where('stretch_id', $verdict->stretch_id)
                ->where('vlan_record_id', $winner->vlan_record_id)
                ->exists();

            $verdict->delete();

            if ($same) {
                continue;
            }

            Duplicate::query()->create([
                'family' => $verdict->family,
                'address' => $verdict->address,
                'vlan_record_id' => $winner->vlan_record_id,
                'site_id' => $verdict->site_id,
                'vlan_group_id' => $verdict->vlan_group_id,
                'stretch_id' => $verdict->stretch_id,
                'ignored' => $verdict->ignored,
                'note' => $verdict->note,
                'updated_by' => $verdict->updated_by,
            ]);
        }

        return $verdicts->count();
    }
}
