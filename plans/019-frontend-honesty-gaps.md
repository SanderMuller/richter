# Plan 019: Close the frontend lane's fail-toward-under-reporting gaps

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Changes/ChangedSymbols.php src/Changes/FrontendChanges.php src/Tracers/FrontendReferenceScanner.php tests/Unit/FrontendChangesTest.php tests/Unit/FrontendReferenceScannerTest.php tests/Unit/ChangedSymbolsTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M (four small independent fixes, each S)
- **Risk**: LOW
- **Depends on**: none (plan 020 adds integration coverage on the same seam; order 020 → 019 preferred, not required)
- **Category**: bug
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

Richter's design contract (README, `internal/spike-ts-backend-bridge.md`) is
that the frontend bridge may over-report but must never silently
under-report: anything it cannot resolve must read UNRESOLVED (which makes
`richter:affected-tests` exit 2 → full suite), never a determined
"no impact". Four spots in the new frontend lane currently fail in the
forbidden direction:

1. A changed frontend file whose head source can't be read scans as an empty
   string and reads as a determined "touches nothing" — the identical I/O
   failure on a PHP file is explicitly guarded.
2. A Ziggy route name built by concatenation (`route('videos.' + action)`) is
   dynamic, but the dynamic-argument detector inspects only the first
   character after `route(`, sees a quote, and stays silent; the captured
   partial name matches nothing and drops.
3. A route template with an optional parameter never matches a bare `/`
   (root `/{locale?}` route) nor a trailing-slash literal (`/videos/`).
4. A Wayfinder module specifier carrying an explicit file extension
   (`…/actions/App/Http/Controllers/VideoController.ts`) fails the `$`-anchored
   module regex, producing no reference and no unresolved flag.

Each fix is small; together they close the known holes in the determinability
contract of the bridge that shipped in v0.7.0.

## Current state

- `src/Changes/ChangedSymbols.php:62-91`. The PHP branch guards the unreadable
  head; the frontend branch does not:

  ```php
  $headSrc = self::headSource($head, $file);

  // An unreadable head source (failed `git show` on a diff that *adds* lines, so the file
  // must exist at head) cannot classify — an empty string would read as cosmetic/additive,
  // the forbidden falsely-empty "no impact". Seed coarsely instead. …
  if ($headSrc === null && $hunk['added'] !== []) {
      $changed[] = new ChangedFileSymbols($file, Fqcn::fromPath($file), [
          new MemberChange('', MemberChange::KIND_CLASS, MemberChange::CHANGE_MODIFIED, resolvable: false),
      ], cosmeticOnly: false);

      continue;
  }
  ```

  ```php
  // A changed frontend file (opt-in via richter.frontend.roots) seeds the route nodes of
  // the backend endpoints it references — Wayfinder imports and Ziggy route() calls.
  if ($frontendChanges->handles($file)) {
      $changed[] = $frontendChanges->resolve($file, self::headSource($head, $file), self::baseSource($mergeBase, $hunk['oldPath']));

      continue;
  }
  ```

- `src/Changes/FrontendChanges.php:68-86` — `resolve()` scans
  `$headSrc ?? ''` / `$baseSrc ?? ''`; with both empty it returns a
  `ChangedFileSymbols` with `unresolvedFrontendReferences: false` (a determined
  empty), and `src/Changes/ChangedFileSymbols.php:57-68`
  (`hasOnlyAdditiveOrCosmeticChanges()`: empty members + empty directSeeds +
  unresolved false → `array_all([]) === true`) then makes
  `ImpactAnalyzer::detectChanges` (`src/Analysis/ImpactAnalyzer.php:93-99`)
  shortcut it to `coverage 'analyzed'`, 0 seeds.
- `src/Tracers/FrontendReferenceScanner.php:62-72`:

  ```php
  preg_match_all('/(?<![\w$])route\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $ziggy);
  …
  // `route(` followed by anything but a string literal or `)` is a dynamic argument —
  // a template literal or variable the scan cannot resolve. Ziggy's argless `route()`
  // fluent form is not dynamic.
  'unresolved' => preg_match('/(?<![\w$])route\s*\(\s*[^\'")\s]/', $source) === 1,
  ```

  For `route('videos.' + action)` the first char after `(` is `'` → not
  flagged; the name regex captures `videos.`, which
  `src/Changes/FrontendChanges.php:110-112` looks up and silently drops
  (`$indexes['byName'][$name] ?? []`). Note the silent drop itself is a
  *documented decision for static unknown names* — the bug is only that a
  dynamic (concatenated) argument escapes the unresolved flag.
