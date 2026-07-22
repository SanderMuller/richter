# Plan 036 (v2 redesign): Stop one unfollowable dispatch anywhere from nullifying `affected-tests`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **This is the v2 redesign.** v1 was BLOCKED by a cold review that proved its
> safety argument unsound in two ways (see "Why v1 was unsound"). v2 fixes both,
> and its design has itself passed a fresh cold review (verdict: sound; the one
> blocking gap it found — the GraphCache round-trip — is folded in below).
> **Maintainer sign-off on the residual: given — Option A (see Design).**
>
> **DONE 2026-07-21 — integrated to `main` (`7c44ba0`→`b9cf632`), 623 tests green.**
> The code passed a two-round fresh cold review: round 1 found a real under-selection
> (plain self-handling commands with `handle()`/`__invoke()` and no `Dispatchable`
> were missed) — fixed by `DispatchTarget` rule 5. Round 2 (NEEDS-CHANGES) found that
> `DispatchEdgeTracer`/Brain draw a `dispatcher→command` edge only for job-looking
> targets, so a *resolved* `dispatch(new SelfHandlingCommand())` draws no edge — a
> **pre-existing** limitation in both, confirmed against vendor code, that 036 neither
> introduces nor worsens (caught when S2=true via rule 5; the S2=false residual is
> identical pre/post-036 and rare). Dispositioned to follow-up **plan 043** (widen the
> tracer's edge-drawing to the `DispatchTarget` predicate — a change with `richter:impact`/
> risk ripple, deserving its own review).
>
> **Drift check (run first)**: `git diff --stat c1f41d4..HEAD -- src/Analysis/AffectedTests.php src/Analysis/ImpactAnalyzer.php src/Graph/CodeGraph.php src/Graph/CodeGraphBuilder.php src/Tracers/DispatchEdgeTracer.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1 (nullifies the flagship `affected-tests` on any real app)
- **Effort**: L
- **Risk**: HIGH — determinability + the never-under-select cardinal rule. Mandatory:
  characterization tests, adversarial under-selection guards, a fresh cold review, and
  maintainer sign-off on the documented residual.
- **Depends on**: none
- **Category**: correctness (consumer dogfood, Finding 1)
- **Planned at (v2)**: commit `c1f41d4`, 2026-07-21 (v1 planned at `2d8a437`)

## Why this matters

`richter:affected-tests` is the headline feature. On a large app it returns nothing usable
because `AffectedTests::select()` treats a graph-global "there is at least one unfollowable
dispatch anywhere" flag as an unconditional determinability blocker. Every real Laravel app
trips it, so the feature permanently degrades to "run the full suite." The goal is to make
the blocker **change-scoped**: an unfollowable dispatch a change cannot be reached from is
irrelevant to that change's test selection.

## Why v1 was unsound (the two holes the cold review found — both must stay fixed)

v1 scoped the blocker to "fire only if a `\Jobs\`/ShouldQueue job is in the change's
upward-caller closure." A cold review broke it, confirmed against code:

1. **The flag has TWO sources; only one is scopeable.** `CodeGraph::hasUnresolvedDispatches()`
   is `($consolidated['unresolvedDispatches'] > 0)`, and that count accumulates from **two
   unrelated things** in `CodeGraphBuilder::consolidatedTracerEdges()`:
   - **(S1) Unparseable files** — `if ($ast === null) { ++$unresolved; continue; }` (`~:231`).
     Such a file contributes **zero edges**; its content is unknown, so it could reach the
     change through edges the graph never drew. This is "could-be-anything" taint — exactly
     what plan 032 was rejected for trying to scope. It is **NOT scopeable**.
   - **(S2) Unresolvable dispatch arguments** — `$unresolved += $dispatch['unresolved']`
     (`~:250`), from `DispatchEdgeTracer`. Here the *target* is unknown but it is a bus
     dispatch, so the target category is bounded (a dispatchable). This IS scopeable.

   v1 scoped the combined flag as if it were all S2. An unparseable non-job file (S1) with a
   hidden edge to a non-job change → v1's job-closure check does not fire → under-selection.

2. **The S2 predicate was too narrow.** `DispatchEdgeTracer::jobsFromArg()` (`~:202`)
   increments the count on the *argument shape* (a variable/factory/closure) with **no
   class check at all**, and the counted verbs include the **synchronous** command bus —
   `dispatch_sync`/`dispatchNow`/`dispatchSync` (`DISPATCH_FUNCTIONS`/`DISPATCH_STATICS`).
   Their targets are frequently `Dispatchable` command objects that are **neither `\Jobs\`
   nor `ShouldQueue`**. v1's `\Jobs\`|ShouldQueue predicate misses them → a change downstream
   of such a command → v1 does not fire → under-selection.

   (Note: `Job::dispatch(...)` and `dispatch(new X)` never increment the count — the tracer
   resolves or drops those. Every counted S2 increment is an **unknown-target** dispatch, so
   there is no "provably a job" sub-portion to lean on; the predicate must instead cover the
   full range of what those verbs can dispatch.)

## Design (v2 — decided; do not re-litigate mechanics, but the residual needs sign-off)

**Split the two sources, handle each correctly.**

**A. Separate the counts in the builder + graph + cache (expands scope into `CodeGraphBuilder`,
`CodeGraph`, AND `GraphCache`).**
`consolidatedTracerEdges()` returns `unparseableFiles` (the S1 `++`) and `unresolvedDispatches`
(the S2 `+= $dispatch['unresolved']`) as **two** counts. `CodeGraph` gains
`hasUnparseableFiles(): bool` alongside `hasUnresolvedDispatches(): bool`. `hasUnresolvedDispatches()`
now means **dispatch-only**.

There are **THREE** `CodeGraph` construction sites — update all three or the cache revives with
a wrong (silently-false, unsafe) flag:
1. `CodeGraphBuilder.php ~:173` — the fresh build.
2. `GraphCache.php ~:153` — a **direct** `new CodeGraph(...)` on cache read (NOT via `fromArray`).
3. `CodeGraph::fromArray() ~:139` — `new self(...)`.

**Constructor: make the new flag a REQUIRED param (no default).** A defaulted param lets a
missed construction site silently pass `hasUnparseableFiles = false` (unsafe under-selection);
a required param turns a missed site into an ArgumentCountError → the command's
`catch (Throwable)` backstop → full suite (safe). Accept that this loudly breaks the ~60
`new CodeGraph([edges])` test calls (they must be updated) — desirable for a cardinal-rule flag.

**Cache round-trip: bump `GraphCache::FORMAT_VERSION` 3 → 4.** `CodeGraph::toArray()` must
serialise the new flag and `GraphCache::read()` / `fromArray()` must revive it. But adding a
key to the payload is invisible to the fingerprint, so a stale pre-split cache entry (written
by the old combined-flag code: `hasUnresolvedDispatches: true` from `S1||S2`, no
`hasUnparseableFiles` key) would be served to the new code and revive as `S1=false, S2=true` →
a change reaching no dispatchable narrows even though an unparseable file must force a global
block → **under-selection**. This is a real hit, not a corner: `packageVersion('sandermuller/richter')`
is the placeholder `'unknown'` for the root package, so richter's own dev fingerprint is stable
across the edit. The `FORMAT_VERSION` bump makes every old-format entry a fingerprint miss →
rebuild → correct split. **This is mandatory and load-bearing — do not skip it.**

**B. `AffectedTests::select()` — two blockers, only one scoped.**
- `$hasUnparseableFiles` → **global** determinability blocker (unscopeable). New reason:
  "one or more app files could not be parsed — the graph is incomplete".
- `$hasUnresolvedDispatches && self::changeReachesDispatchable($result, $changed, $graph)`
  → **scoped** blocker. Keep the existing dispatch reason.
  (`select()` is `static` — call `self::changeReachesDispatchable(...)`, **never** `$this->`.)

**Thread the new flag as an explicit `bool` param, not off `$graph`.** `select()`'s existing
signature takes `bool $hasUnresolvedDispatches` and a nullable `?CodeGraph $graph = null` —
reading `$graph->hasUnparseableFiles()` inside would NPE when `$graph` is null. Add a
`bool $hasUnparseableFiles` param (a defaulted named param keeps the existing test call-sites,
which use named args, low-churn) and pass `$graph->hasUnparseableFiles()` from
`AffectedTestsCommand` right beside the existing `$graph->hasUnresolvedDispatches()`.

**C. The scoped criterion — `changeReachesDispatchable`.**
An unfollowable dispatch `D → T::handle` under-selects a change `C` iff its unknown target
`T` reaches `C` (then `D` and `D`'s entry points are hidden callers of `C`). `T` is a bus
dispatchable. Since `T` is unknown, assume any dispatchable in `C`'s upward closure could be
it. So fire iff **any changed file's FQCN, or any node in `$result['callers']`, belongs to a
dispatchable class**. Returns `true`/`false`.

- **Closure source**: `$result['callers']` (the `callersOf($seeds, $maxDepth)` walk, same
  `maxDepth` entry-point discovery uses — so the check inherits the tool's existing depth
  horizon, no new incompleteness). Extract each hop's class from `hop['node']`: skip nodes
  with a structural prefix that is never a dispatch target (`route::`, `view::`, `command::`,
  `schedule::`, `middleware::`, `model::`); for a plain `Class::method` id take the segment
  before the first `::`. Also test every `$changed[i]->fqcn`. (Ambiguous short ids like
  `controller::`/`action::` from short-controller resolution are NOT in the skip-list on
  purpose — they extract to `controller`/`action`, fail `class_exists`, and hit rule 4 below
  → a safe over-fire. You MAY add them to the skip-list to preserve the unlock, but never for
  safety.)
- **The predicate — `DispatchTarget::matches(string $fqcn): bool`** (new shared helper,
  `src/Support/DispatchTarget.php`, `final`, static, memoised). Evaluate the class-existence
  guard **FIRST** — the ordering is load-bearing: `class_uses_recursive()` returns `[]` and
  `is_subclass_of()` returns `false` for a non-existent class **without throwing**, so if the
  autoload guard runs last (or as an `||` tail) a missing class wrongly concludes "not a
  target" → under-fire. Return `true` when **any** holds, in this order:
  1. **fail-toward-fire (evaluate first):** `class_exists($fqcn)` is false or throws → return
     `true`. Uncertainty must never resolve to "not a target". Wrap in `try/catch (Throwable)`
     (mirror `DispatchEdgeTracer::isQueueable`).
  2. `\Jobs\` in the FQCN (matches the tracer's own heuristic);
  3. `is_subclass_of($fqcn, Illuminate\Contracts\Queue\ShouldQueue::class)` (queued jobs);
  4. it uses the `Illuminate\Foundation\Bus\Dispatchable` trait
     (`in_array(Dispatchable::class, class_uses_recursive($fqcn), true)`) — covers commands
     and actions dispatched synchronously that are **not** `ShouldQueue`.

**D. Reconcile `withUnresolvedJobFlips` (`ImpactAnalyzer ~:362`).** It currently triggers on
`hasUnresolvedDispatches()` (the old combined flag). Preserve its exact current trigger by
gating it on `hasUnparseableFiles() || hasUnresolvedDispatches()` (a job's dispatcher could
live in an unparseable file too). Its internal `Str::contains($file->fqcn, '\\Jobs\\')` check
is a coverage/risk annotation, not determinability — **leave it as-is** (widening it is a
separate improvement, out of scope). Add a one-line comment pointing at this plan.

**E. The global signal stays for the human report.** `detect-changes` and the risk pipeline
are unchanged. This plan only changes how `AffectedTests` consumes the two flags.

**Residual + sign-off (MAINTAINER DECISION).** The predicate covers jobs (ShouldQueue),
`\Jobs\`, and `Dispatchable` commands/actions — every common dispatch-target shape. The cold
review confirmed the OTHER categories are NOT new holes: queued Mailables/Notifications/Events/
broadcasts go through `Mail::queue`/`notify()`/`event()`/`broadcast()`, none of which is a
counted dispatch verb (they never increment S2); first-class-callable dispatches are skipped;
and a dispatched *closure* is lexically inside its dispatcher so the reference tracer already
draws that edge. The **documented residual** is two narrow shapes: (a) a command dispatched via
`Bus::dispatch($var)` that uses *neither* `Dispatchable` *nor* `ShouldQueue` *nor* `\Jobs\` (a
plain class wired through `Bus::map` — a rare, pre-`Dispatchable`-era pattern), and (b) a target
of a project-configured `richter.dispatch_helpers` function that falls outside those categories.
Both would be classified "not a target" (if autoloadable) and could be missed.

**DECIDED 2026-07-21: Option A** (maintainer sign-off given after the design cold review
confirmed the residual is narrow). Implement the scoped criterion; document the residual in the
`DispatchTarget` docblock + README. Option B is retained below only as the recorded alternative
— do not implement it.
- **Option A (recommended, this plan):** ship the `Dispatchable`-aware predicate + document
  the rare residual. Delivers the unlock for the common case; the residual is narrow and
  named. A future config allowlist (`richter.dispatch_target_bases`) can close it if a
  consumer reports it.
- **Option B (fallback):** keep S2 **global** too (no scoping) — fully safe, but the feature
  stays nullified on any app with a variable dispatch. This is the status quo; only adopt it
  if the residual is judged unacceptable.
The executor implements Option A and flags the residual in the handoff; if the maintainer
prefers B, the S1/S2 split still stands (it's correct regardless) and only
`changeReachesDispatchable` is dropped.

**Iron rule unchanged.** This is determinability (core reachability), not an advisory
annotation. No security/gate/frontend/test-reference signal may enter this logic.

## Current state (excerpts — confirm against live code at `c1f41d4`)

- `src/Graph/CodeGraphBuilder.php`
  - `~:226-258` — `consolidatedTracerEdges()`: `$unresolved = 0`; `++$unresolved` for
    `$ast === null` (S1, `~:231-235`); `$unresolved += $dispatch['unresolved']` (S2, `~:250`);
    `return [... 'unresolvedDispatches' => $unresolved, ...]`.
  - `~:173` — `$graph = new CodeGraph($edges, $consolidated['unresolvedDispatches'] > 0, ...)`.
- `src/Graph/CodeGraph.php`
  - `~:42` — `__construct(array $edges, private readonly bool $hasUnresolvedDispatches = false, private readonly array $nodeMetadata = [])` (make the new flag a **required** param — see Design A).
  - `~:58` — `hasUnresolvedDispatches(): bool`.
  - `~:123` — `toArray()` (serialises `hasUnresolvedDispatches`; add `hasUnparseableFiles`).
  - `~:137-139` — `fromArray()` → `new self(...)` (revive both flags).
- `src/Graph/GraphCache.php` (**in scope — the cold review found the cache is where the split
  breaks without a FORMAT_VERSION bump**):
  - `~:26` — `FORMAT_VERSION = 3` → **bump to 4** (its docstring: bump on any pipeline change
    the fingerprint's inputs can't see — a new serialised flag is exactly that). Without this,
    a stale pre-split entry revives the flags wrong → under-selection.
  - `~:153` — `read()` **directly** constructs `new CodeGraph($edges, ($data['hasUnresolvedDispatches'] ?? false) === true, $metadata)` — NOT via `fromArray`, so this site must revive `hasUnparseableFiles` too.
  - `~:167` — `write()` serialises `$graph->toArray()`.
- **Tests that WILL break (must be updated, not worked around):**
  - `tests/Feature/GraphCacheTest.php:149` — `assertSame` on the exact `toArray()` shape
    `['edges'=>…, 'hasUnresolvedDispatches'=>false, 'nodeMetadata'=>[]]`; add the new key.
  - `tests/Unit/CodeGraphWalkTest.php:49-51` (`fromArray(toArray())` round-trip) and `:62`
    (explicit array literal) — add the new key.
  - ~60 `new CodeGraph([edges])` call-sites across the suite (from the required param).
- `src/Analysis/AffectedTests.php:31-45` — `select(array $result, array $changed, TestReferenceIndex $tests, bool $hasUnresolvedDispatches, ?CodeGraph $graph = null, ?FrontendTestIndex $frontendTests = null)`;
  the three global reasons at `:35-45`. `$result` carries `callers?: list<array{node:string,...}>`
  and `entryPoints`. **Note the signature already takes `bool $hasUnresolvedDispatches`** —
  you must also thread `hasUnparseableFiles` in (add a param, or pass `$graph` and read both
  off it; `AffectedTestsCommand:83` is the caller — update it).
- `src/Console/AffectedTestsCommand.php:83` — passes `$graph->hasUnresolvedDispatches()` into
  `select()`. Update to pass both flags (or pass `$graph`).
- `src/Tracers/DispatchEdgeTracer.php:247-265` — `isJobClass()` / `isQueueable()`; the
  `try/catch (Throwable)` autoload pattern to mirror in `DispatchTarget::matches`.
- `src/Analysis/ImpactAnalyzer.php:362-389` — `withUnresolvedJobFlips()`; guard `if (! $this->graph->hasUnresolvedDispatches())`.
- `tests/Unit/AffectedTestsTest.php` — selection tests to model on.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'AffectedTests|DispatchTarget|DispatchEdgeTracer|CodeGraph|ImpactAnalyzer'` | OK |
| Full suite | `composer test` | `"result":"passed"` (608 at planning time + new) |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | exit 0 / 0 / 0 changed |

