<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

/**
 * Whether a cached graph can serve as the merge base for a scoped Brain rebuild, and over which files.
 *
 * Brain's `ProjectAnalyzer::scopedTo()` re-traces only the controllers declared in the files it is
 * given and carries everything else over from a previous graph. It checks two preconditions itself —
 * that the scope names a file inside the project at all, and that the changed files' own call graph
 * survived the edit — and leaves the rest to the caller: no file added or deleted, nothing outside
 * `app/` moved, and nothing the previous graph attributes no node to.
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
     * every scoped path must be one of them. One reason for that is left, and it is load-bearing —
     * it is the difference between a correct scoped build and a silently stale one.
     *
     * Brain's soundness check compares what the scope owns in the previous graph against what it
     * owns in the fresh one. A scope owning nothing owns nothing in both, and two empty sets compare
     * equal — so the check passes, nothing is substituted, and the previous graph is returned as
     * though it were current. Brain 2.5.0 closed two ways in: `GraphProvenance` now resolves a
     * caller's path form against the keys a build recorded, so a `realpath()`ed path no longer names
     * the right file in a form nothing recognises, and `ProjectAnalyzer::assertScopeIsUsable()`
     * refuses a scope naming a path that is not a file inside the project root. The floor in
     * `composer.json` is `^2.5.0`, so both hold for every consumer.
     *
     * What is left is a file that exists here and the previous graph attributes nothing to. Brain
     * keeps that on its fast path, arguing an edit to a file nothing reaches cannot change the graph
     * without an edit to its caller, which would be in the scope and does own provenance. The
     * argument has a hole: a file whose parse failed on the previous build owns nothing either, and
     * the edit may be the fix for it. So a changed file the previous graph knows nothing about
     * refuses the whole scope rather than quietly shrinking it, and the paths returned here stay in
     * the provenance's own form, which is the form this set is keyed on.
     *
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}|null  $previous  the record the cached graph was built from
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}  $current
     * @param  array<string, true>  $provenanceFiles  files the previous Brain graph attributes nodes to
     * @return list<string>|null
     */
    public static function filesFor(?array $previous, array $current, string $projectRoot, array $provenanceFiles = []): ?array
    {
        return self::decide($previous, $current, $projectRoot, $provenanceFiles)->files;
    }

    /**
     * {@see filesFor()} plus, when the answer is no, which precondition said so.
     *
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}|null  $previous
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}  $current
     * @param  array<string, true>  $provenanceFiles
     */
    public static function decide(?array $previous, array $current, string $projectRoot, array $provenanceFiles = []): ScopedRebuildDecision
    {
        if ($previous === null) {
            return ScopedRebuildDecision::refused('no-merge-base');
        }

        // A version, config or namespace change can alter edges the file hashes never see, so the
        // previous graph is not a base for anything.
        if ($previous['nonFile'] !== $current['nonFile']) {
            return ScopedRebuildDecision::refused('inputs-changed', self::nonFileDetail($previous['nonFile'], $current['nonFile']));
        }

        // Brain names added and deleted files as out of contract, and a key appearing or disappearing
        // is exactly that — no git plumbing needed to notice. Compared as SETS: the two records are
        // both produced from one sorted list, so a difference in iteration order alone says nothing
        // about the project and must not read as an added file.
        $added = array_diff_key($current['files'], $previous['files']);
        $removed = array_diff_key($previous['files'], $current['files']);

        if ($added !== [] || $removed !== []) {
            return ScopedRebuildDecision::refused('file-set-changed', self::fileSetDetail($added, $removed));
        }

        $changed = [];

        foreach ($current['files'] as $path => $hash) {
            if ($previous['files'][$path] === $hash) {
                continue;
            }

            // Routes, views and config are re-read in full on every pass anyway, but a change in one
            // can move an edge the scoped pass would never revisit. Out of contract.
            if (! str_starts_with($path, 'app/')) {
                return ScopedRebuildDecision::refused('non-app-change', "{$path} differs from the cached graph and sits outside app/");
            }

            $changed[] = $path;
        }

        // Nothing differing means the fingerprints matched and the caller should have served the
        // entry whole. Reaching here anyway would scope to zero files, which re-emits the previous
        // graph unchanged — a green run against a stale graph, the worst outcome available.
        if ($changed === []) {
            return ScopedRebuildDecision::refused('no-change', 'every hashed input matches the cached graph — the comparison is against the last build, not against a git ref');
        }

        $resolved = [];

        foreach ($changed as $path) {
            $absolute = "{$projectRoot}/{$path}";

            // Checked against the provenance rather than the filesystem — see the method docblock.
            // A path the previous graph attributes nothing to cannot be substituted, and a scope
            // short of one changed file merges a stale version of that file back in. All or nothing.
            // The form is the provenance's own because that is what this set is keyed on.
            if (! isset($provenanceFiles[$absolute])) {
                return ScopedRebuildDecision::refused('not-in-provenance', self::provenanceDetail($absolute, $provenanceFiles));
            }

            $resolved[] = $absolute;
        }

        return ScopedRebuildDecision::scoped($resolved);
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    private static function nonFileDetail(array $previous, array $current): string
    {
        // Over the union of both key sets. Iterating only the current record would call a key the
        // previous one carries and this one dropped "key order only" — a sentence claiming nothing
        // differs, printed because something did.
        $keys = array_unique([...array_keys($previous), ...array_keys($current)]);
        $differing = array_values(array_filter(
            $keys,
            static fn (string $key): bool => ! array_key_exists($key, $previous)
                || ! array_key_exists($key, $current)
                || $previous[$key] !== $current[$key],
        ));

        // Values, not just key names, for the short ones. `config` is a JSON blob whose diff would
        // swamp the line, and `format` alone already tells the whole story (a version bump).
        $described = array_map(
            static fn (string $key): string => in_array($key, ['php', 'richter', 'brain'], strict: true)
                ? sprintf('%s (%s → %s)', $key, self::scalar($previous[$key] ?? null), self::scalar($current[$key] ?? null))
                : $key,
            $differing,
        );

        return 'differing non-file inputs: ' . ($described === [] ? 'key order only' : implode(', ', $described));
    }

    /**
     * @param  array<string, string>  $added
     * @param  array<string, string>  $removed
     */
    private static function fileSetDetail(array $added, array $removed): string
    {
        $sample = static fn (array $paths): string => $paths === []
            ? 'none'
            : implode(', ', array_slice(array_keys($paths), 0, 3)) . (count($paths) > 3 ? sprintf(' (+%d more)', count($paths) - 3) : '');

        return sprintf('%d added (%s), %d removed (%s)', count($added), $sample($added), count($removed), $sample($removed));
    }

    /**
     * The refused path plus, when one exists, a provenance path sharing its basename.
     *
     * That sample is what turns this reason into an answer. The two forms differing only by prefix
     * (a resolved `/private/var` against an unresolved `/var`, a symlinked project root, a
     * `realpath()` somewhere in the middle) looks identical to the file simply being absent from the
     * graph — and the remedies are nothing alike.
     *
     * @param  array<string, true>  $provenanceFiles
     */
    private static function provenanceDetail(string $absolute, array $provenanceFiles): string
    {
        $basename = basename($absolute);

        foreach (array_keys($provenanceFiles) as $known) {
            if (basename($known) === $basename) {
                return "{$absolute} is absent from the previous graph's provenance, which knows {$known}";
            }
        }

        return "{$absolute} is absent from the previous graph's provenance, which has no path of that name at all";
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
