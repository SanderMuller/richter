# Plan 018: Resolve Blade-view seeds by exact node membership, not boundary substring

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Analysis/ImpactAnalyzer.php src/Graph/CodeGraph.php src/Graph/BladeViews.php tests/Unit/ImpactAnalyzerTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (plan 020 adds integration coverage around the same area; execution order 020 → 018 is preferred but not mechanically required)
- **Category**: bug
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

A changed Blade view seeds its own graph node (`view::blade__components.card`).
Today that seed is resolved with the *substring* matcher `nodesContaining()`,
whose boundary regex treats `.` as a non-identifier boundary — so the seed for
`components.card` also matches `view::blade__components.card.header`,
`view::blade__components.card.body`, and every other view whose dotted slug has
the changed slug as a prefix. Those sibling views did **not** change, but their
callers are walked as if they did. Component folders (`<x-card>` →
`components.card` next to `<x-card.header>` → `components.card.header`) are the
common trigger. The result inflates `impacted`, `entryPoints`, and `risk` — the
over-reporting direction, which erodes reviewer trust and can trip an opt-in
`--fail-on` CI gate on unrelated views. The codebase already documents this
exact hazard for route ids and guards *that* path with exact `hasNode()`
matching; view seeds never got the same treatment.

## Current state

- `src/Analysis/ImpactAnalyzer.php:115-130` — the seed loop for a changed
  file's `directSeeds`:

  ```php
  // A changed Blade view seeds its own node directly (no members to pin) — a precise seed,
  // so it raises no low-confidence flag; it resolves to nothing only when the view is
  // unreachable, which then reads as UNRESOLVED below, not "no impact". An entry-prefixed
  // direct seed (a route an inline `fetch()` calls) instead takes the same annotation
  // lane as frontend files: a touched surface, never a walk seed.
  foreach ($file->directSeeds as $directSeed) {
      if (Str::startsWith($directSeed, self::ENTRY_POINT_PREFIXES)) {
          if ($this->graph->hasNode($directSeed)) {
              $frontendSeeds[$file->file] = [...$frontendSeeds[$file->file] ?? [], $directSeed];
          }

          continue;
      }

      $fileSeeds = [...$fileSeeds, ...$this->seedsFor($directSeed)];
  }
  ```

  Entry-prefixed seeds (line 121-122) use exact `hasNode`. Everything else —
  which is (a) Blade view node ids and (b) FQCN seeds from pure renames — goes
  through `seedsFor()`.
- `src/Analysis/ImpactAnalyzer.php:593-606` — `seedsFor()` delegates to
  `candidateNodes()` which calls `$this->graph->nodesContaining($fqcn)`.
- `src/Graph/CodeGraph.php:150-162` — `nodesContaining()` filters with the
  boundary regex `'/(?<![A-Za-z0-9_])' . preg_quote($needle, '/') . '(?![A-Za-z0-9_])/i'`.
  A `.` is not in `[A-Za-z0-9_]`, so needle `view::blade__components.card`
  matches inside `view::blade__components.card.header`.
- `src/Graph/CodeGraph.php:67-70` — `hasNode(string $node): bool` exists
  (`isset($this->nodes[$node])`).
- `src/Graph/BladeViews.php:26-31` — `nodeId()` produces
  `view::blade__<dotted-slug>` and keeps dots:

  ```php
  $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9._]/', '_', self::BLADE_FQCN_PREFIX . $viewName));

  return 'view::' . $slug;
  ```

- `src/Analysis/ImpactAnalyzer.php:246-252` — `frontendLane()` documents the
  identical hazard for routes: seeds are filtered with `hasNode(...)`, "not
  `nodesContaining()`, since a shorter route id is a boundary-clean substring
  of a longer one."
- **Why the FQCN fallback must stay**: a pure-rename change seeds the old FQCN
  (`App\Jobs\X`) as a `directSeed` (`src/Changes/ChangedSymbols.php:54-57`).
  For that seed, substring matching is *intentional* — it must pick up both the
  class node and its member nodes (`App\Jobs\X::handle`). An exact-only switch
  for every directSeed would silently break rename detection. The fix must
  discriminate view seeds (prefix `view::`) from FQCN seeds.
- Test conventions: `tests/Unit/ImpactAnalyzerTest.php` uses `#[Test]`,
  snake_case method names, hand-built graphs and `ChangedFileSymbols`
  instances; see `changedFrontend()` at `tests/Unit/ImpactAnalyzerTest.php:947-950`
  for the helper style, and the top of the file for how the test graph is
  constructed.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `vendor/bin/phpunit --filter ImpactAnalyzerTest` | OK, 0 failures |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `bug-fixing` — this is a test-first bug fix; write the failing test
  before the change.
