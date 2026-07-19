# Plan 026: Memoize riskInputs' repeated graph walks within one detectChanges run

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Analysis/ImpactAnalyzer.php tests/Unit/ImpactAnalyzerTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED (touches the risk pipeline; behavior must be provably identical)
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

`detectChanges()` calls `riskInputs()` — which runs two graph traversals
(`callersOf` + `reachedViaTypes`) — once for the coarse-cap recheck, once
**per changed entry-point-class file** (self-listing), and once **per changed
job file** (unresolved-dispatch flips). On a broad refactor diff touching many
jobs/listeners/commands against a large host-app graph, that is
O(changed entry files × their reach) repeated BFS on top of the ~5 walks the
main body already runs. Each walk is seed-bounded (not whole-graph), so this
is a constant-factor cost, not an asymptotic explosion — worth fixing cheaply,
not worth a redesign. The same per-file seed sets are frequently identical or
empty; a run-scoped memo keyed by the seed set removes the repeats without
changing any semantics.

## Current state

- `src/Analysis/ImpactAnalyzer.php:388-402`:

  ```php
  private function riskInputs(array $seeds, int $maxDepth): array
  {
      if ($seeds === []) {
          return [0, 0];
      }

      $entryPoints = $this->entryPointsAmong($this->graph->callersOf($seeds, $maxDepth));
      $impacted = count(array_filter($this->graph->reachedViaTypes($seeds, $maxDepth), $this->isRiskBearing(...)));

      return [count($entryPoints), $impacted];
  }
  ```

- Call sites: `:229` (coarse-cap recompute over `$preciseSeeds`), `:284`
  (`withSelfListedEntryClasses` — per changed entry-point-class file, seeds
  `$perFileSeeds[$file->file] ?? []`), `:346` (`withUnresolvedJobFlips` — per
  changed job file, same per-file seeds).
- The per-file logic is deliberately isolated (a sibling file's reach must not
  mask another's) — the memo must key on the exact seed set, never merge sets.
- The analyzer holds the graph in `$this->graph`; one `ImpactAnalyzer` instance
  can serve multiple `detectChanges()` calls (MCP session), and the graph is
  immutable per instance — so an instance-level memo is safe *for a given
  maxDepth + seed set*, but a run-scoped memo is simpler to reason about.
  Choose instance-level keyed on `(maxDepth, sorted seeds)` ONLY if you verify
  the graph member is readonly/immutable; otherwise scope the memo to the
  `detectChanges` invocation.
- Test conventions: `tests/Unit/ImpactAnalyzerTest.php` — hand-built graphs;
  no perf harness exists in the repo (do not invent one; correctness tests +
  a call-count assertion are the verification).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter ImpactAnalyzerTest` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `backend-quality`. The repo also has an `autoresearch` skill
  (benchmark-driven optimization loop) — only reach for it if the operator
  asks for measured numbers; this plan's scope is the structural memo.

## Scope

**In scope** (the only files you should modify):
- `src/Analysis/ImpactAnalyzer.php` (`riskInputs` and, if needed, a private memo property or local memo threading)
- `tests/Unit/ImpactAnalyzerTest.php`

**Out of scope** (do NOT touch):
- `src/Graph/CodeGraph.php` — `callersOf`/`reachedViaTypes` stay unmemoized
  primitives; the policy lives in the analyzer.
- The per-file isolation semantics of self-listing and job flips.
- Any output field of `detectChanges`.

## Git workflow

- Branch: `advisor/026-riskinputs-memoization`
- Commit style: imperative subject, e.g. `Memoize riskInputs walks within a detectChanges run`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Characterize with a call-counting test

In `tests/Unit/ImpactAnalyzerTest.php`, add a test that proves the memo works
observably: build a graph, run `detectChanges` with two changed job files that
produce the **same** per-file seed set (e.g. two files whose members resolve
to the same node), and assert the result is correct. To observe the walk
count, extend the test graph double if one exists — check how the tests build
`CodeGraph` (real instance, most likely): a real `CodeGraph` cannot count
calls, so instead assert *behavioral equivalence*, and add one white-box test:
call the (new) memoized path twice with identical inputs and assert identical
results (`assertSame` on the tuples). Keep it honest: the primary safety net
is the full existing `ImpactAnalyzerTest` suite passing unchanged.

**Verify**: new test passes against current code (it must — memoization is
invisible), all existing tests pass.

### Step 2: Add the memo

In `ImpactAnalyzer`, memoize `riskInputs` results keyed by
`$maxDepth . '|' . implode(',', $sortedSeeds)` (sort a copy — do not reorder
the caller's array). Prefer a private array property documented as
"per-graph memo: the graph is immutable for this instance's lifetime, so a
(depth, seed-set) key can never go stale" — verify `$this->graph` is
assigned once in the constructor and never replaced (`grep -n "graph =" src/Analysis/ImpactAnalyzer.php`).
If anything reassigns it, scope the memo to `detectChanges` (pass a local
array by reference or an inline closure) instead.

```php
/** @var array<string, array{0: int, 1: int}> keyed by maxDepth + sorted seed set — see docblock note on graph immutability */
private array $riskInputsMemo = [];
```

**Verify**: `vendor/bin/phpunit --filter ImpactAnalyzerTest` → all pass
unchanged; `composer test` → `"result":"passed"`.

### Step 3: Static + style gates

**Verify**: `composer phpstan` → exit 0; `vendor/bin/pint --test` → exit 0;
`vendor/bin/rector process --dry-run` → no changes proposed for the touched
file.

## Test plan

- Step 1's equivalence test (identical inputs → identical tuple).
- The entire existing `ImpactAnalyzerTest` file is the real regression net —
  especially the self-listed-entry-class and unresolved-job-flip tests, which
  exercise the memoized call sites with distinct per-file seed sets.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer test` exits 0 with zero expectation changes in existing tests
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `riskInputs` results are memoized (grep shows the memo keyed on depth + sorted seeds)
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- `$this->graph` turns out to be replaceable on a live analyzer instance and
  the run-scoped fallback would require restructuring `detectChanges`'
  helper signatures beyond adding one parameter.
- Any existing test's expectations change — memoization must be invisible;
  a changed expectation means the key is wrong (probably seed ordering).
- You find the *same* memo opportunity inside `CodeGraph` tempting — out of
  scope by design; note it instead.

## Maintenance notes

- The memo assumes graph immutability per analyzer instance; if a future
  change lets an analyzer swap graphs (e.g. cache refresh mid-session), the
  memo must be keyed by graph identity or cleared on swap — leave a pointed
  docblock so that change can't miss it.
- If real-world profiling later shows the *main-body* walks dominate instead,
  that is a different plan; this one only removes the per-file repeats.
