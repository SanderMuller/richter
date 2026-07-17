# Plan 001: Stop the diff parser from misreading hunk-body lines starting `++ `/`-- ` as file headers

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 50a0efa68928c2bbb7388964676c5e11293b25a7..HEAD -- src/Changes/UnifiedDiffParser.php tests/Unit/UnifiedDiffParserTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `50a0efa`, 2026-07-16

## Why this matters

Richter's whole purpose is to never produce a falsely empty "no impact" report. `UnifiedDiffParser::parse()` currently applies its `--- ` and `+++ ` file-header checks to *every* diff line, including lines inside a hunk body. With `git diff -U0` (the only way Richter invokes it), an **added** content line whose text starts with `++ ` arrives as the diff line `+++ …` and is misread as a file header — the parser reassigns the current file to a bogus path and the real added line is lost. A **removed** content line whose text starts with `-- ` (e.g. a SQL comment in a heredoc) arrives as `--- …` and is silently dropped. Downstream, a file whose only changes are eaten this way has empty hunks, classifies as `cosmeticOnly`, seeds nothing, and the report reads "no impact" — the exact failure mode the package exists to prevent.

## Current state

- `src/Changes/UnifiedDiffParser.php` — the only file with the bug. Pure, static, no I/O. `parse()` is the single public entry point.
- `src/Changes/ChangedSymbols.php:23` — the producer of the input: `git diff -U0 --end-of-options {base}...{head}`. `-U0` means no context lines; every hunk-body line starts with `+`, `-`, or `\` (the no-newline marker). Do **not** modify this file.
- `src/Changes/ChangedSymbols.php:146` — the consumer that turns empty members into `cosmeticOnly: true` (`new ChangedFileSymbols($file, …, $members, $members === [], …)`). Do **not** modify this file; it is context for why the bug matters.
- `tests/Unit/UnifiedDiffParserTest.php` — existing unit tests; heredoc `DIFF` fixtures, one behavior per test, `#[Test]` attribute, `final class`, extends `SanderMuller\Richter\Tests\TestCase`. Model all new tests after these.

The buggy loop, as it exists today (`src/Changes/UnifiedDiffParser.php:36-81`):

```php
foreach (explode("\n", $diff) as $line) {
    if (str_starts_with($line, 'diff --git ')) {
        $current = null;
        $pendingOld = null;

        continue;
    }

    if (str_starts_with($line, '--- ')) {
        $pendingOld = self::stripPrefix(substr($line, 4));

        continue;
    }

    if (str_starts_with($line, '+++ ')) {
        // The `---` line always precedes `+++`; key on the new path, or the old path on a
        // deletion (new path is /dev/null). Record the old path for base-side resolution.
        $current = self::stripPrefix(substr($line, 4)) ?? $pendingOld;

        if ($current !== null && ! isset($oldPaths[$current])) {
            $added[$current] = [];
            $removed[$current] = [];
            $oldPaths[$current] = $pendingOld ?? $current;
        }

        continue;
    }

    if (str_starts_with($line, '@@')) {
        [$oldLine, $newLine] = self::parseHunkHeader($line);

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
```

The failure traces:

1. Added line with text `++ $i;` → diff line `+++ $i;` → matches the `+++ ` branch → `$current` becomes the bogus path `$i;`, a spurious file entry is registered (with `oldPath` polluted from the still-set `$pendingOld`), and the real added line is never recorded against the real file.
2. Removed line with text `-- DROP TABLE users` → diff line `--- DROP TABLE users` → matches the `--- ` branch → the removal is dropped and `$pendingOld` is polluted.

Both branches must be inert **inside a hunk body**. A hunk body starts at an `@@` line and ends at the next `diff --git ` line. Real `-U0` git output never emits `--- `/`+++ ` file headers between an `@@` line and the next `diff --git ` — headers always sit in the per-file preamble.

Repo conventions that apply:

