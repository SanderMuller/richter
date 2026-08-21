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
     * How much of a statically-called class {@see SecondHopWalk} reads: `none`, `methods` or `class`.
     *
     * A scope, not a depth: the static-call tracer runs per file over the whole app, so every
     * statically-called method is already known before the walk starts. There is no chain to follow
     * one hop at a time — one round covers all of them. What the scope widens is how much of each
     * class that round reads.
     *
     * `false` / `true` keep their meanings (`none` / `methods`) so a published config file keeps
     * working; `'class'` is the added tier. One spelling per tier — `'methods'` is refused, so two
     * config files cannot describe the same behaviour two ways.
     *
     * @return 'none'|'methods'|'class'
     */
    public static function secondHopScope(): string
    {
        $value = config('richter.second_hop');

        if ($value === null || $value === true) {
            return 'methods';
        }

        if ($value === false) {
            return 'none';
        }

        if ($value !== 'class') {
            throw new InvalidArgumentException("The richter.second_hop config value must be true, false, or 'class'.");
        }

        return 'class';
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

    public static function hazardsEnabled(): bool
    {
        $value = config('richter.hazards.enabled');

        if ($value === null) {
            return true;
        }

        if (! is_bool($value)) {
            throw new InvalidArgumentException('The richter.hazards.enabled config value must be a boolean.');
        }

        return $value;
    }

    /** @return list<string> */
    public static function hazardsIgnore(): array
    {
        return self::stringList('richter.hazards.ignore') ?? [];
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

        $commit = trim($result->output());

        // Empty output with a zero exit is not a resolution. Returning it would leave the diff range
        // as `base...` and read as committed-tree mode, so the run would analyse a ref it never found.
        if (! $result->successful() || $commit === '') {
            throw new InvalidArgumentException("Git ref \"{$option}\" could not be resolved to a commit.");
        }

        return $commit;
    }

    private static function refOrFail(string $ref): string
    {
        if (str_starts_with($ref, '-')) {
            throw new InvalidArgumentException("Git ref \"{$ref}\" may not start with \"-\".");
        }

        return $ref;
    }
}
