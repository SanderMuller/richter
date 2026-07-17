# Plan 009: Reuse the consolidated pass's ASTs for the entry-point tracer's own parses

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
> it as a STOP condition. **Additionally**: plans 005 and 008 must be DONE —
> check their rows in `plans/README.md`.

## Status

- **Priority**: P3
- **Effort**: L
- **Risk**: MED
- **Depends on**: plans/005-dedupe-name-resolution.md, plans/008-single-pass-ast-collection.md
- **Category**: perf
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

Every class under the entry-point roots (`Jobs`, `Listeners`, `Console/Commands`, `Helpers`, `Http/Middleware`, `Livewire`, `Observers`) is parsed at least three times per uncached graph build: once inside Brain's `ProjectAnalyzer::analyze()`, once by the consolidated per-file pass, and once more by `EntryPointTracer::methodsOf()` (via Brain's `PhpFileParser`) just to list its method names. `EventServiceProvider.php` gets a fourth parse. The Richter-side redundancy is cheap to remove: the consolidated pass already produces a resolved AST for every one of those files — retain the few that the entry-point tracer needs and hand them over. The deep re-parsing *inside* Brain's `MethodTracer` (it re-tokenises downstream callee files per traced method) is the larger cost but lives behind Brain's API — this plan explicitly does not touch it (see the investigation step and Maintenance notes).

## Current state

- `src/Graph/CodeGraphBuilder.php:78-88` — the build order today: `EntryPointTracer::trace()` runs **before** `consolidatedTracerEdges()`:

```php
foreach (new EntryPointTracer(RichterConfig::entryPointRoots())->trace($projectRoot) as $entryPointEdge) {
    $edges[] = $entryPointEdge;
}

// One consolidated AST pass feeds the dispatch/policy/reference/interface tracers — each used
// to re-parse the whole app tree itself, which cost ~30-60s per tracer per build.
$consolidated = $this->consolidatedTracerEdges($projectRoot);
```

- `src/Graph/CodeGraphBuilder.php:131-132` — the consolidated loop parses every file under `app/`: `foreach (AppFiles::phpClasses($projectRoot . '/app', $projectRoot) as $class) { $ast = AppFiles::parseResolved((string) file_get_contents($class['path'])); ... }` — this scan **includes** every entry-point root (they all live under `app/`) and `app/Providers/EventServiceProvider.php`.
- `src/Tracers/EntryPointTracer.php:55-76` — `trace()` iterates the roots and calls `methodsOf()` per class; `:127-145` — `methodsOf()` re-parses the class file via Brain's `PhpFileParser` **only to list non-abstract method names** (a `findInstanceOf(ClassMethod)` over the fresh AST). No name resolution is needed for method names.
- `src/Tracers/EntryPointTracer.php:156+` — `eventListenerEdges()` parses `EventServiceProvider.php`. After plan 005 this goes through `AppFiles::parseResolved(file_get_contents(...))` — still its own parse of a file the consolidated pass also parses.
- `src/Tracers/EntryPointTracer.php:70-74` — per method, `traceMethod()` calls Brain's `MethodTracer::traceMethod($fqcn, $method, $psr4, $projectRoot)` — Brain parses internally; out of Richter's control.
- Memory constraint (why the consolidated loop streams): it processes one file at a time and discards the AST. Retaining **all** ASTs would trade the parse win for a memory blow-up on large apps. Only the entry-point-root files + `EventServiceProvider.php` may be retained — a bounded subset.
- Behavior pins: `tests/Feature/CodeGraphBuilderTest.php` (fixture-project edges, incl. `a_dispatched_job_links_back_to_its_dispatching_action` and `a_listen_registered_listener_links_to_its_event`), plus the tracer unit tests.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Builder + tracer tests | `vendor/bin/phpunit --filter 'CodeGraphBuilderTest|ImpactAnalyzerTest'` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Graph/CodeGraphBuilder.php`
- `src/Tracers/EntryPointTracer.php`
- `tests/Feature/CodeGraphBuilderTest.php` (only if a new pin test is added per the test plan)

**Out of scope** (do NOT touch, even though they look related):

- Brain internals (`MethodTracer`, `PhpFileParser`, `ProjectAnalyzer`) — vendor code.
- Feeding ASTs *into* `MethodTracer` — investigation only (step 4); implementing it requires Brain-side API changes.
- Retaining ASTs for files outside the entry-point roots + `EventServiceProvider.php` (memory constraint above).

## Git workflow

- Branch: `advisor/009-entry-point-parse-reuse` off `main`.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Reorder the build so the consolidated pass runs first

In `build()`, move the `consolidatedTracerEdges()` call **above** the `EntryPointTracer::trace()` loop. Edge accumulation order does not affect the graph (`CodeGraph` is order-insensitive adjacency; the final `dedupeEdges` keeps first-seen, and duplicates are identical arrays) — but confirm via the full suite, which pins the fixture graph's behavior.

**Verify**: `composer test` → exit 0 before proceeding further (isolates any order sensitivity to this step).

### Step 2: Retain the entry-point subset of ASTs in the consolidated pass

1. Compute the retained-path set before the loop: files whose project-relative path starts with `app/{root}/` for any configured root (`RichterConfig::entryPointRoots()` ?? the tracer's defaults — reuse the same source of truth the tracer uses; expose the tracer's effective roots rather than duplicating the default list), plus `app/Providers/EventServiceProvider.php`.
2. In the loop, when the current file is in the retained set, store its resolved AST in a `array<string, list<Stmt>>` map keyed by absolute path.
3. Return the map alongside the existing `edges`/`unresolvedDispatches` shape.

**Verify**: `composer phpstan` → exit 0 (shape annotations).

### Step 3: Hand the map to the entry-point tracer

1. Change `EntryPointTracer::trace()` to accept the map: `trace(string $projectRoot, array $resolvedAstsByPath = [])`.
2. `methodsOf()`: look up the class's file path in the map first; on a hit, run the method-name extraction over the retained AST (a resolved AST lists the same `ClassMethod` names — name resolution is irrelevant to method names); on a miss, keep the existing `PhpFileParser` fallback (a root configured outside the `app/` scan, or a file that failed `parseResolved`, must not silently lose its methods).
3. `eventListenerEdges()`: same pattern — map hit first, existing parse as fallback.
4. Update the call site in `build()` to pass the map.

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all pass — the fixture project has jobs, listeners, and an EventServiceProvider, so both the map-hit paths are exercised for real.

### Step 4: Investigate (report only) the MethodTracer re-parse

Timebox: read Brain's `MethodTracer`/`PhpFileParser` in `vendor/laramint/laravel-brain` and answer in the completion report: (a) does `PhpFileParser` cache parses per path within a process? (b) is there an existing seam to hand it a pre-parsed AST? Do **not** implement anything against Brain — findings go to the report and to the maintenance notes for audit finding 13's contract work.

**Verify**: the completion report contains both answers with `file:line` references into the vendor package.

### Step 5: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures, zero existing assertions modified.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 6: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "009" plans/README.md` → row shows DONE.

