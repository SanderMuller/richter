<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The fields a frontend source sends, read send-shaped only: an object-literal key (`{ title: … }`,
 * `{ 'title': … }`, the `{ title }` shorthand), a `FormData`/`URLSearchParams` `append`/`set` with the
 * name as a string literal, a bracket write (`payload['title'] = …`), and a dotted assignment
 * (`form.title = …`). Shared by both request-side lanes.
 *
 * The object-literal pattern over-matches: a middle argument (`save(id, title, body)`), an array literal, an
 * import list, a destructure, a TS type literal and a Vue `{{ title }}` interpolation all take its
 * shape. The lanes accept that, because a finding still needs a validation site naming the same
 * field, and the ignore entry is the escape hatch.
 *
 * @internal
 */
final class SentFields
{
    /**
     * The four {@see sends()} shapes, each capturing the name in group 1. The object-literal key is a
     * quoted name or a JS identifier; `(?|` is a branch reset, so each alternative captures into the
     * same group.
     */
    private const array LISTING_PATTERNS = [
        '/[{,]\s*(?|\'([^\']+)\'|"([^"]+)"|([A-Za-z_$][\w$]*))(?=\s*[,}:])/',
        '/\.(?:append|set)\(\s*[\'"]([^\'"]+)[\'"]/',
        '/\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*=[^=]/',
        '/\.([A-Za-z_$][\w$]*)\s*=[^=]/',
    ];

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

        foreach (self::LISTING_PATTERNS as $pattern) {
            preg_match_all($pattern, $source, $matches);
            $names = [...$names, ...$matches[1]];
        }

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
}
