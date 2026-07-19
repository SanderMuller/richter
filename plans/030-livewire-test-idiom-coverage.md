# Plan 030: Pin the Livewire/Filament test-selection idioms and close the string-name gap

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Analysis/TestReferenceIndex.php tests/Unit/TestReferenceIndexTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (recall widening only — more tests selected is the safe direction; never feeds `risk`)
- **Depends on**: none
- **Category**: direction
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

`richter:affected-tests` was requested for a Livewire/Filament host app, and
the feature-handoff research names teaching `TestReferenceIndex` the
Livewire testing idioms as the "(S, do first)" prerequisite "that makes #1
honest". **Scope nuance established at planning time**: the index's generic
class-reference scan already covers the class-shaped idioms — imports
(`use App\Livewire\Settings;` + `Livewire::test(Settings::class)`) and
fully-qualified in-body references (`livewire(\App\Filament\…::class)`) both
record, and existing tests **already pin exactly those two idioms**
(`tests/Unit/TestReferenceIndexTest.php:213-228`). What remains genuinely
open is components referenced by **string name**
(`Livewire::test('admin.dashboard')`, kebab/dot names), which no current
pattern maps to a class — those tests are silently not selected when the
component class changes. This plan closes that one gap via Livewire's naming
convention, plus one characterization edge (relative qualification) to pin
honestly.

## Current state

- `src/Analysis/TestReferenceIndex.php:100-112` — the generic class scan:

  ```php
  private function recordClassReferences(string $source, ?string $file): void
  {
      if (preg_match_all('/^use\s+(App\\\\[^;\s{]+)(?:\s+as\s+\w+)?\s*;/mi', $source, $matches) > 0) {
          foreach ($matches[1] as $class) {
              $this->record($this->classes, $class, $file);
          }
      }

      if (preg_match_all('/(?<![\w$\\\\])\\\\?(App\\\\(?:[A-Za-z_]\w*\\\\)*[A-Za-z_]\w*)/', $source, $matches) > 0) {
          …
  ```

  Its docblock: "a test need not import a class to break when it changes.
  Strings and comments can over-match; for reference detection and test
  selection, over is the safe direction."
- `src/Analysis/TestReferenceIndex.php:158-161` — `testsImporting(string $fqcn)`
  reads the classes map; this is `affected-tests`' second selection axis.
- `src/Analysis/TestReferenceIndex.php:179-186` — FQCN-shaped entry-point
  nodes (self-listed Livewire/job/listener classes) resolve against the same
  classes map:

  ```php
  if (preg_match('/^App\\\\[\w\\\\]+$/', $entryPointNode) === 1) {
      return [
          'referenced' => isset($this->classes[$entryPointNode]),
          'tests' => $this->classes[$entryPointNode] ?? [],
      ];
  }
  ```

- `grep -ci 'livewire\|filament' src/Analysis/TestReferenceIndex.php` → 0
  (no bespoke patterns in `src/`), but
  `tests/Unit/TestReferenceIndexTest.php:213-228` already pins the two
  class-shaped idioms:

  ```php
  public function a_livewire_component_test_references_the_component_entry_point(): void
  {
      // `Livewire::test(Settings::class)` needs no bespoke pattern: the component class reference
      …
      $index->addSource("<?php\nuse App\Livewire\Settings;\nLivewire::test(Settings::class)->call('save');", 'tests/Feature/SettingsTest.php');
      $this->assertTrue($index->hasReference('App\Livewire\Settings'));
      …
      $index->addSource("<?php livewire(\App\Filament\Resources\VideoResource::class)->callTableAction('delete');", 'tests/Feature/VideoResourceTest.php');
  ```

  No test covers string-named components.
