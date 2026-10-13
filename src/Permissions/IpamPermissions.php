<?php

namespace Dercol1\LibrenmsIpam\Permissions;

use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The two permissions the plugin asks for.
 *
 * ipam.view to read the maps and the reports, ipam.manage to describe things
 * and to correct a classification. Splitting them means the people who look
 * after the network day to day can see the address plan without being able to
 * rewrite it.
 *
 * Registered on boot rather than in a migration, because spatie's permission
 * tables are LibreNMS's and it already seeds them. Doing it here keeps the
 * plugin from needing a migration of its own on someone else's tables.
 */
class IpamPermissions
{
    public const VIEW = 'ipam.view';

    public const MANAGE = 'ipam.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE];
    }

    /**
     * Create the permissions if they are missing and grant them to admin.
     *
     * Idempotent, so this can run on every boot. The global-read role gets the
     * read permission, matching how LibreNMS treats read only operators.
     */
    public static function register(): void
    {
        try {
            foreach (self::all() as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $admin = Role::findByName('admin');
            $admin->syncPermissions(array_merge($admin->getPermissionNames()->all(), self::all()));

            // A read only operator may look at the map but not change it.
            try {
                $globalRead = Role::findByName('global-read');

                // hasPermissionTo wants the guard as well, and this plugin only
                // ever registers on 'web'
                if (! $globalRead->hasPermissionTo(self::VIEW, 'web')) {
                    $globalRead->givePermissionTo(self::VIEW);
                }
            } catch (\Throwable $e) {
                // the role may not exist on a fresh install, the admin grant
                // above is the one that matters
            }
        } catch (\Throwable $e) {
            // No database, or the permission tables are not migrated yet. The
            // pages gate on the Gate below, which denies by default, so a
            // failure here degrades to "nobody sees ipam" rather than to an
            // error on every request.
        }
    }

    /**
     * May the current user read the ipam?
     */
    public static function canView(): bool
    {
        return Gate::allows(self::VIEW) || Gate::allows('plugin.admin');
    }

    /**
     * May the current user change classifications and annotate duplicates?
     */
    public static function canManage(): bool
    {
        return Gate::allows(self::MANAGE) || Gate::allows('plugin.admin');
    }
}