- `<?php declare(strict_types=1);` on line 1, `final` classes, PHP 8.4.
- Comments state constraints/why, not what — match the density and tone of the existing comments in this file (e.g. the `--- always precedes +++` comment).
- Tests: PHPUnit 12 attributes (`#[Test]`), snake_case test method names describing behavior (`it_parses_a_single_line_modification_with_correct_line_numbers`), heredoc `DIFF` fixtures, exact `assertSame` on the parsed shape.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `vendor/bin/phpunit --filter UnifiedDiffParserTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (267+ tests) |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Code style | `vendor/bin/pint --test` | exit 0, no changes needed |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Suggested executor toolkit

- If the `test-writing` skill is available in the environment, invoke it before writing the tests in steps 1–2.
- If the `backend-quality` skill is available, use it for the step-4 verification tier instead of running the commands manually.

## Scope

**In scope** (the only files you should modify):

- `src/Changes/UnifiedDiffParser.php`
- `tests/Unit/UnifiedDiffParserTest.php`

**Out of scope** (do NOT touch, even though they look related):

- `src/Changes/ChangedSymbols.php` — consumer; its cosmetic classification is correct once the parser stops eating lines.
- Rename handling (`rename from` / `rename to` lines with no hunks) — a **known, separate** bug tracked as audit finding 4; it gets its own plan. Do not add rename parsing here.
- `phpstan-baseline.neon`, `pint.json`, `rector.php`, CI workflows.

## Git workflow

- Branch: `advisor/001-diff-parser-hunk-state` off `main`.
- Commit style: imperative sentence-case, no prefix — e.g. existing history: `Consolidate the detect-changes --json error backstop`. One commit for the tests+fix is fine, or tests-first then fix.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Pin current edge-case behavior with characterization tests

Add three tests to `tests/Unit/UnifiedDiffParserTest.php` that pass against the **current** code, pinning behavior the fix must not change:

1. `it_parses_a_crlf_diff_with_clean_paths` — a fixture whose lines end `\r\n` (build the string with `str_replace("\n", "\r\n", $diff)` around a heredoc, or use double-quoted concatenation; a plain heredoc cannot embed `\r`). Assert: the file key is exactly `app/Foo.php` (no trailing `\r` — `stripPrefix()` trims it), the line numbers are correct, and the captured `text` **retains** the trailing `"\r"` (current behavior: `substr($line, 1)` does not trim). Example assertion: `['line' => 6, 'text' => "        return 1;\r"]`.
2. `it_ignores_a_binary_file_diff` — fixture: `diff --git a/public/logo.png b/public/logo.png`, an `index …` line, then `Binary files a/public/logo.png and b/public/logo.png differ`. Assert `UnifiedDiffParser::parse($diff)` returns `[]`.
3. `it_ignores_a_mode_only_change` — fixture: `diff --git a/app/Script.php b/app/Script.php`, `old mode 100644`, `new mode 100755`, nothing else. Assert the result is `[]`.

**Verify**: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → all pass (existing 6 + new 3). If any characterization test fails, the "Current state" understanding is wrong — STOP condition.

### Step 2: Add failing regression tests for the two header-confusion shapes

Add two tests that reproduce the bug. They must FAIL against the current code:

1. `it_treats_an_added_line_starting_with_plus_plus_as_content_inside_a_hunk`:

```php
$diff = <<<'DIFF'
diff --git a/app/Counter.php b/app/Counter.php
--- a/app/Counter.php
+++ b/app/Counter.php
@@ -10,0 +11 @@ class Counter
+++ $i;
DIFF;

$parsed = UnifiedDiffParser::parse($diff);

$this->assertSame(['app/Counter.php'], array_keys($parsed));
$this->assertSame([['line' => 11, 'text' => '++ $i;']], $parsed['app/Counter.php']['added']);
$this->assertSame([], $parsed['app/Counter.php']['removed']);
```

2. `it_treats_a_removed_line_starting_with_dash_dash_as_content_inside_a_hunk`:

```php
$diff = <<<'DIFF'
diff --git a/app/Query.php b/app/Query.php
--- a/app/Query.php
+++ b/app/Query.php
@@ -5 +4,0 @@ class Query
--- DROP TABLE archive
DIFF;

$parsed = UnifiedDiffParser::parse($diff);

