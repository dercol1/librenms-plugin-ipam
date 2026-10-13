<?php

namespace Dercol1\LibrenmsIpam\Console;

use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Removes everything the plugin put in the database.
 *
 * Deliberately separate from `plugin:remove`, which only unregisters the
 * package and leaves the tables behind. Those tables are the only copy of the
 * operator's classifications, so dropping them is worth a command that asks
 * first and says what it is about to lose.
 *
 * The plugin's own tables are dropped. Nothing else is touched: LibreNMS has its
 * own tables, and so does the spatie permission table, where only the two rows
 * this plugin created are removed.
 */
class UninstallCommand extends Command
{
    protected $signature = 'ipam:uninstall
                            {--force : do not ask, drop everything without confirmation}
                            {--keep-data : leave the tables in place and only deregister}';

    protected $description = 'Remove the ipam tables, permissions and settings';

    public function handle(): int
    {
        $present = array_values(array_filter(
            SchemaInstaller::tables(),
            fn (string $table): bool => Schema::hasTable($table)
        ));

        if ($present === []) {
            $this->info('There are no ipam tables to remove.');

            return self::SUCCESS;
        }

        $counts = $this->counts($present);

        if ($this->option('keep-data')) {
            $this->line('--keep-data: the tables stay in place, only the permissions and settings go.');

            $this->deregister();

            return self::SUCCESS;
        }

        $this->warn('This deletes the ipam database: every classification, description and owner you entered.');
        $this->newLine();

        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-22s %s rows', $table, number_format($count)));
        }

        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Drop these tables?', false)) {
            $this->info('Nothing was removed.');

            return self::SUCCESS;
        }

        // children first, so nothing is ever dropped before something pointing
        // at it. There are no foreign keys, but the order reads as intent.
        foreach (array_reverse($present) as $table) {
            Schema::drop($table);
            $this->line("  dropped $table");
        }

        $this->deregister();

        $this->newLine();
        $this->info('The ipam database is gone.');
        $this->comment('The package itself is still installed: run `php artisan plugin:remove dercol1/librenms-plugin-ipam` to unregister it.');

        return self::SUCCESS;
    }

    /**
     * How much is in each table, so the confirmation means something.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function counts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Take the plugin's permissions and settings out of LibreNMS.
     *
     * Leaving them behind is harmless but untidy, and a plugin name that is
     * still in the permissions table will make a later reinstall look already
     * installed.
     */
    private function deregister(): void
    {
        try {
            foreach (IpamPermissions::all() as $permission) {
                Permission::where('name', $permission)->delete();
            }

            $this->line('  removed the ipam permissions');
        } catch (\Throwable $e) {
            // the permission tables may not exist, which is not a reason to stop
            $this->comment('  permissions not touched: ' . $e->getMessage());
        }

        try {
            DB::table('plugins')
                ->where('plugin_name', IpamPluginProvider::PLUGIN_NAME)
                ->update(['plugin_active' => 0, 'settings' => null]);

            $this->line('  disabled the plugin and cleared its settings');
        } catch (\Throwable $e) {
            $this->comment('  plugin row not touched: ' . $e->getMessage());
        }
    }
}
