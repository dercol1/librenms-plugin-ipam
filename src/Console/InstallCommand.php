<?php

namespace Dercol1\LibrenmsIpam\Console;

use App\Models\Plugin;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'ipam:install
                            {--force : create the tables even when some already exist}';

    protected $description = 'Create the ipam tables and register the ipam permissions';

    public function handle(): int
    {
        $missing = SchemaInstaller::missing();

        // install() runs either way, not only when a table is missing. It also
        // adds columns a newer version of the plugin introduced, and that work
        // has to happen on an install whose tables are already there, which is
        // exactly the case after an upgrade.
        $changes = SchemaInstaller::install();

        if ($missing === []) {
            $this->info('The ipam tables are already in place.');
        }

        foreach ($changes as $change) {
            $this->line("  $change");
        }

        IpamPermissions::register();
        $this->info('ipam.view and ipam.manage are registered, admin holds both.');

        // A reinstall normally follows an uninstall, and the uninstall switches
        // the plugin off. Without this the tables would be created and no page
        // would appear, which looks exactly like a broken install.
        if (! $this->pluginIsEnabled()) {
            $this->newLine();
            $this->warn('The plugin is disabled, so its pages will not appear until you enable it.');
            $this->comment('Enable it with: php artisan plugin:enable ipam');
        }

        $this->newLine();
        $this->comment('Next: lnms ipam:import to build the database from what LibreNMS already polls.');

        if ($missing !== []) {
            $this->newLine();
            $this->warn(
                'LibreNMS lists tables it does not know about as "extra tables" on the Validate page and '
                . 'offers to drop them. These ipam_* tables are expected here. Do not apply that fix.'
            );
            $this->comment('Run `lnms ipam:check` instead, it verifies the plugin tables on their own terms.');
        }

        if ($changes !== []) {
            $this->newLine();
            $this->comment('Rebuild the ipam database with lnms ipam:import to see the effect of the changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Is the plugin switched on in LibreNMS?
     *
     * The plugin row is what the ui reads, so a disabled plugin has no pages
     * however complete the install was.
     */
    private function pluginIsEnabled(): bool
    {
        try {
            return (bool) Plugin::query()
                ->where('plugin_name', IpamPluginProvider::PLUGIN_NAME)
                ->value('plugin_active');
        } catch (\Throwable) {
            return true; // no table to ask, assume the plugin admin has it on
        }
    }
}
