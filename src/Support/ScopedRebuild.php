<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

/**
 * Whether a cached graph can serve as the merge base for a scoped Brain rebuild, and over which files.
 *
 * Brain's `ProjectAnalyzer::scopedTo()` re-traces only the controllers declared in the files it is
 * given and carries everything else over from a previous graph. It checks one precondition itself —
 * that the changed files' own call graph survived the edit — and leaves the rest to the caller: no
 * file added or deleted, nothing outside `app/` moved.
 *
 * This answers that caller half, by comparing the input record the cached entry was built from against
 * the current one. That comparison is strictly better than reading the git diff: the diff describes the
 * branch, while the record describes what actually differs from the graph *on disk*, and the two come
 * apart as soon as the working tree moves on after a cache write.
 *
 * Conservative in every ambiguous case. A wrong "no" costs one full build — today's behaviour for
 * every run. A wrong "yes" produces a graph that is quietly missing edges, which is the failure this
 * package exists to prevent.
 */
final class ScopedRebuild
{
    /**
     * Absolute paths to re-trace, or null when a scoped rebuild is not sound.
     *
     * `$provenanceFiles` is the set of file paths the previous Brain graph attributes nodes to, and
     * every scoped path must be one of them. This is not belt-and-braces — it is load-bearing, and
     * it is the difference between a correct scoped build and a silently stale one:
     *
     * Brain matches paths two different ways. Its controller filter realpaths both sides, so any
     * form works there. Its soundness check compares the given paths *verbatim* against each node's
     * `data['file']`. A path that matches nothing therefore yields an EMPTY owned-edge set on both
     * sides of that check — which compares equal, so the check passes, nothing is substituted, and
     * the previous graph is returned as though it were current. On macOS a plain `realpath()` is
     * enough to trigger it, since `/var` resolves to `/private/var` while Brain's provenance keeps
     * the unresolved form.
     *
     * So the paths returned here are in the provenance's own form, and one that is absent from it
     * refuses the whole scope rather than quietly shrinking it.
     *
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}|null  $previous  the record the cached graph was built from
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}  $current
     * @param  array<string, true>  $provenanceFiles  files the previous Brain graph attributes nodes to
     * @return list<string>|null
     */
    public static function filesFor(?array $previous, array $current, string $projectRoot, array $provenanceFiles = []): ?array
    {
        if ($previous === null) {
            return null;
        }

        // A version, config or namespace change can alter edges the file hashes never see, so the
        // previous graph is not a base for anything.
        if ($previous['nonFile'] !== $current['nonFile']) {
            return null;
        }

        // Brain names added and deleted files as out of contract, and a key appearing or disappearing
        // is exactly that — no git plumbing needed to notice.
        if (array_keys($previous['files']) !== array_keys($current['files'])) {
            return null;
        }

        $changed = [];

        foreach ($current['files'] as $path => $hash) {
            if ($previous['files'][$path] === $hash) {
                continue;
            }

            // Routes, views and config are re-read in full on every pass anyway, but a change in one
            // can move an edge the scoped pass would never revisit. Out of contract.
            if (! str_starts_with($path, 'app/')) {
                return null;
            }

            $changed[] = $path;
        }

        // Nothing differing means the fingerprints matched and the caller should have served the
        // entry whole. Reaching here anyway would scope to zero files, which re-emits the previous
        // graph unchanged — a green run against a stale graph, the worst outcome available.
        if ($changed === []) {
            return null;
        }

        $resolved = [];

        foreach ($changed as $path) {
            $absolute = "{$projectRoot}/{$path}";

            // Deliberately NOT realpath()'d, and checked against the provenance rather than the
            // filesystem — see the method docblock. A path the previous graph attributes nothing to
            // cannot be substituted, and a scope short of one changed file merges a stale version of
            // that file back in. All or nothing.
            if (! isset($provenanceFiles[$absolute])) {
                return null;
            }

            $resolved[] = $absolute;
        }

        return $resolved;
    }
}
