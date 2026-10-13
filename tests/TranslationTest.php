<?php

namespace Dercol1\LibrenmsIpam\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The translation files, checked for the one mistake that cannot be seen.
 *
 * A php array literal with two identical keys is legal: the second wins and
 * nothing complains. When a flat string and a nested group share a name, the
 * string vanishes and __() starts returning an array, which blows up inside a
 * blade template on htmlspecialchars() with a message that points nowhere near
 * the lang file. That cost an afternoon once, so it gets a test.
 */
class TranslationTest extends TestCase
{
    public function test_no_translation_key_is_used_twice(): void
    {
        $found = inspectLangFiles($this->langFiles());

        $this->assertSame(
            [],
            $found,
            "duplicate translation keys, the later one silently wins:\n"
            . implode("\n", array_map(
                fn (string $name, array $keys): string => "  $name: " . implode(', ', $keys),
                array_keys($found),
                array_values($found)
            ))
        );
    }

    /**
     * @return list<string>
     */
    private function langFiles(): array
    {
        $root = dirname(__DIR__);

        return array_values(array_filter(array_merge(
            glob($root . '/resources/lang/*/*.php') ?: [],
            glob($root . '/resources/lang/*.php') ?: []
        ), 'is_readable'));
    }
}
