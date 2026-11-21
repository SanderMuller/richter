<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The route names a test source can name, for {@see TestReferenceIndex}: each literal
 * `route('name')`, and, in a test that calls `route($page)` with a name from a data provider, every
 * string literal in the file. Only a literal equal to a registered route name ever matches a route.
 *
 * @internal
 */
final class TestRouteNames
{
    /** @return list<string> */
    public static function in(string $source): array
    {
        $names = preg_match_all('/route\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) > 0 ? $matches[1] : [];

        if (preg_match('/(?<![\w>:$])route\(\s*\$/', $source) === 1 && preg_match_all('/[\'"]([\w.\-]+)[\'"]/', $source, $matches) > 0) {
            array_push($names, ...$matches[1]);
        }

        return array_values(array_unique($names));
    }
}
