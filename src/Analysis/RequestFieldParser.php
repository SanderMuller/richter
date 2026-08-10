<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Statically enumerates a form request's `rules()` field names from SOURCE — the request-side
 * counterpart to {@see ResourceKeyParser}. A field dropped from `rules()` stops being validated
 * and stops arriving in `validated()`, so a frontend that still sends it now sends it into
 * nothing: no error, no value, the same silent shape the response-side lane covers.
 *
 * Strict-only, unlike the resource parser's two modes. There is no historical caller wanting the
 * lenient reading, and this parser exists exclusively to DIFF two sides of a change — the case
 * where an unkeyed item or a base-side class constant would fabricate a removal.
 *
 * A `rules()` that builds its array up (`$rules = [...]; if (…) …; return $rules;`) is not
 * enumerable and yields nothing. That is a reach limit, never a wrong finding.
 */
final class RequestFieldParser
{
    /**
     * The request-parity lane's diff-time inputs for a changed form-request file: the field names
     * this diff removed from and added to `rules()`. Yields nothing for a non-request path, a
     * brand-new file (no consumer sends a field that never existed), an unreadable base, or a
     * `null` parse on either side.
     *
     * @return array{0: list<string>, 1: list<string>}  [removedFields, addedFields]
     */
    public static function diffFor(string $file, bool $isNew, string $headSrc, ?string $baseSrc): array
    {
        if ($isNew || $baseSrc === null || ! self::isRequestPath($file)) {
            return [[], []];
        }

        $headFields = self::fieldsOf($headSrc);
        $baseFields = self::fieldsOf($baseSrc);

        if ($headFields === null || $baseFields === null) {
            return [[], []];
        }

        return [
            array_values(array_diff($baseFields, $headFields)),
            array_values(array_diff($headFields, $baseFields)),
        ];
    }

    /**
     * Path-prefix matching, never an `App\` FQCN prefix, which would break a non-`App\` root
     * namespace. The directory Laravel's own `make:request` writes to is the whole convention
     * here — a rules() method elsewhere is not addressed by a request payload.
     */
    public static function isRequestPath(string $file): bool
    {
        return str_starts_with($file, 'app/Http/Requests/');
    }

    /**
     * The statically enumerable `rules()` field names of the source, or null when they cannot be
     * vouched for.
     *
     * @return list<string>|null
     */
    public static function fieldsOf(string $source): ?array
    {
        return ArrayReturnKeys::of($source, 'rules', strict: true);
    }
}
