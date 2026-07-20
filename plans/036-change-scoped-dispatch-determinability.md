# Plan 036: Stop one unfollowable dispatch anywhere from nullifying `affected-tests`

> ## ⚠ BLOCKED — DO NOT EXECUTE AS WRITTEN (cold review 2026-07-20)
>
> A fresh-context adversarial review found the central safety proof below is
> **false against the live code**, in two independent ways — each reintroduces
> silent under-selection (the one error this tool exists to prevent):
>
> 1. **Unparseable files taint the signal.** `hasUnresolvedDispatches` is also
>    raised by `CodeGraphBuilder.php:231-235` when any app file fails to parse
>    (`$ast === null → ++$unresolved`). Such a file contributes **zero edges**
>    and could be anything — its missing caller edges can reach a non-job change
>    with no job anywhere in the closure, so "no job upstream ⇒ safe to narrow"
>    is false. This is the same could-be-anything taint plan 032 was rejected
>    for scoping.
> 2. **The count is class-agnostic and includes sync command dispatches.**
>    `DispatchEdgeTracer::jobsFromArg` (`:202`) increments on the dispatch *arg
>    shape* with **no `isJobClass` check**, and the counted verbs include
>    `dispatch_sync`/`dispatchNow`/`dispatchSync` (`:38/:40`) whose targets are
>    often `Dispatchable` command objects that are **neither `\Jobs\` nor
>    `ShouldQueue`** — exactly what `JobClass::matches` would fail to recognise.
>
> **Required redesign before any execution** (both are mandatory):
> - **Expand scope into `CodeGraphBuilder`**: split the unparseable-file count
>   from the job-dispatch count so `AffectedTests` can tell a scopeable
>   job-dispatch flag from an un-scopeable "graph is incomplete" flag. An
>   unparseable-file flag must keep blocking determination globally (it is
>   genuine could-be-anything taint); only the pure job-dispatch portion is
>   scopeable.
> - **Fix the predicate/criterion**: either widen it to every bus-dispatchable
>   target the tracer actually counts (incl. non-`ShouldQueue` `Dispatchable`
>   command objects, and account for autoload-fail → treat-as-job), or scope
>   only the portion of the signal whose target category is provably a
>   recognised job. `withUnresolvedJobFlips` keeps a third, `\Jobs\`-only
>   definition — reconcile all three.
> - **Fix the executability nits below**: `select()` is `static`, so the guard
>   must call `self::changeReachesJob(...)`, not `$this->…`; and the node-class
>   extraction instruction (strip `model::`/`view::` prefix vs. "take element 0")
>   is self-contradictory — pick one.
>
> The intent (one unfollowable dispatch must not nullify the feature) stays
> valid; the mechanism needs the redesign above. Everything under this line is
> the ORIGINAL plan, retained for context — its "Design" safety proof is the
> part proven unsound.

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2d8a437..HEAD -- src/Analysis/AffectedTests.php src/Analysis/ImpactAnalyzer.php src/Graph/CodeGraph.php src/Graph/CodeGraphBuilder.php src/Tracers/DispatchEdgeTracer.php tests/Unit/AffectedTestsTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1 (highest — nullifies the flagship `affected-tests` feature on any real app)
- **Effort**: M–L
- **Risk**: HIGH (touches determinability — a wrong scoping reintroduces the one
  error this tool exists to prevent: silent under-selection). Test-first,
  adversarial guards mandatory.
- **Depends on**: none
- **Category**: correctness (consumer dogfood, Finding 1)
- **Planned at**: commit `2d8a437`, 2026-07-20

## Why this matters

`richter:affected-tests` is the headline feature: narrow a large suite to the tests
that can reach a diff. On the reporting consumer's codebase (~6,200 tests) it returns
**nothing usable** — every change reports "could not be determined — run the full
suite" (exit 2). The reason: `AffectedTests::select()` treats a **graph-global**
"there is at least one unfollowable job dispatch anywhere" flag as an unconditional
determinability blocker. Every non-trivial Laravel app has at least one variable/
factory dispatch somewhere, so the flag is permanently `true`, so the feature can
never narrow — it degrades to "always run everything." No value delivered.

The fix is to make the blocker **change-scoped**: an unfollowable dispatch in a module
the change cannot be reached from is irrelevant to that change's test selection.

**This reverses a stance recorded when plan 032 was rejected** ("determinability is
whole-diff"). That rejection was about *UNRESOLVED-file taint*, a different mechanism
where a tainted seed can resolve to anything, so scoping was genuinely unsafe. This
plan scopes a *different* signal — unfollowable **job** dispatches — where the target
category is known (always a job), which is exactly what makes a safe scoping possible.
The Design section proves it. **Flag this reversal to the maintainer in the handoff.**

## Background: two mechanisms already exist, and the handoff's fix is unsafe

Read both before touching code — the interaction is the whole difficulty.

1. **The global blocker (the bug), `src/Analysis/AffectedTests.php:43-45`:**

   ```php
   if ($hasUnresolvedDispatches) {
       $reasons[] = 'the graph contains job dispatches that could not be followed';
   }
   ```

   `$hasUnresolvedDispatches` is `$graph->hasUnresolvedDispatches()` (passed from
   `AffectedTestsCommand.php:83`), a single boolean derived at
   `CodeGraphBuilder.php:173` from a graph-wide count summed across every class
   (`$unresolved += $dispatch['unresolved']` in the per-class loop, `:250`; plus `++`
   for unparseable files, `:235`). Any single unfollowable dispatch → permanently `true`.

2. **The already-scoped coverage flip, `src/Analysis/ImpactAnalyzer.php:362`
   (`withUnresolvedJobFlips`)** — when the graph has unresolved dispatches, it flips a
   *changed* file's coverage to `unresolved` **only** when the file is a `\Jobs\`
   class, non-cosmetic, currently `analyzed`, and reaches **zero** entry points of its
   own. This handles the "orphan changed job" case via the `coverage` signal (which
   `select()` reads at `:35`). It is NOT redundant with the global blocker and is NOT
   in scope to change here — but you must understand it so your new check doesn't
   double-count or contradict it.

3. **The dispatch tracer, `src/Tracers/DispatchEdgeTracer.php`:** `$unresolved` is
   incremented **only** inside recognised dispatch shapes with an unresolvable argument
   (`jobsFromArg`/`jobsFromArray`/`jobFromNew`, `:202/:211/:227/:237`). Crucially, every
   dispatch shape this tracer recognises targets a **job** — `dispatch()`,
   `$this->dispatch()`, `Job::dispatch()`, `Bus::dispatch/chain/batch`, custom helpers.
   So **an unfollowable dispatch is always a hidden `dispatcher → job::handle` edge.**
   Its job predicate is `isJobClass()` (`:247`): `\Jobs\` in the FQCN, or
   `is_subclass_of($fqcn, ShouldQueue::class)` (autoloaded, memoised).

**Why the handoff's suggested fix is unsafe.** The handoff proposes scoping to
"dispatch nodes reachable from the change (in the impacted subgraph)." But the missing
edge `dispatcher → job` is *invisible*, so a dispatcher connected to the change only
through that edge is **not** in the change's known reachable set. Keying off "dispatcher
in the impacted subgraph" would therefore fail to fire in exactly the case that hides a
caller → silent under-selection. Do not implement the handoff's version. Implement the
Design below.

## Current state (excerpts — confirm against live code)

- `src/Analysis/AffectedTests.php`
  - `select(array $result, array $changed, TestReferenceIndex $tests, bool $hasUnresolvedDispatches, ?CodeGraph $graph = null, ?FrontendTestIndex $frontendTests = null)`
    (`:31`). `$result` already carries `callers?: list<array{depth:int, node:string, via:string, ...}>`
    and `entryPoints: list<string>` (docblock `:20-29`). `$changed` is
    `list<ChangedFileSymbols>`, each with a `->fqcn` string.
  - The three global reasons at `:35-45` (unresolved coverage, low confidence,
    unresolved dispatches). Only the third changes.
- `src/Tracers/DispatchEdgeTracer.php:247-265` — `isJobClass()` / `isQueueable()`,
  currently `private`. The canonical job predicate.
- `src/Graph/CodeGraph.php:237` — `callersOf(array $from, int $maxDepth = 6)`;
  `detectChanges` walks callers with `maxDepth = 6` (`ImpactAnalyzer.php:153`), and
  `$result['callers']` is that walk with locations attached. Entry-point discovery uses
  the **same** `maxDepth`, so keying the job check off `$result['callers']` inherits the
  identical depth bound the feature already lives under — no new incompleteness.
- `tests/Unit/AffectedTestsTest.php` — existing selection tests (locate the file;
  it builds a `CodeGraph`, an `ImpactAnalyzer` result, and a `TestReferenceIndex`, then
  asserts `select()`'s `determinable`/`reasons`/`tests`). Model new tests on its style.

## Design (decided — do not re-litigate during execution)

**The safe criterion.** An unfollowable dispatch causes under-selection of a change
`C`'s tests **iff** some job `J` reaches `C` (via known edges) *and* `J` has a hidden
dispatcher — because then `J`'s dispatcher `D` and `D`'s entry points are hidden callers
of `C`. We can't know *which* job is the hidden-dispatch target (that's what
"unfollowable" means), so we must assume any job upstream of `C` could be it. Therefore:

> **Fire the unfollowable-dispatch determinability blocker only when the graph has
> unfollowable dispatches AND a *job class* appears in the change's upward-caller
> closure** — i.e. the changed class itself is a job, or any node in `$result['callers']`
> belongs to a job class.

**Why this is safe (never under-selects).** If no job is in `C`'s upward closure, then
the hidden edge — which always lands on a `job::handle` — cannot insert a hidden caller
into `C`'s caller tree, because there is no job in that tree for it to attach to. So a
change with no job upstream (an unrelated Livewire component, a controller with no queue
interaction, a model method) is genuinely determinable, and narrowing is correct. If a
job *is* upstream, we conservatively fire (possibly over-firing when that specific job
has no hidden dispatcher — acceptable: over-selection is the safe error).

**Why this is useful.** On the reporting consumer's case — a one-line change to an
unrelated Livewire component — no job is upstream, so the blocker no longer fires and
`affected-tests` narrows. That is the unlock.

**The job predicate.** Reuse the tracer's exact predicate so "what counts as a job" is
defined in one place and matches what produced the unresolved count. Extract
`DispatchEdgeTracer::isJobClass()` + `isQueueable()` into a shared, stateless helper
`src/Support/JobClass.php` with a `public static function matches(string $fqcn): bool`
(carry the `\Jobs\` fast-path and the memoised `class_exists && is_subclass_of(ShouldQueue)`
check; a per-call static cache array is fine). Point `DispatchEdgeTracer` at it (its
private methods become thin forwarders or are deleted in favour of the helper — keep its
`$jobClassCache` behaviour intact, so prefer the helper owning a static memo). This
mirrors plan 005's "route all name resolution through the helpers" dedup ethos.

**Where the check lives.** In `AffectedTests::select()`, replace the bare
`if ($hasUnresolvedDispatches)` with `if ($hasUnresolvedDispatches && $this->changeReachesJob($result, $changed))`.
`changeReachesJob` is a new `private static` method:

- returns `true` if any `$changed[i]->fqcn` matches `JobClass::matches()`;
- else `true` if any `$result['callers'][j]['node']`'s class segment (everything before
  the first `::`, `model::`/`view::`-style prefixes stripped to their class if present)
  matches `JobClass::matches()`;
- else `false`.

Extract the class from a node id conservatively: split on `::`, take element 0; if it
contains a `\\` or looks like an FQCN, test it. A node whose class can't be extracted is
**not** treated as a job (it cannot be a `dispatch()` target — those are always resolved
FQCN `Class::handle` ids). Document that reasoning inline.

**Interaction with `withUnresolvedJobFlips`.** Leave it untouched. For a changed job that
reaches ≥1 entry point, that method does not flip coverage, but the new scoped blocker
*does* fire (the changed class is a job) → still "not determinable." For an orphan
changed job, both fire (coverage flip + scoped blocker) — harmless overlap. Net: every
case the global blocker used to catch for a job-touching change is still caught; only
non-job-touching changes are newly freed to narrow.

**The global signal stays.** `CodeGraph::hasUnresolvedDispatches()` remains as the
"graph is imperfect" disclaimer for the human `detect-changes` report and for
`withUnresolvedJobFlips`. Do not remove it. This plan only changes how `AffectedTests`
*uses* it.

**Iron rule unchanged.** This is determinability logic (core reachability), not an
advisory annotation — the security/gate/frontend/test-reference annotations still never
feed selection. Do not let any annotation signal into this check.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'AffectedTestsTest|DispatchEdgeTracerTest|JobClassTest'` | OK |
| Full suite | `composer test` | `"result":"passed"` (580 at planning time + new) |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |
| Rector (check) | `vendor/bin/rector process --dry-run` | 0 changed files |

