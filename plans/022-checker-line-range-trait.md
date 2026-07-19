# Plan 022: Extract the duplicated line-range scoping shared by the source checkers

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Tracers/FeatureGateChecker.php src/Tracers/InertiaPageChecker.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

The per-source checker family grew to three members in three days
(`EagerLoadStringChecker`, `FeatureGateChecker`, `InertiaPageChecker`), and the
two newest share a copied skeleton: `withinRanges()` is byte-identical in both,
and both implement the same "locality rule" (only findings inside the changed
members' line ranges count). The locality rule is a correctness-bearing
behavior — a fix to it (e.g. an off-by-one on range ends, or multi-line call
handling) must currently be applied per copy by hand, and a missed copy drifts
silently. A fourth checker is likely (the family is the designated seam for
"changed-source" findings); extracting the shared piece now keeps the rule in
one place.

## Current state

- `src/Tracers/FeatureGateChecker.php:97-101` and
  `src/Tracers/InertiaPageChecker.php:91-95` — byte-identical
  (verified with `diff` at planning time):

  ```php
  /** @param  list<array{int, int}>  $ranges */
  private function withinRanges(int $line, array $ranges): bool
  {
      return array_any($ranges, static fn (array $range): bool => $line >= $range[0] && $line <= $range[1]);
  }
  ```

- The shared call-shape in both `findingsFor()` methods
  (`FeatureGateChecker.php:35-72`, `InertiaPageChecker.php:37-60`):
  `AppFiles::parseResolved($source)` → null-guard → `new NodeFinder()
  ->findInstanceOf($ast, CallLike::class)` → per-call
  `$lineRanges !== null && ! $this->withinRanges($call->getStartLine(), $lineRanges)`
  filter → collect → `findings()` (sort + map to message strings).
- Class shapes differ: `FeatureGateChecker` is `final class` (stateless),
  `InertiaPageChecker` is `final readonly class` with a constructor
  (`private ?string $projectRoot = null`). A common *base class* would have to
  reconcile readonly-ness and constructors — a **trait** does not.
- `EagerLoadStringChecker` is a more divergent cousin (different traversal,
  instance caching) — explicitly not part of this extraction.
- Repo conventions: `declare(strict_types=1)` on the `<?php` line, `final`
  classes, heavy "why" docblocks. There is no `src/Tracers/Concerns/`
  directory yet; nested concern directories are an established Laravel idiom
  (the fixture app itself has `app/Models/Concerns/`).
- Both checkers are unit-tested: `tests/Unit/FeatureGateCheckerTest.php`,
  `tests/Unit/InertiaPageCheckerTest.php` — these are the behavior lock for
  the refactor.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'FeatureGateCheckerTest|InertiaPageCheckerTest'` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `backend-quality` for closing checks.

## Scope

**In scope** (files you may create/modify):
- `src/Tracers/Concerns/ChecksChangedLineRanges.php` (create)
- `src/Tracers/FeatureGateChecker.php`
- `src/Tracers/InertiaPageChecker.php`

**Out of scope** (do NOT touch):
- `src/Tracers/EagerLoadStringChecker.php` — divergent shape; forcing it into
  this extraction adds risk for no drift-reduction (its scan isn't
  line-range-scoped the same way).
- The checkers' public APIs and message strings — formatters and tests depend
  on the exact finding text.
- `src/Changes/ChangedSymbols.php` — the wiring is unchanged.

## Git workflow

- Branch: `advisor/022-checker-line-range-trait`
- Commit style: imperative subject, e.g. `Extract the shared line-range scoping into a checker trait`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Create the trait

Create `src/Tracers/Concerns/ChecksChangedLineRanges.php`:

```php
<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers\Concerns;

/**
 * The locality rule shared by the per-source checkers ({@see \SanderMuller\Richter\Tracers\FeatureGateChecker},
 * {@see \SanderMuller\Richter\Tracers\InertiaPageChecker}): a finding counts only when its call
 * starts inside one of the CHANGED members' [start, end] line spans, so an untouched sibling
 * method's call never reads as part of the change.
 */
trait ChecksChangedLineRanges
{
    /** @param  list<array{int, int}>  $ranges */
    private function withinRanges(int $line, array $ranges): bool
    {
        return array_any($ranges, static fn (array $range): bool => $line >= $range[0] && $line <= $range[1]);
    }
}
```

**Verify**: `composer phpstan` → exit 0 (the unused trait passes analysis).

### Step 2: Use it in both checkers

In `FeatureGateChecker` and `InertiaPageChecker`: add
`use SanderMuller\Richter\Tracers\Concerns\ChecksChangedLineRanges;` (import)
and `use ChecksChangedLineRanges;` (trait statement, first statement in the
class body per Pint conventions), delete the private `withinRanges()` method
from each. Do not touch anything else in either file.

**Verify**: `vendor/bin/phpunit --filter 'FeatureGateCheckerTest|InertiaPageCheckerTest'`
→ all pass unchanged.

### Step 3: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0; `vendor/bin/rector process --dry-run` → no
proposed changes in the touched files (if Rector proposes something unrelated
elsewhere, leave it alone and note it).

## Test plan

- No new tests: this is a behavior-preserving extraction locked by the two
  existing checker test files. If any assertion changes, the extraction went
  wrong.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -rn "private function withinRanges" src/Tracers/*.php` → no matches (only the trait defines it)
- [ ] Both checkers `use ChecksChangedLineRanges`
- [ ] `composer test` exits 0 with zero assertion changes in the two checker test files
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The two `withinRanges` bodies are no longer byte-identical (drift since
  planning — reconcile intent first, don't pick one silently).
- PHPStan strict rules object to a `private` trait method in a `readonly`
  class context — report rather than switching to `protected` (that would
  widen the API surface).
- You are tempted to also extract `findingsFor`'s traversal skeleton — that
  is a bigger design step (the collect/message halves differ meaningfully);
  explicitly deferred.

## Maintenance notes

- A fourth per-source checker should `use` this trait from day one; if its
  locality rule needs to differ, that difference now has exactly one place to
  be discussed.
- Deferred: unifying the full `findingsFor` skeleton (parse → find → filter →
  findings) behind an abstract; revisit when the family gains a member and
  the shape stabilizes.
