# Plan 045: Unquote git-quoted untracked paths so the affected-tests fail-closed contract holds

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat afcefa1..HEAD -- src/Changes/ChangedSymbols.php src/Changes/UnifiedDiffParser.php tests/Feature/CommandsTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1 (the safety mechanism it repairs — fail-closed on an
  untracked relevant file — is the one this tool exists to protect)
- **Effort**: S
- **Risk**: LOW (pure widening of what counts as "untracked relevant"; the
  fail-closed can only fire in *more* cases after this, never fewer)
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `afcefa1`, 2026-07-22

## Why this matters

`richter:affected-tests` fails closed (exit 2, "run the full suite") when an
untracked file under `app/`, `resources/views/`, or a configured frontend root
exists — such a file is invisible to `git diff`, so a narrowed selection can't
vouch for it. That safety net has a hole: `untrackedRelevantFiles()` reads
`git status --porcelain` but never undoes git's path quoting. Git wraps any path
containing a **space** (or `"`, `\`, or a control char) in double quotes —
independent of `core.quotepath`, which only governs bytes ≥ 0x80. So an untracked
`resources/views/my page.blade.php` arrives as `?? "resources/views/my page.blade.php"`;
after `substr($line, 3)` the value still carries a leading `"`, every
`str_starts_with($file, 'resources/views/')` check returns false, the file is
dropped, and the fail-closed **never fires** — the selection narrows as if the
new file didn't exist. Blade views and frontend component files (plausibly named
with spaces) are the realistic trigger. This is exactly the silent
under-selection the untracked-file mechanism was added (commit `d76328c`) to
prevent. The sibling diff path already solves this with
`UnifiedDiffParser::unquote()`; this plan reuses it.

## Current state

- `src/Changes/ChangedSymbols.php` — holds `untrackedRelevantFiles()`, the
  method with the bug. Current code (lines 144–168):

  ```php
  public static function untrackedRelevantFiles(): array
  {
      $status = Process::path(base_path())->run(['git', '-c', 'core.quotepath=off', 'status', '--porcelain', '--end-of-options']);

      if (! $status->successful()) {
          return [];
      }

      $roots = ['app/', 'resources/views/', ...array_map(
          static fn (string $root): string => rtrim($root, '/') . '/',
          RichterConfig::frontendRoots(),
      )];

      $untrackedLines = array_filter(
          explode("\n", $status->output()),
          static fn (string $line): bool => str_starts_with($line, '?? '),
      );

      $untrackedPaths = array_map(static fn (string $line): string => substr($line, 3), $untrackedLines);

      return array_values(array_filter(
          $untrackedPaths,
          static fn (string $file): bool => array_any($roots, static fn (string $root): bool => str_starts_with($file, $root)),
      ));
  }
  ```

  Note: the `-c core.quotepath=off` flag is already there but does **not** close
  this gap — space/quote/backslash/control quoting happens regardless of
  `core.quotepath`. Leave the flag in place (it still suppresses octal escaping
  of non-ASCII bytes); it is not the fix.

- `src/Changes/UnifiedDiffParser.php` — already ships the exact unquote logic,
  currently `private static` (lines 167–186):

  ```php
  private static function unquote(string $path): string
  {
      if (strlen($path) < 2 || ! str_starts_with($path, '"') || ! str_ends_with($path, '"')) {
          return $path;
      }
      $inner = substr($path, 1, -1);
      return (string) preg_replace_callback(
          '/\\\\(?:([0-7]{1,3})|(.))/',
          static fn (array $m): string => $m[1] !== '' ? chr((int) octdec($m[1]) & 0xFF) : match ($m[2]) {
              'n' => "\n", 't' => "\t", 'r' => "\r", default => $m[2],
          },
          $inner,
      );
  }
  ```

  It is pure (string in, string out) and passes unquoted values through
  untouched, so it is safe to apply to every porcelain path. It is called
  internally at `UnifiedDiffParser.php:53,59,149`.

- **Convention**: this repo tests git plumbing with `Process::fake([...])`
  keyed by wildcard patterns against the shell-quoted command string — see
  `tests/Feature/CommandsTest.php:585-609`
  (`affected_tests_plain_exits_undetermined_with_an_untracked_file_present`),
  which fakes `'*status*' => Process::result("?? app/Models/Report.php\n")`
  and asserts exit 2 plus the file named in the stderr warning. Model the new
  test on it.

## Commands you will need

| Purpose   | Command                                                      | Expected on success |
|-----------|-------------------------------------------------------------|---------------------|
| Tests (targeted) | `vendor/bin/phpunit --filter untracked`              | all pass            |
| Full suite | `composer test`                                            | all pass            |
| Static analysis | `composer phpstan`                                    | exit 0, no errors   |
| Style     | `vendor/bin/pint --test`                                    | no style issues     |

## Scope

**In scope** (the only files you should modify):
- `src/Changes/UnifiedDiffParser.php` — change `unquote` from `private` to
  `public` (visibility only).
- `src/Changes/ChangedSymbols.php` — apply the unquote in
  `untrackedRelevantFiles()`.
- `tests/Feature/CommandsTest.php` — add the regression test.

**Out of scope** (do NOT touch, even though they look related):
- The diff-parsing path in `UnifiedDiffParser` (lines 53, 59, 149) — it already
  unquotes correctly; leave it.
- The `-c core.quotepath=off` flag in `untrackedRelevantFiles()` — keep it.
- Any change to the stderr warning wording or the exit-code contract — the fix
  is purely that the file is now *seen*; the downstream behavior is already
  correct and tested.

## Git workflow