## Scope

**In scope:**
- `src/Graph/CodeGraphBuilder.php` (split the two counts)
- `src/Graph/CodeGraph.php` (`hasUnparseableFiles()`, required constructor param, `toArray`/`fromArray`)
- `src/Graph/GraphCache.php` (**FORMAT_VERSION 3→4**, the direct `new CodeGraph(...)` at `~:153`)
- `src/Support/DispatchTarget.php` (new predicate)
- `src/Analysis/AffectedTests.php` (two blockers + `changeReachesDispatchable`)
- `src/Console/AffectedTestsCommand.php` (pass both flags)
- `src/Analysis/ImpactAnalyzer.php` (`withUnresolvedJobFlips` trigger only)
- Tests: a new `DispatchTargetTest`; `AffectedTestsTest`; the ~60 `new CodeGraph([edges])`
  call-sites (required-param churn); `tests/Feature/GraphCacheTest.php:149` and
  `tests/Unit/CodeGraphWalkTest.php:49-51/:62` (the `toArray`/`fromArray` shape assertions)

**Out of scope:**
- `withUnresolvedJobFlips`'s internal `\Jobs\` job check (coverage/risk, not determinability).
- The `detect-changes` command / risk pipeline.
- The GraphCache **fingerprint inputs** (files/config/versions) — unchanged. Note this is NOT
  the same as `FORMAT_VERSION`, which **must** be bumped (a serialised-format change the
  fingerprint's other inputs can't see — see Design A / STOP conditions).

