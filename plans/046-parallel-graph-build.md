# Plan 046: Build the Brain branch and the tracer branch concurrently

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat -- src/Graph/CodeGraphBuilder.php`
> against the "Planned at" commit. The phase sequence in `build()` (lines ~63–182)
> is the contract this plan rewrites; if it changed, re-derive the branch
> boundaries against the live code before proceeding, and treat a structural
> mismatch as a STOP condition.

## Status

- **State**: WRITTEN — not executed.
- **Priority**: P2 (largest richter-controllable graph-build win; see impact)
- **Effort**: M–H
- **Risk**: MED — the output graph must be **byte-identical** to the serial build. Mitigated by a
  hard parallel-==-serial equality gate (below) and a robust **fallback to serial** on any worker
  failure (advisory tooling must never fail closed on a fork hiccup).
- **Depends on**: nothing. Composes with 045 (independent).
- **Category**: performance (graph-build wall-time)
- **Context**: `internal/perf-graph-build-report-2026-07-24.md` — read §3–§5 and the "Runtime split"
  section first. This plan implements the report's headline richter-side lever.
- **Planned at**: commit `e57931b`, 2026-07-24

## Why this matters + the impact cap

`CodeGraphBuilder::build()` runs two data-independent branches **sequentially**:

- **Branch A (Brain):** `ProjectAnalyzer::analyze()` + `canonicalize-metadata` ≈ **12.6s** (cold,
  hihaho). Produces Brain's edges + the node-metadata map.
- **Branch B (richter's source tracers):** `consolidated-tracers` → `entry-point-tracer` →
  `blade-tracers` ≈ **10.0s**. `consolidatedTracerEdges()` and `entryPointTracer->trace()` take only
  `$projectRoot`/parsed ASTs — **verified: neither reads `$analysis`** (`CodeGraphBuilder.php:124,135`).

They meet only at the merge (`rewrites-and-members`). Running them concurrently:
`max(12.6, 10.0) + merge ≈ 13s` vs 23s → **~43% faster**, no upstream dependency.

**Cap (state it in the PR):** `brain-analyze` (12.6s / 54%) is one Brain call richter cannot split,
so ~13s is the floor. This 2-way split captures essentially the whole richter-side parallelism win;
finer-grained `traceMethod` parallelism only helps on apps where Branch B > Branch A. Going below
the floor needs the (delegated) upstream Brain work.

## Mechanism decision (settled — do not substitute)

Use a **hidden worker artisan command** + `illuminate/process`, **not** Laravel's `Concurrency`
facade. `Concurrency`'s default `process` driver fails on closures capturing `$this` (Branch B lives
in instance methods), and its `fork` driver needs non-portable `pcntl`. richter already runs
subprocesses via `Process::path(base_path())->run([...])` throughout (`ChangedSymbols`,
`BenchmarkCommand`) and fakes them in tests (`Process::fake`, see plan 006) — reuse that.

## Current state

`CodeGraphBuilder::build()` (`src/Graph/CodeGraphBuilder.php`):
- ~63–116: Branch A — `analyze()` then the `foreach ($analysis->fullGraph…)` canonicalize loop
  building `$canonical`, `$metadata`, `$edges` (Brain edges), `$routeMiddlewareEdges`.
- 120–151: Branch B — `consolidatedTracerEdges()` (returns `{edges, unparseableFiles,
  unresolvedDispatches, entryPointAsts}`), then `entryPointTracer->trace($root, entryPointAsts)`,
  then `BladeViewTracer`/`PolicyEdgeTracer`. Edges appended in that order.
- 153–182: merge — controller/middleware rewrites, `memberDeclarationEdges`, `declaresEdges`,
  final `dedupeEdges`, `new CodeGraph(...)`. Consumes edges from **both** branches + the two Branch-B
  flags (`unparseableFiles`, `unresolvedDispatches`).

`entryPointAsts` is consumed **inside** Branch B — it never needs to cross a process boundary.
`dedupeEdges` keeps first-occurrence, so preserving Branch B's internal edge order (consolidated →
entry-point → blade) makes the merged array identical to serial.

## Change

**1. Extract Branch B into one method — used by both paths (no divergence).**
`private function buildTracerBranch(string $projectRoot, ?callable $onProgress): array` returning
`['edges' => list<{source,target,type}>, 'unparseableFiles' => int, 'unresolvedDispatches' => int]`,
where `edges` is exactly `[...consolidated['edges'], ...entryPointTracer->trace(...), ...bladeEdges]`
in that order, and the existing `consolidated-tracers` / `entry-point-tracer` / `blade-tracers`
phase emits fire through `$onProgress` (null in the worker → silent). `build()`'s serial path calls
this inline.

**2. Add the hidden worker command** `richter:internal-tracer-branch` (`$hidden = true`):
`--project=<root>` and `--out=<file>`. It calls `buildTracerBranch($project, null)` and writes the
result as JSON to `--out` (a file, **not** stdout — avoids bootstrap/deprecation noise polluting the
payload). Exit non-zero on any throwable.

**3. Parallelize in `build()`** behind `RichterConfig::parallel()` (config key `richter.parallel`):
- Spawn the worker: `Process::path(base_path())->timeout(N)->run([PHP_BINARY, base_path('artisan'),
  'richter:internal-tracer-branch', "--project={$projectRoot}", "--out={$tmp}"])` **before** running
  Branch A inline, so the two overlap; then run Branch A (analyze + canonicalize) in the main process;
  then `wait()`/read the worker's JSON from `$tmp`.
- **Merge**: append the worker's `edges` at the same position Branch B occupied serially (after
  Brain edges, before the rewrites), set the two flags, then run the unchanged merge tail. Result is
  byte-identical.
- **Fallback to serial** (mandatory): if the worker exits non-zero, times out, `$out` is missing, or
  the JSON fails to decode/validate → run `buildTracerBranch($projectRoot, $onProgress)` inline
  instead. Emit a one-line stderr note; never throw. This also covers environments where spawning
  artisan is impossible.
- `--profile` **forces serial** (the phase split must stay measurable; a subprocess's phase timings
  wouldn't reach the parent's `onProgress`).

**4. Config**: add `'parallel' => true` to `config/richter.php` with a comment. Default **on**
(the win is the point; correctness is gated by the equality test and the serial fallback). Note in
the PR that a maintainer may set it `false` for a conservative rollout.

Cache: no interaction — the parallel path lives inside `build()`, which `GraphCache` only calls on a
miss; the output graph (hence the fingerprint-keyed cached value) is unchanged.

## Test plan (test-first)

1. **Refactor invariance** — after extracting `buildTracerBranch`, the existing `CodeGraphBuilder`
   suite passes unchanged (serial path behavior identical).
2. **Equality gate (hard)** — for the fixture project, assert the graph built with `parallel = false`
   and the graph built via the merge-with-worker-output path are **identical**. Practical form: build
   Branch A inline + `buildTracerBranch()` inline, merge, and assert the resulting `CodeGraph` (or its
   `--json` serialization) is byte-identical to the plain serial `build()`. This proves the
   merge/order logic without needing a live subprocess.
3. **Worker command** — invoke `richter:internal-tracer-branch --project=<fixture> --out=<tmp>`;
   assert the JSON decodes to `{edges, unparseableFiles, unresolvedDispatches}` matching
   `buildTracerBranch()` on the same fixture.
4. **Merge + fallback wiring via `Process::fake`** — fake the worker invocation returning known JSON
   → assert `build()` merges it into the identical graph; fake a **failed** worker (non-zero / no
   output) → assert `build()` falls back to serial and still returns the identical graph, without
   throwing.
5. **`--profile` forces serial** — assert the phase events still arrive (worker path not taken).

**Testbench note**: the package test env has no real `base_path('artisan')` binary, so the *live*
cross-process spawn is not unit-testable — tests 2–5 cover the merge/worker/fallback logic with
`Process::fake` + in-process `buildTracerBranch`. Validate the true fork in a **host app**: run
`richter:detect-changes --profile` (serial baseline) vs a normal run (parallel) in hihaho and confirm
(a) identical `--json`, (b) wall-time drop toward ~max(A,B). Record the before/after in the report.

## Verification

- `vendor/bin/pest` — full suite green (equality + fallback + worker + profile cases).
- `vendor/bin/phpstan analyse`, `vendor/bin/pint --dirty`, rector — clean.
- Host-app check (hihaho): `--json` identical serial-vs-parallel; wall-time ~13s vs ~23s.

## STOP conditions

- The equality gate (test 2) shows **any** difference (edges, order, metadata, flags) between serial
  and merged-parallel → stop; the merge order or the Branch-B extraction is wrong. Do not "sort to
  match" — find why the order diverged.
- The only portable spawn path in the target env is `pcntl`/`Concurrency` (i.e. `Process` + artisan
  can't be made to work) → stop and report; do not adopt a non-portable driver.
- Worker output can only be delivered via stdout (not a file) and bootstrap noise can't be
  suppressed → stop; a polluted payload that *silently* parses wrong is worse than serial.

## Follow-ups (not this plan)

- Fine-grained `traceMethod` parallelism (chunk entry-point classes across workers) — only worth it
  for apps where Branch B > Branch A; defer until such an app is measured.
- Plan 045 (skip call-free methods) composes with this and stays independent.
