# Plan 011: Cover the interface-implementation and container-binding edges plus the MCP success paths

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

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (additive tests and fixtures only; no `src/` changes)
- **Depends on**: none (integration note: plan 002 also appends a test to `tests/Feature/McpTest.php` on its own branch — a trivial append-append merge)
- **Category**: tests
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-17

## Why this matters

The README headlines "container bindings, interface implementations" among the graph edges that are Richter's core advantage over Brain — and neither has a single test. The fixture project (`tests/Fixtures/project`) contains no `interface`, no `->bind(`, no `->singleton(` (verified by grep), so `EntryPointTracer::bindingEdges()` and `interfaceEdgesForResolvedAst()` — non-trivial logic — can silently break with zero CI signal. Separately, the MCP surface's tests assert only registration, tool names, and two error strings: no test proves either tool's *success* response, so a regression in the text an agent actually receives would ship green. This plan closes both gaps with additive fixtures and tests; it changes no production code.

## Current state

- `src/Tracers/EntryPointTracer.php:236-251` — `bindingEdges()`: feeds Brain's `ContainerBindingAnalyzer::analyze($projectRoot)->all()` into `binding` edges (`abstract → concreteFqcn`), both sides root-slash-trimmed.
- `src/Tracers/EntryPointTracer.php:263-285` — `interfaceEdgesForResolvedAst()`: for each `Class_`/`Enum_` in the file, emits `['source' => implementor, 'target' => interface, 'type' => 'implements']` — **only when the interface FQCN starts with `App\`** (vendor contracts are deliberately excluded). The edge runs implementor → interface so `callersOf(interface)` walks up through implementors.
- `tests/Fixtures/project/` — the mini Laravel app the graph tests build against. Contains `app/Models`, `app/Policies`, `app/Http/Kernel.php`, `app/Providers/EventServiceProvider.php`, `app/Rules`, `app/Enums`, `routes/web.php` — and **zero** interfaces or container bindings (`grep -rn "interface \|->bind(\|->singleton(" tests/Fixtures/project/` → no matches).
- `tests/Feature/CodeGraphBuilderTest.php` — builds the fixture graph once per test via its private `graph()` helper (line 31) using `TestCase::fixtureProjectPath()`; asserts specific edges by walking (`assertContains` on caller lists, a `directCallersOf()` helper at the bottom). Existing tests cover route/middleware/job/listen/resource/relation/view/trait edges. Assertions are membership-based, not exact-count — additive fixture files do not disturb them as long as the new fixtures reference no existing fixture classes.
- `tests/Feature/McpTest.php` — 4 tests (server registered, tool names, ImpactTool empty-symbol error, DetectChangesTool broken-ref error). Style: `resolve(ImpactTool::class)->handle(new Request([...]))`, assertions on the `Response`. NOTE: plan 002's branch adds a 5th test (option-shaped ref) to this file; this plan's tests must be *new additional* methods, not edits to existing ones.
- `src/Mcp/Tools/ImpactTool.php:35-46` — success path: `Response::text(ImpactFormatter::impact($result))` for a non-empty string symbol. The graph comes from `GraphCache->graph()` with `base_path()` as root — in tests that is the **testbench skeleton app**, which contains `app/Models/User.php`.
- `src/Mcp/Tools/DetectChangesTool.php:48-50` — empty-diff success path: `Response::text("No changed PHP files under app/ against {$base}.")`. `ChangedSymbols::resolve()` shells out to `git diff` / `git merge-base` via `Illuminate\Support\Facades\Process` — fakeable with `Process::fake([...])` exactly as `tests/Feature/CommandsTest.php` does (see its `'*diff*' => Process::result($diff)` pattern and `benchmark_skips_a_configured_case_whose_commit_is_unavailable` for per-binary keys).
- `tests/TestCase.php` — disables the graph cache for every test (`richter.cache.enabled` = false), so MCP tool calls build fresh; no cache state to manage.
- Conventions: PHPUnit 12 `#[Test]`, snake_case behavioral names, `<?php declare(strict_types=1);`, fixture PHP files are plain classes with the `App\` namespace.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Builder tests | `vendor/bin/phpunit --filter CodeGraphBuilderTest` | all pass |
| MCP tests | `vendor/bin/phpunit --filter McpTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (318 baseline + new tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector (scoped) | `vendor/bin/rector process --dry-run tests/Feature` | no findings in files this plan touched |

Note: a repo-wide `rector process --dry-run` currently reports two pre-existing findings in baseline files (`src/Graph/GraphCache.php`, `tests/Feature/GraphCacheTest.php`) — known baseline debt, not this plan's concern; verify only that the files this plan touches are clean.

## Scope

**In scope** (the only files you should modify or create):

- `tests/Fixtures/project/app/Contracts/VideoPublisher.php` (create — the interface)
- `tests/Fixtures/project/app/Services/YoutubePublisher.php` (create — the implementor)
- `tests/Fixtures/project/app/Providers/AppServiceProvider.php` (create — the binding)
- `tests/Feature/CodeGraphBuilderTest.php` (new assertions)
- `tests/Feature/McpTest.php` (new success-path tests)

**Out of scope** (do NOT touch, even though they look related):

- Any file under `src/` — this plan is coverage-only. If an edge does not materialize, that is a STOP-and-report, not a fix-the-tracer.
- Existing fixture files — the new fixtures must not reference existing fixture classes (models, policies), so existing edge assertions stay undisturbed.
- `tests/Feature/CommandsTest.php`.

## Git workflow

- Branch: `advisor/011-interface-binding-mcp-coverage` off the baseline commit.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR.

## Steps

### Step 1: Add the interface + implementor fixtures

Create `tests/Fixtures/project/app/Contracts/VideoPublisher.php`:

```php
<?php declare(strict_types=1);

