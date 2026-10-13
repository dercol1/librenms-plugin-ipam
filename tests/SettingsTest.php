<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Dercol1\LibrenmsIpam\Settings;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * How a saved setting wins over the packaged default.
 *
 * A checkbox that is not ticked is simply absent from the posted array, so
 * "absent" and "false" have to be told apart. Getting that wrong would mean
 * unticking a box in the browser silently does nothing.
 */
class SettingsTest extends TestCase
{
    /**
     * The settings read config("ipam.*") for their defaults, so a container with
     * just a config repository is all the fixture needs.
     *
     * @param  array<string, mixed>  $defaults
     */
    private function withDefaults(array $defaults): void
    {
        $app = new Container;
        $app->instance('config', new Repository($defaults));

        Container::setInstance($app);
    }

    public function test_defaults_are_used_when_nothing_was_saved(): void
    {
        $this->withDefaults(['ipam.use_vlan_scope' => true, 'ipam.per_page' => 50]);

        $settings = Settings::fromArray([]);

        $this->assertTrue($settings->useVlanScope());
        $this->assertTrue($settings->importIpv4());
        $this->assertSame(50, $settings->perPage());
    }

    public function test_a_saved_value_wins_over_the_default(): void
    {
        $this->withDefaults(['ipam.use_vlan_scope' => true]);

        $this->assertFalse(Settings::fromArray(['use_vlan_scope' => false])->useVlanScope());
        $this->assertTrue(Settings::fromArray(['use_vlan_scope' => true])->useVlanScope());
    }

    public function test_a_checkbox_value_of_one_is_read_as_true(): void
    {
        $this->withDefaults(['ipam.site_from_device' => false]);

        // this is what arrives from an html checkbox
        $this->assertTrue(Settings::fromArray(['site_from_device' => '1'])->siteFromDevice());
        $this->assertTrue(Settings::fromArray(['site_from_device' => 'on'])->siteFromDevice());
        $this->assertTrue(Settings::fromArray(['site_from_device' => true])->siteFromDevice());
    }

    public function test_the_page_size_is_kept_within_something_sane(): void
    {
        $this->withDefaults([]);

        // an unbounded per page would let one request ask for the whole table
        $this->assertSame(500, Settings::fromArray(['per_page' => 100000])->perPage());
        $this->assertSame(10, Settings::fromArray(['per_page' => 1])->perPage());
        $this->assertSame(25, Settings::fromArray(['per_page' => '25'])->perPage());
    }
}
