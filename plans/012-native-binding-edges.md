# Plan 012: Replace the Brain container-binding scan with a Richter-native one that survives strict_types providers

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: compare every "Current state" excerpt below
> against the live code in your checkout before proceeding; on a mismatch,
> treat it as a STOP condition. This plan builds on plan 005's branch
> (`advisor/005-dedupe-name-resolution`, commit `c58ddca`).

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW-MED (rewrites one private method; semantics pinned by new fixtures/tests and parity rules below)
- **Depends on**: plans/005-dedupe-name-resolution.md (DONE — this plan branches from its commit)
- **Category**: bug (audit finding 19)
- **Planned at**: `advisor/005-dedupe-name-resolution` @ `c58ddca`, 2026-07-17

## Why this matters

Plan 011's execution proved that Richter's container-binding edges — a marquee README claim — are silently absent for any host app whose service providers open with `declare(strict_types=1);`. The cause is upstream: Brain's `ContainerBindingAnalyzer::scanFile()` unwraps the file's namespace only when it is the *first* AST statement, so a leading `Declare_` makes the provider yield zero binding records, silently. Strict-typed codebases (including this repo's own fixtures, whose Pint config enforces the declare) get no binding edges at all — the falsely-empty result the package exists to prevent. An upstream fix will be PR'd separately; this plan makes Richter independent of the bug by scanning providers itself with its existing, `Declare_`-safe parsing helpers, and pins the behavior with the fixture + test that plan 011 had to revert.

## Current state

- `src/Tracers/EntryPointTracer.php:236-251` (line numbers from the pre-005 tree; on this branch the file is slightly shorter — locate by method name) — the method to replace, verbatim:

```php
/**
 * Resolve interface → concrete bindings registered in service providers.
 *
 * @return list<array{source: string, target: string, type: string}>
 */
private function bindingEdges(string $projectRoot): array
{
    $edges = [];

    foreach (new ContainerBindingAnalyzer()->analyze($projectRoot)->all() as $abstract => $record) {
        if ($record->concreteFqcn !== null && $record->concreteFqcn !== '') {
            $edges[] = [
                'source' => ltrim((string) $abstract, '\\'),
                'target' => ltrim($record->concreteFqcn, '\\'),
                'type' => 'binding',
            ];
        }
    }

    return $edges;
}
```

  Its only call site is inside `trace()`: `...$this->bindingEdges($projectRoot),` — whose result flows through `AppFiles::dedupeEdges(..., byType: true)`, so the new scan may emit duplicates freely.

- The upstream bug, for reference (do NOT modify vendor code): `vendor/laramint/laravel-brain/src/Analysis/ContainerBindingAnalyzer.php:69-72` — `$stmts = $ast; if (isset($stmts[0]) && $stmts[0] instanceof Namespace_) { $stmts = $stmts[0]->stmts; }` — a leading `Declare_` defeats the unwrap; `:86` also means only the first top-level class per file is ever scanned.

- **Parity contract** — the semantics of Brain's analyzer that the replacement must preserve (read from `ContainerBindingAnalyzer.php`, lines noted):
  - Provider discovery: PHP files under `app/Providers/` including subdirectories (`:44`). Use `AppFiles::phpClasses($projectRoot . '/app/Providers', $projectRoot)` — recursive via Finder, a superset of Brain's two-level glob; acceptable.
  - Registration methods (`:28`): `bind`, `singleton`, `scoped`, `bindIf`, `singletonIf`, `scopedIf` — calls with **≥ 2 args** (`:204`); the distinction between kinds is irrelevant to Richter (every kind becomes the same `binding` edge).
  - App-like receivers (`:223-252`): `$this->app->…`, `$app->…`, `app()->…` — exactly these three shapes.
  - Property form (`:98-113`): **non-static** properties named `bindings` or `singletons` with an `Array_` default; each item's key is the abstract, value the concrete.
  - Class-like resolution (`:254-277`): `Xxx::class` const-fetch → FQCN; a **string literal only when it contains a backslash** and matches `/^\\\\?[\w\\\\]+$/` (a bare `'foo'` alias is not a class); anything else → null. A closure concrete → null.
  - Null/empty concrete produces **no edge** (that is what the current `bindingEdges` filter does).

- The replacement's resolution primitive: with `AppFiles::parseResolved()` (name-resolved AST), `Xxx::class` is a `ClassConstFetch` whose `class` is a `Name` carrying `resolvedName` — resolve via `AppFiles::resolveName($node->class)`. This is strictly better than Brain's manual useMap and is the same helper the other tracers use (post-005).

- **Documented behavioral deltas** (deliberate, must appear in the new method's docblock): (1) providers opening with `declare(strict_types=1)` now yield their bindings — the bug this plan fixes; (2) every class in a provider file is scanned, not only the first.

- `src/Tracers/EntryPointTracer.php` imports on this branch: `use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;` becomes unused after the rewrite — remove it. `ContainerBindingAnalyzer` must not appear anywhere in `src/` afterwards.

- `tests/Fixtures/project/` — contains no service provider with bindings (plan 011's attempt was reverted). Existing fixtures follow `<?php declare(strict_types=1);` + `App\` namespaces. New fixture classes must be self-contained (reference no existing fixture classes) so existing membership assertions stay undisturbed.

- `tests/Feature/CodeGraphBuilderTest.php` — the fixture-graph tests; `graph()` helper, `directCallersOf()` helper, membership-style assertions. Model the new tests on its existing edge tests.

- Conventions: `<?php declare(strict_types=1);`, `final` classes, constraint-stating comments, PHPUnit 12 `#[Test]`, snake_case behavioral test names.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Builder tests | `vendor/bin/phpunit --filter CodeGraphBuilderTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (318 on this branch + new tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector (scoped) | `vendor/bin/rector process --dry-run src/Tracers/EntryPointTracer.php tests/Feature/CodeGraphBuilderTest.php` | no findings |
| No Brain analyzer left | `grep -rn "ContainerBindingAnalyzer" src/` | no matches |

(Repo-wide rector still reports the two known pre-existing findings in `src/Graph/GraphCache.php` and `tests/Feature/GraphCacheTest.php` — baseline debt, out of scope.)

## Scope

**In scope** (the only files you should modify or create):

- `src/Tracers/EntryPointTracer.php`
- `tests/Fixtures/project/app/Contracts/VideoTranscoder.php` (create)
- `tests/Fixtures/project/app/Services/FfmpegTranscoder.php` (create)
- `tests/Fixtures/project/app/Contracts/ThumbnailRenderer.php` (create)
- `tests/Fixtures/project/app/Services/GdThumbnailRenderer.php` (create)
- `tests/Fixtures/project/app/Providers/AppServiceProvider.php` (create)
- `tests/Feature/CodeGraphBuilderTest.php`

**Out of scope** (do NOT touch):

- `vendor/` — the upstream fix is a separate PR by the maintainer.
- `src/Graph/CodeGraphBuilder.php`, `AppFiles`, the other tracers.
- Existing fixture files.

## Git workflow

- Branch: `advisor/012-native-binding-edges` created **from commit `c58ddca`** (plan 005's tip — it touches the same file; branching from it makes integration order deterministic).
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR.

## Steps

### Step 1: Add the fixtures

Create the two contract/implementation pairs (all strict-typed, self-contained):

- `App\Contracts\VideoTranscoder` (interface, one method `transcode(): void`) + `App\Services\FfmpegTranscoder` (final class implementing it).
- `App\Contracts\ThumbnailRenderer` (interface, one method `render(): void`) + `App\Services\GdThumbnailRenderer` (final class implementing it).

Create `tests/Fixtures/project/app/Providers/AppServiceProvider.php` exercising **both registration surfaces** the parity contract names:

```php
<?php declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ThumbnailRenderer;
use App\Contracts\VideoTranscoder;
use App\Services\FfmpegTranscoder;
use App\Services\GdThumbnailRenderer;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        ThumbnailRenderer::class => GdThumbnailRenderer::class,
    ];

    public function register(): void
    {
        $this->app->bind(VideoTranscoder::class, FfmpegTranscoder::class);
    }
}
```

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all existing tests still pass (fixtures are additive and self-contained).

### Step 2: Add the failing binding-edge tests

In `tests/Feature/CodeGraphBuilderTest.php`, add two tests modeled on the file's existing edge tests:

1. `a_container_binding_links_the_contract_to_its_concrete` — assert the graph carries a `binding` edge from `App\Contracts\VideoTranscoder` to `App\Services\FfmpegTranscoder` (edge direction abstract → concrete: assert via a dependencies walk from the contract, exact `via` type `'binding'`).
2. `a_singletons_property_links_the_contract_to_its_concrete` — same assertion shape for `ThumbnailRenderer` → `GdThumbnailRenderer`.

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → exactly these 2 tests fail (no binding edge exists — the strict-typed provider is invisible to Brain's analyzer). Any other failure is a STOP condition. This failure IS the regression reproduction for audit finding 19.

### Step 3: Rewrite `bindingEdges()` as a Richter-native scan

In `src/Tracers/EntryPointTracer.php`:

1. Add a private constant with the six registration method names (mirroring the parity contract).
2. Replace `bindingEdges()`'s body: iterate `AppFiles::phpClasses($projectRoot . '/app/Providers', $projectRoot)`; per file, `AppFiles::parseResolved((string) file_get_contents(...))` (skip on null); then extract edges from **both** surfaces per the parity contract:
   - `MethodCall` nodes (found via `NodeFinder`, any depth) whose name is one of the six methods, whose receiver is app-like (`$this->app` property fetch, `$app` variable, `app()` func call — replicate exactly those three shapes), with ≥ 2 args: abstract from arg 0, concrete from arg 1.
   - Non-static `Property` nodes named `bindings`/`singletons` with an `Array_` default: abstract from each item key, concrete from each item value.
   - Class-like resolution for both: `ClassConstFetch` with identifier `class` and a `Name` class → `AppFiles::resolveName()`; `String_` only when it contains `\\` and matches `/^\\\\?[\w\\\\]+$/` → `ltrim(..., '\\')`; anything else (closures included) → no edge.
   - Emit `['source' => $abstract, 'target' => $concrete, 'type' => 'binding']`; duplicates are fine (deduped downstream).
3. Update the method docblock: what it scans, and the two documented deltas vs Brain (declare-safe; all classes per file). Reference the upstream bug in one line so a future reader knows why this is Richter-native (`laravel-brain ContainerBindingAnalyzer skips providers whose AST starts with Declare_`).
4. Remove the now-unused `use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;` import.

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all pass, including the two step-2 tests.

### Step 4: Full verification

**Verify**:
- `grep -rn "ContainerBindingAnalyzer" src/` → no matches.
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run src/Tracers/EntryPointTracer.php tests/Feature/CodeGraphBuilderTest.php` → no findings.

## Test plan

- The two step-2 feature tests are the regression pin for finding 19 (they fail against Brain's analyzer, pass against the native scan) and cover both registration surfaces (a `bind()` call and a `$singletons` property).
- All existing `CodeGraphBuilderTest` assertions must pass unmodified — the fixtures are additive.
- Full suite green proves no other tracer/builder behavior moved.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter CodeGraphBuilderTest` exits 0 and includes the two new binding tests
- [ ] `grep -rn "ContainerBindingAnalyzer" src/` outputs nothing
- [ ] `composer test` exits 0, 0 failures, zero existing assertions modified
- [ ] `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0
- [ ] Rector reports no findings in the two non-fixture files this plan touches
- [ ] `git status --short` clean after commit; changes only in the in-scope files
- [ ] `plans/README.md` untouched (reviewer maintains it)

## STOP conditions

Stop and report back (do not improvise) if:

- The `bindingEdges()` body does not match the excerpt (modulo the 005-branch context).
- The step-2 tests fail for any reason other than a missing `binding` edge.
- After step 3, any *existing* test assertion would need modification.
- Replicating the app-like-receiver or string-literal semantics turns out to require behavior the parity contract does not describe (report the divergent case; do not invent semantics).
- `composer test` on the branch base (before your changes) is not 318/0 — the base is not what this plan assumes.

## Maintenance notes

- When the upstream laravel-brain fix lands and the dependency floor is raised past it, this native scan can either stay (it is now also the more complete implementation — all classes per file) or be reverted to the analyzer; that is a deliberate future choice, not an obligation.
- Audit finding 13 (Brain contract test) shrinks by one coupling point once this lands — note it there when finding 13 is planned.
- Reviewer scrutiny: the string-literal rule (backslash required) and the closure-skip are the two places an executor might over-extract; both must match the parity contract exactly.