## Test plan

- The existing `CodeGraphBuilderTest` fixture assertions are the primary behavioral pin (jobs/listeners/`$listen` edges must be identical before and after).
- Add one new pin test only if the fixture project lacks a class under a *configured-but-nonstandard* root: the fallback path (map miss → `PhpFileParser`) should be exercised by at least one test. If `tests/Fixtures/project` has no such case, add a minimal one (e.g. configure `entry_point_roots` to include a root the map doesn't retain in one targeted test) — keep it surgical.
- Verification: `composer test` → green, zero modified assertions.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer test` exits 0, 0 failures, zero existing assertions modified
- [ ] `methodsOf()` and `eventListenerEdges()` consult the retained-AST map before parsing (visible in the diff), with the parse fallback intact
- [ ] The consolidated loop retains ASTs **only** for entry-point-root files + `EventServiceProvider.php` (no unbounded retention)
- [ ] The completion report answers step 4's two questions with vendor `file:line` references
- [ ] `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0; `vendor/bin/rector process --dry-run` clean
- [ ] `git status --short` shows changes only in the in-scope files plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Plans 005/008 are not DONE.
- Step 1's reorder alone changes any test result — the build order carries a dependency this plan's model missed; report which test.
- The map-hit path yields different method lists than `PhpFileParser` for any fixture class (parser-behavior divergence between Brain's parser and `AppFiles::parse` — e.g. PHP-version settings). Report the class and both lists; do not paper over with a merged list.
- Retaining the subset measurably breaks the suite's memory behavior (unlikely at fixture scale; a real-app report would come from a consumer).

## Maintenance notes

- The genuinely large remaining cost is Brain-internal (`MethodTracer` re-parsing callees per traced method). Step 4's findings should feed audit finding 13 (Brain coupling/contract) — if Brain ever exposes an AST-injection seam or a parse cache, `traceMethod`'s cost drops without Richter-side changes.
- Reviewer scrutiny: the fallback must remain — deleting the `PhpFileParser` path in `methodsOf` breaks any configured root outside `app/` scanning (or any file `parseResolved` rejects but Brain's parser accepts).
- If `entry_point_roots` config semantics ever change (e.g. roots outside `app/`), the retained-path set computation here must follow.
