<?php

namespace Dercol1\LibrenmsIpam\Import;

/**
 * What one import run did.
 *
 * Returned rather than logged so the same numbers can go to the web ui, to a
 * command line, and to the tests.
 */
class ImportResult
{
    public int $seen = 0;

    public int $inserted = 0;

    public int $updated = 0;

    public int $removed = 0;

    /** @var array<int, int> network ids created, by prefix length */
    public array $networksCreated = [];

    /** @var array<int, int> vlan ids created, by vlan id */
    public array $vlansCreated = [];

    public int $unscoped = 0;

    /** addresses imported but ignored, because they sit in an excluded range */
    public int $ignored = 0;

    public int $addressesSeen = 0;

    public float $seconds = 0.0;

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        ksort($this->networksCreated);
        ksort($this->vlansCreated);

        return [
            'seen' => $this->seen,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'removed' => $this->removed,
            'addresses' => $this->addressesSeen,
            'unscoped' => $this->unscoped,
            'ignored' => $this->ignored,
            'networks_created' => $this->networksCreated,
            'vlans_created' => $this->vlansCreated,
            'seconds' => round($this->seconds, 3),
            'warnings' => $this->warnings,
        ];
    }
}
