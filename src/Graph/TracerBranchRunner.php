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
 * closed on a fork hiccup (plan 046).
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
     * @return array{edges: list<array{source: string, target: string, type: string}>, unparseableFiles: int, unresolvedDispatches: int}|null
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
     * @return array{edges: list<array{source: string, target: string, type: string}>, unparseableFiles: int, unresolvedDispatches: int}|null
     */
    private static function validate(mixed $decoded): ?array
    {
        if (! is_array($decoded)
            || ! is_array($decoded['edges'] ?? null)
            || ! is_int($decoded['unparseableFiles'] ?? null)
            || ! is_int($decoded['unresolvedDispatches'] ?? null)) {
            return null;
        }

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

        return [
            'edges' => $edges,
            'unparseableFiles' => $decoded['unparseableFiles'],
            'unresolvedDispatches' => $decoded['unresolvedDispatches'],
        ];
    }
}
