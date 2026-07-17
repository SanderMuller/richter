# Plan 008: Collect AST nodes once per file and feed the tracers buckets instead of five full walks

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
> it as a STOP condition. **Additionally**: plan 005 (dedupe name resolution)
> must be DONE before this plan — check its row in `plans/README.md`.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED (touches correctness-sensitive tracers; gated by their unit tests)
- **Depends on**: plans/005-dedupe-name-resolution.md
- **Category**: perf
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

The consolidated tracer pass shares *parsing* (one `parseResolved` per app file) but not *traversal*: for every file, four tracers each run their own `NodeFinder` walks over the same AST — `findInstanceOf(ClassMethod)` three separate times (reference, dispatch, policy), `findInstanceOf(TraitUse)` once, `findInstanceOf(ClassLike)` once. That is five full-tree descents per file where one suffices, multiplied over every file in the host app on every uncached build. Collecting the needed node buckets in a single traversal and handing them to the tracers removes the redundant descents without changing a single edge. The absolute win is smaller than the build cache already landed (the graph is only rebuilt on input changes now), so this is deliberately P3: correct to do, not urgent.

## Current state

- `src/Graph/CodeGraphBuilder.php:120-155` — `consolidatedTracerEdges()`: per file, parses once then calls four tracers on the same `$ast`:

```php
$dispatch = $dispatchTracer->edgesForResolvedAst($ast, $class['fqcn']);
$unresolved += $dispatch['unresolved'];

array_push($edges, ...$dispatch['edges']);
array_push($edges, ...$policyTracer->edgesForResolvedAst($ast, $class['fqcn']));
array_push($edges, ...$referenceTracer->edgesForResolvedAst($ast, $class['fqcn']));
array_push($edges, ...$entryPointTracer->interfaceEdgesForResolvedAst($ast, $class['fqcn']));
```

- The five top-level walks those calls perform on the full AST:
  - `src/Tracers/ReferenceEdgeTracer.php:75` — `findInstanceOf($ast, ClassMethod::class)`; `:93` — `findInstanceOf($ast, TraitUse::class)`.
  - `src/Tracers/DispatchEdgeTracer.php:79` — `findInstanceOf($ast, ClassMethod::class)`.
  - `src/Tracers/PolicyEdgeTracer.php:52` — `findInstanceOf($ast, ClassMethod::class)`.
  - `src/Tracers/EntryPointTracer.php:267` — `findInstanceOf($ast, ClassLike::class)` (in `interfaceEdgesForResolvedAst`).
