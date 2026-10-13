<?php

namespace Dercol1\LibrenmsIpam\Console;

use App\Models\Plugin;
use Dercol1\LibrenmsIpam\Import\ImportResult;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'ipam:import
                            {--vlans : import only the vlans}
                            {--addresses : import only the addresses}
                            {--json : machine readable output}';

    protected $description = 'Build the ipam database from the addresses and vlans LibreNMS polls';

    public function handle(): int
    {
        $missing = SchemaInstaller::missing();

        if ($missing !== []) {
            $this->error('The ipam tables are missing, run lnms ipam:install first.');

            foreach ($missing as $table) {
                $this->line("  $table");
            }

            return self::FAILURE;
        }

        $settings = Settings::fromArray($this->storedSettings());
        $result = (new ImportResult);
        $started = microtime(true);

        $onlyVlans = (bool) $this->option('vlans');
        $onlyAddresses = (bool) $this->option('addresses');

        if (! $onlyAddresses) {
            $settings->vlanImporter()->import($result);
        }

        if (! $onlyVlans) {
            $settings->importer()->import($result);
        }

        $result->seconds = microtime(true) - $started;

        if ($this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderResult($result);

        return self::SUCCESS;
    }

    private function renderResult(ImportResult $result): void
    {
        $this->newLine();
        $this->info(sprintf(
            'Imported in %.2fs: %d new, %d refreshed, %d removed, %d addresses in scope',
            $result->seconds,
            $result->inserted,
            $result->updated,
            $result->removed,
            $result->seen,
        ));

        if ($result->networksCreated !== []) {
            $parts = [];

            foreach ($result->networksCreated as $prefixLen => $count) {
                $parts[] = "/$prefixLen x$count";
            }

            $this->line('  network blocks created: ' . implode(', ', $parts));
        }

        if ($result->vlansCreated !== []) {
            $this->line('  vlans seen: ' . count($result->vlansCreated));
        }

        if ($result->ignored > 0) {
            // "hidden" here means the excluded ranges: loopback and friends,
            // imported but kept out of the lists and out of collision detection
            $this->line(sprintf('  %d hidden by the excluded ranges (loopback, link-local, multicast)', $result->ignored));
        }

        if ($result->unscoped > 0) {
            $this->warn(sprintf(
                '  %d addresses have neither a vlan nor a site, so they take no part in duplicate detection',
                $result->unscoped
            ));
            $this->comment('  Describe a block on the Networks page, and everything under it is classified.');
        }

        if ($result->warnings !== []) {
            $this->newLine();

            foreach (array_slice($result->warnings, 0, 20) as $warning) {
                $this->line("  ! $warning");
            }

            if (count($result->warnings) > 20) {
                $this->line(sprintf('  ... and %d more', count($result->warnings) - 20));
            }
        }

        $this->newLine();
        $this->line('Duplicates: lnms ipam:duplicates');
    }

    /**
     * The settings the web ui stored for the plugin.
     *
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
