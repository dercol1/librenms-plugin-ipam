<?php

/*
 * Bootstrap for the tests and for phpstan.
 *
 * The plugin is developed against an installed LibreNMS, so the autoloader has
 * to know about both. That is why the paths point at /opt/librenms by default:
 * point them somewhere else with the LIBRENMS_PATH environment variable if the
 * checkout lives elsewhere.
 */

$librenms = getenv('LIBRENMS_PATH') ?: '/opt/librenms';

$autoload = $librenms . '/vendor/autoload.php';

if (! is_readable($autoload)) {
    fwrite(
        STDERR,
        "Cannot find LibreNMS at $librenms. Set LIBRENMS_PATH to the directory holding vendor/autoload.php.\n"
    );

    exit(1);
}

require $autoload;

// The LibreNMS root, for the test cases that reach into the install: its blade
// component directory, its docroot, the yaml of its config. Same value as the
// phpunit bootstrap defines, and for the same reason.
define('LIBRENMS_PATH', $librenms);

// the plugin's own classes, for a test run that has not gone through composer
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Dercol1\\LibrenmsIpam\\Tests\\' => __DIR__ . '/',
        'Dercol1\\LibrenmsIpam\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require $file;

            return;
        }
    }
});
