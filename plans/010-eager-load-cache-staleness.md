# Plan 010: Investigate (and, if confirmed, fix) the process-lifetime staleness of the eager-load model-methods cache

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

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug (investigate-first — the finding is LOW-confidence)
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

`EagerLoadStringChecker` memoizes the union of all model method names in a **static** property for the lifetime of the PHP process. Richter's service provider registers a long-lived MCP server, so one process can serve many `detect-changes` calls across ongoing development: a developer who adds a relation mid-session can then get a **false finding** ("segment '…' is not a method on any model") for a perfectly valid new relation — a confident false alarm from the very checker whose design comments obsess over avoiding them. The window is narrow (models must change within one MCP-server lifetime), which is why this is investigate-first: confirm the mechanism with a test, then apply the smallest fix that removes the staleness without re-paying the model scan per file.

There is a promising shape for that fix already in the code: `ChangedSymbols::classifyFile()` accepts an injectable checker instance, but `resolve()` constructs **a new checker per changed file** implicitly — one shared per-run instance would make the per-instance cache do the work the static cache does today, scoped to a single run.

## Current state

- `src/Tracers/EagerLoadStringChecker.php:48-54` — the static tier:

```php
/**
 * Memoized only when the build was complete — an incomplete set must be retried, not cached
 * across instances. Keyed by models path so checkers pointed at different trees never share a set.
 *
 * @var array<string, array<string, true>>
 */
private static array $modelMethodsByPath = [];
```

- `:57-61` — the per-instance tier: `private ?array $modelMethodsCache = null;` ("so an incomplete build is still computed once per checked file, not once per expression").
- `:217-275` — `modelMethods()`: returns the static entry when present (`:221-223`); otherwise scans `app/Models`, builds the set via `class_exists`/`get_class_methods`, and memoizes statically **only when complete** (`:270-272`).
- `src/Changes/ChangedSymbols.php:144` — the construction site, one new checker per classified file:

```php
$findings = $members === [] ? [] : ($eagerLoadChecker ?? new EagerLoadStringChecker())->findingsFor($headSrc);
```

  and `classifyFile()`'s signature (`:77`) already accepts `?EagerLoadStringChecker $eagerLoadChecker = null`. `resolve()` (`:21-74`) calls `classifyFile` at `:59` **without** passing a checker.
- `src/RichterServiceProvider.php:33-40` — `packageBooted()` registers the MCP server (`Mcp::local('richter', RichterServer::class)`): the long-lived process this matters in. `GraphCache` (`:30`) is a singleton for the same reason — but note the graph cache does NOT help here: the model-methods set is consulted per `detect-changes` run on diffed sources, independent of graph builds.
- Existing tests: `tests/Unit/EagerLoadStringCheckerTest.php` — constructs checkers with an explicit `modelsPath` (see its `findings()` helper), covering the checker's validation semantics. No test covers cross-instance staleness.
- Conventions: PHPUnit 12 `#[Test]`, snake_case test names, temp dirs via test-local setup (see `tests/Feature/GraphCacheTest.php:32` for the write-a-temp-app-file pattern).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Checker tests | `vendor/bin/phpunit --filter EagerLoadStringCheckerTest` | all pass |
| Changed-symbols tests | `vendor/bin/phpunit --filter ChangedSymbolsTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Tracers/EagerLoadStringChecker.php`
- `src/Changes/ChangedSymbols.php`
- `tests/Unit/EagerLoadStringCheckerTest.php`

**Out of scope** (do NOT touch, even though they look related):

- `GraphCache` — its fingerprint covers graph inputs, not this checker's scan; wiring the two together is a bigger design than this staleness warrants.
- The reflection-based scan itself (`class_exists`/`get_class_methods`) — that is audit finding 3's deferred static-resolution spike.
- `RichterServiceProvider` — no new bindings needed for the recommended fix shape.

## Git workflow

- Branch: `advisor/010-eager-load-cache-staleness` off `main`.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Reproduce the staleness in a test

Add a test to `tests/Unit/EagerLoadStringCheckerTest.php` that:

