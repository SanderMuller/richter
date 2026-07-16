<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use InvalidArgumentException;
use SanderMuller\Richter\Analysis\BenchmarkCase;

/**
 * Typed access to the `richter.php` config values. Config reads return `mixed`; funnelling them
 * through here keeps the runtime validation and defaults in one place. A mis-shaped value throws
 * rather than degrading — a silently dropped entry would produce the falsely-empty impact report
 * this package exists to prevent.
 */
final class RichterConfig
{
    public static function baseRef(mixed $option = null): string
    {
        if (is_string($option) && $option !== '') {
            return self::refOrFail($option);
        }

        $configured = config('richter.default_base');

        return is_string($configured) && $configured !== '' ? self::refOrFail($configured) : 'origin/main';
    }

    /** @return list<string> */
    public static function dispatchHelpers(): array
    {
        return self::stringList('richter.dispatch_helpers') ?? [];
    }

    /** @return list<string>|null null when not configured — callers fall back to their own default */
    public static function entryPointRoots(): ?array
    {
        return self::stringList('richter.entry_point_roots');
    }

    /** @return list<BenchmarkCase> */
    public static function benchmarkCases(): array
    {
        $cases = config('richter.benchmark_cases');

        if ($cases === null) {
            return [];
        }

        if (! is_array($cases)) {
            throw new InvalidArgumentException('The richter.benchmark_cases config value must be a list of case arrays.');
        }

        return array_map(BenchmarkCase::fromArray(...), array_values($cases));
    }

    /** @return list<string>|null */
    private static function stringList(string $key): ?array
    {
        $value = config($key);

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("The {$key} config value must be a list of strings.");
        }

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new InvalidArgumentException("Every {$key} entry must be a string.");
            }
        }

        return array_values($value);
    }

    /**
     * No legitimate git rev (`origin/main`, `HEAD~3`, a SHA, a tag) starts with `-`; rejecting one
     * here keeps an option-injection attempt (e.g. `--upload-pack=…`) out of every git argv, even
     * if a future call site forgets its `--end-of-options`.
     */
    private static function refOrFail(string $ref): string
    {
        if (str_starts_with($ref, '-')) {
            throw new InvalidArgumentException("Git ref \"{$ref}\" may not start with \"-\".");
        }

        return $ref;
    }
}
