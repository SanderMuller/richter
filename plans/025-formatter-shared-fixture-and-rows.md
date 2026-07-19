# Plan 025: One report fixture across all three formatters, then de-duplicate the entry-point row building

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Analysis/ImpactFormatter.php src/Analysis/MarkdownFormatter.php src/Analysis/JsonPresenter.php tests/Unit/ImpactFormatterTest.php tests/Unit/MarkdownFormatterTest.php tests/Unit/JsonPresenterTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (refactors two user-facing output formats; the step-1 tests are the mitigation)
- **Depends on**: 024 (same file; land the small escaping fix first)
- **Category**: tech-debt + tests
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

Three surfaces render one `detectChanges` result: text (`ImpactFormatter`),
markdown (`MarkdownFormatter`), JSON (`JsonPresenter`). The text and markdown
formatters carry near-identical private machinery — `entryLabel()` is
byte-identical, `testReferenceSuffix()`, `locationSuffix()`, `pathChain()`,
`LIST_CAP = 15`, and the same build-items → usort-by-label → cap →
per-item-security-issues traversal — differing only in decoration (brackets
vs emoji-badges, `… and N more` vs `<details>`). Every annotation added in the
last releases (locations, security, gates) had to be threaded through both
copies; nothing but discipline keeps them aligned, and no test would catch a
field rendered in one format and forgotten in the other. This plan first locks
behavior with a single rich fixture asserted against all three formatters,
then extracts the shared row-building so the next annotation lane is added in
one place.

## Current state

- Byte-identical: `entryLabel()` — `src/Analysis/ImpactFormatter.php:204-207`
  vs `src/Analysis/MarkdownFormatter.php` (same body; locate with
  `grep -n "entryLabel" src/Analysis/*.php`):

  ```php
  private static function entryLabel(string $node): string
  {
      return str_starts_with($node, 'command::') ? explode(' ', $node, 2)[0] : $node;
  }
  ```

- Same-shape traversal — text (`ImpactFormatter.php:130-170`,
  `entryPointList()`):

  ```php
  $items = array_map(static fn (string $node): array => [
      'label' => self::entryLabel($node)
          . self::locationSuffix($locations[$node] ?? null)
          . self::testReferenceSuffix($tests, $node)
          . (isset($security[$node]) ? "  [{$security[$node]['exposure']}]" : '')
          . (isset($gates[$node]) ? '  [gated: ' . implode(', ', $gates[$node]) . ']' : ''),
      'node' => $node,
  ], $entryPoints);
  usort($items, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);
  ```

  and markdown (`MarkdownFormatter.php:139-166`, `entryPointChecklist()`):

  ```php
  $items = array_map(static fn (string $node): array => [
      'label' => '`' . self::entryLabel($node) . '`'
          . self::locationSuffix($locations[$node] ?? null)
          . self::testReferenceSuffix($tests, $node)
          . (isset($security[$node]) ? ' — ' . self::exposureBadge($security[$node]['exposure']) : '')
          . (isset($gates[$node]) ? ' — 🚩 ' . implode(', ', $gates[$node]) : ''),
      'node' => $node,
  ], $entryPoints);
  ```

  Both then iterate `security[...]['issues']` per shown item
  (`ImpactFormatter.php:155-160` vs `MarkdownFormatter.php:196-218`,
  `checklistEntries()`), and both hold `private const int LIST_CAP = 15;`.
- `locationSuffix` differs only in decoration:
  `'  (' . file:line . ')'` (text, `ImpactFormatter.php:173-180`) vs
  `' — `' . file:line . '`'` (markdown, `MarkdownFormatter.php:169-176`).
- `pathChain` differs only in backticks around hops
  (`ImpactFormatter.php:188-198` vs `MarkdownFormatter.php:226-236`).
- `JsonPresenter` maps the result to the documented JSON keys — different
  shape, correctly separate; it participates in the shared-fixture test only.
- Current tests build **divergent** fixtures per file:
  `tests/Unit/ImpactFormatterTest.php` (its `summary()` omits gates/security
  by default), `tests/Unit/MarkdownFormatterTest.php:19-37`,
  `tests/Unit/JsonPresenterTest.php:130-158` (`detectChangesResult()`).
- Formatter call contracts: read the public entry points of the three classes
  before starting (`format(...)`/`render(...)` signatures and the exact
  result-array shape they take — the array-shape PHPDoc sits on
  `ImpactAnalyzer::detectChanges`).
