# Plan 020: Cover the frontend/Blade-inline seam of ChangedSymbols::resolve() end-to-end

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Changes/ChangedSymbols.php src/Changes/FrontendChanges.php tests/Feature tests/Fixtures/project`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW (adds tests and fixtures only; no `src/` change)
- **Depends on**: none. Preferred order: this plan **before** 018/019 (its
  tests pin the seam those plans then change knowingly). If 019 already
  landed, expect its UNRESOLVED guard to be present and assert accordingly.
- **Category**: tests
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

The frontend bridge (~600 lines across scanner, mapper, spec index) is v0.7.0's
flagship feature, and its *integration seam* — the branches inside
`ChangedSymbols::resolve()` that gate on `handles()`, wire head/base sources
in, and pick the frontend-vs-Blade-view path — has zero test coverage. Every
frontend unit test calls `FrontendChanges::resolve()` / `inlineUriSeeds()`
directly with literal source strings; every analyzer test hand-builds
pre-mapped seeds; every end-to-end command test changes `app/Models/User.php`.
The fixture project contains no endpoint-referencing frontend file and no spec
file, so `FrontendChanges` and `FrontendTestIndex` never run against it. A
regression in root/extension/generated-path gating, in source wiring, or in
branch selection would break the whole frontend lane in a real diff with no
failing test.

## Current state

- `src/Changes/ChangedSymbols.php:85-106` — the untested seam:

  ```php
  // A changed frontend file (opt-in via richter.frontend.roots) seeds the route nodes of
  // the backend endpoints it references — Wayfinder imports and Ziggy route() calls.
  if ($frontendChanges->handles($file)) {
      $changed[] = $frontendChanges->resolve($file, self::headSource($head, $file), self::baseSource($mergeBase, $hunk['oldPath']));

      continue;
  }

  // A changed Blade view carries no PHP member to pin, so it seeds its own view node — …
  $viewSeed = BladeViews::seedForChangedFile($file);

  if ($viewSeed !== null) {
      $headSrc = self::headSource($head, $file);
      $changed[] = new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: [
          $viewSeed,
          ...$frontendChanges->inlineUriSeeds($headSrc, self::baseSource($mergeBase, $hunk['oldPath'])),
      ], findings: $featureGateChecker->bladeFindingsFor($headSrc ?? ''));
  }
  ```

- **How sources are read**: `ChangedSymbols::resolve(string $base, string $head = 'HEAD')`
  (`src/Changes/ChangedSymbols.php:23`) — per its docblock, `HEAD` reads
  changed sources **from the working tree** (i.e. `base_path() . '/' . $file`);
  any other ref reads them via `git show`. The commands always pass
  head=`HEAD`, so a Feature-level test needs the changed file to exist under
  `base_path()`.
- **The faked-git pattern** used by every end-to-end command test
  (`tests/Feature/CommandsTest.php:96-121`):

  ```php
  $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- …\n+++ …\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

  Process::fake([
      '*merge-base*' => Process::result("abc123\n"),
      '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
      '*diff*' => Process::result($diff),
  ]);

  $this->withoutMockingConsoleOutput();
  $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base']);
  $output = Artisan::output();
  ```

- **The fixture project** is `tests/Fixtures/project` (exposed via
  `TestCase::fixtureProjectPath()`, `tests/TestCase.php:14-17`). Its only
  frontend file is `resources/js/Pages/Videos/Show.vue`, which renders a prop
  and references no endpoint. There are no `*.spec.ts` / `*.test.ts` files.
  Its PHP classes autoload as `App\` (see `composer.json` autoload-dev).
- **Pointing the app at the fixture project**: check how the existing tests do
  it before inventing anything. `tests/Feature/CodeGraphBuilderTest.php:36-41`
  passes the path into the builder (`new CodeGraphBuilder()->build(self::fixtureProjectPath())`);
  for command-level tests the graph builds against the app's `base_path()`.
  Orchestra's `$app->setBasePath(...)` (callable from a test's
  `defineEnvironment` or via `$this->app->setBasePath(...)` before the
  command runs) is the mechanism to make `base_path()` resolve to the fixture
  project so working-tree reads (`headSource`) find committed fixture files.
  **Verify this mechanism works in a scratch test before building on it**
  (step 2); if it does not, use the fallback in the STOP/fallback note below.
- `tests/Unit/FrontendChangesTest.php:12-21` — route registration pattern for
  the router-backed index:

  ```php
  config()->set('richter.frontend.roots', ['resources/js']);
  Route::get('/videos/{video}', ['App\Http\Controllers\VideoController', 'show'])->name('videos.show');
  ```

- `config/richter.php:32-42` — the frontend config keys (`roots`,
  `generated_paths`, `pages_path`, `test_paths`).
- Graph cache is off in tests (`tests/TestCase.php:29-32`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter FrontendSeamTest` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `test-writing` — naming and structure conventions.
- Skill `backend-quality` for closing checks.

