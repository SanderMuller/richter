<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Changes\EloquentConfig;
use SanderMuller\Richter\Changes\MemberChange;

/**
 * A model's write and read surface. `$hidden` narrowed is tier 3 — a field that was never serialised
 * now is, which is a disclosure, not a contract change. Everything else here is tier 2.
 *
 * Comparison is by AST-canonical key and value ({@see EloquentConfig::configMap()}), never by text,
 * so reordering or reformatting an array draws nothing. Anything non-enumerable — a spread, an
 * unresolvable constant, a member two classes in the file declare — yields a null map and the lane
 * stays silent, matching that class's existing no-guess rule.
 */
final class ModelHazardLane implements HazardLane
{
    public static function for(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/Models/')) {
            return [[], []];
        }

        $fqcn = array_key_first(HazardSource::classLikes($headSrc)) ?? '';

        if ($fqcn === '') {
            return [[], []];
        }

        return [[
            ...self::widened($fqcn, $headSrc, $baseSrc, 'fillable', 'CWE-915', '$fillable gained'),
            ...self::narrowed($fqcn, $headSrc, $baseSrc, 'guarded', 2, 'CWE-915', '$guarded no longer blocks'),
            ...self::narrowed($fqcn, $headSrc, $baseSrc, 'hidden', 3, 'CWE-200', '$hidden no longer hides'),
            ...self::castsChanged($fqcn, $headSrc, $baseSrc),
        ], []];
    }

    /**
     * `$fillable` gaining an entry widens what a mass assignment may write. This is the one place the
     * hazard family contradicts an ADDITION being harmless: `EloquentConfig` classifies an
     * addition-only `$fillable` edit as additive so it seeds nothing, and it stays additive — the
     * hazard rides alongside rather than changing the seeding.
     *
     * @return list<Hazard>
     */
    private static function widened(string $fqcn, string $headSrc, string $baseSrc, string $name, ?string $cwe, string $label): array
    {
        [$head, $base] = self::maps($headSrc, $baseSrc, $name, MemberChange::KIND_PROPERTY);

        if ($head === null || $base === null) {
            return [];
        }

        $gained = array_values(array_diff(array_keys($head), array_keys($base)));

        return $gained === []
            ? []
            : [new Hazard('model', 2, $cwe, "{$fqcn}::\${$name}", "{$label} " . self::readable($gained), ignoreKey: "{$fqcn}::\${$name}")];
    }

    /**
     * A block-list or hide-list losing entries. `$guarded = []` is the widest possible
     * mass-assignment surface and is reported even though the ARRAY did not lose a named entry — it
     * lost every entry it had.
     *
     * @return list<Hazard>
     */
    private static function narrowed(string $fqcn, string $headSrc, string $baseSrc, string $name, int $tier, ?string $cwe, string $label): array
    {
        [$head, $base] = self::maps($headSrc, $baseSrc, $name, MemberChange::KIND_PROPERTY);

        if ($head === null || $base === null) {
            return [];
        }

        $lost = array_values(array_diff(array_keys($base), array_keys($head)));

        return $lost === []
            ? []
            : [new Hazard('model', $tier, $cwe, "{$fqcn}::\${$name}", "{$label} " . self::readable($lost), ignoreKey: "{$fqcn}::\${$name}")];
    }

    /**
     * A cast changed on a key BOTH sides declare. A key only one side has is an addition or a removal
     * — the parity lane's business and already classified additive — so only a surviving key counts.
     * Both syntaxes are checked, and a model declaring each half in a different syntax is compared
     * within its own syntax rather than across the two.
     *
     * @return list<Hazard>
     */
    private static function castsChanged(string $fqcn, string $headSrc, string $baseSrc): array
    {
        $hazards = [];

        foreach ([[MemberChange::KIND_PROPERTY, '$casts'], [MemberChange::KIND_METHOD, 'casts()']] as [$kind, $label]) {
            [$head, $base] = self::maps($headSrc, $baseSrc, 'casts', $kind);

            if ($head === null || $base === null) {
                continue;
            }

            $changed = array_keys(array_filter(
                $base,
                static fn (string $value, string $key): bool => isset($head[$key]) && $head[$key] !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($changed !== []) {
                $hazards[] = new Hazard('model', 2, null, "{$fqcn}::{$label}", "{$label} changed the cast on " . self::readable($changed), ignoreKey: "{$fqcn}::{$label}");
            }
        }

        return $hazards;
    }

    /** @return array{0: array<string, string>|null, 1: array<string, string>|null} */
    private static function maps(string $headSrc, string $baseSrc, string $name, string $kind): array
    {
        return [EloquentConfig::configMap($headSrc, $name, $kind), EloquentConfig::configMap($baseSrc, $name, $kind)];
    }

    /**
     * Canonical keys back to something a reader recognises — `s:title` is how the comparison stores
     * a string, not how it should print.
     *
     * @param  list<string>  $keys
     */
    private static function readable(array $keys): string
    {
        sort($keys);

        return implode(', ', array_map(
            static fn (string $key): string => (string) preg_replace('/^(s|i|d|c|cc):/', '', $key),
            $keys,
        ));
    }
}
