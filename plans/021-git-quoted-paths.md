# Plan 021: Handle git's quoted pathnames so non-ASCII files never read as "no impact"

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Changes/UnifiedDiffParser.php src/Changes/ChangedSymbols.php tests/Unit/UnifiedDiffParserTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

With git's default `core.quotePath=true`, any path containing a byte ≥ 0x80
(accented or non-Latin filenames) or a special character is emitted in diff
headers C-quoted with octal escapes: `+++ "b/resources/views/café.blade.php"`
becomes `+++ "b/resources/views/caf\303\251.blade.php"`. Richter's diff parser
takes header and `rename from`/`rename to` values raw, so the quoted path keeps
its surrounding `"` and escaped bytes. Downstream, every gate is a prefix check
— `str_starts_with($file, 'app/')`, `resources/views/`, the frontend roots —
and a leading `"` fails them all, so the file silently drops out of
classification entirely: not mis-classified, not UNRESOLVED, just absent. A
changed Blade view or frontend file with an accented name reads as "no impact",
the falsely-empty result the tool exists to prevent. PHP class files are
effectively immune (ASCII identifiers), which is why this went unnoticed.

## Current state

- `src/Changes/ChangedSymbols.php:25` — the diff invocation:

  ```php
  $diff = Process::path(base_path())->run(['git', 'diff', '-U0', '--end-of-options', "{$base}...{$head}"]);
  ```

- `src/Changes/UnifiedDiffParser.php:52-62` — rename values taken raw:

  ```php
  if (! $inHunk && str_starts_with($line, 'rename from ')) {
      $pendingRenameFrom = substr($line, 12);
      continue;
  }

  if (! $inHunk && str_starts_with($line, 'rename to ')) {
      $pendingRenameTo = substr($line, 10);
      continue;
  }
  ```

- `src/Changes/UnifiedDiffParser.php:147-160` — `stripPrefix()` handles only
  trim + `a/`/`b/`:

  ```php
  private static function stripPrefix(string $path): ?string
  {
      $path = trim($path);

      if ($path === '/dev/null') {
          return null;
      }

      if (str_starts_with($path, 'a/') || str_starts_with($path, 'b/')) {
          return substr($path, 2);
      }

      return $path;
  }
  ```

  Note: on a quoted header the raw value is `"a/caf\303\251.blade.php"` — the
  leading `"` means even the `a/` strip misses.
- Downstream prefix gates: `src/Changes/ChangedSymbols.php:50`
  (`str_starts_with($file, 'app/')`), `src/Graph/BladeViews.php` (the
  `resources/views/` check in `viewNameFromPath`), `FrontendChanges::handles`
  (root prefixes).
- After parsing, the path is used to fetch sources (`git show ref:path`, or a
  working-tree read) — so the decoded value must be the real on-disk byte
  sequence, not the escaped form.
- `tests/Unit/UnifiedDiffParserTest.php` covers modification, rename-with-edit,
  pure rename, multi-file, deletion — nothing quoted.
