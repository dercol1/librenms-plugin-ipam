<?php

namespace Dercol1\LibrenmsIpam\Tests;

use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * A stand-in for LibreNMS's PluginManagerInterface.
 *
 * The provider asks for the real one, which only core binds. This records what
 * was published instead of publishing it, which is what the provider tests need
 * to assert on. Its own static, rather than the test case's, so an anonymous
 * class can reach it.
 */
class RecordingPluginManager implements PluginManagerInterface
{
    /** @var list<array{plugin: string, hook: string, class: class-string}> */
    public static array $published = [];

    public static bool $enabled = true;

    public static function reset(): void
    {
        self::$published = [];
        self::$enabled = true;
    }

    public function publishHook(string $pluginName, string $hookType, string $implementationClass): bool
    {
        self::$published[] = [
            'plugin' => $pluginName,
            'hook' => $hookType,
            'class' => $implementationClass,
        ];

        return true;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function hasHooks(string $hookType, array $args = [], ?string $plugin = null): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int, mixed>
     */
    public function call(string $hookType, array $args = [], ?string $plugin = null): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(string $pluginName): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function setSettings(string $pluginName, array $settings): bool
    {
        return true;
    }

    public function pluginExists(string $pluginName): bool
    {
        return true;
    }

    public function pluginEnabled(string $pluginName): bool
    {
        return self::$enabled;
    }

    public function cleanupPlugins(): void {}
}