## Suggested executor toolkit

- Skill `test-writing`; skill `backend-quality` for the closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Analysis/AffectedTests.php` (the scoped blocker + `changeReachesJob`)
- `src/Support/JobClass.php` (new shared predicate)
- `src/Tracers/DispatchEdgeTracer.php` (delegate `isJobClass` to the helper)
- `tests/Unit/AffectedTestsTest.php` (characterization + adversarial + unlock cases)
- `tests/Unit/JobClassTest.php` (new) and, if it exists, the dispatch-tracer test
  (only to confirm the delegation kept behaviour)

**Out of scope** (do NOT touch, even though they look related):
- `src/Analysis/ImpactAnalyzer.php` (`withUnresolvedJobFlips`, risk) — the risk pipeline
  and coverage flips are unchanged by design.
- `src/Graph/CodeGraph.php` / `CodeGraphBuilder.php` — the global count and boolean stay.
- `AffectedTestsCommand.php` — it still passes `$graph->hasUnresolvedDispatches()`.

## Git workflow

- Branch: `advisor/036-change-scoped-dispatch-determinability` from the local main tip.
- Commit per logical unit (JobClass helper + tracer delegation; scoped blocker + tests).
  No signing configured. End messages with:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- Do NOT push or open a PR.

## Fixtures & anonymization (MANDATORY)

Do **not** use the reporting consumer's product nouns. Use the neutral domain that plan
041 makes canonical: models `Post`/`Comment`/`Report`, controllers `PostController`/
`ReportController`, jobs `App\Jobs\PublishPostJob`/`App\Jobs\GenerateReportJob`, Livewire
`App\Livewire\StatusPanel`, routes `posts.show` / `/posts/{post}`. The consumer's live
reproduction ("a one-line change to an unrelated Livewire component while a job dispatch
is unfollowable elsewhere") maps to: a change to `App\Livewire\StatusPanel::refresh`
while `App\Jobs\GenerateReportJob` is dispatched via a variable somewhere unrelated.

## Steps

### Step 1: Extract the job predicate (test-first)

1. New `tests/Unit/JobClassTest.php`: `App\Jobs\PublishPostJob` → true (namespace);
   a `ShouldQueue` subclass outside `\Jobs\` → true; a plain `App\Models\Post` → false;
   a non-existent class → false (no throw). Model autoload-dependent cases on how
   `DispatchEdgeTracerTest` sets up job fixtures today.
2. Create `src/Support/JobClass.php` (`final`, `declare(strict_types=1)`) with
   `public static function matches(string $fqcn): bool` carrying the `\Jobs\` fast-path
   and the memoised `class_exists && is_subclass_of(ShouldQueue::class)` guarded by
   `try/catch (Throwable)`.
3. Point `DispatchEdgeTracer::isJobClass()` at `JobClass::matches()` (keep the public
   dispatch behaviour identical).

**Verify**: `vendor/bin/phpunit --filter 'JobClassTest|DispatchEdgeTracerTest'` → pass.

### Step 2: Characterize the current global blocker (test-first, RED→document)

Add tests to `AffectedTestsTest.php` pinning **today's** behaviour so the change is
visible in the diff:

1. `hasUnresolvedDispatches = true` + a change to a non-job class reaching no job →
   currently `determinable === false` with the dispatch reason. (This is the case the
   next step will flip to `true`.)
2. `hasUnresolvedDispatches = false` → determinable regardless (regression guard).

### Step 3: The scoped blocker + `changeReachesJob`

Implement per Design. Then add the behavioural tests:

- **Unlock (must now narrow):** `hasUnresolvedDispatches = true`, change to
  `App\Livewire\StatusPanel::refresh`, callers contain no job node →
  `determinable === true`, the dispatch reason absent, and the referencing test selected.
- **Adversarial guard A (must stay undeterminable):** change to
  `App\Jobs\PublishPostJob::handle` (changed class is a job) with
  `hasUnresolvedDispatches = true` → `determinable === false`.
- **Adversarial guard B (must stay undeterminable):** change to a service, with
  `$result['callers']` containing `App\Jobs\GenerateReportJob::handle` (a job reaches the
  change) → `determinable === false`.
- **Adversarial guard C (broadened predicate):** same as B but the caller job is a
  `ShouldQueue` class **outside** `\Jobs\` → still `determinable === false` (this is why
  the predicate must be `ShouldQueue`-aware, not `\Jobs\`-only).

Update the `select()` docblock to state the scoped rule and the safety invariant
("a change with no job in its caller closure cannot be reached through a hidden
dispatch, so an unfollowable dispatch elsewhere does not block its determination").

**Verify**: `vendor/bin/phpunit --filter AffectedTestsTest` → all pass.

### Step 4: Full regression

**Verify**: `composer test` → passed; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0; `vendor/bin/rector process --dry-run` → clean.

## Test plan

Steps 1–3 enumerate the cases. The load-bearing ones are the three adversarial guards —
they are the proof the scoping never under-selects. A reviewer must see all three green.
If any guard cannot be made to pass without also breaking the unlock case, **STOP** — the
predicate or the closure source is wrong, and shipping it would under-select.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `src/Support/JobClass.php` exists; `DispatchEdgeTracer` delegates to it; dispatch
      tracer tests unchanged and green
- [ ] The unlock case and all three adversarial guards pass
- [ ] `grep -n 'hasUnresolvedDispatches' src/Analysis/AffectedTests.php` shows the flag
      now `&&`-gated by `changeReachesJob`, not standalone
- [ ] `composer test` exits 0; `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0;
      `vendor/bin/rector process --dry-run` clean