- The *nested* per-method walks inside each tracer (`referencesIn`, `relationsLoadedIn`, the dispatch call-finding, `policiesReferencedIn`) are method-subtree-scoped and are **not** part of this plan.
- Each tracer also keeps a `edgesForSource()` front (parse-per-call) used by its unit tests and documented as staying: `ReferenceEdgeTracer.php:58-64`, `DispatchEdgeTracer.php:58-67`, `PolicyEdgeTracer.php:36-41`.
- Tests pinning tracer behavior: `tests/Unit/ReferenceEdgeTracerTest.php`, `tests/Unit/DispatchEdgeTracerTest.php`, `tests/Unit/PolicyEdgeTracerTest.php`, and `tests/Feature/CodeGraphBuilderTest.php` (fixture-project edges: route/middleware/job/listen/resource/relation/view/trait).
- Conventions: `final` classes, constraint-stating comments, PHPUnit 12.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Tracer + builder tests | `vendor/bin/phpunit --filter 'ReferenceEdgeTracerTest|DispatchEdgeTracerTest|PolicyEdgeTracerTest|CodeGraphBuilderTest'` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Graph/CodeGraphBuilder.php`
- `src/Tracers/ReferenceEdgeTracer.php`
- `src/Tracers/DispatchEdgeTracer.php`
- `src/Tracers/PolicyEdgeTracer.php`
- `src/Tracers/EntryPointTracer.php`

**Out of scope** (do NOT touch, even though they look related):

- The nested per-method walks inside the tracers — subtree-scoped, different cost class.
- `BladeViewTracer`, `EagerLoadStringChecker` — not part of the consolidated per-file loop.
- The `edgesForSource()` fronts — they stay, as documented, for tests and single-file use.
- `EntryPointTracer::trace()` / `methodsOf()` — that re-parsing is plan 009.

## Git workflow

- Branch: `advisor/008-single-pass-ast-collection` off `main`.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Introduce the shared collector

In `CodeGraphBuilder` (private static method or small dedicated class — match the file's style; a private method returning an array shape is the smaller diff):

One `NodeTraverser` with one visitor over the file's AST that collects three buckets in a single descent:

- `classMethods`: every `ClassMethod` node (any depth — anonymous classes included, exactly what `findInstanceOf` returned),
- `traitUses`: every `TraitUse` node,
- `classLikes`: every `ClassLike` node.

Return shape: `array{classMethods: list<ClassMethod>, traitUses: list<TraitUse>, classLikes: list<ClassLike>}`.

**Verify**: `composer phpstan` → exit 0 (shape annotations parse).

### Step 2: Add bucket-accepting tracer methods, delegating the existing ones

For each tracer, split `edgesForResolvedAst()` so the top-level find is separated from the per-node edge derivation, without changing any derivation logic:

1. `ReferenceEdgeTracer`: new `edgesForNodes(array $classMethods, array $traitUses, string $classFqcn): array` containing the current loop bodies from `edgesForResolvedAst` (lines 70-104), minus the two `findInstanceOf` calls. `edgesForResolvedAst` becomes: run its own two finds, delegate to `edgesForNodes`.
2. `DispatchEdgeTracer`: new `edgesForMethods(array $classMethods, string $classFqcn): array{edges: ..., unresolved: int}` with the current loop body; `edgesForResolvedAst` delegates.
3. `PolicyEdgeTracer`: new `edgesForMethods(array $classMethods, string $classFqcn): array` with the current loop body; `edgesForResolvedAst` delegates.
4. `EntryPointTracer`: new `interfaceEdgesForClassLikes(array $classLikes, string $classFqcn): array` with the current loop body from `interfaceEdgesForResolvedAst` (lines 263-285); the old method delegates.

**Verify**: `vendor/bin/phpunit --filter 'ReferenceEdgeTracerTest|DispatchEdgeTracerTest|PolicyEdgeTracerTest'` → all pass (they exercise the delegating fronts, proving the split is behavior-preserving).

### Step 3: Switch the consolidated loop to the buckets

In `consolidatedTracerEdges()`, after `parseResolved`, run the step-1 collector once and replace the four `edgesForResolvedAst`/`interfaceEdgesForResolvedAst` calls with their bucket-accepting counterparts. The `$unresolved` accounting and edge accumulation stay identical.

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all pass — the fixture-project edge assertions are the end-to-end guard that the buckets feed the tracers the same nodes the finds did.

### Step 4: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures, zero existing assertions modified.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 5: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "008" plans/README.md` → row shows DONE.

## Test plan

Pure refactor — no new tests required. The tracer unit tests exercise the delegating fronts; the builder feature tests exercise the bucket path end-to-end. Both must pass with zero assertion changes. Optional (only if trivially done): a unit test asserting the collector's buckets on a small source string match the equivalent `findInstanceOf` results — worthwhile if the collector becomes its own class.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer test` exits 0, 0 failures, zero existing assertions modified
- [ ] `grep -c "findInstanceOf" src/Graph/CodeGraphBuilder.php src/Tracers/ReferenceEdgeTracer.php src/Tracers/DispatchEdgeTracer.php src/Tracers/PolicyEdgeTracer.php src/Tracers/EntryPointTracer.php` — the consolidated path (`consolidatedTracerEdges` + the bucket methods) contains none; finds remain only in the `edgesForSource`/`edgesForResolvedAst` fronts, the nested per-method walks, and `methodsOf`/`eventListenerEdges`
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in the five in-scope files plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Plan 005 is not DONE (its consolidation is assumed here).
- Any tracer unit test or builder feature test assertion would need modification — the refactor has changed behavior; report the failing edge.
- The visitor's bucket contents differ from `findInstanceOf` semantics in any discovered case (e.g. nodes inside closures/anonymous classes) — report the discrepancy; do not add filtering to force agreement.
- The change appears to require touching the nested per-method walks.

## Maintenance notes

- Plan 009 (entry-point parse reuse) touches `EntryPointTracer::trace()`; land this plan first so 009 rebases on the split methods.
- Reviewer scrutiny: `DispatchEdgeTracer`'s `unresolved` counter must flow through the new method signature unchanged — losing it would silently disable the unresolved-dispatch honesty signal (the package's core promise).
- The win here is traversal constant-factor only; if profiling on a real host app ever happens (see plan 009's escape hatches), measure before extending this pattern further.