- `src/Changes/FrontendChanges.php:305-325` — `uriTemplateRegex()`:

  ```php
  $regex .= match (true) {
      str_starts_with($part, '/{') => str_ends_with($part, '?}') ? '(?:/[^/]+)?' : '/[^/]+',
      str_starts_with($part, '{') => str_ends_with($part, '?}') ? '[^/]*' : '[^/]+',
      default => preg_quote($part, '#'),
  };
  ```

  For route `/{locale?}` this yields `#^(?:/[^/]+)?$#`, which does not match
  the literal `/` (the group needs a slash plus ≥1 non-slash char; the empty
  alternative leaves `/` unconsumed). `/videos/{video?}` yields
  `#^/videos(?:/[^/]+)?$#`, which does not match `/videos/`. The scanner
  captures trailing-slash literals verbatim
  (`src/Tracers/FrontendReferenceScanner.php:90`).
- `src/Tracers/FrontendReferenceScanner.php:38,48` — module regexes are
  `$`-anchored on a `\w`-only final segment:

  ```php
  if (preg_match('#(?:^|/)actions/((?:[A-Za-z_]\w*/)+[A-Za-z_]\w*)$#', $module, $matches) === 1) {
  …
  if (preg_match('#(?:^|/)routes(?:/([A-Za-z0-9_/-]+))?$#', $module, $matches) === 1) {
  ```

  `actions/App/Http/Controllers/VideoController.ts` matches neither and flips
  nothing.
- Design constraints to honor (from `internal/spike-ts-backend-bridge.md` and
  the release notes — quote, because they bound what a "fix" may do):
  - "Per-file `unresolved` flag when a **detected-but-unresolvable** pattern
    appears … Plain 'no references found' is a determined answer, not
    unresolved."
  - "unmatched names never guess" — an unknown *static* route name stays a
    silent drop; do not change that.
  - Over-matching is the safe direction; under-matching is the bug.
- Test conventions: `tests/Unit/FrontendReferenceScannerTest.php` and
  `tests/Unit/FrontendChangesTest.php` (see its `setUp()` registering routes
  via the `Route` facade and `config()->set('richter.frontend.roots', …)`);
  `#[Test]`, snake_case names, one behavior per test.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `vendor/bin/phpunit --filter 'FrontendReferenceScannerTest|FrontendChangesTest|ChangedSymbolsTest'` | OK, 0 failures |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `bug-fixing` (test-first: each fix below writes its failing test before the change).
- Skill `backend-quality` for closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Changes/ChangedSymbols.php` (frontend branch only)
- `src/Changes/FrontendChanges.php` (`uriTemplateRegex`, and `seedsForUris` only if trailing-slash normalization lands there)
- `src/Tracers/FrontendReferenceScanner.php` (the `unresolved` detector and the two module regexes)
- `tests/Unit/FrontendReferenceScannerTest.php`, `tests/Unit/FrontendChangesTest.php`, `tests/Unit/ChangedSymbolsTest.php`

**Out of scope** (do NOT touch, even though they look related):
- `src/Analysis/ImpactAnalyzer.php` — the annotation-lane semantics are correct; the fixes are upstream of it.
- The silent-drop behavior for unknown *static* route names — documented design.
- `src/Analysis/AffectedTests.php` — exit-2 propagation for unresolved files already works; these fixes feed it.

## Git workflow

- Branch: `advisor/019-frontend-honesty-gaps`
- Commit per fix (four commits), imperative subjects, e.g. `Read an unreadable frontend head source as UNRESOLVED`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Unreadable frontend head source → UNRESOLVED

Failing test first, in `tests/Unit/ChangedSymbolsTest.php` (or the Feature
level if that file only tests the pure `classifyFile`; check where
`resolve()` is exercised — the Feature test at
`tests/Feature/CommandsTest.php:96-121` shows the faked-git pattern with
`'*show*' => Process::result(errorOutput: 'bad object', exitCode: 128)`).
The test: a diff adding lines to a file under a configured frontend root
whose head source cannot be read; assert the resulting `ChangedFileSymbols`
has `unresolvedFrontendReferences === true` (or, at command level, that the
output contains `UNRESOLVED`).

Then, in `src/Changes/ChangedSymbols.php`, mirror the PHP guard in the
frontend branch:

```php
if ($frontendChanges->handles($file)) {
    $headSrc = self::headSource($head, $file);

    // Same honesty rule as the PHP branch above: a diff that adds lines proves the file
    // exists at head, so an unreadable head is an I/O failure, not a deletion — scanning
    // '' would read as a determined "no references", the forbidden falsely-empty result.
    if ($headSrc === null && $hunk['added'] !== []) {
        $changed[] = new ChangedFileSymbols($file, '', [], cosmeticOnly: false,
            findings: ['frontend source could not be read at head — references could not be checked'],
            unresolvedFrontendReferences: true);

        continue;
    }

    $changed[] = $frontendChanges->resolve($file, $headSrc, self::baseSource($mergeBase, $hunk['oldPath']));

    continue;
}
```

(A pure deletion — `added === []`, head legitimately null — still flows into
`resolve()` and scans the base side, unchanged.)

**Verify**: the new test passes; `vendor/bin/phpunit --filter ChangedSymbolsTest` → 0 failures.

### Step 2: Concatenated `route()` argument → unresolved

Failing test first, in `tests/Unit/FrontendReferenceScannerTest.php`:
`a_concatenated_route_name_marks_the_scan_unresolved` — source
`route('videos.' + action)` → `unresolved === true`. Add a companion
negative: `route('videos.show', { video: 1 })` (string then comma) must stay
`unresolved === false`.

Then extend the detector in `src/Tracers/FrontendReferenceScanner.php` with a
second pattern: a string literal directly followed by `+` inside the
argument, e.g.:

```php
'unresolved' => preg_match('/(?<![\w$])route\s*\(\s*[^\'")\s]/', $source) === 1
    || preg_match('/(?<![\w$])route\s*\(\s*[\'"][^\'"]*[\'"]\s*\+/', $source) === 1,
