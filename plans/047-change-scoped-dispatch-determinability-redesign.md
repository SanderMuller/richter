# Plan 047: Change-scope the unfollowable-dispatch blocker so affected-tests is usable on real apps (supersedes plan 036)

> **Supersedes plan 036**, which is BLOCKED — its safety proof was shown false
> in two ways (see 036's banner). This plan carries a *different, sounder*
> mechanism: scope by **dispatch-site reachability**, not by target category.
> That change of mechanism is what dissolves 036's second blocker (the
> class-agnostic count including sync-command dispatches) — when relevance is
> decided by "is the unfollowable dispatch *site* reachable from the change,"
> the target's category never enters the proof.

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. **This plan has a HARD STOP between Phase A and
> Phase B**: Phase A is a safe, behavior-preserving refactor you may complete
> and hand back; Phase B changes determinability (the one thing this tool
> exists to get right) and MUST NOT be merged without explicit maintainer
> sign-off on its safety proof. When done with Phase A, update the status row
> in `plans/README.md` — unless a reviewer dispatched you and maintains the
> index.
>
> **Drift check (run first)**: `git diff --stat afcefa1..HEAD -- src/Analysis/AffectedTests.php src/Analysis/ImpactAnalyzer.php src/Graph/CodeGraph.php src/Graph/CodeGraphBuilder.php src/Tracers/DispatchEdgeTracer.php src/Console/AffectedTestsCommand.php tests/Unit/AffectedTestsTest.php`
> If any in-scope file changed since this plan was written, compare the "Current
> state" excerpts against live code before proceeding; on a mismatch, STOP.

## Status

- **Priority**: P1 (nullifies the flagship `affected-tests` feature on any real
  app — see "Why this matters")
- **Effort**: M (Phase A) + M–L (Phase B)
- **Risk**: **HIGH** for Phase B (a wrong scoping reintroduces silent
  under-selection — the cardinal error). LOW for Phase A (behavior-preserving).
- **Depends on**: none. Merge-adjacent to plan 045 (both touch `ChangedSymbols`
  only trivially / not at all — no conflict).
- **Category**: correctness
- **Planned at**: commit `afcefa1`, 2026-07-22

## Why this matters

`richter:affected-tests` narrows a large suite to the tests a diff can reach —
the headline feature. On any non-trivial Laravel app it returns **nothing
usable**: every change reports "could not be determined — run the full suite"
(exit 2). Cause: `AffectedTests::select()` treats a **graph-global** "there is at
least one unfollowable job dispatch anywhere" boolean as an unconditional
determinability blocker. Every real app has at least one variable/factory
dispatch somewhere, so the flag is permanently `true`, so selection can never
narrow. No value delivered.

The fix is to make that blocker **change-scoped**: an unfollowable dispatch in
code the change cannot reach is irrelevant to that change's test selection.

Plan 036 tried this and was blocked because its safety proof leaned on the
target being "always a job" (false — the counter also fires on unparseable files
and on sync-command dispatches). This plan avoids that trap: it scopes by
whether the **dispatch site** (the dispatching method node) is reachable from the
change, which needs no claim about the target's category. Unparseable files —
which have no locatable site and could be anything — stay a global blocker.

## Background: the three signals to reconcile

Read all three before touching code; their interaction is the whole difficulty.

1. **The global blocker (the bug)** — `src/Analysis/AffectedTests.php:43-45`:

   ```php
   if ($hasUnresolvedDispatches) {
       $reasons[] = 'the graph contains job dispatches that could not be followed';
   }
   ```

   `$hasUnresolvedDispatches` is `$graph->hasUnresolvedDispatches()`, passed from
   `AffectedTestsCommand.php:~83`. It is a single readonly bool on `CodeGraph`
   (`CodeGraph.php:58-60`), set true when a build-time integer count is `> 0`.

