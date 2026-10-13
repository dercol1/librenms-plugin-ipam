<?php

use Orchestra\Testbench\TestCase;

/*
 * Test bootstrap.
 *
 * The plugin runs inside an installed LibreNMS, so its tests need that install's
 * autoloader as well as their own. Path is overridable with LIBRENMS_PATH for
 * a checkout that lives somewhere else.
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
require dirname(__DIR__) . '/vendor/autoload.php';

// The LibreNMS root, so a test can reach into the install rather than guessing.
// The UnitTestCase needs it for core's blade component directory: the plugin
// pages extend the core layout and use <x-panel> and <x-option-bar>, and the
// tests have to compile the same markup the browser gets.
define('LIBRENMS_PATH', $librenms);

// the translation key checker, plain functions the TranslationTest calls
require __DIR__ . '/check-lang-keys.php';

// orchestra/testbench is what lets the database backed tests boot a Laravel app
// without dragging the whole of LibreNMS's service provider list in
if (! class_exists(TestCase::class)) {
    fwrite(STDERR, "orchestra/testbench is missing, run composer install.\n");

    exit(1);
}
