<?php

namespace Dercol1\LibrenmsIpam\Tests;

use App\ConfigRepository;
use App\Facades\DeviceCache;
use App\Facades\LibrenmsConfig;
use App\Facades\Permissions;
use App\Facades\PluginManager;
use App\Facades\PortCache;
use App\Facades\Rrd;
use App\Models\User;
use Dercol1\LibrenmsIpam\IpamPluginProvider;
use Dercol1\LibrenmsIpam\Permissions\IpamPermissions;
use Dercol1\LibrenmsIpam\Support\SchemaInstaller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Cache\Device;
use LibreNMS\Cache\PermissionsCache;
use LibreNMS\Cache\Port;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Base for the tests that need a database.
 *
 * The plugin tables are created once per test and dropped after, so a failing
 * test cannot leave a table behind for the next one. No plugin data is seeded:
 * each test builds the little it needs, so the tests do not quietly depend on
 * each other's rows.
 */
abstract class UnitTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLibrenmsStandIns();

        SchemaInstaller::install();

        $this->registerSqliteAddressFunctions();
    }

    /**
     * The LibreNMS tables the plugin reads, empty.
     *
     * The importer joins across devices, ports, ports_vlans, the address tables
     * and vlans, so without these every import test fails for the same
     * uninteresting reason. Declared here rather than per test because the shape
     * matters, not the rows.
     */
    protected function createLibrenmsStandIns(): void
    {
        Schema::create('locations', function ($table): void {
            $table->increments('id');
            $table->string('location');
        });

        Schema::create('devices', function ($table): void {
            $table->increments('device_id');
            $table->string('hostname')->default('');
            $table->string('sysName')->nullable();
            $table->string('display')->default('');
            $table->string('ifAlias', 255)->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->integer('ifIndex')->default(0);
        });

        Schema::create('ports', function ($table): void {
            $table->increments('port_id');
            $table->unsignedInteger('device_id')->default(0);
            $table->string('ifName', 255)->nullable();
            $table->string('ifDescr', 255)->nullable();
            $table->string('ifAlias', 255)->nullable();
            $table->integer('ifVlan')->default(0);
            $table->boolean('deleted')->default(false);
            $table->boolean('ignore')->default(false);
            $table->boolean('disabled')->default(false);
        });

        Schema::create('ports_vlans', function ($table): void {
            $table->increments('port_vlan_id');
            $table->unsignedInteger('device_id')->default(0);
            $table->unsignedInteger('port_id')->default(0);
            $table->unsignedInteger('vlan')->default(0);
        });

        Schema::create('ipv4_addresses', function ($table): void {
            $table->increments('ipv4_address_id');
            $table->string('ipv4_address', 32);
            $table->integer('ipv4_prefixlen')->default(24);
            $table->unsignedInteger('port_id')->default(0);
            $table->string('context_name', 128)->nullable();
        });

        Schema::create('ipv6_addresses', function ($table): void {
            $table->increments('ipv6_address_id');
            $table->string('ipv6_address', 128);
            $table->integer('ipv6_prefixlen')->default(64);
            $table->unsignedInteger('port_id')->default(0);
            $table->string('context_name', 128)->nullable();
        });

        Schema::create('vlans', function ($table): void {
            $table->increments('vlan_id');
            $table->unsignedInteger('device_id')->nullable();
            $table->integer('vlan_vlan')->nullable();
            $table->integer('vlan_domain')->default(1);
            $table->string('vlan_name', 64)->nullable();
        });

        // one device and two ports, so a page that follows an address through to
        // its device name and its interface name has something to find. An
        // importer test that needs more devices creates them.
        DB::table('devices')->insert([
            'device_id' => 1,
            'hostname' => 'sw1',
            'display' => 'sw1',
            'location_id' => null,
        ]);

        DB::table('ports')->insert([
            ['port_id' => 1, 'device_id' => 1, 'ifName' => 'Vlan100'],
            ['port_id' => 2, 'device_id' => 1, 'ifName' => 'Vlan200'],
        ]);
    }

    /**
     * Teach sqlite the address functions LibreNMS's mysql server provides.
     *
     * The address list sorts and range-searches with INET_ATON, which is the
     * right thing to do on the database LibreNMS actually runs on: a lexical
     * sort puts 10.14.10.1 before 10.14.9.1, and a LIKE cannot express a range.
     * sqlite has neither function, so they are registered here as php callables
     * over the same semantics. That keeps the production sql identical and lets
     * the tests exercise it as written.
     *
     * The callable returns the packed bytes rather than a number. A number
     * worked for v4, but a v6 address does not fit in a php int: the shift
     * overflows at the eighth byte and every comparison after that is against
     * a wrapped value, which is how a v6 range search once silently found
     * nothing. Packed bytes compare byte by byte under sqlite's default
     * collation, which for unsigned bytes is numeric order, and it is what
     * mysql's INET6_ATON returns on the real install anyway.
     */
    private function registerSqliteAddressFunctions(): void
    {
        $pdo = DB::connection()->getPdo();

        if (! $pdo instanceof \PDO || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }

        $toPacked = function (?string $address): string {
            $address = trim((string) $address);

            if ($address === '') {
                return '';
            }

            $packed = @inet_pton($address);

            return $packed === false ? '' : $packed;
        };

        $pdo->sqliteCreateFunction('INET_ATON', $toPacked);
        $pdo->sqliteCreateFunction('INET6_ATON', $toPacked);
    }

    protected function tearDown(): void
    {
        SchemaInstaller::uninstall();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        // the web middleware stack encrypts the session cookie, which needs a key. A
        // throwaway one is enough: nothing in these tests is a secret and
        // nothing is decoded across processes.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Core's ConfigRepository caches its whole config under the file driver,
        // which writes to disk and survives the process. A cache left behind by an
        // earlier run would hand every later test a config built for a different
        // app: install_dir pointing at the testbench skeleton, and values that
        // arrive as stdClass where arrays are expected, which is how the menu
        // builder once died on "Undefined property: stdClass::$type". The array
        // driver keeps a run hermetic: nothing is written, nothing is inherited.
        $app['config']->set('cache.default', 'array');

        // the provider is not booted here, so the view namespace it would
        // register has to be added by hand for the render test
        $app['view']->addNamespace(
            IpamPluginProvider::PLUGIN_NAME,
            dirname(__DIR__) . '/resources/views'
        );

        // The pages are full LibreNMS pages, so they use core's own blade
        // components: <x-panel>, <x-option-bar>, <x-tabs>. In the real install
        // those resolve from core's view path, which the test app does not have.
        // Adding it means the tests compile the same markup the browser gets
        // rather than a stand-in, so a component that is used wrongly fails here
        // instead of in production.
        $librenmsViews = LIBRENMS_PATH . '/resources/views';

        if (is_dir($librenmsViews)) {
            $app['view']->addLocation($librenmsViews);
        }

        // Core registers this in ComposerServiceProvider, which the test app does
        // not load. It is what supplies $pagetitle and $show_menu to the layout,
        // so without it every page dies on an undefined variable before it
        // renders a single row.
        //
        // show_menu is false here, which skips the include of layouts.menu. The
        // menu is core's and pulls in half of the schema to build itself
        // (services, sensors, nac, routing, alerts, packages, ...). Rebuilding all
        // of that as fixtures would test core's menu, not this plugin, and would
        // break on every new table core's menu reads. The layout itself still
        // renders in full: head, css, vite, body, @yield('content'), which is
        // exactly the chrome the plugin pages have to sit inside.
        $app['view']->composer('layouts.librenmsv1', function ($view): void {
            $view->with('pagetitle', 'LibreNMS')->with('show_menu', false);
        });

        // The layout reads LibrenmsConfig for the title suffix, the stylesheet and
        // the favicon. Core binds it in ConfigServiceProvider, which is not loaded
        // here either. A real ConfigRepository reads the yaml files under
        // LIBRENMS_PATH, which is what makes the rendered page match what a
        // browser would get rather than being stubbed into always matching.
        if (! $app->bound('librenms-config')) {
            $app->singleton('librenms-config', fn () => new ConfigRepository);
        }

        // The alias the layout uses unqualified, "LibrenmsConfig::get(...)". It
        // is declared in core's config/app.php, and testbench does not read that
        // file, so the name has to be bound here or the layout throws
        // "Class LibrenmsConfig not found".
        if (! class_exists('LibrenmsConfig', false)) {
            class_alias(LibrenmsConfig::class, 'LibrenmsConfig');
        }

        // The rest of core's aliases, for the same reason: the layout and the menu
        // builder reach for Permissions and PluginManager by their short names.
        foreach ([
            'DeviceCache' => DeviceCache::class,
            'Permissions' => Permissions::class,
            'PortCache' => PortCache::class,
            'PluginManager' => PluginManager::class,
            'Rrd' => Rrd::class,
        ] as $alias => $class) {
            if (! class_exists($alias, false)) {
                class_alias($class, $alias);
            }
        }

        // Those facades resolve through the container, and the services they name
        // are bound in core's AppServiceProvider, which is not loaded here. The
        // real classes are used, not stubs: the menu reads the permission cache
        // to decide what to show, and stubbing it would make the render test
        // pass while the page it tests is broken in a different install.
        if (! $app->bound('permissions')) {
            $app->scoped('permissions', fn () => new PermissionsCache);
        }

        if (! $app->bound('device-cache')) {
            $app->scoped('device-cache', fn () => new Device);
        }

        if (! $app->bound('port-cache')) {
            $app->scoped('port-cache', fn () => new Port);
        }

        // The layout names core's route that publishes its javascript route
        // table. Testbench loads none of core's routes, so the name is unknown
        // and route() throws before any markup is produced. Registering the name
        // only, pointing nowhere, is enough to let the layout render.
        foreach (['js.routes'] as $name) {
            if (! Route::has($name)) {
                Route::get($name, fn (): string => '')
                    ->name($name);
            }
        }

        // Core's docroot is html/, not public/: the vite manifest, the stylesheets
        // and every icon the layout refers to live there. The test app's own
        // docroot is a testbench skeleton, so @vite would throw on a missing
        // manifest. Pointing at core's real docroot means asset() and @vite
        // resolve to what the browser actually downloads.
        $docroot = LIBRENMS_PATH . '/html';

        if (is_dir($docroot)) {
            $app->usePublicPath($docroot);
        }

        // Same story for the menu: it is built by MenuComposer, which reads the
        // user's style preference and the plugin list. Skipped instead, see the
        // composer above for why.

        $app['translator']->addNamespace(
            IpamPluginProvider::PLUGIN_NAME,
            dirname(__DIR__) . '/resources/lang'
        );

        // spatie's permission service provider is not booted here, so the
        // permission model class names it registers are set by hand. Without
        // them Gate::allows() throws on a missing service, which would look like
        // a fault in the page rather than in the fixture.
        $app['config']->set('permission.models.permission', Permission::class);
        $app['config']->set('permission.models.role', Role::class);
        $app['config']->set('permission.models.model', User::class);

        // Without these spatie falls back to its own defaults, which name the
        // pivots permission_role and role_has_permissions, so the tables created
        // below and the ones it queries would not be the same tables.
        foreach ([
            'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        ] as $table) {
            $app['config']->set("permission.table_names.$table", $table);
        }

        // LibreNMS binds its own permissions cache, which the address and
        // collision pages consult to hide devices a user may not see. Here it
        // answers "everything", which is what the pages expect for an admin.
        $app->scoped('permissions', fn () => new class
        {
            /**
             * @return Collection<int, int>
             */
            public function devicesForUser(User $user): Collection
            {
                return collect();
            }

            /**
             * @return Collection<int, int>
             */
            public function portsForUser(User $user): Collection
            {
                return collect();
            }
        });

        // the registrar reads these when it binds permissions to roles, and
        // leaves them null when the config is missing, which it then complains
        // about. Set here so the pages can be exercised without booting all of
        // LibreNMS's providers.
        $app['config']->set('permission.display.permission_in_exception', false);
        $app['config']->set('permission.display.role_in_exception', false);
        $app['config']->set('permission.cache.store', 'array');
        $app['config']->set('permission.cache.expiration_time', Carbon::now()->addMinutes(5));
        $app['config']->set('permission.cache.key', 'spatie.permission.cache');
    }

    protected function defineDatabaseMigrations(): void
    {
        // The plugins table is LibreNMS's, but every page reads the plugin's
        // stored settings from it. The one row below stands in for the whole
        // thing: the settings column is null, which the plugin treats exactly as
        // an absent settings array.
        Schema::create('plugins', function ($table): void {
            $table->increments('plugin_id');
            $table->string('plugin_name', 60);
            $table->boolean('plugin_active')->default(true);
            $table->integer('version')->default(2);
            $table->text('settings')->nullable();
        });

        DB::table('plugins')->insert([
            'plugin_name' => IpamPluginProvider::PLUGIN_NAME,
            'plugin_active' => 1,
            'version' => 2,
            'settings' => null,
        ]);

        // spatie's tables, so Gate::allows() can answer for a real user instead
        // of every test having to define the gates by hand. The addresses page
        // asks whether the user may see all devices, which goes through here.
        foreach (['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'] as $table) {
            if (Schema::hasTable($table)) {
                continue;
            }

            Schema::create($table, match ($table) {
                'permissions' => function ($t): void {
                    $t->bigIncrements('id');
                    $t->string('name');
                    $t->string('guard_name');
                    $t->timestamps();
                },
                'roles' => function ($t): void {
                    $t->bigIncrements('id');
                    $t->string('name');
                    $t->string('guard_name');
                    $t->timestamps();
                },
                'role_has_permissions' => function ($t): void {
                    $t->unsignedBigInteger('permission_id');
                    $t->unsignedBigInteger('role_id');
                },
                'model_has_roles' => function ($t): void {
                    $t->unsignedBigInteger('role_id');
                    $t->unsignedBigInteger('model_id');
                    $t->string('model_type');
                },
                default => function ($t): void {
                    $t->unsignedBigInteger('permission_id');
                    $t->unsignedBigInteger('model_id');
                    $t->string('model_type');
                },
            });
        }

        // Only the plugin's own permissions are inserted. LibreNMS's device
        // permissions (viewAll and the rest) are abilities the pages ask the Gate
        // about directly, and putting a row of the same name here would make
        // spatie's before-callback answer for them and shadow the Gate::define
        // the tests rely on.
        DB::table('permissions')->insert(array_map(
            fn (string $name): array => ['name' => $name, 'guard_name' => 'web'],
            IpamPermissions::all()
        ));

        // the role grant is what makes Gate::allows() answer, so it goes on
        // the role rather than straight to the user
        $roleId = (int) DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);

        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_id' => 1,
            'model_type' => User::class,
        ]);

        foreach (IpamPermissions::all() as $name) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => (int) DB::table('permissions')->where('name', $name)->value('id'),
                'role_id' => $roleId,
            ]);
        }
    }
}
