<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

/**
 * Parses `git diff -U0` output into per-file added/removed lines with their line numbers. Pure (no
 * git, no I/O) so the hunk logic is unit-testable. With `-U0` there is no context, so every `+`/`-`
 * line is a real change and each hunk header gives the exact line ranges.
 */
final class UnifiedDiffParser
{
    /**
     * `oldPath` is the file's pre-change path — equal to the key for a normal edit, but the original
     * name for a rename, so the base-side source can be fetched from the right path.
     *
     * `isNew` is true when the old side is `/dev/null` — a genuinely new file. A null base source for
     * a NON-new file is an unreadable base (I/O failure), which the classifier must fail closed on
     * rather than treat as additive; see {@see ChangedSymbols::classifyFile()}.
     *
     * @return array<string, array{added: list<array{line: int, text: string}>, removed: list<array{line: int, text: string}>, oldPath: string, isNew: bool}>
     *   keyed by the new-side file path (or old-side path for deletions).
     */
    public static function parse(string $diff): array
    {
        // Accumulate each side in its own typed map keyed by path, then assemble the sealed shape
        // at the end. Mutating a nested array-shape through a variable key widens PHPStan's inferred
        // value type to list|string; keeping the lists separate keeps each type exact.
        /** @var array<string, list<array{line: int, text: string}>> $added */
        $added = [];
        /** @var array<string, list<array{line: int, text: string}>> $removed */
        $removed = [];
        /** @var array<string, string> $oldPaths */
        $oldPaths = [];
        /** @var array<string, bool> $isNewFiles */
        $isNewFiles = [];

        $current = null;
        $pendingOld = null;
        $pendingIsNew = false;
        $pendingRenameFrom = null;
        $pendingRenameTo = null;
        $inHunk = false;
        $newLine = 0;
        $oldLine = 0;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                self::flushPendingRename($pendingRenameFrom, $pendingRenameTo, $added, $removed, $oldPaths);

                $current = null;
                $pendingOld = null;
                $pendingIsNew = false;
                $pendingRenameFrom = null;
                $pendingRenameTo = null;
                $inHunk = false;

                continue;
            }

            if (! $inHunk && str_starts_with($line, 'rename from ')) {
                $pendingRenameFrom = self::unquote(substr($line, 12));

                continue;
            }

            if (! $inHunk && str_starts_with($line, 'rename to ')) {
                $pendingRenameTo = self::unquote(substr($line, 10));

                continue;
            }

            // Headers only occur between `diff --git` and the first `@@`; inside a hunk body (`-U0`:
            // content lines only) a `--- `/`+++ ` line is a removed/added line whose text starts with
            // `-- `/`++ `, never a file header.
            if (! $inHunk && str_starts_with($line, '--- ')) {
                $pendingOld = self::stripPrefix(substr($line, 4));
                // stripPrefix() returns null only for `/dev/null`, i.e. the old side is absent — a new file.
                $pendingIsNew = $pendingOld === null;

                continue;
            }

            if (! $inHunk && str_starts_with($line, '+++ ')) {
                // The `---` line always precedes `+++`; key on the new path, or the old path on a
                // deletion (new path is /dev/null). Record the old path for base-side resolution.
                $current = self::stripPrefix(substr($line, 4)) ?? $pendingOld;

                if ($current !== null && ! isset($oldPaths[$current])) {
                    $added[$current] = [];
                    $removed[$current] = [];
                    $oldPaths[$current] = $pendingOld ?? $current;
                    $isNewFiles[$current] = $pendingIsNew;
                }

                continue;
            }

            if (str_starts_with($line, '@@')) {
                [$oldLine, $newLine] = self::parseHunkHeader($line);
                $inHunk = true;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $added[$current][] = ['line' => $newLine, 'text' => substr($line, 1)];
                ++$newLine;
            } elseif (str_starts_with($line, '-')) {
                $removed[$current][] = ['line' => $oldLine, 'text' => substr($line, 1)];
                ++$oldLine;
            }
        }

        self::flushPendingRename($pendingRenameFrom, $pendingRenameTo, $added, $removed, $oldPaths);

        $files = [];

        foreach ($oldPaths as $path => $oldPath) {
            // A 100%-rename registers only via flushPendingRename() (no `+++`), so it never sets
            // isNewFiles — default false: a rename is not a new file.
            $files[$path] = ['added' => $added[$path], 'removed' => $removed[$path], 'oldPath' => $oldPath, 'isNew' => $isNewFiles[$path] ?? false];
        }

        return $files;
    }

    /**
     * Registers a section that carried `rename from`/`rename to` but no hunks. A 100%-similarity
     * rename emits neither `---`/`+++` headers nor hunks, so the section registers nothing through
     * `+++ ` — without this flush the vanishing FQCN reads as "no impact". A content rename does
     * register via `+++ ` (`isset($oldPaths[$to])`) and skips the flush.
     *
     * @param  array<string, list<array{line: int, text: string}>>  $added
     * @param  array<string, list<array{line: int, text: string}>>  $removed
     * @param  array<string, string>  $oldPaths
     */
    private static function flushPendingRename(?string $from, ?string $to, array &$added, array &$removed, array &$oldPaths): void
    {
        if ($from === null || $to === null || isset($oldPaths[$to])) {
            return;
        }

        $added[$to] = [];
        $removed[$to] = [];
        $oldPaths[$to] = $from;
    }

    /** @return array{0: int, 1: int} [oldStart, newStart] from `@@ -old[,n] +new[,n] @@`. */
    private static function parseHunkHeader(string $header): array
    {
        preg_match('/@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $header, $m);

        return [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0)];
    }

    private static function stripPrefix(string $path): ?string
    {
        $path = self::unquote(trim($path));

        if ($path === '/dev/null') {
            return null;
        }

        if (str_starts_with($path, 'a/') || str_starts_with($path, 'b/')) {
            return substr($path, 2);
        }

        return $path;
    }

    /**
     * Undo git's core.quotePath C-style quoting: a path containing bytes ≥ 0x80 or
     * specials is emitted double-quoted with octal (\303\251) and character (\" \\ \t \n)
     * escapes. Unquoted values pass through untouched, so this is safe on every path.
     *
     * Public so {@see ChangedSymbols::untrackedRelevantFiles()} can reuse it on
     * `git status --porcelain` output.
     */
    public static function unquote(string $path): string
    {
        if (strlen($path) < 2 || ! str_starts_with($path, '"') || ! str_ends_with($path, '"')) {
            return $path;
        }

        $inner = substr($path, 1, -1);

        return (string) preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|(.))/',
            // A byte escape is at most 3 octal digits, but that allows \400-\777 (256-511) — values
            // no byte can hold. Git never emits those (an octal escape is always one raw byte), but
            // `chr()` itself only accepts int<0,255>; masking the low byte keeps the type honest
            // without changing behaviour for any escape git actually produces.
            static fn (array $m): string => $m[1] !== '' ? chr((int) octdec($m[1]) & 0xFF) : match ($m[2]) {
                'n' => "\n", 't' => "\t", 'r' => "\r", default => $m[2],
            },
            $inner,
        );
    }
}
