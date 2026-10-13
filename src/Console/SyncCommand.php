<?php

namespace Dercol1\LibrenmsIpam\Console;

use App\Models\Plugin;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Settings;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Dercol1\LibrenmsIpam\Sync\Alignment;
use Illuminate\Console\Command;

/**
 * Does the ipam database say what LibreNMS is polling right now?
 *
 * The ipam is built by copy, so the honest question about it is not "is it
 * correct" but "is it current". A poller that ran since the last import has
 * brought addresses the ipam has never seen; a device that went away left rows
 * the next import would remove. Both make every report built on the copy
 * provisional, and neither shows up anywhere else in the plugin.
 *
 * This reports both directions and names a few examples of each, then says the
 * one thing that fixes it. It never changes anything itself: the import is the
 * only thing that writes, and an operator who wants to know the drift should
 * not have to accept a rebuild to find it out.
 */
class SyncCommand extends Command
{
    protected $signature = 'ipam:sync {--json : machine readable output}';

    protected $description = 'Compare the ipam database with what LibreNMS currently polls';

    public function handle(): int
    {
        $missing = SchemaInstaller::missing();

        if ($missing !== []) {
            $this->error('The ipam tables are not installed, run: lnms ipam:install');

            return self::FAILURE;
        }

        $settings = Settings::fromArray($this->pluginSettings());
        $report = Alignment::check($settings);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return $report['in_sync'] ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf('  addresses LibreNMS polls: %d, ipam holds: %d',
            $report['addresses_in_librenms'],
            $report['addresses_in_ipam'],
        ));
        $this->line(sprintf('  vlan/site pairs LibreNMS has: %d, ipam holds: %d',
            $report['vlans_in_librenms'],
            $report['vlans_in_ipam'],
        ));

        $this->newLine();

        if ($report['addresses_not_imported'] > 0) {
            $this->warn(sprintf('%d address occurrence(s) are in LibreNMS but not in the ipam yet:', $report['addresses_not_imported']));

            foreach ($report['not_imported_samples'] as $sample) {
                $this->line("  $sample");
            }
        }

        if ($report['vlans_not_imported'] > 0) {
            $this->warn(sprintf('%d vlan(s) are in LibreNMS but not in the ipam yet.', $report['vlans_not_imported']));
        }

        if ($report['addresses_gone'] > 0) {
            $this->warn(sprintf('%d ipam row(s) are no longer reported by LibreNMS:', $report['addresses_gone']));

            foreach ($report['gone_samples'] as $sample) {
                $this->line("  $sample");
            }
        }

        if ($report['vlans_gone'] > 0) {
            $this->warn(sprintf('%d ipam vlan(s) are no longer reported by LibreNMS.', $report['vlans_gone']));
        }

        if ($report['in_sync']) {
            $this->info('The ipam database is in sync with what LibreNMS polls.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment('Run lnms ipam:import to bring the ipam in line. Rows you added by hand are not touched by it.');

        return self::FAILURE;
    }

    /**
     * The settings the web ui stored, which is where the import reads its own
     * configuration from: alignment has to be judged by the same rules.
     *
     * @return array<string, mixed>
     */
    private function pluginSettings(): array
    {
        $stored = Plugin::query()
            ->where('plugin_name', IpamPluginProvider::PLUGIN_NAME)
            ->value('settings');

        return is_array($stored) ? $stored : [];
    }
}