```

Decide whether the captured partial name (`videos.`) should also be excluded
from `routeNames`; it is harmless (it matches nothing in the route map), so
prefer leaving the capture untouched — the flag is the fix.

**Verify**: both new tests pass; existing scanner tests still pass.

### Step 3: Optional-parameter templates match `/` and trailing slashes

Failing tests first, in `tests/Unit/FrontendChangesTest.php` (its `setUp()`
already registers `Route::get('/users/{user?}', …)`):

- a literal `/users/` maps to the `/users/{user?}` route;
- register `Route::get('/{locale?}', …)` in the test and assert a literal `/`
  maps to it.

Then in `src/Changes/FrontendChanges.php::uriTemplateRegex`, change the
optional-segment emission from `(?:/[^/]+)?` to `(?:/[^/]*)?` (a slash
followed by zero-or-more non-slash chars, whole group optional). Check the
existing `uriTemplateRegex`-adjacent tests: `/users` and `/users/5` must keep
matching `/users/{user?}`, and required params (`/videos/{video}`) must keep
requiring their segment.

**Verify**: new tests pass; `vendor/bin/phpunit --filter FrontendChangesTest` → 0 failures.

### Step 4: Extension-suffixed Wayfinder module specifiers

Failing tests first, in `tests/Unit/FrontendReferenceScannerTest.php`:

- `import { show } from '@/actions/App/Http/Controllers/VideoController.ts'`
  yields the action reference `{class: 'App\Http\Controllers\VideoController', method: 'show'}`;
- `import { index } from '@/routes/videos.ts'` yields route name `videos.index`.

Then allow an optional extension before the anchors in both module regexes in
`src/Tracers/FrontendReferenceScanner.php`:

```php
'#(?:^|/)actions/((?:[A-Za-z_]\w*/)+[A-Za-z_]\w*)(?:\.\w+)?$#'
'#(?:^|/)routes(?:/([A-Za-z0-9_/-]+))?(?:\.\w+)?$#'
```

The capture groups are unchanged (the char classes exclude `.`, so the
extension can't leak into them). Note the second regex now also matches a
bare `routes.ts` module — the derived names self-gate against the route map
exactly like the already-accepted `routes/` collision, which the spike doc
records as harmless.

**Verify**: new tests pass; the full scanner test file passes.

### Step 5: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0.

## Test plan

- Step 1: unreadable-head frontend file reads UNRESOLVED (+ pure-deletion
  still determined, if not already covered — check for an existing deletion
  test in `FrontendChangesTest`).
- Step 2: concatenated name → unresolved; name-plus-options-object → not
  unresolved.
- Step 3: `/users/` and `/` match their optional-param routes; `/users`,
  `/users/5` regressions pinned.
- Step 4: `.ts`-suffixed action and route imports resolve.
- Pattern: model after the existing tests in the same files.
- Verification: `composer test` → all pass (516 + new).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] All four new failing tests written first, now passing
- [ ] `composer test` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `grep -n "unresolvedFrontendReferences: true" src/Changes/ChangedSymbols.php` shows the new guard
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The "Current state" excerpts don't match the live code (drift).
- Step 3's regex change breaks any existing template-matching test in a way
  that isn't the two documented new behaviors — the `[^/]*` change would then
  be over-matching beyond the safe direction claimed here.
- Step 1's guard cannot be tested without touching `ImpactAnalyzer` or
  `AffectedTests` — the seam assumption would be wrong.
- You find additional dynamic-argument shapes (e.g. `.concat(`) while working:
  note them in the report; do NOT expand scope beyond `+` concatenation.

## Maintenance notes

- The unresolved-detector is now two patterns; any future dynamic-shape
  addition belongs beside them with a test each.
- Reviewer focus: step 3's `[^/]*` — confirm no *required*-parameter template
  loosened; step 4's `routes.ts` bare-module note.
- Deferred deliberately: template literals with interpolation inside
  `route(…)` are already flagged by the first-character check (backtick);
  `.concat()` chains are not handled (noted, rare in idiomatic code).
