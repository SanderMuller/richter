# Plan 043: Draw dispatch edges to every DispatchTarget shape, not just job-looking ones

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat b9cf632..HEAD -- src/Tracers/DispatchEdgeTracer.php src/Support/DispatchTarget.php src/Analysis/ImpactAnalyzer.php tests/Unit/DispatchEdgeTracerTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **State**: DONE — the three `isJobClass()` sites now call `DispatchTarget::matches()`;
  `isJobClass`/`isQueueable`/`$jobClassCache` retired (one definition of "dispatch target" across
  the tracer + 036). New test-first coverage: a resolved `dispatch(new ArchiveStalePosts())`
  (plain self-handling `handle()`) and `dispatch(new GenerateReport())` (`Dispatchable`, not
  `ShouldQueue`) now draw an `action-to-job` edge; the non-target case was re-pinned to a real
  loadable class (`Post`) because an unloadable name fails toward firing under `DispatchTarget`.
  Graph/impact expectations needed **no** reconciliation (the fixture project's only dispatch/`new`
  is a job). Benchmark: 29 unit cases green; no `richter.benchmark_cases` corpus configured, so no
  control-case `max_risk` STOP was reachable. Removing the mutable cache made the class
  `readonly` (Rector `ReadOnlyClassRector`, applied). README needed no change — it makes no
  "only jobs get a dispatch edge" claim; the exit-2/determinability wording describes the unchanged
  S2 path. 717 tests green; phpstan/pint/rector clean.
- **Priority**: P2
- **Effort**: M
- **Risk**: MED (safe direction — only ADDS edges, so `affected-tests` can only get MORE
  conservative and `richter:impact` shows MORE reach; never under-selects. The risk is
  over-drawing / risk-level churn and benchmark drift, not a correctness hazard)
- **Depends on**: 036 (uses the `DispatchTarget` predicate it introduced)
- **Category**: correctness/recall (plan 036 round-2 cold-review follow-up)
- **Planned at**: commit `b9cf632`, 2026-07-21

## Why this matters