2. **The count's two conflated sources** — `CodeGraphBuilder::consolidatedTracerEdges()`
   (`src/Graph/CodeGraphBuilder.php:226-258`) sums one `$unresolved`:
   - `++$unresolved` for **unparseable files** (line 235) — "could be anything"
     taint; the file contributes zero edges, so its dispatch (if any) has no
     locatable site.
   - `$unresolved += $dispatch['unresolved']` for **unfollowable dispatches**
     (line 250) — each is a real dispatch site whose *job target* couldn't be
     seen (a variable/factory/closure arg). The dispatching method node IS known.

3. **The already-scoped coverage flip** — `ImpactAnalyzer::withUnresolvedJobFlips`
   (`src/Analysis/ImpactAnalyzer.php:~362`) uses a **`\Jobs\`-only** notion when
   flipping a coverage verdict. It governs the *risk/coverage* axis, NOT test
   determinability. **This plan does not change it** — but the reviewer must
   confirm (Phase B, Step B4) that leaving it as-is does not let the two axes
   disagree in a way that under-selects.

The dispatcher node id is formed in `DispatchEdgeTracer::edgesForMethods`
(`DispatchEdgeTracer.php:87-113`) as
`$dispatcher = ltrim($classFqcn, '\\') . '::' . $method->name->toString()`
(line 93) — a method node like `App\Http\Controllers\PostController::publish`.
The `++$unresolved` increments happen deeper (`jobsFromArg:202`,
`jobsFromArray:211,227`, `jobFromNew:237`) via a `&$unresolved` reference; the
dispatcher id is in scope only at line 93. Phase A captures the site by
snapshotting the counter around each method — no deep threading.

## Current state — the reach data select() already has

`AffectedTests::select()` receives the full `$result` array. For a
`detect-changes` result that array carries (see `ImpactAnalyzer.php:222-224` and
the `DetectChangesResult` shape in `HtmlFormatter.php:21`):

- `seeds: list<string>` — the changed member/class nodes.
- `reach: array<string, array<string, true>>` — the forward reach map.
- `edges: list<{source, target, via, depth}>` — the walked blast-radius edges.
- `callers?` / `dependencies?` — hop lists (present on the impact path).

`select()` also already accepts `?CodeGraph $graph` (used today to resolve
`schedule::` nodes, `AffectedTests.php:113`). So Phase B has both the change's
reach and the graph available without a signature widening beyond removing the
bare bool.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Tests (targeted) | `vendor/bin/phpunit --filter AffectedTests` | all pass |
| Tests (graph) | `vendor/bin/phpunit --filter CodeGraph` and `--filter Dispatch` | all pass |
| Full suite | `composer test` | all pass |
| Static analysis | `composer phpstan` | exit 0 |
| Style | `vendor/bin/pint --test` | no issues |
| Benchmark accuracy | `php artisan richter:benchmark` (via `vendor/bin/testbench`, or the project's benchmark test) | controls stay green |

Note: this is a package with no host app — use `vendor/bin/phpunit` /
`vendor/bin/testbench`, never `php artisan` directly. If a benchmark run needs a
booted app, run it through the existing benchmark test rather than artisan.

## Scope

**Phase A — In scope:**
- `src/Tracers/DispatchEdgeTracer.php` — return the unresolved dispatch *sites*.
- `src/Graph/CodeGraphBuilder.php` — split the two counts; carry sites.
- `src/Graph/CodeGraph.php` — store both; keep `hasUnresolvedDispatches()`
  identical; add accessors; update `toArray`/`fromArray`.
- `tests/Unit/` — characterization tests proving no behavior change + new
  accessors.

**Phase B — In scope (do NOT start without completing Phase A and reading the
Phase B gate):**
- `src/Analysis/AffectedTests.php` — change-scoped blocker logic.
- `src/Console/AffectedTestsCommand.php` — pass the new inputs instead of the
  bare bool.
- `tests/Unit/AffectedTestsTest.php` and `tests/Feature/CommandsTest.php` — the
  adversarial determinability battery.

**Out of scope (both phases):**
- `ImpactAnalyzer::withUnresolvedJobFlips` and the risk/coverage axis — leave the
  logic untouched; only *verify* it (Step B4).
- The `--fail-on*` gate and the risk level — this plan changes test *selection*
  determinability only, never risk.
- Plan 036's file — leave it as the BLOCKED historical record; this plan
  supersedes it (mark 036 SUPERSEDED in the index, do not delete).

## Git workflow

- Branch: `advisor/047-change-scoped-dispatch-determinability`
- Commit Phase A and Phase B as **separate commits** (Phase A is independently
  mergeable and safe; Phase B is gated).
- Conventional commit messages matching `git log`.
- Do NOT push or open a PR. Do NOT merge Phase B without the sign-off in the gate.

## Phase A — behavior-preserving split (safe to complete and hand back)

### Step A1: DispatchEdgeTracer returns unresolved sites

In `DispatchEdgeTracer::edgesForMethods` (line 87), capture the dispatcher id
whenever a method's calls raise the unresolved counter. Snapshot the counter
around each method (the dispatcher id is only in scope at line 93):

```php
public function edgesForMethods(array $classMethods, string $classFqcn): array
{
    $edges = [];
    $unresolved = 0;
    $unresolvedSites = [];

    foreach ($classMethods as $method) {
        $dispatcher = ltrim($classFqcn, '\\') . '::' . $method->name->toString();
        $before = $unresolved;
        // ... existing $calls loop and New_ loop unchanged ...
        if ($unresolved > $before) {
            $unresolvedSites[] = $dispatcher;
        }
    }

    return ['edges' => AppFiles::dedupeEdges($edges), 'unresolved' => $unresolved, 'unresolvedSites' => $unresolvedSites];
}
```

Update the method's `@return` docblock to add
`unresolvedSites: list<string>`. Do the same for `edgesForResolvedAst`/
`edgesForSource` return shapes (they delegate — add `'unresolvedSites' => []`
where they early-return, and forward it where they delegate).

**Verify**: `composer phpstan` → exit 0.

### Step A2: CodeGraphBuilder splits the two counts

In `consolidatedTracerEdges()` (`CodeGraphBuilder.php:226-258`), keep the
unparseable-file count separate from the dispatch-site list:

```php
$unparseableFiles = 0;
$dispatchSites = [];
// ...
if ($ast === null) {
    ++$unparseableFiles;   // was: ++$unresolved
    continue;
}
// ...
$dispatch = $dispatchTracer->edgesForMethods($nodes['classMethods'], $class['fqcn']);
$dispatchSites = [...$dispatchSites, ...$dispatch['unresolvedSites']];
// (drop: $unresolved += $dispatch['unresolved'];)
```

Change the method's return shape to
`{edges, unparseableFiles: int, unresolvedDispatchSites: list<string>, entryPointAsts: ...}`
(replace the old `unresolvedDispatches: int`). Update the `@return` docblock.

At the `CodeGraph` construction (`CodeGraphBuilder.php:173`), pass both pieces of
data through (see Step A3 for the constructor shape). The value that was
`$consolidated['unresolvedDispatches'] > 0` becomes
`$consolidated['unparseableFiles'] > 0 || $consolidated['unresolvedDispatchSites'] !== []`
for the legacy bool — but prefer to compute that inside `CodeGraph` (A3).

**Verify**: `composer phpstan` → exit 0.

### Step A3: CodeGraph stores both, keeps the bool identical

In `CodeGraph.php`, replace the single `bool $hasUnresolvedDispatches`
constructor arg with the two data pieces (`int $unparseableFiles = 0`,
`array $unresolvedDispatchSites = []`). Keep `hasUnresolvedDispatches()`
returning the **same value as before**:

```php
public function hasUnresolvedDispatches(): bool
{
    return $this->unparseableFiles > 0 || $this->unresolvedDispatchSites !== [];
}
```

Add accessors:

```php
public function unparseableFileCount(): int { return $this->unparseableFiles; }
/** @return list<string> */
public function unresolvedDispatchSites(): array { return $this->unresolvedDispatchSites; }
```

Update `toArray()`/`fromArray()` (`CodeGraph.php:121-139`) to serialize both new
fields instead of the single bool. **Because the on-disk cache shape changes**,
bump whatever schema/version token the cache fingerprint or payload carries so
an old `graph.json` reads as a miss and rebuilds (a corrupt/mismatched cache
already reads as a miss — confirm via `GraphCacheTest`; if a version constant
exists, increment it; if the shape is validated by `fromArray` throwing/failing,
confirm the miss path catches it).

**Verify**: `composer phpstan` → exit 0.

### Step A4: Characterization tests (prove no behavior change)

Add/adjust unit tests so the Phase A split is provably inert at the
`hasUnresolvedDispatches()` boundary:

- A graph built from a fixture with an unparseable file → `hasUnresolvedDispatches()`
  true, `unparseableFileCount() === 1`, `unresolvedDispatchSites() === []`.
- A graph built from a fixture with a `dispatch($variable)` site → true,
  `unparseableFileCount() === 0`, `unresolvedDispatchSites()` contains the
  dispatcher node id.
- A clean graph → false, both empty/zero.
- `toArray()`→`fromArray()` round-trips both fields.
- Every **existing** `AffectedTests`/`CodeGraph`/benchmark test still passes
  unchanged (this is the proof Phase A changed no behavior).

**Verify**:
- `composer test` → all pass (report total vs the prior 700).
- `php artisan richter:benchmark` (or the benchmark test) → controls green.

### Phase A done criteria

- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` clean
- [ ] `composer test` all pass; new accessor tests exist; **no existing test
      changed its expectations**
