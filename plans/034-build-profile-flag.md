# Plan 034: Add --profile to detect-changes — phase timings for the incremental-rebuild decision

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 0b28fb0..HEAD -- src/Graph/CodeGraphBuilder.php src/Graph/GraphCache.php src/Console/DetectChangesCommand.php README.md`
> Plan 033 lands before this plan and touches `DetectChangesCommand` (the
> `--json` branch gains a `$tests` argument to `JsonPresenter::detectChanges`)
> — that drift is EXPECTED; reconcile and continue. Treat only unexplained
> mismatches as STOP conditions.

## Status

- **Priority**: P3
- **Effort**: S–M
- **Risk**: LOW (additive option + additive callback parameter; no behavior change without the flag)
- **Depends on**: 033 (same command file — execute after 033 integrates; expect its drift)
- **Category**: dx (measurement gate for the tier-3 incremental-rebuild question)
- **Planned at**: commit `0b28fb0`, 2026-07-20

## Why this matters

A consumer running `richter:detect-changes` mid-review always has a diff, so the
graph cache's fingerprint almost always misses and every run pays the full build
(~1 minute on a large host app). Whether that minute can become seconds via
incremental rebuilding hinges on one unmeasured fact: **how the build time splits
between Brain's monolithic `ProjectAnalyzer::analyze()` (not incrementalizable from
Richter) and Richter's own passes** (incrementalizable in principle). The evaluation
in `internal/research-hihaho-tier3-handoff.md` gates all incremental-rebuild work on
that number. This plan ships the measurement: a `--profile` flag that times each
build phase and prints the split, so any consumer can produce the evidence on their
own tree. It doubles as permanent supportability ("where does richter's build time
go on my app?").

## Current state

- `src/Graph/CodeGraphBuilder.php:44` — the build entry point already threads a
  progress callback, currently forwarded only to Brain:

  ```php
  public function build(?string $projectRoot = null, ?callable $onProgress = null): CodeGraph
  ...
      $analysis = new ProjectAnalyzer()->analyze(
          $projectRoot,
          $onProgress ?? static fn (string $event, array $data): null => null,
      );
  ```

  The build body has these sequential phases (anchors, in order):
  1. Brain analyze — the `try { … analyze(…) } finally` block (`:58-71`).
  2. Canonicalize + metadata — the `foreach ($analysis->fullGraph->nodes() …)` and
     `…->edges()` loops.
  3. Consolidated tracer pass — `$this->consolidatedTracerEdges($projectRoot, $entryPointTracer)`.
  4. Entry-point tracer — `$entryPointTracer->trace($projectRoot, …)` loop.
  5. Blade tracers — `new BladeViewTracer()->trace(…)` + `new PolicyEdgeTracer()->bladeEdges(…)` loops.
  6. Rewrites + members — `controllerBasenames`/`MiddlewareAliases` +
     `resolveShortControllerIds`/`resolveMiddlewareAliases` + the two
     `NodeMetadata::remapKeys` calls + `memberDeclarationEdges` loop (through to the
     `return`).
- `src/Graph/GraphCache.php:39-64` — `graph(?string $projectRoot = null, bool $fresh = false)`
  calls `$this->builder->build($projectRoot)` on the fresh path (`:44`) and the
  miss path (`:56`); no callback is threaded today.
- `src/Console/DetectChangesCommand.php:30-37` — the `$signature` heredoc listing
  the options (`--no-cache` is last); `:200-203`:

  ```php
  private function graph(GraphCache $graphs): CodeGraph
  {
      return $graphs->graph(fresh: (bool) $this->option('no-cache'));
  }
  ```

  Both `handleText` and the JSON path reach the graph through this helper. The
  `--json` contract: stdout is ONE parseable document — anything extra must go to
  STDERR.
- Brain emits its own events through the same callback (string event names, array
  data) — a phase collector must filter on the richter-specific event name.
- Test patterns: `tests/Feature/CodeGraphBuilderTest.php` builds the real fixture
  graph (`new CodeGraphBuilder()->build(self::fixtureProjectPath())`);
  `tests/Feature/CommandsTest.php:96-121` shows the faked-git end-to-end pattern
  (`Process::fake` + `withoutMockingConsoleOutput()` + `Artisan::call`/`Artisan::output()`).
  In the test harness, command error-output writes into the same captured buffer, so
  `Artisan::output()` assertions cover stderr-routed lines.
- Conventions: `declare(strict_types=1)`, final classes, "why" docblocks, `hrtime()`
  for monotonic timing (do not use `microtime()`), PHPStan max/strict.

## Design (decided)

- **Builder**: wrap the six phases above with `hrtime(true)` and emit one event per
  phase through the EXISTING callback:
  `$onProgress('richter:phase', ['phase' => '<name>', 'seconds' => <float>])` with
  names `brain-analyze`, `canonicalize-metadata`, `consolidated-tracers`,
  `entry-point-tracer`, `blade-tracers`, `rewrites-and-members`. Emit nothing when
  the callback is null (guard once — keep the hot path allocation-free). No
  signature change.
- **GraphCache**: `graph()` gains an additive
  `?callable $onProgress = null` parameter, forwarded to both `build()` call sites.
  Cache HITS never emit phases (nothing was built) — that is correct, not a gap.
- **Command**: add `--profile : Time each graph-build phase and print the split to
  stderr (forces a fresh build)` to the signature after `--no-cache`. In `graph()`:
  when profiling, pass `fresh: true` and a collector closure; after the graph
  returns, write a compact table to STDERR via `$this->getOutput()->getErrorStyle()`:

  ```
  Build profile (fresh build, cache bypassed):
    brain-analyze            41.20s   68%
    consolidated-tracers      9.80s   16%
    …
    total                    60.30s
  ```

  (total = sum of phases; percentages of total, one decimal is fine). `--profile`
  composes with `--json`/`--markdown` because stderr never touches stdout.
- **Scope guard**: `--profile` exists on `richter:detect-changes` only (the command
  the measurement question is about). No MCP surface, no other commands, no README
  config-table change — one sentence in the Graph cache README section.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'CodeGraphBuilderTest|CommandsTest'` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |
