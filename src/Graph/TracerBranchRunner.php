<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use Illuminate\Support\Facades\Process;
use SanderMuller\Richter\Support\RichterConfig;
use Throwable;

/**
 * Runs richter's tracer branch (Branch B) in a child `artisan` process so it overlaps Brain's
 * analyze() in {@see CodeGraphBuilder::build()}. Two-phase: {@see start()} launches the worker
 * before Branch A runs; {@see finish()} awaits and reads its result after. Any failure returns null
 * so the caller falls back to the in-process branch — this is advisory tooling and must never fail
 * closed on a fork hiccup (plan 050).
 *
 * @internal
 */
final class TracerBranchRunner
{
    private const int TIMEOUT_SECONDS = 600;

    private const string COMMAND = 'richter:internal-tracer-branch';

    /**
     * Launch the worker when eligible. Returns null (→ caller runs the branch in-process) when
     * parallelism is disabled, a progress listener is attached (profiling needs the in-process phase
     * events), or there is no `artisan` entrypoint to spawn (a constrained test/runtime env).
     */
    public static function start(string $projectRoot, ?callable $onProgress): ?PendingTracerBranch
    {
        if (! RichterConfig::parallel() || $onProgress !== null) {
            return null;
        }

        $artisan = base_path('artisan');

        if (! is_file($artisan)) {
            return null;
        }

        $out = @tempnam(sys_get_temp_dir(), 'richter-tracer-');

        if ($out === false) {
            return null;
        }

        try {
            $process = Process::path(base_path())
                ->timeout(self::TIMEOUT_SECONDS)
                ->start([PHP_BINARY, $artisan, self::COMMAND, "--project={$projectRoot}", "--out={$out}"]);
        } catch (Throwable) {
            @unlink($out);

            return null;
        }

        return new PendingTracerBranch($process, $out);
    }

    /**
     * Await the worker and read its JSON result. Returns null on any failure (non-zero exit, missing
     * or malformed output) — the caller then rebuilds the branch in-process. The temp file is always
     * cleaned up.
     *
     * @return array{edges: list<array{source: string, target: string, type: string}>, unparseableFiles: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, inheritance: array<string, array{parent: string|null, declared: list<string>}>, declares: array<string, list<array{source: string, target: string, type: string}>>}|null
     */
    public static function finish(PendingTracerBranch $pending): ?array
    {
        try {
            $result = $pending->process->wait();

            if (! $result->successful() || ! is_file($pending->outPath)) {
                return null;
            }

            $decoded = json_decode((string) @file_get_contents($pending->outPath), true);
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($pending->outPath);
        }

        return self::validate($decoded);
    }

    /**
     * Strictly validate the decoded payload. A mis-shaped result returns null (→ serial fallback)
     * rather than risking a wrong graph from a truncated or corrupt file — a slow-but-correct build
     * beats a fast-but-wrong one.
     *
     * @return array{edges: list<array{source: string, target: string, type: string}>, unparseableFiles: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, inheritance: array<string, array{parent: string|null, declared: list<string>}>, declares: array<string, list<array{source: string, target: string, type: string}>>}|null
     */
    private static function validate(mixed $decoded): ?array
    {
        if (! is_array($decoded)
            || ! is_array($decoded['edges'] ?? null)
            || ! array_is_list($decoded['edges'])
            || ! is_array($decoded['inheritance'] ?? null)
            || ! is_array($decoded['declares'] ?? null)
            || ! is_int($decoded['unparseableFiles'] ?? null)
            || $decoded['unparseableFiles'] < 0
            || ! self::isSiteList($decoded['unresolvedDispatchSites'] ?? null)) {
            // A negative count, a non-list edges map, or a site record missing a field is impossible
            // from the worker — treat it as corruption and fall back to the serial build.
            return null;
        }

        /** @var list<array{file: string, line: int, dispatcher: string}> $sites */
        $sites = $decoded['unresolvedDispatchSites'];
        $decoded['unresolvedDispatchSites'] = $sites;

        $edges = [];

        foreach ($decoded['edges'] as $edge) {
            if (! is_array($edge)
                || ! is_string($edge['source'] ?? null)
                || ! is_string($edge['target'] ?? null)
                || ! is_string($edge['type'] ?? null)) {
                return null;
            }

            $edges[] = ['source' => $edge['source'], 'target' => $edge['target'], 'type' => $edge['type']];
        }

        $inheritance = [];

        // Same fail-closed reading as the edges above: a mis-shaped record means the worker's output
        // cannot be trusted, and a wrong inheritance map would draw edges to methods that do not run.
        foreach ($decoded['inheritance'] as $class => $record) {
            if (! is_string($class) || ! is_array($record)) {
                return null;
            }

            $parent = $record['parent'] ?? null;
            $declared = $record['declared'] ?? null;

            if (($parent !== null && ! is_string($parent))
                || ! is_array($declared)
                || ! array_is_list($declared)
                || ! array_all($declared, static fn (mixed $method): bool => is_string($method))) {
                return null;
            }

            /** @var list<string> $declared */
            $inheritance[$class] = ['parent' => $parent, 'declared' => $declared];
        }

        $declares = [];

        // Same fail-closed reading again: these become `declares` edges in the merged graph, so a
        // mis-shaped entry would put a member node under a class that does not declare it.
        foreach ($decoded['declares'] as $class => $classEdges) {
            if (! is_string($class) || ! is_array($classEdges) || ! array_is_list($classEdges)) {
                return null;
            }

            $declares[$class] = [];

            foreach ($classEdges as $edge) {
                if (! is_array($edge)
                    || ! is_string($edge['source'] ?? null)
                    || ! is_string($edge['target'] ?? null)
                    || ! is_string($edge['type'] ?? null)) {
                    return null;
                }

                $declares[$class][] = ['source' => $edge['source'], 'target' => $edge['target'], 'type' => $edge['type']];
            }
        }

        return [
            'edges' => $edges,
            'unparseableFiles' => $decoded['unparseableFiles'],
            'unresolvedDispatchSites' => $decoded['unresolvedDispatchSites'],
            'inheritance' => $inheritance,
            'declares' => $declares,
        ];
    }

    /**
     * Whether a decoded value is a well-formed unresolved-dispatch site list.
     *
     * Checked field by field rather than trusted: this payload crosses a process boundary as JSON,
     * and a half-formed record would otherwise reach a report and name a file:line that does not
     * exist. A rejection costs the parallel build, never correctness — the caller falls back to the
     * serial one, which produces the same graph.
     *
     * @phpstan-assert-if-true list<array{file: string, line: int, dispatcher: string}> $value
     */
    private static function isSiteList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $site): bool => is_array($site)
                && is_string($site['file'] ?? null)
                && $site['file'] !== ''
                && is_string($site['dispatcher'] ?? null)
                && $site['dispatcher'] !== ''
                && is_int($site['line'] ?? null)
                && $site['line'] >= 1);
    }
}