- [ ] No files outside the in-scope list modified (`git status`)
- [ ] `plans/README.md` status row updated (unless a reviewer maintains the index)

## STOP conditions

Stop and report back (do not improvise) if:

- The "Current state" excerpts don't match live code (drift).
- `$result['callers']` turns out NOT to be the transitive upward closure at the same
  `maxDepth` entry-point discovery uses (the safety argument depends on this — if callers
  is narrower than the entry-point walk, the job check would miss a job the walk sees →
  under-selection). Report the discrepancy instead of proceeding.
- An adversarial guard cannot be made green alongside the unlock case.
- You find yourself tempted to key the check off the dispatcher's position (the handoff's
  unsafe suggestion) rather than the change's upstream job presence.

## Maintenance notes

- The scoping's safety rests on one fact: the tracer only counts unresolved dispatches for
  job-targeting verbs. If a future change makes the tracer count unresolved **event** or
  **mailable** dispatches, the closure predicate must widen to those target categories, or
  the scoping becomes unsafe for them. Guard that with a test when it happens.
- `JobClass::matches()` is now the single definition of "job" for both the tracer and
  selection — keep it in sync with any risk-floor namespace changes in `ImpactAnalyzer`.
- If consumers report the blocker still over-fires (a job legitimately in the closure but
  provably not the hidden target), the next refinement is per-job dispatch-followability,
  which needs the tracer to record *which* jobs have hidden dispatchers — a larger change,
  deferred until measured.