- Skill `backend-quality` for the closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Analysis/ImpactAnalyzer.php` (the directSeed loop only)
- `tests/Unit/ImpactAnalyzerTest.php`

**Out of scope** (do NOT touch, even though they look related):
- `src/Graph/CodeGraph.php` — `nodesContaining()`'s semantics are correct for
  FQCN lookups and are covered by the token index; do not change them.
- `src/Graph/BladeViews.php` — the slug format is Brain-compatible; changing it
  would invalidate graph caches.
- `src/Changes/ChangedSymbols.php` — the seed *producer* is fine; only the
  resolver discriminates.

## Git workflow

- Branch: `advisor/018-exact-blade-view-seeds`
- Commit style: imperative sentence subject, e.g. `Resolve Blade view seeds by exact node membership`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Write the failing test

In `tests/Unit/ImpactAnalyzerTest.php`, add a test named
`a_changed_view_never_seeds_a_nested_sibling_view`:

- Build a graph (using the file's existing graph-construction helper) that
  contains two view nodes, `view::blade__components.card` and
  `view::blade__components.card.header`, each with a distinct caller chain to
  a distinct entry point (mirror how existing tests wire callers).
- Build a `ChangedFileSymbols` for a changed view file with
  `directSeeds: ['view::blade__components.card']` (mirror the construction at
  `ChangedSymbols.php:102-105`: `new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: [...])`).
- Assert `detectChanges()` reaches only the entry point behind
  `components.card`, and that the entry point behind `components.card.header`
  is **not** listed.
- Also assert coverage for the changed file is `analyzed` (the exact-match path
  must still resolve the seed).

**Verify**: `vendor/bin/phpunit --filter a_changed_view_never_seeds_a_nested_sibling_view`
→ FAILS (the sibling's entry point is currently listed).

### Step 2: Discriminate view seeds in the resolver

In `src/Analysis/ImpactAnalyzer.php`, inside the `foreach ($file->directSeeds …)`
loop, route seeds with the `view::` prefix through exact membership:

```php
// A view node id is exact — `components.card` is a boundary-clean substring of
// `components.card.header`, and a sibling view that didn't change must never seed
// (the same rule frontendLane applies to route ids). An absent node seeds nothing,
// which reads UNRESOLVED below, exactly as before.
if (str_starts_with($directSeed, 'view::')) {
    if ($this->graph->hasNode($directSeed)) {
        $fileSeeds[] = $directSeed;
    }

    continue;
}

$fileSeeds = [...$fileSeeds, ...$this->seedsFor($directSeed)];
```

Keep the entry-prefixed branch above it untouched. Keep the FQCN fallthrough
(`seedsFor`) untouched — pure-rename seeds depend on it.

**Verify**: `vendor/bin/phpunit --filter a_changed_view_never_seeds_a_nested_sibling_view`
→ passes.

### Step 3: Confirm the unreachable-view honesty path still holds

Check whether `ImpactAnalyzerTest` already has a test asserting that a changed
view absent from the graph reads `unresolved` (search for `unresolved` +
`view::` in the file). If none exists, add one:
`a_changed_view_absent_from_the_graph_reads_unresolved` — directSeed
`view::blade__not.in.graph`, assert `coverage` is `unresolved` for the file.

**Verify**: `vendor/bin/phpunit --filter ImpactAnalyzerTest` → OK, 0 failures.

### Step 4: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0.

If an existing test fails because it asserted the old broad behavior (a
sibling view being seeded), examine it: if it *documents* the substring
behavior as intended, that contradicts this plan's premise — STOP condition.
If it merely happened to rely on it incidentally, update the expectation and
say so in the commit message.

## Test plan

- New: `a_changed_view_never_seeds_a_nested_sibling_view` (step 1) — the
  regression this plan fixes.
- New (if missing): `a_changed_view_absent_from_the_graph_reads_unresolved`
  (step 3) — pins that exact matching did not break the UNRESOLVED honesty
  path.
- Pattern: model after the existing hand-built-graph tests in
  `tests/Unit/ImpactAnalyzerTest.php`.
- Verification: `composer test` → all pass.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter ImpactAnalyzerTest` exits 0, including the new test(s)
- [ ] `composer test` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] In `src/Analysis/ImpactAnalyzer.php`, view-prefixed directSeeds no longer reach `seedsFor()` (verify: `grep -n "view::" src/Analysis/ImpactAnalyzer.php` shows the new branch)
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `Current state` excerpts don't match the live code (drift).
- An existing test explicitly documents nested-sibling seeding as intended
  behavior (contradicts the premise — the maintainer must arbitrate).
- The fix appears to require touching `CodeGraph` or `BladeViews` — that means
  the discrimination-by-prefix approach doesn't hold and the design needs
  rethinking.
- You discover view directSeeds arriving *without* the `view::` prefix
  (the assumption "all Blade seeds are produced by `BladeViews::nodeId()`"
  would be false).

## Maintenance notes

- If a future seed kind is added to `directSeeds`, decide explicitly whether it
  is exact-matched (ids: views, routes) or substring-matched (FQCNs) — this
  plan establishes the prefix-based discrimination point.
- Reviewer focus: the FQCN fallthrough must be provably untouched (pure-rename
  tests in `ImpactAnalyzerTest` / `ChangedSymbolsTest` still green).
