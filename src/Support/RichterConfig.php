<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\BenchmarkCase;

/**
 * Typed access to the `richter.php` config values. Config reads return `mixed`; funnelling them
 * through here keeps the runtime validation and defaults in one place.
 */
final class RichterConfig
{
    public static function baseRef(mixed $option = null): string
    {
        if (is_string($option) && $option !== '') {
            return $option;
        }

        $configured = config('richter.default_base');

        return is_string($configured) && $configured !== '' ? $configured : 'origin/main';
    }

    /** @return list<string> */
    public static function dispatchHelpers(): array
    {
        return self::stringList(config('richter.dispatch_helpers'));
    }

    /** @return list<string>|null null when not configured — callers fall back to their own default */
    public static function entryPointRoots(): ?array
    {
        $roots = config('richter.entry_point_roots');

        return $roots === null ? null : self::stringList($roots);
    }

    /** @return list<BenchmarkCase> */
    public static function benchmarkCases(): array
    {
        $cases = config('richter.benchmark_cases');

        if (! is_array($cases)) {
            return [];
        }

        return array_map(BenchmarkCase::fromArray(...), array_values($cases));
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
