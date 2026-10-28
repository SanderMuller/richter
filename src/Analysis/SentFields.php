<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The fields a frontend source sends, read send-shaped only: an object-literal key (`{ title: … }`,
 * `{ 'title': … }`, the `{ title }` shorthand), a `FormData`/`URLSearchParams` `append`/`set` with the
 * name as a string literal, a bracket write (`payload['title'] = …`), and a dotted assignment
 * (`form.title = …`). One matcher for both request-side lanes: a field a backend diff stopped
 * validating that a frontend still sends, and a field a frontend diff stopped sending that a backend
 * still requires.
 *
 * The object-literal pattern over-matches: an argument list (`save(id, title)`), an array literal, an
 * import list, a destructure, a TS type literal and a Vue `{{ title }}` interpolation all take its
 * shape. The lanes accept that, because a finding still needs a validation site naming the same
 * field, and the ignore entry is the escape hatch.
 *
 * @internal
 */
final class SentFields
{
    /** A JS identifier, or a quoted name — the two shapes a sent key takes. */
    private const string NAME = '(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z_$][\w$]*))';

    public static function sends(string $source, string $field): bool
    {
        $quoted = preg_quote($field, '/');

        return preg_match('/[{,]\s*[\'"]?' . $quoted . '[\'"]?\s*[,}:]/', $source) === 1
            || preg_match('/\.(?:append|set)\(\s*[\'"]' . $quoted . '[\'"]/', $source) === 1
            || preg_match('/\[\s*[\'"]' . $quoted . '[\'"]\s*\]\s*=[^=]/', $source) === 1
            || preg_match('/\.' . $quoted . '\s*=[^=]/', $source) === 1;
    }

    /**
     * Every name {@see sends()} would accept, listed. The object-literal terminator is a lookahead,
     * not a consumed character: consuming the comma after `id` in `{ id, title, body }` leaves `title`
     * with no leading `[{,]`, and the list would skip every second shorthand key.
     *
     * @return list<string>
     */
    public static function in(string $source): array
    {
        $names = [];

        preg_match_all('/[{,]\s*' . self::NAME . '(?=\s*[,}:])/', $source, $matches, PREG_SET_ORDER);
        $names = [...$names, ...self::captured($matches)];

        preg_match_all('/\.(?:append|set)\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_SET_ORDER);
        $names = [...$names, ...self::captured($matches)];

        preg_match_all('/\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*=[^=]/', $source, $matches, PREG_SET_ORDER);
        $names = [...$names, ...self::captured($matches)];

        preg_match_all('/\.([A-Za-z_$][\w$]*)\s*=[^=]/', $source, $matches, PREG_SET_ORDER);
        $names = [...$names, ...self::captured($matches)];

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Whether the source can send fields no literal key names: an object spread (`{ ...form }`) or a
     * `FormData` built from an argument (`new FormData(el)`). A field gone from the literal keys may
     * still be sent then, so a lane reading "no longer sent" must stay silent.
     */
    public static function opaque(string $source): bool
    {
        return preg_match('/[{,]\s*\.\.\.\s*[A-Za-z_$(]/', $source) === 1
            || preg_match('/new\s+FormData\(\s*[^)\s]/', $source) === 1;
    }

    /**
     * The one non-empty capture of each match — the patterns hold alternative groups, and only the
     * one that matched is set.
     *
     * @param  array<int, array<int, string>>  $matches
     * @return list<string>
     */
    private static function captured(array $matches): array
    {
        $names = [];

        foreach ($matches as $match) {
            foreach (array_slice($match, 1) as $group) {
                if ($group !== '') {
                    $names[] = $group;

                    break;
                }
            }
        }

        return $names;
    }
}