$this->assertSame([['line' => 5, 'text' => '-- DROP TABLE archive']], $parsed['app/Query.php']['removed']);
$this->assertSame([], $parsed['app/Query.php']['added']);
```

**Verify**: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → exactly these 2 tests fail. Expected failure shapes: test 1 reports two keys (`app/Counter.php`, `$i;`) and an empty `added` list; test 2 reports an empty `removed` list. Any *other* failure is a STOP condition.

### Step 3: Track hunk state in `parse()`

In `src/Changes/UnifiedDiffParser.php::parse()`:

1. Add `$inHunk = false;` alongside the existing state initialization (`$current`, `$pendingOld`, `$newLine`, `$oldLine`).
2. In the `diff --git ` branch: also reset `$inHunk = false;`.
3. Guard the two header branches so they only run outside a hunk body: `if (! $inHunk && str_starts_with($line, '--- '))` and `if (! $inHunk && str_starts_with($line, '+++ '))`.
4. In the `@@` branch: set `$inHunk = true;` after parsing the header.
5. Add a one-line comment on the guard stating the constraint, in this file's comment style — the reason is: inside a hunk body (`-U0`: content lines only), a `+++ `/`--- ` line is an added/removed line whose text starts with `++ `/`-- `, never a file header; headers only occur between `diff --git` and the first `@@`.

Nothing else changes: with the guards false inside a hunk, `+++ $i;` falls through to the `str_starts_with($line, '+')` content branch (text `++ $i;` via `substr($line, 1)`) and `--- DROP …` falls through to the `-` branch — no new parsing logic is needed. Multiple `@@` hunks per file keep working because the `@@` branch is not guarded (content lines can never start with `@@`; they start with `+`, `-`, or `\`).

**Verify**: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → all 11 pass (6 pre-existing + 3 characterization + 2 regression).

### Step 4: Full verification

Run the full gates:

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0. (Note: `$inHunk` is a genuinely-read local; if PHPStan flags anything it means the implementation deviated from step 3 — re-check rather than baseline.)
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 5: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "001" plans/README.md` → row shows DONE.

## Test plan

- New tests, all in `tests/Unit/UnifiedDiffParserTest.php`, modeled structurally after `it_parses_a_single_line_modification_with_correct_line_numbers` (heredoc fixture, exact `assertSame`):
  - CRLF diff (characterization — paths clean, text keeps `\r`)
  - binary-file diff → `[]` (characterization)
  - mode-only change → `[]` (characterization)
  - added hunk line starting `++ ` (regression — the bug this plan fixes)
  - removed hunk line starting `-- ` (regression — the bug this plan fixes)
- Verification: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → 11 tests pass; `composer test` → whole suite green.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter UnifiedDiffParserTest` exits 0 with 11 tests
- [ ] `composer test` exits 0, 0 failures
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in `src/Changes/UnifiedDiffParser.php`, `tests/Unit/UnifiedDiffParserTest.php`, and `plans/README.md`
- [ ] `plans/README.md` status row for 001 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The drift check shows `src/Changes/UnifiedDiffParser.php` changed since `50a0efa`, or the loop no longer matches the "Current state" excerpt.
- A step-1 characterization test fails against the unmodified code — the plan's model of current behavior is wrong.
- The step-2 regression tests fail in a *different way* than the expected failure shapes described there.
- After the step-3 change, any pre-existing test fails — in particular `it_records_the_old_path_of_a_renamed_file` or `it_keys_a_deletion_on_the_old_path` (the header guards must not affect preamble parsing).
- The fix appears to require touching `src/Changes/ChangedSymbols.php` or any file outside the in-scope list.
- You discover real `git diff -U0` output that emits `--- `/`+++ ` file headers *between* an `@@` line and the next `diff --git ` line (this would falsify the plan's core assumption).

## Maintenance notes

- Audit finding 4 (pure renames — `rename from`/`rename to` with no hunks are invisible) will add parsing to this same loop in a future plan. That change must respect `$inHunk` (rename headers live in the preamble, so their branch belongs with the header branches, guarded by `! $inHunk`).
- Reviewer scrutiny: confirm the guards are on the two header branches only — guarding the `@@` branch would break multi-hunk files; guarding the content branches would break everything.
- The CRLF characterization pins that hunk text retains `\r`. If that is ever deemed wrong (finding 11 discussion), change it deliberately with its own test update — `ChangedSymbols::normalize()` currently strips all whitespace, so the retained `\r` is harmless to cosmetic detection today.