- Conventions: `final` classes, static helpers, PHPStan strict (array shapes
  must stay typed — see the `@phpstan-import-type SecurityShape` pattern in
  both formatter docblocks).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'ImpactFormatterTest|MarkdownFormatterTest|JsonPresenterTest|FormatterContractTest'` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `test-writing`; skill `backend-quality` for closing checks.

## Scope

**In scope** (files you may create/modify):
- `tests/Unit/FormatterContractTest.php` (create)
- `src/Analysis/EntryPointRow.php` (create — or an equivalently named internal value/builder class)
- `src/Analysis/ImpactFormatter.php`
- `src/Analysis/MarkdownFormatter.php`

**Out of scope** (do NOT touch):
- `src/Analysis/JsonPresenter.php` — different shape by design; it only joins
  the shared-fixture test.
- Any rendered output byte: this is behavior-preserving. Golden strings in
  existing tests must not change.
- `src/Analysis/ImpactAnalyzer.php` and the result-array shape.

## Git workflow

- Branch: `advisor/025-formatter-shared-fixture-and-rows`
- Two commits: `Assert all three formatters against one rich report fixture`,
  then `Extract shared entry-point row building from the formatters`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: The shared-fixture contract test

Create `tests/Unit/FormatterContractTest.php` with one rich fixture builder —
a representative `detectChanges`-shaped result exercising **every** renderable
field at once: ≥1 entry point with location + security (with one issue) +
gates + test-reference state, an explain path, related models, findings,
lowConfidence + coarseCapApplied true, one unresolved file, >LIST_CAP entry
points (to exercise the cap/collapse branch). Base its shape on
`tests/Unit/JsonPresenterTest.php`'s `detectChangesResult()` — extend, don't
diverge.

Tests, one per formatter: render the fixture and assert every populated field
leaves a trace in the output (entry-point label, file:line, exposure, gate
flag, issue line, chain arrow, related model, finding text, unresolved marker,
cap marker). Plus one JSON test asserting every documented key is present.
These are presence assertions, not golden files — they catch "field forgotten
in one format", not styling.

**Verify**: `vendor/bin/phpunit --filter FormatterContractTest` → all pass
(they should pass against current code; a failure here is itself a drift
finding — report it).

### Step 2: Extract the shared row builder

Create an internal class (suggested `src/Analysis/EntryPointRow.php`,
`@internal`, final) that owns the shared traversal: given the entry points +
locations + security + gates + tests index, produce sorted row values
exposing the *facts* per row (node, plain label, location, test-reference
tri-state, exposure, gates, issues, explain path) — decoration-free. Both
formatters then map rows to their own strings:

- text keeps `[exposure]`, `[gated: …]`, `  (file:line)`, `… and N more`;
- markdown keeps backticked labels, `exposureBadge()`, `— 🚩`, `<details>`.

Move `entryLabel()` into the row class (single copy). `LIST_CAP` and the
cap/collapse decision stay per-formatter (they present differently: slice+note
vs slice+details). Keep `pathChain`/`locationSuffix` per-formatter if pulling
them into rows would force decoration into the shared layer — the goal is one
copy of the *facts and ordering*, zero shared decoration.

**Verify** after the extraction: `vendor/bin/phpunit --filter 'ImpactFormatterTest|MarkdownFormatterTest|FormatterContractTest'`
→ all pass **without any expectation change** in the two pre-existing test
files.

### Step 3: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0
(the new class needs full array-shape typing); `vendor/bin/pint --test` →
exit 0.

## Test plan

- Step 1's contract tests (4: text, markdown, JSON, plus the cap branch if
  split out) — these are the deliverable as much as the refactor.
- Existing formatter tests: unchanged, green — the no-behavior-change proof.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `tests/Unit/FormatterContractTest.php` exists; all three formatters render one shared fixture
- [ ] `grep -c "entryLabel" src/Analysis/ImpactFormatter.php src/Analysis/MarkdownFormatter.php` → the method body exists in neither (single copy in the row class)
- [ ] Existing `ImpactFormatterTest`/`MarkdownFormatterTest` pass with zero expectation edits
- [ ] `composer test` exits 0; `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Step 1 exposes an actual drift (a field one formatter already forgot) —
  that's a bug finding to report before refactoring on top of it.
- Preserving byte-identical output requires the row class to expose
  decoration (badges, brackets) — the seam is then wrong; report the specific
  field that won't split.
- Existing golden assertions must change to keep tests green — behavior is
  not being preserved.

## Maintenance notes

- The next annotation lane (e.g. a future overlay) should extend
  `EntryPointRow` once and add only decoration per formatter — reviewers
  should reject annotation PRs that thread new fields through both formatters
  directly again.
- Plan 024's `pathCell` escaping lives in `MarkdownFormatter`; the row class
  must keep returning raw paths so escaping stays a markdown concern.
