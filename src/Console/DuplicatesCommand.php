<?php

namespace Dercol1\LibrenmsIpam\Console;

use App\Models\Plugin;
use Dercol1\LibrenmsIpam\Collision\DuplicateDetector;
use Dercol1\LibrenmsIpam\Collision\DuplicateGroup;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Models\Site;
use Dercol1\LibrenmsIpam\Models\Vlan;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Console\Command;

class DuplicatesCommand extends Command
{
    protected $signature = 'ipam:duplicates
                            {--site= : limit to a site id}
                            {--vlan= : limit to a vlan record id}
                            {--all : include the ones marked as known}
                            {--unclassified : list the addresses with no vlan and no site instead}
                            {--json : machine readable output}
                            {--limit=200 : how many groups to show}';

    protected $description = 'Report addresses configured more than once inside the same vlan and site';

    public function handle(): int
    {
        if (SchemaInstaller::missing() !== []) {
            $this->error('The ipam tables are missing, run lnms ipam:install first.');

            return self::FAILURE;
        }

        $settings = Settings::fromArray($this->storedSettings());
        $detector = $settings->detector();

        if ($this->option('unclassified')) {
            return $this->showUnclassified($detector);
        }

        $groups = $detector->detect(
            siteId: $this->option('site') !== null ? (int) $this->option('site') : null,
            vlanRecordId: $this->option('vlan') !== null ? (int) $this->option('vlan') : null,
            limit: (int) $this->option('limit')
        );

        if (! $this->option('all')) {
            $groups = array_values(array_filter($groups, fn (DuplicateGroup $g): bool => ! $g->ignored));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(
                fn (DuplicateGroup $g): array => $g->toArray(),
                $groups
            ), JSON_PRETTY_PRINT));

            // duplicates found is a finding, not a failure of the command
            return self::SUCCESS;
        }

        if ($groups === []) {
            $this->info('No address is configured twice inside the same vlan and site.');

            $unclassified = $detector->unclassifiedCount();

            if ($unclassified > 0) {
                $this->comment(sprintf(
                    '%d addresses have no vlan and no site and so are not compared at all. '
                    . 'Run with --unclassified to see them.',
                    $unclassified
                ));
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Address', 'VLAN', 'Site', 'Seen', 'Devices', 'Where', 'Note'],
            array_map(fn (DuplicateGroup $g): array => [
                $g->address,
                $this->vlanLabel($g),
                $this->siteLabel($g),
                $g->count(),
                $g->deviceCount(),
                $g->summary(),
                $g->ignored ? 'known: ' . ($g->note ?? 'yes') : ($g->note ?? ''),
            ], $groups)
        );

        $crossing = count(array_filter($groups, fn (DuplicateGroup $g): bool => $g->crossesDevices()));

        $this->newLine();
        $this->info(sprintf('%d duplicated addresses, %d of them claimed by more than one device', count($groups), $crossing));

        return self::SUCCESS;
    }

    private function showUnclassified(DuplicateDetector $detector): int
    {
        $rows = $detector->unclassified((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(
                fn ($address): array => [
                    'family' => $address->family,
                    'address' => $address->address,
                    'prefixlen' => $address->prefixlen,
                    'device' => $address->device()?->display,
                    'port' => $address->port()?->ifName,
                ],
                $rows
            ), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Every address has been placed in a vlan or a site.');

            return self::SUCCESS;
        }

        $this->table(
            ['Address', 'Network block', 'Device', 'Interface'],
            array_map(fn ($address): array => [
                $address->label(),
                $address->network->cidr ?? '-',
                $address->device()->display ?? "device {$address->device_id}",
                $address->port()->ifName ?? "port {$address->port_id}",
            ], $rows)
        );

        $this->newLine();
        $this->comment(sprintf(
            '%d shown. Set a site or a vlan on the enclosing network block and they inherit it.',
            count($rows)
        ));

        return self::SUCCESS;
    }

    private function vlanLabel(DuplicateGroup $group): string
    {
        if ($group->vlanRecordId === null) {
            return 'any';
        }

        $vlan = Vlan::find($group->vlanRecordId);

        return $vlan === null ? "record {$group->vlanRecordId}" : $vlan->label();
    }

    private function siteLabel(DuplicateGroup $group): string
    {
        if ($group->siteId === 0) {
            return 'unassigned';
        }

        return Site::find($group->siteId)->name ?? "site {$group->siteId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function storedSettings(): array
    {
        $stored = Plugin::query()
            ->where('plugin_name', IpamPluginProvider::PLUGIN_NAME)
            ->value('settings');

        return is_array($stored) ? $stored : [];
    }
}
