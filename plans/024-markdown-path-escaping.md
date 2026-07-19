# Plan 024: Escape file paths in the markdown formatter's structural positions

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Analysis/MarkdownFormatter.php tests/Unit/MarkdownFormatterTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (plan 025 refactors the same file — land this first, it's smaller)
- **Category**: bug
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

`MarkdownFormatter`'s class docblock justifies skipping all markdown escaping
with "a `|` or backtick cannot occur in those identifiers." That holds for
FQCNs, route ids and command names — but the changed-files table's row key is
a **file path from the diff**, and file paths can legally contain `|` and
backticks. A file named that way breaks the table structure (or closes the
code span early) in output that is, by design, pasted into GitHub PR
descriptions and comments. Severity is low (odd filenames are rare), but the
suppressing docblock claim is factually wrong for paths and will keep future
edits from adding escaping where it's needed.

## Current state

- `src/Analysis/MarkdownFormatter.php:12-13` (class docblock):

  ```
  * Cell and code-span content is repo-derived (paths, FQCNs, route/command ids), so no markdown
  * escaping is applied — a `|` or backtick cannot occur in those identifiers.
  ```

- `src/Analysis/MarkdownFormatter.php:73-81` — the changed-files table row:

  ```php
  foreach ($result['changed'] as $file => $nodeCount) {
      $coverage = ($result['coverage'][$file] ?? 'analyzed') === 'unresolved'
          ? '⚠️ **UNRESOLVED** — not graphed, never "no impact"'
          : 'analyzed';
      $lines[] = "| `{$file}` | {$nodeCount} | {$coverage} |";
  }
  ```

- `src/Analysis/MarkdownFormatter.php:106-112` — findings render raw too
  (`"- ⚠️ {$finding}"`), and findings strings are built as
  `"{$file->file}: {$finding}"` upstream (`src/Analysis/ImpactAnalyzer.php:191`);
  outside a table a `|` is non-structural, but a backtick still is when
  adjacent formatting exists. Treat the table cell as the must-fix; findings
  are in scope only if the same helper applies trivially.
- `tests/Unit/MarkdownFormatterTest.php` builds result-array fixtures directly
  (see its top for the builder style) — a path with `|` can be injected
  without any git plumbing.
- Conventions: `final` class, static private helpers, heavy "why" docblocks.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter MarkdownFormatterTest` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `bug-fixing` (failing test first); `backend-quality` for closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Analysis/MarkdownFormatter.php`
- `tests/Unit/MarkdownFormatterTest.php`

**Out of scope** (do NOT touch):
- `src/Analysis/ImpactFormatter.php` (plain text — pipes are harmless there)
- `src/Analysis/JsonPresenter.php` (JSON-encodes, already safe)
- `src/Analysis/ImpactAnalyzer.php` — finding strings stay as built; only the
  markdown rendering layer escapes.

## Git workflow

- Branch: `advisor/024-markdown-path-escaping`
- Commit style: imperative subject, e.g. `Escape file paths in the markdown changed-files table`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Failing test

In `tests/Unit/MarkdownFormatterTest.php`, add
`a_path_containing_a_pipe_does_not_break_the_changed_files_table`: build a
result whose `changed` map has the key `app/weird|name.php` (and a second
with a backtick, `app/back` . '`' . `tick.php`), render, and assert:

- the table row for the pipe path contains no **unescaped** `|` inside the
  cell (e.g. assert the rendered line equals
  ``| `app/weird\|name.php` | 1 | analyzed |`` — pick the exact escape form
  step 2 implements);
- the backtick path renders without a broken code span (assert on the chosen
  escaped form).

**Verify**: `vendor/bin/phpunit --filter a_path_containing_a_pipe` → FAILS.

### Step 2: Escape in the table cell and fix the docblock

Add a small static helper to `MarkdownFormatter`:

```php
/** A diff-derived file path may contain `|` or backticks — the one repo-derived value the
 *  no-escaping rule in the class docblock cannot cover. Escape the pipe for table cells and
 *  swap backticks out of the code span. */
private static function pathCell(string $file): string
{
    $escaped = str_replace('|', '\|', $file);

    return str_contains($escaped, '`') ? '``' . $escaped . '``' : "`{$escaped}`";
}
```

(Double-backtick code spans legally contain single backticks; if the path
contains a double backtick too, accept the imperfect render — pathological
beyond usefulness.) Use it in the table row:
`$lines[] = '| ' . self::pathCell($file) . " | {$nodeCount} | {$coverage} |";`

Correct the class docblock: scope the no-escaping claim to identifier-shaped
values (FQCNs, route/command ids) and name the path cell as the escaped
exception.

Check the findings loop (`:106-112`): if `self::pathCell`-style escaping is
NOT trivially applicable (findings are prose, not cells), leave findings
untouched — the docblock correction covers the reasoning.

**Verify**: `vendor/bin/phpunit --filter MarkdownFormatterTest` → all pass,
including step 1's tests.

### Step 3: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0.

## Test plan

- Step 1's two cases (pipe, backtick) plus the existing formatter tests as the
  regression net for normal paths.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] New test(s) pass; `composer test` exits 0
- [ ] `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0
- [ ] The class docblock no longer claims paths cannot contain `|`/backticks
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The "Current state" excerpts don't match the live code (plan 025 may have
  landed first and moved the table rendering — re-locate it, and if the
  structure changed materially, report instead of adapting blind).
- Escaping a path changes any *existing* test expectation (would mean normal
  paths hit the escape — the helper is wrong).

## Maintenance notes

- Plan 025 (formatter consolidation) must route its shared row-building
  through `pathCell` for path-valued cells — reviewer should check that when
  025 lands after this.