## Scope

**In scope** (files you may create/modify):
- `tests/Feature/FrontendSeamTest.php` (create)
- `tests/Fixtures/project/resources/js/**` (create 2–3 small fixture files)
- `tests/Fixtures/project/resources/views/**` (create 1 Blade file with an inline script, if the seam test needs one — see step 4)

**Out of scope** (do NOT touch):
- Any file under `src/` — this plan is coverage-only. If a test exposes a bug,
  record it (expected: the bugs plans 018/019 fix, if those haven't landed) —
  pin current behavior with a comment referencing the plan number instead of
  fixing it here.
- Existing fixture PHP files — the graph-builder tests depend on their exact
  shape.
- `phpunit.xml`, `testbench.yaml`.

## Git workflow

- Branch: `advisor/020-frontend-e2e-seam-coverage`
- Commit style: imperative subject, e.g. `Cover the frontend seam of ChangedSymbols end-to-end`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add endpoint-referencing frontend fixtures

Create under `tests/Fixtures/project/resources/js/`:

- `components/VideoApi.ts` — contains a Wayfinder action import, a Ziggy call
  and a literal:

  ```ts
  import { show } from '@/actions/App/Http/Controllers/Video/QuestionController';

  export function load(id: number) {
      return fetch(route('videos.show', id));
  }

  export function ping() {
      return fetch('/ping');
  }
  ```

  (Class/route names must exist in the routes you register in step 3 — align
  them; the fixture project's controllers live under
  `app/Http/Controllers/Video/`, e.g. `QuestionController`.)
- `specs/video.spec.ts` — a frontend spec referencing one of the same routes
  (`route('videos.show')` or the literal), for the `frontendTests` lane.

**Verify**: `ls tests/Fixtures/project/resources/js/components tests/Fixtures/project/resources/js/specs` → files exist.

### Step 2: Prove the base-path mechanism in the new test file

Create `tests/Feature/FrontendSeamTest.php` (extends
`SanderMuller\Richter\Tests\TestCase`, mirrors `CommandsTest`'s imports). Add
a setup that (a) points `base_path()` at `self::fixtureProjectPath()` and
(b) sets `config()->set('richter.frontend.roots', ['resources/js'])` and
registers the routes from step 3. Add a smoke test:

```php
#[Test]
public function the_fixture_project_is_the_working_tree(): void
{
    $this->assertFileExists(base_path('resources/js/components/VideoApi.ts'));
}
```

**Verify**: `vendor/bin/phpunit --filter the_fixture_project_is_the_working_tree` → passes.
If it fails after one honest attempt at wiring `setBasePath`, take the
fallback: skip base-path switching entirely and call
`ChangedSymbols::resolve()` is not needed — instead test the seam by invoking
the **command** with `Process::fake` where the `*diff*` result names the
fixture files AND fake `*show*` per-path to return the fixture file contents
(read them with `file_get_contents(self::fixtureProjectPath() . '/…')` in the
test) using a **non-HEAD head ref** only if the command supports it; if
neither works, STOP and report which assumption broke.

### Step 3: Seam test — changed frontend file seeds its referenced routes

In `FrontendSeamTest`, register routes matching the fixture references:

```php
Route::get('/videos/{video}', [\App\Http\Controllers\Video\QuestionController::class, 'show'])->name('videos.show');
Route::get('/ping', [\App\Http\Controllers\Video\QuestionController::class, 'index']);
```

Fake git so the diff reports `resources/js/components/VideoApi.ts` as changed
(hunk content is irrelevant — one added line suffices), with `*merge-base*`
faked and `*show*` failing (base side unreadable is fine: head side comes from
the working tree). Run `richter:detect-changes --base=some-base --json` via
`Artisan::call` + `Artisan::output()` and assert on the decoded document:

- `changed` contains the `.ts` file;
- `coverage[file] === 'analyzed'`;
- `entryPoints` contains the `route::GET::/videos/{video}` node **only if the
  graph knows it** — note the graph is built from the fixture project's real
  routes file, so instead assert the weaker, stable contract: the file's
  `changed` count > 0 OR `coverage` is `analyzed` and `risk` stays `low`.
  Inspect the actual output first and pin the strongest assertions that hold;
  document in a comment why each is the contract (frontend never moves risk).

Add the negative-gating sibling test: the same diff for
`resources/js/actions/App/Http/Controllers/Anything.ts` (a generated-tree
path) must NOT appear in `changed` as a frontend entry (generated paths are
excluded by `handles()`).

**Verify**: `vendor/bin/phpunit --filter FrontendSeamTest` → all pass.

### Step 4: Seam test — Blade view with inline script

Add a fixture Blade file
`tests/Fixtures/project/resources/views/videos/inline.blade.php` containing an
inline `<script>fetch('/ping')</script>` block. Fake a diff naming it, run
`richter:detect-changes --json`, and assert:

- the view file appears in `changed`;
- coverage is `analyzed` (its view seed resolves — check the built graph
  actually contains the view node; if the fixture view isn't referenced by
  any controller/view the node may be absent and coverage reads `unresolved`;
  in that case reference it from an existing fixture view with `@include` so
  it enters the graph, or assert `unresolved` with a comment explaining the
  choice).

**Verify**: `vendor/bin/phpunit --filter FrontendSeamTest` → all pass.

### Step 5: Full regression

The new fixture files must not disturb the graph-builder or Blade tests
(new views/scripts enter the built graph).

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0.

## Test plan

Covered by the steps: seam happy path (frontend file → analyzed, routes
mapped), generated-path exclusion, Blade-inline script branch, plus the
smoke test pinning the base-path mechanism. Model structure after
`tests/Feature/CommandsTest.php` (faked git; `withoutMockingConsoleOutput`).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `tests/Feature/FrontendSeamTest.php` exists with ≥4 tests, all passing
- [ ] `tests/Fixtures/project/resources/js/` contains ≥1 endpoint-referencing source file and ≥1 spec file
- [ ] `composer test` exits 0 (516 + new)
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] No `src/` file modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The "Current state" excerpts don't match the live code (drift).
- Both the `setBasePath` mechanism and the step-2 fallback fail — the seam
  may genuinely need a `src/` seam change to be testable, which is a design
  decision for the maintainer.
- Adding fixture frontend/Blade files breaks existing graph-builder tests in
  a way that adjusting assert-counts doesn't cleanly fix (fixture coupling is
  tighter than assumed).
- A seam test exposes a bug beyond those documented in plans 018/019 — pin it
  with an explicitly-commented expectation and report it; do not fix `src/`.

## Maintenance notes

- These tests are the safety net for plans 018/019 (and any future frontend
  work): when those land, the pinned expectations here may need a deliberate
  update — that is the point.
- The fixture `.ts`/`.spec.ts` files are now part of the graph-build input
  surface for tests; anyone renaming fixture controllers/routes must update
  them in step with the references.