- [ ] Benchmark controls stay green (no green→red flip)
- [ ] `plans/README.md`: plan 047 row shows "Phase A DONE — Phase B gated"

---

## ⚠ HARD STOP — maintainer sign-off gate before Phase B

Phase B changes determinability. Do **not** implement or merge it until a
maintainer has signed off on the safety proof below. Present this proof, get
explicit approval, then proceed. If you are an autonomous executor with no
maintainer to ask, STOP after Phase A and report — Phase A is valuable on its
own and safe; Phase B is not yours to ship unreviewed.

**Safety proof to ratify (the reviewer must agree with every line):**

1. **Unparseable files stay a global blocker.** An unparseable file contributes
   zero edges and could contain a dispatcher reaching anything; it has no
   locatable site, so it cannot be scoped. `unparseableFileCount() > 0` keeps
   blocking determination unconditionally. (This closes 036's blocker #1.)
2. **Dispatch sites are scoped by reachability, not target category.** For each
   `site` in `unresolvedDispatchSites()` (a dispatching **method node**), the
   missing job edge can only affect the change's test selection if the change's
   blast radius **includes that site**. If the change cannot reach the site and
   the site cannot reach the change, the missing edge is in unrelated code and
   is irrelevant. Because relevance is decided by site reachability, it is
   **irrelevant whether the target was a queued job or a sync-dispatched command
   object** — this closes 036's blocker #2 without needing `isJobClass`.
3. **Reachability direction (the load-bearing decision — reviewer must confirm).**
   A missing `dispatcher → job` edge undercounts the graph in the job's
   direction. The conservative, provably-safe relevance test is: the site is
   relevant if it lies in the **same connected component the change's selection
   walks** — i.e. the site node appears in the change's `reach` map keys, in
   `seeds`, or in the `callers`/`dependencies` hop nodes. **When in doubt,
   include** (over-select → run more tests → safe). The default MUST err toward
   "relevant" for any site whose reachability to/from the change cannot be
   decided from the available data.
4. **Fail-safe default preserved.** If the reach data needed to decide a site is
   absent for a given result shape (e.g. an impact-only result lacking `reach`),
   the site is treated as relevant (blocks). No code path may treat "couldn't
   decide" as "not relevant."

Only after the reviewer ratifies points 1–4 does Phase B proceed.

---

## Phase B — change-scoped blocker (gated on the sign-off above)

### Step B1: Replace the bare bool in select()

Change `AffectedTests::select()` to stop taking `bool $hasUnresolvedDispatches`
and instead take the two split inputs (pass the `CodeGraph` — already an arg — or
pass `int $unparseableFiles` and `list<string> $unresolvedDispatchSites`
explicitly; explicit args keep `select()` testable without a full graph).
Replace lines 43-45 with:

```php
if ($unparseableFiles > 0) {
    $reasons[] = 'the graph contains file(s) that could not be parsed — coverage is incomplete';
}

$reachedSites = self::dispatchSitesReachedByChange($unresolvedDispatchSites, $result);
if ($reachedSites !== []) {
    $reasons[] = 'the change reaches job dispatch(es) whose target could not be followed';
}
```

Add the private helper implementing proof-point 3, erring toward inclusion:

```php
/**
 * A site is reached-by-change when it lies in the same closure the selection
 * walks — the reach map, the seeds, or the caller/dependency hops. Fail-safe:
 * if the result lacks a reach map, every site counts (block), never "none".
 *
 * @param  list<string>  $sites
 * @param  array{seeds?: list<string>, reach?: array<string, array<string, true>>, callers?: list<...>, dependencies?: list<...>, ...}  $result
 * @return list<string>
 */
private static function dispatchSitesReachedByChange(array $sites, array $result): array
{
    if ($sites === []) {
        return [];
    }
    // No reach data → cannot scope → every site is relevant (fail safe).
    if (! array_key_exists('reach', $result) && ! array_key_exists('seeds', $result)) {
        return $sites;
    }
    $inClosure = self::changeClosureNodes($result); // set<string>: seeds ∪ reach keys ∪ hop nodes
    return array_values(array_filter($sites, static fn (string $s): bool => isset($inClosure[$s])));
}
```

Implement `changeClosureNodes()` to union: `$result['seeds']`, the keys of
`$result['reach']`, and the `node` of each hop in `callers`/`dependencies`.
Match a site (`Class::method`) against closure nodes at the **same granularity**
— a site is in-closure if the exact `Class::method` node is present OR its
declaring class node (`Class`) is present (a class-level seed covers its
methods). Confirm the node-id forms line up (seeds and reach keys use the same
`Class::method` / `Class` id space the tracer emits).

**Verify**: `composer phpstan` → exit 0.

### Step B2: Update the command call site

In `AffectedTestsCommand.php` (~line 83), stop passing
`$graph->hasUnresolvedDispatches()` and pass `$graph->unparseableFileCount()` and
`$graph->unresolvedDispatchSites()` (or the graph, per B1's chosen signature).

**Verify**: `composer phpstan` → exit 0.

### Step B3: The adversarial determinability battery (test-first is mandatory)

Write these BEFORE finalizing B1's logic; each must fail on the old global-bool
behavior and pass on the new. In `tests/Unit/AffectedTestsTest.php` (and a
feature test in `CommandsTest.php` for the end-to-end exit code):

1. **Unparseable file still blocks globally** — a result with
   `unparseableFiles = 1` and a change reaching NO dispatch site → not
   determinable (exit 2). (Proves proof-point 1.)
2. **Reached dispatch site blocks** — a site node that IS in the change's
   `reach`/`seeds` → not determinable. (Proves the scoping still catches the
   real case.)
3. **Unreached dispatch site does NOT block** — a site node in unrelated code,
   absent from the change's closure, with everything else clean → **determinable**
   (exit 0), selection narrowed. *This is the whole point of the plan — the case
   that is broken today.*
4. **Sync-command dispatch, unreached** — a `dispatch_sync($cmd)` site (target is
   a non-`ShouldQueue` command) in unrelated code → determinable. (Proves
   proof-point 2: target category never entered.)
5. **Fail-safe on missing reach data** — a result shape with no `reach`/`seeds`
   and a non-empty site list → not determinable (every site counts). (Proves
   proof-point 4.)
6. **Class-level seed covers its methods** — a site `App\X::run` with the change
   seeding class `App\X` (not the specific method) → blocks. (Proves the
   granularity matching in B1.)

Model structure on the existing `AffectedTestsTest.php` cases.

**Verify**: `vendor/bin/phpunit --filter AffectedTests` → all pass, including the
6 new cases; then confirm cases 3 and 4 **fail** if you temporarily restore the
old global-bool blocker (proves they guard the behavior change).

### Step B4: Verify the risk/coverage axis didn't drift

Confirm `ImpactAnalyzer::withUnresolvedJobFlips` (untouched) and the new
selection scoping do not disagree in an under-selecting way: run the full
benchmark and the whole suite. A control flipping green→red, or any existing
`affected-tests` test changing expectation in the under-selecting direction, is a
STOP.

**Verify**:
- `composer test` → all pass.
- `php artisan richter:benchmark` (or the benchmark test) → all bug fixtures
  still signal, all controls still green.
- `composer phpstan` → exit 0; `vendor/bin/pint --test` → clean.

### Step B5: Documentation

Update the README's "Affected-test selection" section (`README.md:242-289`) and
the `AffectedTests` class docblock to state the refined rule: an unfollowable
dispatch blocks determination **only when the change reaches its dispatch site**;
an unparseable file always blocks. Keep the "over-selection is the acceptable
error; under-selection is the one this tool exists to prevent" framing. Follow
the repo's doc voice; scan the diff for any product/domain names per
`CLAUDE.md`'s anonymization rule (use neutral nouns).

**Verify**: `composer phpstan` → exit 0 (docblock refs resolve).

## Phase B done criteria

- [ ] Maintainer sign-off on proof-points 1–4 recorded (in the PR/handoff)
- [ ] `composer phpstan` exits 0; `vendor/bin/pint --test` clean
- [ ] `composer test` all pass; the 6 adversarial cases exist and pass; cases 3
      and 4 proven to fail under the old blocker
- [ ] Benchmark: all bug fixtures signal, all controls green (no green→red)
- [ ] README + `AffectedTests` docblock describe the change-scoped rule
- [ ] `plans/README.md`: plan 047 row DONE; plan 036 row marked SUPERSEDED by 047

## STOP conditions

Stop and report back (do not improvise) if:

- **Phase B is reached without maintainer sign-off** — halt at the gate.
- Any "Current state" excerpt or line reference doesn't match live code (drift).
- A benchmark control flips green→red at any point — that is the exact
  trustworthiness regression this feature must never introduce.
- You cannot line up the site node-id space (`Class::method`) with the `reach`/
  `seeds` id space — if the ids don't match, scoping silently never matches and
  every site would look unreached (catastrophic under-selection). STOP and report
  the id mismatch rather than shipping.
- The reach data needed for scoping turns out to be absent on the
  `affected-tests` code path (vs the impact path) — if `select()` doesn't
  actually receive `reach`/`seeds` for detect-changes results, the fail-safe
  default (block) means the feature stays broken; report this before proceeding,
  because it means the reach map must first be threaded through.
- Adversarial case 3 or 4 cannot be made to pass without also breaking case 1 or
  2 — that means the scoping predicate is wrong; report the tension.

## Maintenance notes

- The security-critical invariant: **under-selection is unacceptable,
  over-selection is fine.** Any future change to `dispatchSitesReachedByChange`
  or `changeClosureNodes` must preserve "when in doubt, block." A reviewer should
  scrutinize any edit that makes a site *less* likely to count as reached.
- If the graph's node-id scheme changes (e.g. member ids gain a signature), the
  site↔closure matching in B1 must be revisited — it depends on the two id
  spaces agreeing.
- `withUnresolvedJobFlips` remains the risk/coverage axis. If a future change
  makes test-selection and risk-coverage share a signal, re-verify they can't
  disagree in the under-selecting direction.
- Deferred: recording *why* each site is unresolved (variable vs factory vs
  closure) for a richer reason string — not needed for the determinability
  contract, a possible later polish.
