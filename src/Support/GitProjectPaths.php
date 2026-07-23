<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Illuminate\Support\Facades\Process;

/**
 * Bridges `base_path()` (the Laravel project root) and the git repository root for the git plumbing
 * in `ChangedSymbols`. When the app IS the repo root — the common case, and every current consumer —
 * the prefix is empty and every transform is an identity/no-op. When `base_path()` is a subdirectory
 * of a larger repo (a monorepo whose Laravel root is nested, or this package's own
 * `tests/Fixtures/project`), the transforms re-root paths: git resolves a bare `{ref}:app/…` object
 * path and `status --porcelain` output against the repo ROOT, not the process cwd, so those paths
 * must be translated. `git diff --relative` re-roots its own output and does not go through here.
 *
 * The prefix is computed fresh (never cached): its value depends on git, so a single memo keyed by
 * `base_path()` would be wrong the moment two callers see different git state for the same root — the
 * exact hazard a faked-git test hits. Callers therefore resolve it once per operation with
 * {@see prefix()} and pass it to the pure {@see objectPath()} transform, so a whole `resolve()` costs
 * one extra `git rev-parse`, not one per file.
 */
final class GitProjectPaths
{
    /**
     * `base_path()` relative to the git repo root; '' when it IS the root. Null when it could not be
     * determined — the caller MUST fail closed on null rather than assume '' (see {@see relevantUntracked()}).
     */
    public static function prefix(): ?string
    {
        $result = Process::path(base_path())->run(['git', 'rev-parse', '--show-prefix']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Turn a `base_path()`-relative path into the repo-root-relative form `git show {ref}:PATH`
     * needs (a no-op at the repo root, where the prefix is empty).
     */
    public static function objectPath(string $prefix, string $baseRelativePath): string
    {
        return $prefix . $baseRelativePath;
    }

    /**
     * The subset of $repoRelativePaths (repo-root-relative, e.g. `git status --porcelain` entries)
     * that fall under one of $roots, re-rooted to `base_path()`.
     *
     * Fails CLOSED when the prefix can't be resolved: rather than silently dropping a relevant file —
     * an `affected-tests` cardinal-rule violation — it keeps any path that contains a root segment,
     * repo-root-relative. That branch is near-unreachable (a successful `git status` implies a
     * checkout, so `git rev-parse --show-prefix` succeeds too), but the cardinal rule forbids the
     * alternative of treating an unknown layout as "root" and missing a nested `app/` file.
     *
     * @param  list<string>  $repoRelativePaths
     * @param  list<string>  $roots  `base_path()`-relative, trailing-slash (e.g. `app/`)
     * @return list<string>
     */
    public static function relevantUntracked(array $repoRelativePaths, array $roots): array
    {
        $prefix = self::prefix();

        if ($prefix === null) {
            return array_values(array_filter(
                $repoRelativePaths,
                static fn (string $path): bool => array_any(
                    $roots,
                    static fn (string $root): bool => str_starts_with($path, $root) || str_contains($path, '/' . $root),
                ),
            ));
        }

        return array_values(array_filter(
            array_map(static fn (string $path): ?string => self::toBaseRelative($prefix, $path), $repoRelativePaths),
            static fn (?string $path): bool => $path !== null && array_any(
                $roots,
                static fn (string $root): bool => str_starts_with($path, $root),
            ),
        ));
    }

    /**
     * Re-root a repo-root-relative path to `base_path()`; null when it lies outside `base_path()`'s
     * subtree — a sibling package in a monorepo, which is not this app's concern.
     */
    private static function toBaseRelative(string $prefix, string $repoRelativePath): ?string
    {
        if ($prefix === '') {
            return $repoRelativePath;
        }

        return str_starts_with($repoRelativePath, $prefix)
            ? substr($repoRelativePath, strlen($prefix))
            : null;
    }
}
