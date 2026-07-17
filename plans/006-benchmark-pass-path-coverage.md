# Plan 006: Exercise the benchmark's pass path end-to-end so the accuracy net actually runs in CI

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: This plan was written against a working tree
> with uncommitted changes on top of commit `50a0efa`, so a commit-range diff
> is not a reliable drift signal. Instead, compare every "Current state"
> excerpt below against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none (pairs well after plan 001 — the parser fixes make the replay guard real changes)
- **Category**: tests
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

`richter:benchmark` is documented as the trustworthiness regression net: bug fixtures must resolve and reach an entry point, benign controls cap over-reporting. But nothing anywhere executes its *pass* path: `benchmark_cases` ships empty, CI runs only PHPUnit, and the existing tests cover only the warn/no-match/skip/fail branches. `BenchmarkCase::evaluate()`'s scoring is unit-tested in isolation, yet no test proves the full chain — config → commit check → historical diff → member resolution → graph walk → PASS — ever produces a green result, nor that a control fixture is scored end-to-end. A regression that breaks the replay pipeline itself (or silently stops evaluating controls) would ship with a green build. Because benchmark cases are inherently host-app-specific (real commits in a consuming app's history), the right fix for this *package* is feature tests with faked git plumbing against the real fixture-project graph — the same pattern `detect_changes_reports_a_real_diff_end_to_end` already uses.

## Current state

- `src/Console/BenchmarkCommand.php` — the command. Key mechanics:
  - `:36-40` — warns and exits SUCCESS when `RichterConfig::benchmarkCases()` is `[]`.
  - `:79-83` — per case, `git cat-file -e --end-of-options {fixCommit}^{commit}` decides run vs. SKIP.
  - `:86` — `ChangedSymbols::resolve("{$case->fixCommit}^", $case->fixCommit)` replays the historical diff.
  - `:93-94` — `$result = $analyzer->detectChanges($changed); $failures = $case->evaluate($result);`
  - `:100-103` — empty failures → `PASS`; `:68` — score line `"Score: {$passed} passed, {$failed} failed{$skipSuffix} of N fixtures."`; `:70` — exit SUCCESS only when `$failed === 0`.
- `src/Analysis/BenchmarkCase.php:73-102` — `evaluate()`: an `expectSignal` case fails on any `unresolved` coverage or empty `entryPoints`; a control (expectSignal false) fails when it resolved nothing (drift guard) or when `risk` exceeds `maxRisk`.
- `src/Changes/ChangedSymbols.php:21-35` — `resolve($base, $head)`: runs `git diff -U0 --end-of-options {base}...{head}` and `git merge-base`; **when `$head !== 'HEAD'` the head-side source comes from `git show {head}:{file}`** (`headSource()`, `:284-301`) and the base side from `git show {mergeBase}:{file}` (`baseSource()`, `:272-281`). So a benchmark replay is fully drivable through `Process::fake` — no real git history needed.
- `tests/Feature/CommandsTest.php` — the test file and its established fakes:
  - `benchmark_skips_a_configured_case_whose_commit_is_unavailable` (`:34`) — `Process::fake(['*cat-file*' => Process::result(errorOutput: 'missing', exitCode: 1)])`.
  - `benchmark_fails_a_case_whose_diff_cannot_be_resolved` (`:220`) — fakes `*cat-file*` success + `*diff*` failure.
  - `detect_changes_reports_a_real_diff_end_to_end` (`:95`) — the pattern to copy: fake `*merge-base*`, `*show*`, `*diff*`; real graph build against the testbench skeleton; `withoutMockingConsoleOutput()` + `Artisan::call()` + assertions on `Artisan::output()`.
  - Helpers: `runArtisan()` (`:379`), `benchmarkCase()` (`:389`) returning the CASE-1 array shape.