namespace App\Contracts;

interface VideoPublisher
{
    public function publish(): void;
}
```

Create `tests/Fixtures/project/app/Services/YoutubePublisher.php`:

```php
<?php declare(strict_types=1);

namespace App\Services;

use App\Contracts\VideoPublisher;

final class YoutubePublisher implements VideoPublisher
{
    public function publish(): void {}
}
```

(Deliberately self-contained: no references to existing fixture classes.)

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all existing tests still pass (the additive fixtures disturb nothing).

### Step 2: Assert the `implements` edge and its walk

In `tests/Feature/CodeGraphBuilderTest.php`, add a test (model after the existing edge tests, using the `graph()` helper):

- Assert the edge exists: a caller walk from `App\Contracts\VideoPublisher` reaches `App\Services\YoutubePublisher` — e.g. `callersOf(['App\Contracts\VideoPublisher'])` contains a hop whose node is `App\Services\YoutubePublisher` (via type `implements`; use the file's existing assertion idiom, e.g. `directCallersOf('App\Contracts\VideoPublisher')` if that helper fits).

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all pass including the new test. If the `implements` edge does not appear, STOP (see STOP conditions).

### Step 3: Add the container-binding fixture and assert the `binding` edge

Create `tests/Fixtures/project/app/Providers/AppServiceProvider.php`:

```php
<?php declare(strict_types=1);

namespace App\Providers;

use App\Contracts\VideoPublisher;
use App\Services\YoutubePublisher;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoPublisher::class, YoutubePublisher::class);
    }
}
```

Then add a `CodeGraphBuilderTest` test asserting the `binding` edge: a dependency walk from `App\Contracts\VideoPublisher` reaches `App\Services\YoutubePublisher` via type `binding` (`dependenciesOf(['App\Contracts\VideoPublisher'])` — the edge direction is abstract → concrete per `bindingEdges()`).

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all pass. If no `binding` edge materializes, first inspect how Brain's `ContainerBindingAnalyzer` discovers providers (read `vendor/laramint/laravel-brain` — does it scan `app/Providers/*.php` statically, or require `bootstrap/providers.php` registration?). If a small, additive fixture change (e.g. a `bootstrap/providers.php` entry in the fixture project) makes discovery work, that file joins the in-scope list — document it in NOTES. If discovery requires anything beyond additive fixture files, STOP and report the analyzer's requirements with vendor `file:line` references.

### Step 4: MCP success-path tests

In `tests/Feature/McpTest.php`, add two tests:

1. `the_impact_tool_reports_a_blast_radius_for_a_known_symbol` — `resolve(ImpactTool::class)->handle(new Request(['symbol' => 'User']))`; assert NOT an error and the response text mentions `User` (use the Response's text accessor — check how `laravel/mcp` v0.8 exposes content on `Response`; the existing error tests use `isError()`, so inspect the class in `vendor/laravel/mcp` for the text/content accessor and assert on that).
2. `the_detect_changes_tool_reports_an_empty_diff_cleanly` — `Process::fake(['*merge-base*' => Process::result("abc123\n"), '*diff*' => Process::result('')])`, then `resolve(DetectChangesTool::class)->handle(new Request([]))`; assert NOT an error and the text contains `No changed PHP files under app/`.

**Verify**: `vendor/bin/phpunit --filter McpTest` → all pass (4 existing + 2 new).

### Step 5: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures (318 baseline + the new tests).
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run tests/Feature` → no findings in the two test files this plan touched (pre-existing `GraphCacheTest.php` findings excepted).

## Test plan

This plan IS the test plan: two fixture-graph edge tests (implements, binding) in `CodeGraphBuilderTest` and two MCP success-path tests in `McpTest`, all modeled on their files' existing idioms. Zero existing assertions may change.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -rn "interface " tests/Fixtures/project/app/Contracts/` → 1 match (the new contract)
- [ ] `vendor/bin/phpunit --filter CodeGraphBuilderTest` exits 0 and includes the `implements` and `binding` edge tests
- [ ] `vendor/bin/phpunit --filter McpTest` exits 0 with ≥6 tests
- [ ] `composer test` exits 0, 0 failures, zero existing assertions modified
- [ ] `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0
- [ ] `git status --short` shows changes only in the in-scope files (plus, if step 3 required it, the documented fixture bootstrap file)
- [ ] `plans/README.md` untouched (reviewer maintains it)

## STOP conditions

Stop and report back (do not improvise) if:

- The `implements` edge does not appear in the graph for the new fixture (report the actual `nodesContaining('VideoPublisher')` output and the tracer inputs — that would mean the marquee claim is broken, which is a *finding*, not something to patch here).
- Step 3's binding discovery requires more than one small additive fixture file — report Brain's provider-discovery mechanics with vendor `file:line` references instead of restructuring fixtures.
- The `laravel/mcp` `Response` class exposes no usable text accessor for step 4's assertions — report the class's actual API.
- Any existing test assertion would need modification.

## Maintenance notes

- Plan 002's branch also appends to `McpTest.php`; whoever integrates both branches resolves a trivial append-append conflict.
- If the fixture project ever gains classes referencing `YoutubePublisher`/`VideoPublisher`, the membership assertions here keep holding (they assert presence, not counts).
- Follow-up candidates deliberately not in scope: a dedicated `EntryPointTracer` unit test file (its `trace()` loop is currently covered only via the feature graph), and real-diff MCP success coverage (mirroring `CommandsTest`'s faked-diff end-to-end test at the MCP layer).
