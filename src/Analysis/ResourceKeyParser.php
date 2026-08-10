<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

/**
 * Statically enumerates an API resource's `toArray()` keys from SOURCE, in two modes.
 *
 * Default mode is {@see PayloadParityChecker}'s historical behaviour, byte-for-byte: an
 * unkeyed item (`$this->mergeWhen(...)` and friends) is skipped, and a class-constant key
 * resolves through the autoloaded codebase. That is safe in the model→resource direction,
 * where a dropped key can at worst change which findings fire — never fabricate one from
 * a key that is actually emitted conditionally.
 *
 * Strict mode serves the classification-time key DIFF (consumer parity), where those two
 * behaviours would fabricate findings: a key moved *into* `mergeWhen` must not read as
 * removed, and a base-side constant key must never resolve against the *head* codebase.
 * Literal string keys only — anything else aborts to null, and null means silence.
 *
 * The enumeration itself lives in {@see ArrayReturnKeys}, which the form-request lane asks
 * the same question of a different method.
 */
final class ResourceKeyParser
{
    /**
     * The consumer-parity lane's diff-time inputs for a changed resource file: the keys
     * this diff removed from and added to `toArray()`, strict-mode-parsed. Yields nothing
     * for a non-resource path (path-prefix matching — never an `App\` FQCN prefix, which
     * would break non-`App\` root namespaces), a brand-new file (no consumer relies on a
     * key that never shipped), an unreadable base, or a `null` strict parse on either
     * side (a deleted file's head degrades to `''`, which parses to null).
     *
     * @return array{0: list<string>, 1: list<string>}  [removedKeys, addedKeys]
     */
    public static function diffFor(string $file, bool $isNew, string $headSrc, ?string $baseSrc): array
    {
        if ($isNew || $baseSrc === null || ! self::isResourcePath($file)) {
            return [[], []];
        }

        $headKeys = self::keysOf($headSrc, strict: true);
        $baseKeys = self::keysOf($baseSrc, strict: true);

        if ($headKeys === null || $baseKeys === null) {
            return [[], []];
        }

        return [
            array_values(array_diff($baseKeys, $headKeys)),
            array_values(array_diff($headKeys, $baseKeys)),
        ];
    }

    /**
     * The two directories {@see ReferenceEdgeTracer} maps
     * to the `resource` edge, as paths.
     */
    public static function isResourcePath(string $file): bool
    {
        return str_starts_with($file, 'app/Http/Resources/') || str_starts_with($file, 'app/Transformers/');
    }

    /**
     * The statically enumerable `toArray()` keys of the source, or null when they cannot
     * be vouched for: no single `toArray()`, a body richer than one `return [...]`, a
     * spread, a dynamic key — and in strict mode any non-literal-string key or unkeyed
     * item (see the class docblock for why the modes differ).
     *
     * @return list<string>|null
     */
    public static function keysOf(string $source, bool $strict = false): ?array
    {
        return ArrayReturnKeys::of($source, 'toArray', $strict);
    }
}