- Handoff research (`internal/research-hihaho-feature-handoff.md`, "Recommended
  slicing" item 1): "Teach `TestReferenceIndex` the Livewire/Filament testing
  idioms — `Livewire::test(Component::class)`, the `livewire(…)` helper,
  `::class` references in test bodies (not only imports)." — note the third
  item is already implemented by the second regex above; verify rather than
  re-implement.
- **Livewire's naming convention** (the string-name mapping): default
  component names kebab-case the class path under the app's Livewire
  namespace — `App\Livewire\Admin\Dashboard` ⇄ `admin.dashboard`,
  `App\Livewire\ShowPosts` ⇄ `show-posts`. Richter's stance on conventions
  (README): "Richter assumes standard Laravel conventions."
- Out-of-scope sibling (explicitly): counting `filament_*`/`livewire_component`
  node types as entry points is handoff slice 2, benchmark-gated — do not
  touch `entryPointsAmong()` or the analyzer.
- Test conventions: `tests/Unit/TestReferenceIndexTest.php` feeds sources via
  `addSource()` with inline heredoc PHP strings.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter TestReferenceIndexTest` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `test-writing`; skill `backend-quality` for closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Analysis/TestReferenceIndex.php`
- `tests/Unit/TestReferenceIndexTest.php`

**Out of scope** (do NOT touch):
- `src/Analysis/ImpactAnalyzer.php` / `entryPointsAmong()` — entry-point
  *recognition* widening is benchmark-gated (handoff slice 2).
- `src/Analysis/AffectedTests.php` — selection logic reads the index; no
  change needed.
- Any `route::`/`command::` resolution path in the index.

## Git workflow

- Branch: `advisor/030-livewire-test-idiom-coverage`
- Two commits: `Pin the Livewire test idioms the reference index already covers`,
  then `Map string-named Livewire components onto their conventional classes`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Confirm the existing pins, characterize the one open edge

Read the existing tests at `tests/Unit/TestReferenceIndexTest.php:213-228`
and confirm they cover the import-based `Livewire::test(X::class)` and the
fully-qualified `livewire(\App\…::class)` idioms (they did at planning time —
do NOT re-add those). Add exactly one characterization test:

- `a_relatively_qualified_page_class_pins_its_current_recording` — a source
  with `use App\Filament\Resources\VideoResource;` +
  `livewire(VideoResource\Pages\ListVideos::class)` (relative qualification
  through the imported namespace). Run it, observe what the current regexes
  record, and pin exactly that observed truth — with a comment where a
  sub-path is NOT recorded (the in-body regex anchors on `App\`, so the
  relative form is likely missed). Widening relative-name resolution is NOT
  in scope (it needs alias tracking); the pin plus a note in the final
  report is the deliverable.

**Verify**: `vendor/bin/phpunit --filter TestReferenceIndexTest` → all pass.

### Step 2: Failing tests for string-named components

Add:

1. `a_string_named_livewire_component_maps_to_its_conventional_class` —
   source `Livewire::test('admin.dashboard')` → assert
   `testsImporting('App\Livewire\Admin\Dashboard')` contains the file.
2. Same for the kebab case: `livewire('show-posts')` →
   `App\Livewire\ShowPosts`.
3. Negative: `Livewire::test($component)` (variable) records nothing new and
   throws nothing.

**Verify**: the two positive tests FAIL (current code records no classes for
string names).

### Step 3: Implement the convention mapping

In `TestReferenceIndex::addSource()` (or a private helper beside
`recordClassReferences`), add a pattern for the two call shapes with a string
literal argument:

```php
if (preg_match_all('/(?:Livewire::test|(?<![\w$])livewire)\(\s*[\'"]([a-z0-9\-.]+)[\'"]/', $source, $matches) > 0) {
    foreach ($matches[1] as $name) {
        $this->record($this->classes, self::livewireClassFor($name), $file);
    }
}
```

with the convention inverse (document it as convention-based, mirroring the
README's "assumes standard Laravel conventions" stance):

```php
/** `admin.dashboard-stats` → `App\Livewire\Admin\DashboardStats` — Livewire's default
 *  naming convention, applied in reverse. A custom-namespaced or manually-registered
 *  component won't match; over-recording a non-existent class is harmless (nothing
 *  imports it), under-recording a real one is the direction this closes. */
private static function livewireClassFor(string $name): string
{
    $segments = array_map(
        static fn (string $segment): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $segment))),
        explode('.', $name),
    );

    return 'App\\Livewire\\' . implode('\\', $segments);
}
```

**Verify**: step 2's tests pass; `vendor/bin/phpunit --filter TestReferenceIndexTest`
→ all pass.

### Step 4: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0.

## Test plan

- Step 1: one characterization test (the relative-qualification edge, pinned
  as observed).
- Step 2/3: string-name mapping (dot-nested, kebab, variable-negative).
- Model after the existing `TestReferenceIndexTest` source-feeding style —
  specifically the Livewire tests at lines 213-228.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] ≥4 new tests in `TestReferenceIndexTest`, all passing
- [ ] `testsImporting('App\Livewire\Admin\Dashboard')` selects a file whose only reference is `Livewire::test('admin.dashboard')` (proven by a passing test)
- [ ] `composer test` exits 0; `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated, and the final report lists what step 1 found already-covered vs missed

## STOP conditions

Stop and report back (do not improvise) if:

- The existing Livewire-idiom tests at `TestReferenceIndexTest:213-228` are
  gone or fail (drift) — the planning-time premise ("class-shaped idioms
  already covered and pinned") would be wrong, and the plan's scoping
  decision needs revisiting.
- The string-name convention mapping would need Livewire installed (runtime
  registry lookup) to be correct for the test cases — the convention-only
  approach is the design; report the conflicting case instead of adding a
  dependency.

## Maintenance notes

- The convention mapper is deliberately registry-free; apps with custom
  Livewire namespaces (`livewire.class_namespace` ≠ `App\Livewire`) won't
  match — if that demand materializes, the mapper should read the host app's
  config, a small follow-up.
- Handoff slice 2 (entry-point recognition for `filament_*`/Livewire node
  types) remains benchmark-gated behind plan 029's corpus — do not bundle it
  into review follow-ups here.