- Parser conventions: pure static class, no I/O
  (`src/Changes/UnifiedDiffParser.php:5-9` docblock: "Pure (no git, no I/O) so
  the hunk logic is unit-testable"). Keep the unquoting pure.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter UnifiedDiffParserTest` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `bug-fixing` (failing test first).
- Skill `backend-quality` for closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Changes/UnifiedDiffParser.php`
- `src/Changes/ChangedSymbols.php` (one argv change, step 3)
- `tests/Unit/UnifiedDiffParserTest.php`

**Out of scope** (do NOT touch):
- `copy from`/`copy to` sections — they require non-default git config
  (`diff.renames=copies`) to appear; noted as a deliberate non-goal.
- The downstream consumers (`BladeViews`, `FrontendChanges`) — after decoding
  they receive plain paths and need no change.
- `BenchmarkCase` / benchmark git plumbing.

## Git workflow

- Branch: `advisor/021-git-quoted-paths`
- Commit style: imperative subject, e.g. `Decode git's quoted pathnames in the diff parser`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Write the failing tests

In `tests/Unit/UnifiedDiffParserTest.php`, add (modeled on the existing
multi-line-diff string style):

1. `a_quoted_unicode_path_is_decoded` — a diff whose headers carry
   `--- "a/resources/views/caf\303\251.blade.php"` and
   `+++ "b/resources/views/caf\303\251.blade.php"` (in the PHP test string,
   write the octal escapes as literal backslash sequences:
   `'"b/resources/views/caf\\303\\251.blade.php"'`), one hunk, one added line.
   Assert the parsed array is keyed by `resources/views/café.blade.php`
   (`"caf\xC3\xA9.blade.php"` in PHP source) with the added line present.
2. `a_quoted_pure_rename_is_decoded` — `rename from`/`rename to` lines with
   quoted values and no hunks; assert the entry's key and `oldPath` are the
   decoded paths.
3. `a_quoted_path_with_escaped_quote_and_backslash_is_decoded` — a path like
   `"a/we\"ird\\name.php"` decodes to `we"ird\name.php` (pins the `\"` and
   `\\` escapes).

**Verify**: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → the three
new tests FAIL, existing ones pass.

### Step 2: Add a pure unquote helper and apply it

In `UnifiedDiffParser`, add:

```php
/**
 * Undo git's core.quotePath C-style quoting: a path containing bytes ≥ 0x80 or
 * specials is emitted double-quoted with octal (\303\251) and character (\" \\ \t \n)
 * escapes. Unquoted values pass through untouched, so this is safe on every path.
 */
private static function unquote(string $path): string
{
    if (strlen($path) < 2 || ! str_starts_with($path, '"') || ! str_ends_with($path, '"')) {
        return $path;
    }

    $inner = substr($path, 1, -1);

    return (string) preg_replace_callback(
        '/\\\\(?:([0-7]{1,3})|(.))/',
        static fn (array $m): string => $m[1] !== '' ? chr((int) octdec($m[1])) : match ($m[2]) {
            'n' => "\n", 't' => "\t", 'r' => "\r", default => $m[2],
        },
        $inner,
    );
}
```

Apply it at every point a path enters the parser: inside `stripPrefix()`
(first line: `$path = self::unquote(trim($path));` — then the existing
`/dev/null` and `a/`/`b/` logic runs on the decoded value) and on the two
rename captures (`self::unquote(substr($line, 12))` / `…, 10))`). Check
whether the `diff --git a/… b/…` line is also parsed for paths anywhere — if
it is, decode there too (quoted `diff --git` lines carry both paths quoted).

**Verify**: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → all pass,
including the three new tests.

### Step 3: Turn off quoting at the source as belt-and-braces

In `src/Changes/ChangedSymbols.php:25`, add the config flag to the argv so
common non-ASCII paths arrive raw and only genuinely special characters
(quotes, control chars) still need the parser-side decode:

```php
['git', '-c', 'core.quotepath=off', 'diff', '-U0', '--end-of-options', "{$base}...{$head}"]
```

Check the file for other `git diff`/`git show` invocations reading *paths*
(`grep -n "'git'" src/Changes/ChangedSymbols.php`) — `git show ref:path` calls
receive the decoded path as an argument and do not need the flag (they emit
content, not paths); leave them.

**Verify**: `vendor/bin/phpunit --filter ChangedSymbolsTest` → passes;
`grep -n "quotepath" src/Changes/ChangedSymbols.php` → exactly one hit.

### Step 4: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0.

## Test plan

- The three parser tests from step 1 (quoted unicode edit, quoted pure rename,
  escaped-quote/backslash path).
- Existing parser tests are the regression net for unquoted paths.
- Verification: `composer test` → all pass.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] The three new parser tests exist and pass
- [ ] `composer test` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `grep -c "unquote" src/Changes/UnifiedDiffParser.php` ≥ 3 (helper + call sites)
- [ ] `grep -n "core.quotepath=off" src/Changes/ChangedSymbols.php` → 1 hit on the diff argv
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The "Current state" excerpts don't match the live code (drift).
- The parser turns out to derive paths from the `diff --git` line in a way the
  plan's helper can't cleanly cover (two quoted paths on one line need a
  smarter split than assumed) — report the actual line format encountered.
- PHPStan strict rules reject the `preg_replace_callback` shape and the fix
  requires loosening a baseline — do not touch `phpstan-baseline.neon`.
- Decoded paths break a downstream consumer test (would mean a consumer was
  depending on the raw quoted form — report it).

## Maintenance notes

- The unquote helper is intentionally tolerant (unquoted input passes
  through) — future parser changes must keep calling it before prefix logic.
- `core.quotepath=off` emits raw UTF-8 bytes in diff output; if diff output is
  ever logged/rendered, that is by design (paths are repo-derived).
- Deferred: `copy from`/`copy to` support (needs non-default git config to
  trigger; revisit only on a real report).