## Git workflow

- Branch `advisor/036-change-scoped-dispatch-determinability` from the current `main` tip.
  **If your worktree is on a stale base, `git checkout -B <branch> <current-main-sha>` first**
  (a known trap: isolation worktrees anchor to the session baseline, not live `main`).
- Commit per logical unit (count split; predicate; scoped blocker + guards; reconcile).
  No signing. End messages with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
  Do NOT push or open a PR.

## Fixtures & anonymization (MANDATORY)

Neutral domain only (the suite's canonical vocabulary): `App\Jobs\PublishPostJob` (ShouldQueue),
a `Dispatchable`-but-not-`ShouldQueue` command e.g. `App\Actions\GenerateReport` (uses the
`Dispatchable` trait), `App\Livewire\StatusPanel`, `App\Services\PostPublisher`, routes
`posts.show`. Never a consumer product noun.

## Steps

### Step 1: Split the two counts + cache round-trip (test-first)

Separate `unparseableFiles` from `unresolvedDispatches` in `consolidatedTracerEdges()`; add
`CodeGraph::hasUnparseableFiles()` with a **required** constructor param; update **all three**
construction sites (`CodeGraphBuilder ~:173`, `GraphCache ~:153`, `CodeGraph::fromArray ~:139`);
add the flag to `toArray()`; and **bump `GraphCache::FORMAT_VERSION` 3 → 4**. Update the ~60
`new CodeGraph([edges])` test call-sites and the two shape assertions (`GraphCacheTest:149`,
`CodeGraphWalkTest:49-51/:62`). Tests: a graph over a fixture with an unparseable file →
`hasUnparseableFiles() === true`, `hasUnresolvedDispatches()` reflects dispatches only; a graph
with a variable dispatch but all files parseable → `hasUnparseableFiles() === false`,
`hasUnresolvedDispatches() === true`; both survive a `toArray()`→`fromArray()` and a
`GraphCache` write→read round-trip.

**Verify**: `vendor/bin/phpunit --filter 'CodeGraph|GraphCache'` → pass.

### Step 2: The `DispatchTarget` predicate (test-first)

New `tests/Unit/DispatchTargetTest.php`: `App\Jobs\PublishPostJob` → true (`\Jobs\`); a
`ShouldQueue` class outside `\Jobs\` → true; a `Dispatchable`-trait command that is NOT
`ShouldQueue` → true; a plain `App\Models\Post` → false; a non-existent class → true
(fail-toward-fire). Implement `src/Support/DispatchTarget.php` per Design C.

**Verify**: `vendor/bin/phpunit --filter DispatchTarget` → pass.

### Step 3: The two blockers + `changeReachesDispatchable` (test-first)

Wire S1 as a global blocker and S2 as scoped in `select()`; thread `hasUnparseableFiles`.
Then the load-bearing tests:

- **Unlock (must narrow):** `hasUnresolvedDispatches=true`, `hasUnparseableFiles=false`, a
  change to `App\Livewire\StatusPanel::refresh` whose callers contain no dispatch target →
  `determinable === true`, dispatch reason absent.
- **Guard S1 (must stay undeterminable):** `hasUnparseableFiles=true`, change to a non-job
  `App\Services\PostPublisher` → `determinable === false` with the unparseable reason,
  regardless of the closure.
- **Guard S2-job:** change to `App\Jobs\PublishPostJob::handle`, `hasUnresolvedDispatches=true`
  → `determinable === false`.
- **Guard S2-command (the A2 fix):** change to a service reached by a `Dispatchable`
  non-`ShouldQueue` command (`App\Actions\GenerateReport`) in `callers`,
  `hasUnresolvedDispatches=true` → `determinable === false`.
- **Guard S2-unclassifiable:** a caller node whose class can't autoload,
  `hasUnresolvedDispatches=true` → `determinable === false` (fail-toward-fire).

Update the `select()` docblock with the two-source rule and the safety invariant.

**Verify**: `vendor/bin/phpunit --filter AffectedTests` → all pass.

### Step 4: Reconcile `withUnresolvedJobFlips` + full regression

Gate `withUnresolvedJobFlips` on `hasUnparseableFiles() || hasUnresolvedDispatches()` (preserve
its current trigger). Then `composer test` → passed; `phpstan`/`pint --test`/`rector --dry-run`
clean.

## Test plan

Steps 1–3 enumerate the cases. The **five guards** (unlock + S1 + S2-job + S2-command +
S2-unclassifiable) are the proof the scoping never under-selects — a reviewer must see all
five green. If any guard cannot pass alongside the unlock, **STOP** — the split or the
predicate is wrong, and shipping it would under-select.

## Done criteria

- [ ] `hasUnparseableFiles()` and dispatch-only `hasUnresolvedDispatches()` split across builder + graph + all three construction sites (required param); `GraphCache::FORMAT_VERSION` bumped 3→4; both flags survive `toArray`/`fromArray` and a GraphCache write→read round-trip (with a test proving a pre-split-format entry is a cache MISS, not a wrong-flag hit)
- [ ] `DispatchTarget::matches` covers `\Jobs\` / ShouldQueue / `Dispatchable` / unclassifiable-→-true
- [ ] All five guards green; the unlock case narrows
- [ ] `grep -n 'hasUnparseableFiles\|changeReachesDispatchable' src/Analysis/AffectedTests.php` shows the global S1 blocker and the scoped S2 blocker distinct
- [ ] `composer test` / `phpstan` / `pint --test` / `rector --dry-run` clean
- [ ] No out-of-scope files modified; `plans/README.md` row updated
- [ ] Handoff flags the documented residual for maintainer sign-off, and requests a fresh cold review

## STOP conditions

- Drift vs the "Current state" excerpts.
- `$result['callers']` is not the transitive upward closure at the entry-point `maxDepth`
  (the safety argument needs this — report if callers is narrower than the entry-point walk).
- A guard cannot be made green alongside the unlock.
- You cannot bump `GraphCache::FORMAT_VERSION`, or the flags don't round-trip through all
  three construction sites — a warm pre-split cache entry reviving with a wrong flag
  under-selects. Report rather than ship a cache that can serve a stale-format entry.
- You are tempted to widen the predicate to "has a `handle()` method" — that matches middleware
  and over-fires to near-nullification; do not.

## Maintenance notes

- **Source 1 (unparseable files) stays a global blocker by design** — a file the parser can't
  read has no edges and could reach anything. If a consumer sees `affected-tests` globally
  blocked, check for unparseable files (e.g. newer PHP syntax the pinned `nikic/php-parser`
  can't handle): the fix is parser coverage, not scoping. Consider surfacing the unparseable
  file paths in the reason to make this diagnosable.
- The documented residual (bus-mapped non-`Dispatchable` command) is the one gap; a
  `richter.dispatch_target_bases` config allowlist would close it if reported.
- `DispatchTarget::matches` is now the definition of "possible dispatch target" for selection;
  keep it in step with any dispatch-verb additions in `DispatchEdgeTracer` (a new verb whose
  targets fall outside jobs/`Dispatchable` widens what the predicate must recognise).
- After implementation: **fresh cold review + maintainer sign-off** before release (v1 shipped
  a wrong safety proof; v2's must be independently re-broken and survive).
