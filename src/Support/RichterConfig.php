<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use InvalidArgumentException;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Tracers\FeatureGateChecker;

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

    /** @return list<string> `FQCN::method` wrapper allowlist for {@see FeatureGateChecker} */
    public static function featureGateMethods(): array
    {
        return self::stringList('richter.feature_gate_methods') ?? [];
    }

    /** @return string|null the configured editor name, or null when file links are off (the default) */
    public static function editor(): ?string
    {
        $value = config('richter.editor');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The richter.editor config value must be a string editor name or null.');
        }

        return $value;
    }

    /** @return list<string>|null null when not configured — callers fall back to their own default */
    public static function entryPointRoots(): ?array
    {
        return self::stringList('richter.entry_point_roots');
    }

    /** @return list<string> empty when the frontend bridge is off (the default) */
    public static function frontendRoots(): array
    {
        return self::stringList('richter.frontend.roots') ?? [];
    }

    /** @return list<string> */
    public static function frontendGeneratedPaths(): array
    {
        return self::stringList('richter.frontend.generated_paths') ?? ['actions', 'routes', 'wayfinder', 'ziggy.js'];
    }

    /** @return list<string> empty means "derive from the frontend roots" */
    public static function frontendTestPaths(): array
    {
        return self::stringList('richter.frontend.test_paths') ?? [];
    }

    /** @return list<string> project-custom callees merged with the scanner's built-in HTTP/route defaults */
    public static function frontendHttpCallees(): array
    {
        return self::stringList('richter.frontend.http_callees') ?? [];
    }

    public static function frontendPagesPath(): string
    {
        $value = config('richter.frontend.pages_path');

        if ($value === null || $value === '') {
            return 'resources/js/Pages';
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The richter.frontend.pages_path config value must be a string path.');
        }

        return $value;
    }

    public static function cacheEnabled(): bool
    {
        $value = config('richter.cache.enabled');

        if ($value === null) {
            return true;
        }

        if (! is_bool($value)) {
            throw new InvalidArgumentException('The richter.cache.enabled config value must be a boolean.');
        }

        return $value;
    }

    public static function cacheDirectory(): string
    {
        $value = config('richter.cache.directory');

        if ($value === null || $value === '') {
            return storage_path('framework/cache/richter');
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The richter.cache.directory config value must be a string path.');
        }

        return $value;
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