| Rector (check) | `vendor/bin/rector process --dry-run` | 0 changed files |

## Scope

**In scope** (the only files you should modify):
- `src/Graph/CodeGraphBuilder.php`
- `src/Graph/GraphCache.php`
- `src/Console/DetectChangesCommand.php`
- `README.md` (one sentence, Graph cache section)
- `tests/Feature/CodeGraphBuilderTest.php`, `tests/Feature/CommandsTest.php`

**Out of scope** (do NOT touch):
- Any incremental-rebuild/provenance machinery — this plan produces the evidence,
  nothing more.
- `src/Mcp/**`, `ImpactCommand`, `AffectedTestsCommand`, `BenchmarkCommand`.
- The cache format / `FORMAT_VERSION` — nothing stored changes.

## Git workflow

- Branch: `advisor/034-build-profile-flag`, created FROM the local main tip.
- Commits per logical unit, imperative subjects, no signing configured, end with:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- Do NOT push or open a PR.

## Steps

### Step 1: Phase events in the builder (test-first)

Failing test in `tests/Feature/CodeGraphBuilderTest.php`:
`the_build_reports_its_phase_timings_through_the_progress_callback` — build the
fixture project with a collecting callback, filter events to `richter:phase`, and
assert: the six phase names appear exactly once each, in the documented order, and
every `seconds` is a float ≥ 0. (Brain's own events flow through the same callback —
the filter is the point of the event-name namespace.)

Then instrument the six phases in `build()`. Keep the null-callback path free of
timing work.

**Verify**: `vendor/bin/phpunit --filter the_build_reports_its_phase_timings` → passes;
`vendor/bin/phpunit --filter CodeGraphBuilderTest` → all pass (the shared
`self::$graph` memo must still build identically — pass no callback there).

### Step 2: Thread the callback through GraphCache

Additive `?callable $onProgress = null` on `graph()`, forwarded at both `build()`
call sites. No test beyond compilation/PHPStan — step 3's command test exercises it
end-to-end.

**Verify**: `composer phpstan` → exit 0.

### Step 3: The --profile option (test-first)

Failing test in `tests/Feature/CommandsTest.php`:
`detect_changes_profile_prints_the_build_phase_split` — the faked-git real-diff
pattern plus `'--profile' => true`; assert `Artisan::output()` contains
`Build profile`, `brain-analyze`, and `total`, AND still contains the normal report
(`Changed files:`). Add a JSON sibling: `--profile --json` → stdout decodes as JSON
(the profile lines ride stderr; in the test buffer they may interleave — decode from
the first `{` if needed, or assert the report keys plus the profile label are both
present; pick the strongest assertion that holds and pin it).

Then implement: signature line, the collector in `graph()` (profile ⇒ fresh build),
the stderr table. `--profile --no-cache` is redundant but harmless — no special
casing.

**Verify**: `vendor/bin/phpunit --filter detect_changes_profile` → passes.

### Step 4: README sentence + full regression

Graph cache section, after the `--no-cache` bullet: one bullet — `--profile` (on
`richter:detect-changes`) forces a fresh build and prints a phase-by-phase timing
split to stderr, for judging where build time goes on a given codebase.

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0; `vendor/bin/rector process --dry-run` → clean.

## Test plan

Steps 1 and 3 enumerate the cases (builder events: names/order/values; command:
profile+report coexistence, JSON contract intact). Model after the named existing
patterns.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] Builder emits six `richter:phase` events in order (pinned by test)
- [ ] `--profile` prints the split and leaves the report (text and JSON contracts) intact (pinned by tests)
- [ ] No profile output whatsoever without the flag (`grep -c 'Build profile' <plain-run output>` → existing tests unchanged)
- [ ] `composer test`, `composer phpstan`, `vendor/bin/pint --test`, `vendor/bin/rector process --dry-run` all clean
- [ ] No files outside the in-scope list modified (`git status`)
- [ ] `plans/README.md` status row updated (unless reviewer maintains the index)

## STOP conditions

Stop and report back (do not improvise) if:

- Unexplained drift beyond plan 033's documented `DetectChangesCommand` change.
- The command's error-output writer is not captured in the test harness buffer
  (the `Artisan::output()` assertion strategy fails) — report the actual capture
  behavior rather than weakening the test to "command exits 0".
- Threading the callback requires touching any file outside the in-scope list.

## Maintenance notes

- The phase names are now a soft contract for anyone parsing profile output —
  renaming a phase deserves a changelog line.
- When a consumer reports their split: Brain ≥ ~60% of total → file the upstream
  laravel-brain issue (per-file provenance / incremental analyze) and keep
  Richter-side incremental work parked; Richter's own passes dominating → revisit
  `internal/research-hihaho-tier3-handoff.md` #1 with a pre-rewrite-provenance
  design. The decision rule lives in that doc; this flag only produces the number.