- Branch: `advisor/045-untracked-quoted-paths-fail-closed`
- Commit style — conventional, matching `git log` (e.g. commit `d76328c`
  "Make affected-tests undetermined on an untracked relevant file"). One commit
  is fine.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Expose `unquote()` as a reusable helper

In `src/Changes/UnifiedDiffParser.php`, change the method signature at line 167
from:

```php
private static function unquote(string $path): string
```

to:

```php
public static function unquote(string $path): string
```

Update its docblock's first line to note it is shared (e.g. append
"Public so {@see ChangedSymbols::untrackedRelevantFiles()} can reuse it on
`git status --porcelain` output."). Change nothing else about the method body.

**Verify**: `composer phpstan` → exit 0, no errors. (No behavior change yet.)

### Step 2: Unquote each porcelain path before the root-prefix filter

In `src/Changes/ChangedSymbols.php`, in `untrackedRelevantFiles()`, change the
`$untrackedPaths` mapping so each path is unquoted after the `?? ` prefix is
stripped:

```php
$untrackedPaths = array_map(
    static fn (string $line): string => UnifiedDiffParser::unquote(substr($line, 3)),
    $untrackedLines,
);
```

Add `use SanderMuller\Richter\Changes\UnifiedDiffParser;` if it is not already
imported — but note both classes are in the **same namespace**
(`SanderMuller\Richter\Changes`), so no `use` is needed; reference it as
`UnifiedDiffParser::unquote(...)` directly. Confirm the namespace at the top of
each file before adding any import.

**Verify**: `composer phpstan` → exit 0, no errors.

### Step 3: Add the regression test

In `tests/Feature/CommandsTest.php`, add a test modelled exactly on
`affected_tests_plain_exits_undetermined_with_an_untracked_file_present`
(lines 585-609), but with a **space in the untracked path** so the porcelain
output is quoted. Assert the run fails closed (exit 2) and the warning names the
**unquoted** path:

```php
#[Test]
public function affected_tests_fails_closed_on_an_untracked_file_whose_path_git_quotes(): void
{
    // git status --porcelain double-quotes any path with a space, independent of
    // core.quotepath. If the quoting isn't undone, the root-prefix check misses the
    // file and the fail-closed silently doesn't fire — the exact under-selection bug.
    Process::fake([
        '*merge-base*' => Process::result("abc123\n"),
        '*diff*' => Process::result(''),
        '*status*' => Process::result("?? \"resources/views/my page.blade.php\"\n"),
    ]);

    $this->withoutMockingConsoleOutput();
    $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--plain' => true]);
    $output = Artisan::output();

    $this->assertSame(2, $exitCode);
    $this->assertStringContainsString('untracked file(s)', $output);
    $this->assertStringContainsString('resources/views/my page.blade.php', $output);
    $this->assertStringNotContainsString('"resources/views', $output); // the leading quote was stripped
}
```

Match the surrounding file's import style and `#[Test]` attribute convention
(the file already imports `Process` and `Artisan`; confirm before adding).

**Verify**: `vendor/bin/phpunit --filter affected_tests_fails_closed_on_an_untracked_file_whose_path_git_quotes`
→ 1 passing.

Then confirm the test genuinely catches the bug: temporarily revert Step 2
(drop the `unquote` call), re-run the test, and confirm it **fails**; restore
Step 2 and confirm it passes again. (This proves the test guards the fix.)

### Step 4: Full verification

**Verify**:
- `composer test` → all pass (report the total; it was 700 before this plan).
- `vendor/bin/pint --test` → no style issues.
- `composer phpstan` → exit 0.

## Test plan

- New test in `tests/Feature/CommandsTest.php`:
  `affected_tests_fails_closed_on_an_untracked_file_whose_path_git_quotes` —
  covers the space-in-path porcelain-quoting case, asserting exit 2 and the
  unquoted path in the warning.
- Structural pattern: model after
  `affected_tests_plain_exits_undetermined_with_an_untracked_file_present`
  (`tests/Feature/CommandsTest.php:585`).
- Verification: `composer test` → all pass, including the 1 new test.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer phpstan` exits 0, no errors
- [ ] `vendor/bin/pint --test` reports no style issues
- [ ] `composer test` exits 0; the new test exists and passes; total is the
      prior count + 1
- [ ] Reverting only the Step 2 `unquote` call makes the new test fail (proven
      once, then restored)
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row for plan 045 updated to DONE

## STOP conditions

Stop and report back (do not improvise) if:

- The code at `untrackedRelevantFiles()` or `UnifiedDiffParser::unquote()` does
  not match the "Current state" excerpts (drift since this plan was written).
- Making `unquote` public introduces a PHPStan or Pint error you can't resolve
  with a one-line visibility/docblock change.
- The new test passes **even with Step 2 reverted** — that means the
  reproduction is wrong (perhaps the porcelain fake isn't quoted as expected);
  report it rather than shipping a test that doesn't guard the fix.
- The fix appears to require touching `AffectedTestsCommand` or the warning
  wording — it should not; the file is dropped upstream, so once it's seen the
  existing downstream path handles it.

## Maintenance notes

- If a second consumer of `git status --porcelain` output is added, it must
  unquote too — consider whether a small dedicated porcelain-parsing helper is
  warranted at that point rather than a third call site.
- A reviewer should confirm the new test's fake output is genuinely quoted
  (leading/trailing `"` around a path with a space) — a fake that isn't quoted
  would pass trivially and prove nothing.
- Deferred out of scope: paths quoted for reasons *other* than a space (a `"` or
  backslash in the name). `unquote()` handles them by construction (it decodes
  the C-style escapes), so no extra test is required, but a future edge-case
  report there is a candidate for one more fixture.