Both Laravel Brain (`MethodTracer::handleFuncCall`, gated on `looksLikeJob`) and richter's
`DispatchEdgeTracer` (gated on `isJobClass`) draw a `dispatcher → X::handle` edge for a
dispatch **only when the target looks like a job** (`\Jobs\` / `ShouldQueue` / name contains
`Job`). So a *resolved* `dispatch(new SelfHandlingCommand())` — a plain command with `handle()`
or `__invoke()` and no `Dispatchable`/`ShouldQueue`/`\Jobs\`, which Laravel's
`BusDispatcher::dispatchNow` runs via `container->call([$command, 'handle'|'__invoke'])` — draws
**no edge**. The dispatcher is then a hidden caller of the command.

Two consequences:
- **`richter:impact` / `detect-changes` under-draw** the blast radius today: a change to such a
  command shows fewer callers than reality.
- **`affected-tests` can under-select** in the narrow case plan 036 left open: when the graph
  has **no** unfollowable dispatch at all (S2 = false), 036's scoped blocker never fires, and
  the missing edge means the dispatcher's test is dropped. (When S2 = true — the common case —
  036 already catches this via `DispatchTarget` rule 5, since the command reaches the change and
  is in its closure. So this plan closes the *S2 = false* residual and the impact-graph
  under-draw.)

This is a **pre-existing** limitation, not a 036 regression — but 036 introduced the
`DispatchTarget` predicate that makes the fix clean: the edge-drawer and the determinability
predicate should recognise the same set of dispatch targets.

Brain is a vendored dependency and cannot be changed here; richter's `DispatchEdgeTracer`
**supplements** Brain's graph, so widening the tracer draws the edges Brain omits.

## Design (decided — do not re-litigate)

**Replace `DispatchEdgeTracer::isJobClass()` with the shared `DispatchTarget::matches()`** at its
call sites, so the tracer draws its `action-to-job` edge for every shape `DispatchTarget`
recognises (jobs, `Dispatchable` commands, plain self-handling `handle()`/`__invoke()` commands),
not only `\Jobs\`/`ShouldQueue`. The predicate already fails toward "yes" on an unloadable class,
which is the safe direction for edge-drawing too (an extra edge over-selects, never under).

- Keep the edge **type** `action-to-job` (renaming ripples into risk classification + all three
  formatters + JSON/MCP contracts — out of scope; the type string is an internal label, and a
  command-dispatch is risk-bearing exactly like a job-dispatch). Add a short comment that the
  target may now be any dispatch target, not only a job.
- Retire `DispatchEdgeTracer::isJobClass()` / `isQueueable()` (or make them thin forwarders to
  `DispatchTarget::matches`) so there is ONE definition of "dispatch target" across the tracer,
  the determinability blocker (036), and this edge-drawing.
- The `unresolved` counting is **unchanged** — a variable/factory dispatch still counts as S2
  regardless of target. This plan only changes which *resolvable* targets get an edge.

**Cardinal-rule note:** this change only ADDS edges. `affected-tests` can therefore only become
more conservative (more callers found → more blocking / more selection), and `richter:impact`
only shows more reach. It cannot under-select. That is why the risk is churn, not correctness.

## Current state (excerpts — confirm against live code)

- `src/Tracers/DispatchEdgeTracer.php`
  - `:106` — the `new X()` instantiation link: `... && $this->isJobClass($job = ...) && ...`.
  - `:129` — the `'class'` mode (`SomeClass::dispatch(...)`): `$this->isJobClass($site['class']) ? [...] : []`.
  - `:244` — `jobFromNew()`: `return $this->isJobClass($job) ? [$job] : [];`.
  - `:247-265` — `isJobClass()` / `isQueueable()` (the `\Jobs\` + `ShouldQueue` autoload check).
- `src/Support/DispatchTarget.php` — `DispatchTarget::matches(string $fqcn): bool` (from 036):
  class-existence guard first (→ true), then `\Jobs\` / `ShouldQueue` / `Dispatchable` trait /
  `handle()`|`__invoke()`.
- `src/Analysis/ImpactAnalyzer.php:40` — `RISK_EXCLUDED_EDGE_TYPES = ['model-relationship', 'declares', 'uses-trait']`; `action-to-job` is **not** excluded → risk-bearing (this is why reach/risk shift).
- Tests that assert dispatch-edge behaviour (expect updates): `tests/Unit/DispatchEdgeTracerTest.php`,
  `tests/Feature/CodeGraphBuilderTest.php`, `tests/Unit/ImpactAnalyzerTest.php`.
- `richter:benchmark` fixtures involving dispatch/jobs — re-run the benchmark; a widened edge set
  may change a case's reach. Update expectations only where the new reach is *correct* (more
  complete), never to paper over a regression.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'DispatchEdgeTracer|DispatchTarget|CodeGraphBuilder|ImpactAnalyzer'` | OK |
| Benchmark | `vendor/bin/phpunit --filter 'Benchmark'` (and `vendor/bin/testbench richter:benchmark` if a fixture project is configured) | pass / 7-of-7 unchanged unless a case's reach legitimately grew |
| Full suite | `composer test` | `"result":"passed"` (623 at planning time ± test updates) |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | 0 / 0 / 0 |

## Scope

**In scope:**
- `src/Tracers/DispatchEdgeTracer.php` (the three `isJobClass` sites → `DispatchTarget::matches`; retire/forward `isJobClass`/`isQueueable`)
- `tests/Unit/DispatchEdgeTracerTest.php` (+ a self-handling-command edge case; update job-only expectations)
- `tests/Feature/CodeGraphBuilderTest.php`, `tests/Unit/ImpactAnalyzerTest.php` (edge/reach expectations)
- README — if it documents that only jobs get dispatch edges, correct it; note command dispatch is now drawn.

**Out of scope:**
- Renaming the `action-to-job` edge type (ripples into risk/formatters/JSON/MCP).
- The `unresolved` (S2) counting — unchanged.
- `AffectedTests`/`DispatchTarget`/`GraphCache` from 036 — unchanged.
- Brain (vendored).

## Git workflow

- Branch `advisor/043-dispatch-tracer-edge-drawing-parity` from the current `main` tip (if the
  worktree is stale, `git checkout -B <branch> <current-main-sha>` first). Commit per logical unit.
  No signing. End messages with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Do NOT
  push or open a PR.

## Fixtures & anonymization (MANDATORY)

Neutral domain only. Reuse the 036 fixtures where possible: `App\Commands\ArchiveStalePosts`
(plain self-handling), `App\Actions\GenerateReport` (`Dispatchable`), `App\Jobs\PublishPostJob`
(`\Jobs\`). Never a consumer product noun.

## Steps

### Step 1: Point the tracer at DispatchTarget (test-first)

Add a `DispatchEdgeTracerTest` case: a method doing `dispatch(new App\Commands\ArchiveStalePosts())`
now yields an `action-to-job` edge `→ App\Commands\ArchiveStalePosts::handle` (it previously
yielded none). Keep an existing job case green. Then swap the three `isJobClass(...)` calls to
`DispatchTarget::matches(...)` and retire/forward `isJobClass`/`isQueueable`.

**Verify**: `vendor/bin/phpunit --filter 'DispatchEdgeTracer|DispatchTarget'` → pass.

### Step 2: Reconcile graph/impact expectations

Run `CodeGraphBuilderTest` + `ImpactAnalyzerTest`; update any expectation where the new
(correct, more complete) reach changed. Do NOT loosen an assertion to hide a real regression —
every changed expectation must be a case where a command-dispatch edge SHOULD now exist.

**Verify**: `vendor/bin/phpunit --filter 'CodeGraphBuilder|ImpactAnalyzer'` → pass.

### Step 3: Benchmark re-validation + full regression

Run the benchmark. If a case's reach/risk grew because a real command-dispatch edge is now drawn,
that is a correct improvement — confirm it is, and update the fixture expectation with a note.
If a benign-control case now exceeds its `max_risk`, STOP and report (the widening may be
over-drawing). Then `composer test` / `phpstan` / `pint --test` / `rector --dry-run` clean.

## Test plan

The load-bearing new test: a resolved `dispatch(new <self-handling command>)` draws the edge
(Step 1). The benchmark is the guard that the widened edges don't over-inflate risk on the
control cases.

## Done criteria

- [x] The three `isJobClass` sites use `DispatchTarget::matches`; one definition of "dispatch target" across tracer + 036
- [x] A resolved self-handling-command dispatch draws an `action-to-job` edge (new test)
- [x] Graph/impact/benchmark expectations reconciled — each change is a correct reach increase, not a hidden regression (no reconciliation needed — see Status)
- [x] `composer test` / `phpstan` / `pint --test` / `rector --dry-run` clean
- [x] README dispatch-edge wording corrected if stale (no stale claim found); `plans/README.md` row updated

## STOP conditions

- Drift vs the "Current state" excerpts.
- A benchmark **control** case (`expect_signal: false`) now exceeds its `max_risk` — the widening
  is over-drawing; report before adjusting any `max_risk`.
- The widened edge set makes a previously-determinable `affected-tests` case flip to
  undeterminable in a way that looks like over-blocking beyond the intended safe-direction
  conservatism — report for a judgement call.

## Maintenance notes

- After this lands, `DispatchTarget::matches` is the single source of truth for "what is a
  dispatch target" across edge-drawing (this plan) and determinability (036). Keep them together.
- The `action-to-job` type name is now a slight misnomer (it covers commands too). A future rename
  to `action-to-dispatch` is cosmetic but ripples into risk/formatters/JSON/MCP — defer unless a
  consumer is confused by the label.