1. Creates a temp models directory with one model class defining relation `alpha()` (follow the temp-dir pattern from `GraphCacheTest`; the class needs a unique namespace/name per test run to avoid autoloader collisions — since the scan uses `class_exists`, the class must be loadable: simplest is `eval` or writing a file and `require`-ing it, matching however the existing checker tests provide loadable models — inspect the existing test file's fixtures first and reuse its mechanism).
2. Runs a checker against source using a *valid* constant-backed `alpha` load — no findings (this warms the static cache for that path).
3. Adds relation `beta()` (new model class in the same temp dir, loadable).
4. Runs a **new checker instance** against source loading `beta` — with the current code this yields the false finding (the static set is stale).

Assert the false finding occurs — pinning the bug. Mark the test clearly (name it e.g. `a_relation_added_after_the_first_scan_is_reported_as_missing_until_the_process_restarts`).

If the reproduction fails — the static cache does not behave as this plan models — STOP and report; the finding dies here and the plan should be marked REJECTED with that evidence.

**Verify**: `vendor/bin/phpunit --filter EagerLoadStringCheckerTest` → the new test passes (asserting the current stale behavior).

### Step 2: Apply the scoped-cache fix

The recommended shape (smallest change that removes cross-run staleness without re-paying the scan per file):

1. **Delete the static tier** (`self::$modelMethodsByPath` and its two uses), keeping the per-instance cache.
2. **Share one checker per run**: in `ChangedSymbols::resolve()`, construct a single `EagerLoadStringChecker` before the loop and pass it to every `classifyFile()` call (the parameter already exists). Now the model scan runs at most once per `detect-changes` invocation — same cost profile as today within a run, fresh set every run.
3. Update the class docblock and the `modelMethodsByPath` comment block accordingly (the "incomplete build must be retried" caveat now applies to the instance cache only — and note `modelMethodsCache` currently memoizes incomplete sets per instance deliberately; keep that behavior).

Then **flip the step-1 test's assertions**: the second run must now see `beta` as valid (rename the test accordingly, e.g. `a_relation_added_between_runs_is_seen_by_the_next_run`).

**Verify**: `vendor/bin/phpunit --filter 'EagerLoadStringCheckerTest|ChangedSymbolsTest'` → all pass with the flipped assertion.

### Step 3: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.
- `grep -c "static array \$modelMethodsByPath" src/Tracers/EagerLoadStringChecker.php` → `0`.

### Step 4: Update the index

Set this plan's row in `plans/README.md` to `DONE` (or `REJECTED` with the step-1 evidence if the reproduction failed).

**Verify**: `grep -n "010" plans/README.md` → row updated.

## Test plan

- Step 1: a staleness-reproduction test (pins the bug, then flips to pin the fix).
- Step 2's flip: the same test asserting freshness across checker instances/runs.
- Existing `EagerLoadStringCheckerTest` and `ChangedSymbolsTest` assertions must pass unmodified — the checker's validation semantics and the per-file findings wiring must not change.
- Verification: `composer test` → green.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] The staleness test exists and asserts fresh-per-run behavior
- [ ] `grep -c "static array" src/Tracers/EagerLoadStringChecker.php` outputs `0`
- [ ] `ChangedSymbols::resolve()` constructs exactly one checker per invocation (visible in the diff)
- [ ] `composer test` exits 0, 0 failures, zero *existing* assertions modified
- [ ] `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0; `vendor/bin/rector process --dry-run` clean
- [ ] `git status --short` shows changes only in the three in-scope files plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Step 1 cannot reproduce the staleness (mark REJECTED with evidence — that is a valid, useful outcome).
- Making the models loadable in the test requires machinery the existing test file doesn't already have and that would exceed ~30 lines of setup — report the constraint instead of building a fixture framework.
- Removing the static tier makes any existing test measurably slow (the scan cost moved) — report timings; the fallback design (keep the static tier but key it by a cheap directory fingerprint) needs a maintainer decision.
- `classifyFile()`'s injectable-checker parameter has been removed in the working tree.

## Maintenance notes

- The eager-load checker still autoloads model classes (`class_exists`/`get_class_methods`) — that is audit finding 3's territory and untouched here.
- Reviewer scrutiny: the per-instance incomplete-set semantics (`modelMethodsCache` memoizing even incomplete sets, with the visible skip-note finding) must survive — the fix only changes *how long* a complete set lives, not the incomplete-set honesty.
- If a future change makes MCP serve `detect-changes` from a checker held in a singleton, the staleness returns — the one-checker-per-`resolve()` scoping is the invariant to protect in review.
