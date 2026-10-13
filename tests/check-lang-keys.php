<?php

/*
 * Finds duplicate keys in the translation files.
 *
 * The mistake is invisible on purpose: a php array literal with two identical
 * keys is legal, the second one wins, and require() hands back an array that
 * looks perfectly fine. So the returned array cannot be inspected to find it,
 * because by then the evidence is gone. What is left is to read the tokens.
 *
 * When a flat string and a nested group share a name, the string disappears and
 * __() starts returning an array instead, which blows up inside a blade template
 * on htmlspecialchars() with a message that points nowhere near this file. That
 * cost an afternoon, hence the guard.
 *
 * Reached by tests/TranslationTest.
 */

/**
 * Duplicate keys in one php file, as dotted paths.
 *
 * A key is anything a literal accepts: a bare word, a quoted string, or a
 * constant. A string followed by => is a key, which is how array literals are
 * told apart from array values.
 *
 * @return list<string>
 */
function duplicateKeysInFile(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);

    // one frame per open array literal, holding the keys it already has
    $frames = [];
    $path = [];
    $duplicates = [];
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ($token === '[') {
                $frames[] = [];
                $path[] = null;
                $depth++;

                continue;
            }

            if ($token === ']') {
                array_pop($frames);
                array_pop($path);
                $depth--;

                continue;
            }

            continue;
        }

        [$id, $text] = $token;

        // only string literals and bare words can be keys
        $isKeyCandidate = $id === T_CONSTANT_ENCAPSED_STRING
            || ($id === T_STRING && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $text));

        if (! $isKeyCandidate) {
            continue;
        }

        // is the next meaningful token '=>'? then this one is a key. The arrow is an
        // array token, so it is compared by its id, not by identity.
        $arrowIndex = nextSignificantIndex($tokens, $i);
        $arrow = $arrowIndex === null ? null : ($tokens[$arrowIndex] ?? null);

        if (! is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW) {
            continue;
        }

        $key = $id === T_CONSTANT_ENCAPSED_STRING ? unquote($text) : $text;

        if (! isset($frames[$depth])) {
            $frames[$depth] = [];
        }

        // A path entry is kept only for a group. Recording it for scalars too
        // would leave "bad_cidr" sitting where "missing_tables" belongs, and a
        // clash in the next group would then be reported against the wrong
        // parent. The value of a group is the bracket right after the arrow.
        //
        // Index 0 of $path is the outermost literal, so the enclosing groups of
        // a key at depth $depth are entries 1 through depth - 1.
        $prefix = array_slice($path, 1, max($depth - 1, 0));
        $prefix = array_values(array_filter($prefix, is_string(...)));

        if (isset($frames[$depth][$key])) {
            $duplicates[] = $prefix === [] ? $key : implode('.', $prefix) . ".$key";
        }

        $frames[$depth][$key] = true;

        // the value sits just past the '=>', and for a group that is a bracket
        $afterArrow = nextSignificantIndex($tokens, $arrowIndex);
        $value = $afterArrow === null ? null : ($tokens[$afterArrow] ?? null);
        $path[$depth] = $value === '[' ? $key : null;
    }

    return $duplicates;
}

/**
 * The index of the next token that is not whitespace or a comment.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function nextSignificantIndex(array $tokens, int $from): ?int
{
    $count = count($tokens);

    for ($i = $from + 1; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
}

function unquote(string $literal): string
{
    if (str_starts_with($literal, "'")) {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], trim($literal, "'"));
    }

    return stripcslashes(trim($literal, '"'));
}

/**
 * @param  list<string>  $files
 * @return array<string, list<string>>
 */
function inspectLangFiles(array $files): array
{
    $found = [];

    foreach ($files as $file) {
        $duplicates = duplicateKeysInFile($file);

        if ($duplicates !== []) {
            $found[basename($file)] = array_values(array_unique($duplicates));
        }
    }

    return $found;
}

// only meaningful when run directly, including it just defines the functions
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $root = dirname(__DIR__);

    $files = array_merge(
        glob($root . '/resources/lang/*/*.php') ?: [],
        glob($root . '/resources/lang/*.php') ?: []
    );

    $problems = inspectLangFiles($files);

    foreach ($problems as $name => $duplicates) {
        fwrite(STDERR, sprintf(
            "%s: duplicate keys, the later one silently wins: %s\n",
            $name,
            implode(', ', $duplicates)
        ));
    }

    exit($problems === [] ? 0 : 1);
}
