<?php

namespace Dercol1\LibrenmsIpam\Web;

use Dercol1\LibrenmsIpam\Models\Location;
use Dercol1\LibrenmsIpam\Models\Site;

/**
 * The site names and their rooms, loaded once for a page.
 *
 * Every page in the plugin shows a site in a column, and every one of those
 * rows needs the same two things: what the site is called, and which sysLocation
 * strings rolled up into it. Asking per row means a query per row, and the
 * addresses page has hundreds of them.
 *
 * So the whole directory is loaded once and addressed by id. It is small: a few
 * dozen sites and locations, against a table of several hundred addresses.
 */
final class SiteDirectory
{
    /**
     * @param  array<int, string>  $names  site id to name
     * @param  array<int, list<string>>  $rooms  site id to the location names
     */
    private function __construct(
        private readonly array $names,
        private readonly array $rooms,
    ) {}

    public static function load(): self
    {
        $names = Site::query()->orderBy('name')->pluck('name', 'site_id')->all();

        $rooms = [];

        foreach (Location::query()->orderBy('name')->get(['site_id', 'name']) as $location) {
            $rooms[(int) $location->site_id][] = (string) $location->name;
        }

        return new self(
            array_map(fn ($name): string => (string) $name, $names),
            $rooms
        );
    }

    public function name(int $siteId): string
    {
        return $this->names[$siteId] ?? '';
    }

    /**
     * @return list<string>
     */
    public function rooms(int $siteId): array
    {
        return $this->rooms[$siteId] ?? [];
    }

    public function roomCount(int $siteId): int
    {
        return count($this->rooms[$siteId] ?? []);
    }

    /**
     * Site id to name, for a select.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        return $this->names;
    }

    /**
     * How many rooms every site holds, for a site column heading.
     *
     * @return array<int, int>
     */
    public function roomCounts(): array
    {
        return array_map(fn (array $names): int => count($names), $this->rooms);
    }

    /**
     * @return list<array{id: int, name: string, rooms: int}>
     */
    public function overview(): array
    {
        $rows = [];

        foreach ($this->names as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name, 'rooms' => $this->roomCount($id)];
        }

        return $rows;
    }
}
