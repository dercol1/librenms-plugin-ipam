<?php

namespace Dercol1\LibrenmsIpam\Console;

use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * The plugin's own schema check.
 *
 * Exists because LibreNMS flags any table it does not know as an "extra table"
 * and offers to drop it, and because the answer to "is the ipam database
 * sound" is not the same question as "does the LibreNMS schema match
 * db_schema.yaml". This answers the plugin's question, which is the one worth
 * asking about these tables.
 */
class CheckCommand extends Command
{
    protected $signature = 'ipam:check {--json : machine readable output}';

    protected $description = 'Verify the ipam tables and report what the plugin found';

    public function handle(): int
    {
        $missing = SchemaInstaller::missing();
        $extra = $this->unexpectedColumns();

        $payload = [
            'tables_present' => $missing === [],
            'missing_tables' => $missing,
            'unexpected_columns' => $extra,
            'permissions' => $this->permissions(),
            'counts' => $missing === [] ? $this->counts() : null,
        ];

        $ok = $missing === [] && $extra === [];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        if ($missing !== []) {
            $this->error('Missing tables, run lnms ipam:install:');

            foreach ($missing as $table) {
                $this->line("  $table");
            }

            return self::FAILURE;
        }

        $this->info('All ipam tables are present.');

        if ($extra !== []) {
            $this->warn('Some tables have columns this plugin version does not know about:');

            foreach ($extra as $line) {
                $this->line("  $line");
            }

            $this->comment('Usually a leftover from an older plugin version. Safe to ignore if you know why.');
        }

        foreach ($payload['permissions'] as $permission => $granted) {
            $this->line(sprintf('  %-12s %s', $permission, $granted ? 'registered' : 'MISSING'));
        }

        $counts = $payload['counts'];

        if ($counts !== null) {
            $this->newLine();
            $this->table(
                ['addresses', 'networks', 'vlans', 'sites', 'owners (net)', 'functions', 'owners (vlan)'],
                [[
                    $counts['addresses'],
                    $counts['networks'],
                    $counts['vlans'],
                    $counts['sites'],
                    $counts['net_owners'],
                    $counts['net_functions'],
                    $counts['vlan_owners'],
                ]]
            );
        }

        return self::SUCCESS;
    }

    /**
     * Columns in a plugin table that this version does not define.
     *
     * Catches a downgrade, where the database kept a column the plugin no longer
     * knows about. Not an error, but worth seeing.
     *
     * @return list<string>
     */
    private function unexpectedColumns(): array
    {
        $found = [];

        foreach (SchemaInstaller::tables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $expected = SchemaInstaller::columns($table);
            $actual = Schema::getColumnListing($table);

            foreach (array_diff($actual, $expected) as $column) {
                $found[] = "$table.$column";
            }
        }

        return $found;
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(): array
    {
        $found = [];

        foreach (IpamPermissions::all() as $permission) {
            $found[$permission] = Permission::where('name', $permission)->exists();
        }

        return $found;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'addresses' => (int) \DB::table('ipam_addresses')->count(),
            'networks' => (int) \DB::table('ipam_networks')->count(),
            'vlans' => (int) \DB::table('ipam_vlans')->count(),
            'sites' => (int) \DB::table('ipam_sites')->count(),
            'net_owners' => (int) \DB::table('ipam_net_owners')->count(),
            'net_functions' => (int) \DB::table('ipam_net_functions')->count(),
            'vlan_owners' => (int) \DB::table('ipam_vlan_owners')->count(),
        ];
    }
}
