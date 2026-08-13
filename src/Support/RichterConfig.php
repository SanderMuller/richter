<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Graph\SecondHopWalk;
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

    /**
     * The configured application root namespace, normalised to a single trailing backslash; null when
     * not set, which leaves {@see AppNamespace::root()} to derive it from `composer.json`. An unusable
     * value throws rather than silently reverting to `App\`: a wrong root makes every app class read
     * as absent from the graph, the falsely-empty report this package exists to prevent.
     */
    public static function rootNamespace(): ?string
    {
        $value = config('richter.root_namespace');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The richter.root_namespace config value must be a namespace prefix string (e.g. "App\\\\") or null.');
        }

        return AppNamespace::normalize($value);
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

    /** Whether to build Brain's branch and richter's tracer branch concurrently (plan 050). */
    public static function parallel(): bool
    {
        $value = config('richter.parallel');

        if ($value === null) {
            return true;
        }

        if (! is_bool($value)) {
            throw new InvalidArgumentException('The richter.parallel config value must be a boolean.');
        }

        return $value;
    }

    /**
     * Whether to read the bodies of statically-called methods ({@see SecondHopWalk}).
     *
     * A boolean, not a depth: the static-call tracer runs per file over the whole app, so every
     * statically-called method is already known before the walk starts. There is no chain to follow
     * one hop at a time — one round covers all of them.
     */
    public static function secondHopEnabled(): bool
    {
        $value = config('richter.second_hop');

        if ($value === null) {
            return true;
        }

        if (! is_bool($value)) {
            throw new InvalidArgumentException('The richter.second_hop config value must be a boolean.');
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

    public static function payloadParityEnabled(): bool
    {
        $value = config('richter.payload_parity.enabled');

        if ($value === null) {
            return true;
        }

        if (! is_bool($value)) {
            throw new InvalidArgumentException('The richter.payload_parity.enabled config value must be a boolean.');
        }

        return $value;
    }

    public static function payloadParityMirrorThreshold(): float
    {
        $value = config('richter.payload_parity.mirror_threshold');

        if ($value === null) {
            return 1.0;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('The richter.payload_parity.mirror_threshold config value must be a number between 0 and 1.');
        }

        $threshold = (float) $value;

        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new InvalidArgumentException('The richter.payload_parity.mirror_threshold config value must be a number between 0 and 1.');
        }

        return $threshold;
    }

    /** @return list<string> */
    public static function payloadParityIgnore(): array
    {
        return self::stringList('richter.payload_parity.ignore') ?? [];
    }

    /**
     * The counts at which the advisory risk level steps up, defaults applied per key.
     *
     * Configurable because the defaults saturate on a large codebase: where a routine change reaches
     * thousands of nodes, `impacted >= 20` is met by everything and the level stops discriminating.
     * They stay ABSOLUTE rather than becoming a percentile of the graph — a gate whose meaning shifts
     * with the repo's own distribution is not a gate anyone can reason about in CI.
     *
     * Calibrate `high` before `medium`: raising `high` leaves the `medium` arm of {@see
     * ImpactAnalyzer::risk()} untouched, so it can only demote to `medium`, while raising `medium` is
     * the only edit that can reach `low`. Documented at the config key, because that is where someone
     * about to get it wrong looks.
     *
     * @return array{high: array{entry_points: int, impacted: int}, medium: array{entry_points: int, impacted: int}}
     */
    public static function riskThresholds(): array
    {
        return [
            'high' => [
                'entry_points' => self::positiveThreshold('richter.risk_thresholds.high.entry_points', 3),
                'impacted' => self::positiveThreshold('richter.risk_thresholds.high.impacted', 20),
            ],
            'medium' => [
                'entry_points' => self::positiveThreshold('richter.risk_thresholds.medium.entry_points', 1),
                'impacted' => self::positiveThreshold('richter.risk_thresholds.medium.impacted', 5),
            ],
        ];
    }

    /** A threshold must be a positive int: zero would make every diff meet it, including an empty one. */
    private static function positiveThreshold(string $key, int $default): int
    {
        $value = config($key);

        if ($value === null) {
            return $default;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("The {$key} config value must be an integer of 1 or more.");
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
    /**
     * The ref whose tree a run analyses, or the literal `HEAD` for the working tree.
     *
     * Unset means the working tree — staged and unstaged edits included — which is what a developer
     * running this before committing needs. An explicit ref means that ref's *committed* tree, which
     * is what a run in a dirty checkout needs when the uncommitted work is not the subject.
     *
     * The value is resolved to a commit id rather than passed through, so `--head=HEAD` means the
     * commit rather than the working tree: {@see ChangedSymbols::resolveWithScope()} keys the
     * working-tree mode on the literal string, and a flag that silently did nothing for the most
     * obvious value anyone would type is worse than no flag.
     */
    public static function headRef(mixed $option = null): string
    {
        if (! is_string($option) || $option === '') {
            return 'HEAD';
        }

        $result = Process::path(base_path())->run(['git', 'rev-parse', '--verify', '--end-of-options', self::refOrFail($option) . '^{commit}']);

        if (! $result->successful()) {
            throw new InvalidArgumentException("Git ref \"{$option}\" could not be resolved to a commit.");
        }

        return trim($result->output());
    }

    private static function refOrFail(string $ref): string
    {
        if (str_starts_with($ref, '-')) {
            throw new InvalidArgumentException("Git ref \"{$ref}\" may not start with \"-\".");
        }

        return $ref;
    }
}