- `tests/TestCase.php` — `defineEnvironment` disables the graph cache for all tests; `fixtureProjectPath()` points at the mini Laravel app. NOTE: the commands build the graph via `GraphCache->graph()` with `base_path()` as project root (the testbench skeleton, not the fixture project) — the existing end-to-end tests get an *empty-ish* graph and assert UNRESOLVED honesty. For a **PASS** case this plan needs the diff to reach a real entry point, so the test must make the graph see real edges. The proven route in this suite: `detect_changes` tests fake `git show` as failing and use files under `app/Models/...` that exist in the *skeleton* tree — see step 1 for the working recipe this plan requires.
- Repo conventions: PHPUnit 12 `#[Test]`, snake_case names, `Process::fake` with pattern keys, `<?php declare(strict_types=1);`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `vendor/bin/phpunit --filter CommandsTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `tests/Feature/CommandsTest.php`

**Out of scope** (do NOT touch, even though they look related):

- `src/Console/BenchmarkCommand.php`, `src/Analysis/BenchmarkCase.php` — this plan adds coverage, not behavior. If a test cannot pass without a source change, STOP.
- `config/richter.php` — `benchmark_cases` stays `[]` by default; cases are host-app-specific by design.
- `.github/workflows/run-tests.yml` — the feature tests ARE the CI execution; no separate benchmark CI job is needed once they exist.
- The README's benchmark section.

## Git workflow

- Branch: `advisor/006-benchmark-pass-path-coverage` off `main`.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Establish the working PASS recipe

Goal: a faked replay whose diff resolves to a member and reaches an entry point, so `evaluate()` returns no failures.

Build the test as follows (adapt names to taste, keep the mechanics):

1. Choose a target that reaches an entry point through the *skeleton* graph. The `detect_changes_json_carries_the_entry_point_paths_field` test (`:131`) proves a diff against `app/Models/User.php` with an unreadable head source produces entry-point data end-to-end — but an UNRESOLVED coverage fails `evaluate()` for a signal case. So for the PASS case the head source must *resolve*: fake `git show` to return a real PHP class source whose changed member exists, e.g. the skeleton `User` model source with a modified existing method.
2. Fake the four git calls the replay makes, keyed per binary: `'*cat-file*' => Process::result()` (exists), `'*merge-base*' => Process::result("base123\n")`, `'*diff*' => Process::result($diff)` where `$diff` modifies an existing method of `app/Models/User.php` (copy the `-U0` diff shape from `detect_changes_reports_a_real_diff_end_to_end` but make it a modification: one `-` line and one `+` line inside an existing method), and `'*show*' => Process::result($sourceOfUserModel)` — the same source for base and head sides is fine as long as the diff marks a member change (the member resolver pins members by line span; keep the diff's line numbers inside the changed method's span in that source).
3. Config: `config()->set('richter.benchmark_cases', [$this->benchmarkCase()])` (CASE-1, `expect_signal => true`).
4. Assert: output contains `PASS` and `Score: 1 passed, 0 failed of 1 fixtures.`, exit code success (`->assertSuccessful()` via `runArtisan`, or the `Artisan::call` pattern if output assertions need the raw buffer).

The one genuinely fiddly part is step-2's line-span alignment: the `+`/`-` line numbers in the fake diff must fall inside the span of a method that exists in the faked `git show` source, or the change reads class-level/UNRESOLVED. Derive the line numbers by counting lines in the exact source string the test fakes — do not guess.

**Verify**: `vendor/bin/phpunit --filter benchmark_passes` (name the test so the filter hits) → 1 test, passing, with `PASS` asserted in output.

### Step 2: Add the control-case test

Same fake structure, but the case array has `expect_signal => false` and `max_risk => 'high'`, and the faked diff is a *benign* change (e.g. the same modification — a control only fails when risk exceeds `maxRisk` or it resolves nothing). Assert `PASS` and the score line. Then add the cap-trip variant: set `max_risk => 'low'` and pick a diff that produces at least MEDIUM risk (a change reaching one entry point does — `ImpactAnalyzer::risk()`: `entryPoints >= 1 → Medium`), and assert `FAIL — risk` appears with exit failure — this is the "control flipping green→red is detectable" guarantee, exercised through the real command.

**Verify**: `vendor/bin/phpunit --filter CommandsTest` → all pass, including the two new control tests.

### Step 3: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 4: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "006" plans/README.md` → row shows DONE.

## Test plan

- Three new tests in `tests/Feature/CommandsTest.php`:
  1. `benchmark_passes_a_signal_case_end_to_end` — faked replay resolves, reaches an entry point, scores PASS, exits success.
  2. `benchmark_passes_a_control_case_within_its_risk_cap` — control fixture evaluated end-to-end, PASS.
  3. `benchmark_fails_a_control_case_that_exceeds_its_risk_cap` — `max_risk => 'low'` control trips, `FAIL — risk` in output, exit failure.
- Modeled after `detect_changes_reports_a_real_diff_end_to_end` (Process::fake + real graph + raw-output assertions) and the existing benchmark branch tests.
- Verification: `vendor/bin/phpunit --filter CommandsTest` → all pass.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter CommandsTest` exits 0 and includes 3 new benchmark tests (PASS path, control PASS, control FAIL)
- [ ] The PASS test asserts both `PASS` and `Score: 1 passed, 0 failed of 1 fixtures.`
- [ ] `composer test` exits 0, 0 failures
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in `tests/Feature/CommandsTest.php` plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The PASS recipe cannot produce `coverage: analyzed` + a reached entry point after two careful attempts at the line-span alignment — report the exact output of the command under the fake; the blocker is likely a real gap worth its own finding, not a test problem to force.
- Any change to `src/` appears necessary to make a test pass.
- `Process::fake` pattern keys collide (e.g. `*show*` also matching another git call the command makes) in a way that cannot be disambiguated with more specific patterns — report the collision.

## Maintenance notes

- These tests pin the replay *pipeline*; they do not make the package's own history replayable (that stays host-app territory, and `benchmark_cases` stays `[]` by design). If the maintainer later wants dogfooded real-history cases, that is audit direction item D4 (a `benchmark:add` scaffolder), not an extension of this plan.
- Reviewer scrutiny: the faked `git show` must return the same source for the `{mergeBase}:{file}` and `{head}:{file}` shapes (one pattern key covers both) — if a future test needs them to differ, switch to a callback fake keyed on the full command array.
- If plan 001's parser fixes land after this, nothing here changes — the fake diffs use plain modifications the current parser already handles.
