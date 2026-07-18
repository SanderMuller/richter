# Plan 013: Make pure renames visible — a moved class must never read as "no impact"

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: compare every "Current state" excerpt below
> against the live code; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW-MED (parser + resolve branch; every change is additive and pinned by new tests)
- **Depends on**: none (plan 001's `$inHunk` guard, which this builds on, is merged)
- **Category**: bug (audit finding 4)
- **Planned at**: main @ `00bc642`, 2026-07-18 — baseline suite 337 tests

## Why this matters

A 100%-similarity rename (moving a class file without editing it) emits a diff section containing only `diff --git`, `similarity index 100%`, `rename from`, and `rename to` — no `---`/`+++` headers, no hunks. `UnifiedDiffParser::parse()` reacts to none of those lines, so the section produces no entry, `ChangedSymbols::resolve()` iterates nothing, and the report says **"no impact" for a change that breaks every caller of the old FQCN**. This is the last known correctness gap where Richter violates its own falsely-empty-never promise. The fix has two halves: the parser must register a hunk-less rename section, and the resolver must treat it as a real class-level change that seeds **the old FQCN** (whose callers, still referencing it in the head tree, are exactly the blast radius) as well as the new one.

## Current state

- `src/Changes/UnifiedDiffParser.php:19-96` — `parse()` after plan 001. The loop recognises `diff --git ` (resets `$current`/`$pendingOld`/`$inHunk`), `! $inHunk`-guarded `--- `/`+++ ` headers (entry registration happens **only** in the `+++ ` branch, `:55-67`), `@@` (sets `$inHunk`), and `+`/`-` content lines. `rename from `/`rename to `/`copy from `/`copy to ` lines match no branch and are skipped. Entries are assembled at `:89-93` as `['added' => ..., 'removed' => ..., 'oldPath' => ...]` keyed by new-side path. **Return shape must not change** — existing tests `assertSame` full entry arrays.
- A content-carrying rename (the common case) already works: git emits `rename from`/`rename to` **plus** `---`/`+++`/`@@`, the `+++ ` branch registers the entry, and `--- a/<old>` supplies `oldPath`. Pinned by `tests/Unit/UnifiedDiffParserTest.php` — `it_records_the_old_path_of_a_renamed_file` (its heredoc fixture contains the `rename from`/`rename to` lines).
- `src/Changes/ChangedSymbols.php:44-66` — the resolve loop's app-PHP branch. It already has one pre-`classifyFile` special case (the unreadable-head-source coarse seed at `:52-59`); the pure-rename branch belongs in the same position. `classifyFile` on an empty hunk would return `members === []` → `cosmeticOnly: true` → seeds nothing — the bug's downstream half.
- `src/Changes/ChangedFileSymbols.php:22-29` — constructor `(string $file, string $fqcn, array $members, bool $cosmeticOnly, array $directSeeds = [], array $findings = [])`. `directSeeds` flow through `ImpactAnalyzer::seedsFor()` as precise seeds (the Blade-view mechanism) — the right channel for the old FQCN, which is exact.
- `src/Support/Fqcn.php` — `Fqcn::fromPath('app/Services/Old.php')` → `App\Services\Old` (used throughout the resolve loop).
- Detection invariant (why no shape change is needed): a synthesized pure-rename entry is uniquely `added === [] && removed === [] && oldPath !== file`. No other entry shape produces that — a registered `+++ ` section under `-U0` always carries content lines, deletions key on the old path (`oldPath === file`), binary and mode-only sections register nothing.
- Blade note: a pure-renamed non-PHP file falls through to the existing view branch keyed on the **new** path — unchanged behavior, explicitly out of scope here (the audit finding is about PHP classes).
- Test idioms: `tests/Unit/UnifiedDiffParserTest.php` (heredoc `DIFF` fixtures, exact `assertSame` on entries); `tests/Unit/ChangedSymbolsTest.php` (fakes git via `Process::fake`, asserts `ChangedFileSymbols` fields — model after `a_modified_method_is_a_resolvable_modification`); `tests/Unit/ImpactAnalyzerTest.php` (hand-built `CodeGraph` edge arrays + `detectChanges` result assertions, helpers like `changedCoarse()` at the bottom).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Parser tests | `vendor/bin/phpunit --filter UnifiedDiffParserTest` | all pass |
| Resolver tests | `vendor/bin/phpunit --filter 'ChangedSymbolsTest|ImpactAnalyzerTest'` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (337 baseline + new tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Changes/UnifiedDiffParser.php`
- `src/Changes/ChangedSymbols.php` (the resolve-loop branch only)
- `tests/Unit/UnifiedDiffParserTest.php`
- `tests/Unit/ChangedSymbolsTest.php`
- `tests/Unit/ImpactAnalyzerTest.php`

**Out of scope** (do NOT touch, even though they look related):

- `ChangedSymbols::classifyFile()` and everything below it — the pure-rename branch runs *before* classification, like the existing unreadable-head branch.
- `ChangedFileSymbols`, `MemberChange`, `ImpactAnalyzer`, the formatters — the existing seed/report machinery already handles what the new branch emits.
- Blade-view rename semantics (see Current state).
- Return-shape changes to `parse()` — the detection invariant makes them unnecessary.

## Git workflow

- Branch: `advisor/013-pure-rename-detection` off main (`00bc642` or its current descendant — run the drift check).
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR.

## Steps

### Step 1: Failing parser tests for the missing rename entries

Add to `tests/Unit/UnifiedDiffParserTest.php`:

1. `it_registers_a_pure_rename_with_no_hunks` — fixture:

```
diff --git a/app/Services/Old.php b/app/Services/New.php
similarity index 100%
rename from app/Services/Old.php
rename to app/Services/New.php
```

Assert: `$parsed === ['app/Services/New.php' => ['added' => [], 'removed' => [], 'oldPath' => 'app/Services/Old.php']]`.

2. `it_registers_a_pure_rename_followed_by_another_file` — the same rename section followed by a normal one-line modification section for `app/Foo.php`; assert both entries (the rename flushed by the next `diff --git`, the modification parsed normally).
3. `it_does_not_duplicate_a_content_rename` — reuse the existing content-rename fixture shape (rename lines + hunks) and assert `count($parsed) === 1` with the same entry the existing test pins.
4. `it_ignores_a_pure_copy` — `copy from`/`copy to` section with no hunks → `[]` (a copy leaves the original intact; a pure copy is additive by design and additive changes seed nothing).

**Verify**: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → exactly tests 1 and 2 fail (empty result / missing rename entry); 3 and 4 pass against current code. Any other failure shape is a STOP condition.

### Step 2: Parser — synthesize hunk-less rename sections

In `parse()`:

1. Track `$pendingRenameFrom`/`$pendingRenameTo` (both `?string`, reset in the `diff --git ` branch): in the preamble (`! $inHunk`), lines starting `rename from ` / `rename to ` capture the remainder of the line as the path.
2. Flush at section end — both when the next `diff --git ` line arrives (before resetting state) **and** once after the loop: when both parts are pending and no entry keyed on the rename-to path exists, register `$added[$to] = []; $removed[$to] = []; $oldPaths[$to] = $from;`. A content rename registers normally via `+++ ` and thus skips the flush (`isset($oldPaths[$to])`).
3. `copy from`/`copy to` lines stay unrecognised (test 4 pins that) — add no branch for them.
4. One comment on the flush stating the constraint, in the file's style: a 100%-similarity rename emits no hunks, so the section registers nothing through `+++ ` — without the flush the vanishing FQCN reads as "no impact".

**Verify**: `vendor/bin/phpunit --filter UnifiedDiffParserTest` → all pass (existing 11 + new 4).

### Step 3: Failing resolver test, then the resolve branch

1. Add to `tests/Unit/ChangedSymbolsTest.php` (model the git fakes on the file's existing tests): `a_pure_rename_is_a_class_level_change_that_seeds_both_fqcns` — fake `git diff` returning the step-1 pure-rename fixture (plus whatever `merge-base`/`show` fakes the sibling tests provide); assert exactly one `ChangedFileSymbols` with: `file === 'app/Services/New.php'`, `fqcn === 'App\Services\New'`, `cosmeticOnly === false`, one member that is a class-kind non-resolvable modification, and `directSeeds === ['App\Services\Old']`.

   **Verify**: the new test fails against current code with an *empty* `$changed` (the parser now emits the entry, but `classifyFile` reads it as cosmetic — assert the failure is the cosmetic/empty shape, anything else is a STOP condition). Note: after step 2 the entry exists, so the failure mode is `cosmeticOnly: true`/no members, not a missing entry.

2. In `ChangedSymbols::resolve()`, insert the branch **before** the unreadable-head-source check (both are pre-classification special cases; rename first, since a pure rename has no added lines and would otherwise fall through on `$headSrc`):

```php
// A 100%-similarity rename emits no hunks, but the old FQCN disappears — every caller of it
// breaks. Never cosmetic: seed the vanished old FQCN directly (head-tree callers still
// reference it) and the new FQCN coarsely (a class-level change with no member to pin).
if ($hunk['added'] === [] && $hunk['removed'] === [] && $hunk['oldPath'] !== $file) {
    $changed[] = new ChangedFileSymbols($file, Fqcn::fromPath($file), [
        new MemberChange('', MemberChange::KIND_CLASS, MemberChange::CHANGE_MODIFIED, resolvable: false),
    ], cosmeticOnly: false, directSeeds: [Fqcn::fromPath($hunk['oldPath'])]);

    continue;
}
```

**Verify**: `vendor/bin/phpunit --filter ChangedSymbolsTest` → all pass.

### Step 4: Analyzer-level blast-radius test

Add to `tests/Unit/ImpactAnalyzerTest.php`: `a_pure_rename_reaches_the_old_fqcns_callers` — build a `CodeGraph` where an entry point references the **old** FQCN (e.g. `['source' => 'route::GET::/r', 'target' => 'App\Services\Old', 'type' => 'references']`), run `detectChanges` with a hand-built `ChangedFileSymbols('app/Services/New.php', 'App\Services\New', [<class-level non-resolvable modification>], cosmeticOnly: false, directSeeds: ['App\Services\Old'])`, and assert: `entryPoints` contains `route::GET::/r` and `coverage['app/Services/New.php'] === 'analyzed'`. (Model the construction on the file's existing hand-built-graph tests and helpers.)

**Verify**: `vendor/bin/phpunit --filter ImpactAnalyzerTest` → all pass.

### Step 5: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures, zero existing assertions modified.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

## Test plan

Six new tests, all named above: four parser (pure rename EOF-flush, pure rename mid-stream flush, content-rename no-duplicate, pure-copy ignored), one resolver (both-FQCN seeding, never cosmetic), one analyzer (old-FQCN callers are the reported blast radius). Zero existing assertions may change.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter UnifiedDiffParserTest` exits 0 with 15 tests
- [ ] `vendor/bin/phpunit --filter 'ChangedSymbolsTest|ImpactAnalyzerTest'` exits 0, including the two new tests
- [ ] `composer test` exits 0, 0 failures, zero existing assertions modified
- [ ] `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0; `vendor/bin/rector process --dry-run` clean
- [ ] `git status --short` clean after commit; changes only in the five in-scope files
- [ ] `plans/README.md` untouched (reviewer maintains it)

## STOP conditions

Stop and report back (do not improvise) if:

- Any "Current state" excerpt no longer matches the live code.
- Step 1's tests 3 or 4 fail against the *unmodified* code — the plan's model of current behavior is wrong.
- The step-3 pre-fix failure is anything other than the cosmetic/empty shape described.
- `directSeeds` turn out not to flow through `ImpactAnalyzer::seedsFor()` for a bare FQCN (the step-4 test would expose this) — report rather than switching seed channels ad hoc.
- Any existing test assertion would need modification.

## Maintenance notes

- `git diff` rename detection is on by default (`diff.renames`); a host repo with `diff.renames=false` never emits rename sections (renames appear as delete+add with full hunks — already handled). If Richter ever pins diff flags explicitly (`--find-renames`), revisit this note.
- Reviewer scrutiny: the flush must fire on **both** section boundaries (next `diff --git` and end-of-input), and must not overwrite a content rename's entry (`isset` guard) — those are the two easy-to-miss paths, and tests 2 and 3 pin them.
- The old FQCN rides `directSeeds` (precise, exact-FQCN) while the new FQCN rides the coarse class member (triggers the low-confidence cap when it resolves) — deliberate: the old side is exact knowledge, the new side is an estimate.
