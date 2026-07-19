# Plan 033: Tag test-referenced entry points whose referencing tests prove nothing

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 1141a9b..HEAD -- src/Analysis tests/Unit/TestReferenceIndexTest.php tests/Unit/FormatterContractTest.php src/Mcp README.md`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (annotation-only; certainty-gated; never feeds risk/gates/determinability)
- **Depends on**: none
- **Category**: direction (consumer handoff, GO'd in `internal/research-hihaho-tier3-handoff.md`)
- **Planned at**: commit `1141a9b`, 2026-07-20

## Why this matters

`[test-referenced]` means *named* by a test, not *proven* by one: a test that hits
`GET /videos` and asserts only `assertOk()` counts the same as one asserting the
response body. A downstream review agent must therefore read every referenced test to
judge assertion quality. This plan grades the signal with one conservative sub-tag —
`[test-referenced — no behavioural assertion found]` — emitted **only when the scan is
certain** every referencing test file contains nothing but provably-shallow
assertions. The failure to prevent is a false "proves nothing" (it would make a
reviewer distrust a test that actually asserts behaviour), so **all uncertainty
collapses to today's plain tag, never to the sub-tag**. The sub-tag mechanizes the
"executes but doesn't prove" smell; it never claims coverage.

**Iron rule (same as security/gates/frontend annotations):** this is annotation only.
It must not feed `risk`, `--fail-on` gates, or `richter:affected-tests`
selection/determinability.

## Current state

- `src/Analysis/TestReferenceIndex.php` — the reference index. Facts that matter:
  - `fromTests()` reads each test file's **source** and feeds it to
    `addSource($source, $path)` — the source string is available at index time, so
    per-file grading costs zero extra I/O.
  - `addSource()` (`:69-92`) runs the reference regexes (route names, URIs, artisan
    names, class references) and `record()`s file paths per key.
  - `record()` (`:291-300`) buckets are `array<string, list<string>>` (key → files).
  - `resolve()` returns `['referenced' => bool, 'tests' => list<string>]`;
    `hasReference()` / `testsReferencing()` read it. A source added with a null file
    counts for the boolean but contributes no path (docblock `:66-68`).
- `src/Analysis/EntryPointRow.php` — owns the per-entry-point facts both formatters
  decorate. Constructor fields include `?bool $testReferenced` (the
  `hasReference()` tri-state); `build(...)` fills it via
  `testReferenced: $tests?->hasReference($node)`.
- `src/Analysis/ImpactFormatter.php` — text decoration:

  ```php
  /** "Referenced" is deliberately weak phrasing: the index matches references, it does not prove coverage. */
  private static function testReferenceSuffix(?bool $testReferenced): string
  {
      return match ($testReferenced) {
          true => '  [test-referenced]',
          false => '  [⚠ no test references this]',
          default => '',
      };
  }
  ```

  (Signature may differ slightly post-refactor — locate `testReferenceSuffix` in the
  file; it renders off the row's `testReferenced` field.)
- `src/Analysis/MarkdownFormatter.php` — markdown decoration renders
  `— ✅ test-referenced` / `— ⚠️ no test references this` on the checklist row
  (locate the equivalent suffix helper).
- `src/Analysis/JsonPresenter.php:31-54` — `detectChanges(array $result, string $base)`
  emits the documented keys; **no test-reference key exists in JSON today**, and the
  presenter does not receive the tests index. `emptyDetectChanges()` mirrors the
  shape for the no-diff fast path.
- Call sites that build/pass the index:
  - `src/Console/DetectChangesCommand.php:107` — `TestReferenceIndex::fromTests(base_path('tests'))`,
    passed to `ImpactFormatter`/`MarkdownFormatter`; the `--json` branch calls
    `JsonPresenter::detectChanges($result, $base)` (no index).
  - `src/Mcp/Tools/DetectChangesTool.php:57` — prose via `ImpactFormatter` with the
    index; structured content mirrors the `--json` shape and declares an
    `outputSchema` (map-shaped fields are plain `$schema->object()` with a
    described caveat — `anyOf()` is unavailable on the Laravel 12 floor; follow the
    existing field style exactly).
- `tests/Unit/TestReferenceIndexTest.php` — source-feeding test style
  (`$index->addSource('<?php …', 'tests/Feature/XTest.php')`).
- `tests/Unit/FormatterContractTest.php` — one rich fixture through all three
  surfaces; `richTestIndex()` builds the index used for the test-referenced tag.
- README anchors: the JSON key table (`## Configuration` precedes it; the table sits
  in the detect-changes section, one row per key), and the entry-point tag
  explanation ("Turn reach into a test-coverage prompt" bullet + the tag phrasing
  near the report example).
- Conventions: `declare(strict_types=1)` on the `<?php` line, `final` classes,
  heavy "why" docblocks, `#[Test]` + snake_case tests, PHPStan max/strict (typed
  array shapes everywhere).

## Design (decided — do not re-litigate during execution)

**Per-file grading, not per-method.** A test file is **assertion-weak** only when the
scan is certain: every assert-ish call in the whole file is in the provably-shallow
set (or the file contains no assert-ish call at all). One behavioural or *unknown*
assert-ish call anywhere in the file disqualifies it. This is strictly more
conservative than method-level attribution (a behavioural assertion in an unrelated
test method suppresses the sub-tag — fine: under-emitting is the safe direction) and
needs no offset/AST machinery. Method-level precision is an explicitly deferred
follow-up if the sub-tag under-fires in practice.

**The grade, computed per file at `addSource()` time from the same source string:**

1. Collect assert-ish call names: regex over the source for
   `/(?:->|::)\s*((?:assert|expect)[A-Z_]\w*)\s*\(/` **plus** Pest-style bare
   `/(?<![\w$>])expect\s*\(/`.
2. The file is **weak** when every collected name is in the SHALLOW set and no bare
   `expect(` matched:
   - `assertOk`, `assertSuccessful`, `assertNoContent`, `assertNotFound`,
     `assertForbidden`, `assertUnauthorized`, `assertStatus`
   - `assertTrue`/`assertFalse` **only** when the full call matches a literal
     argument (`assertTrue\(\s*(?:true|false)\s*\)`) — a non-literal argument is
     uncertain → not weak.
   - zero assert-ish calls at all → weak (references it, proves nothing).
3. Everything else — `assertJson*`, `assertDatabaseHas`, `assertRedirect`,
   `assertDispatched`, `assertSee`, custom helpers (`assertVideoPublished`),
   `expectException`, Pest `expect(...)` — is behavioural-or-unknown → **not weak**.
   No allowlist of behavioural names is needed; anything not provably shallow
   disqualifies.

**The sub-tag per entry point:** emitted only when `hasReference($node)` is true,
`testsReferencing($node)` is **non-empty**, and **every** referenced file graded
weak. A referenced-but-fileless source (null file) cannot be graded → no sub-tag.

**Surfaces (all annotation):**

- text: `  [test-referenced — no behavioural assertion found]` replaces
  `  [test-referenced]` when weak.
- markdown: `— 🟡 test-referenced, no behavioural assertion found` replaces
  `— ✅ test-referenced` when weak.
- JSON + MCP structured content: one additive key `entryPointTestReferences` — a map
  of entry-point node → `'referenced' | 'referenced-no-behavioural-assertion' |
  'unreferenced'`; nodes whose tri-state is null (uncheckable) are omitted from the
  map. This is the FIRST test-reference key in JSON, so `JsonPresenter::detectChanges`
  gains an optional `?TestReferenceIndex $tests = null` parameter (additive), the
  command's `--json` branch and the MCP tool pass their existing index, and
  `emptyDetectChanges()` adds the empty map. The MCP `outputSchema` gains the field
  in the same plain-object style as the sibling maps.

**Wording rule:** always "no behavioural assertion **found**" — the scan is a
heuristic; never "not covered" / "untested".

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'TestReferenceIndexTest|FormatterContractTest|ImpactFormatterTest|MarkdownFormatterTest|JsonPresenterTest|McpTest'` | OK |
| Full suite | `composer test` | `"result":"passed"` (562 at planning time + new) |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |
| Rector (check) | `vendor/bin/rector process --dry-run` | 0 changed files |

## Suggested executor toolkit

- Skill `test-writing`; skill `backend-quality` for the closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Analysis/TestReferenceIndex.php`
- `src/Analysis/EntryPointRow.php`
- `src/Analysis/ImpactFormatter.php`, `src/Analysis/MarkdownFormatter.php`
- `src/Analysis/JsonPresenter.php`
- `src/Console/DetectChangesCommand.php` (pass the index to the JSON branch only)
- `src/Mcp/Tools/DetectChangesTool.php` (structured content + outputSchema field)
- `README.md` (JSON table row + the tag-phrasing sentences)
- `tests/Unit/TestReferenceIndexTest.php`, `tests/Unit/FormatterContractTest.php`,
  `tests/Unit/ImpactFormatterTest.php`, `tests/Unit/MarkdownFormatterTest.php`,
  `tests/Unit/JsonPresenterTest.php`, `tests/Feature/McpTest.php`

**Out of scope** (do NOT touch, even though they look related):
- `src/Analysis/AffectedTests.php` / `AffectedTestsCommand` — selection and
  determinability are untouched by design.
- `src/Analysis/ImpactAnalyzer.php` — the risk pipeline never sees this signal.
- `src/Mcp/Tools/ImpactTool.php` — the impact tool carries no test tags today.
- Any change to `hasReference()` / `testsReferencing()` return types — public
  contract, `affected-tests` depends on them.

## Git workflow

- Branch: `advisor/033-assertion-weak-test-tag` — create it FROM the local main tip
  (`git checkout -b advisor/033-assertion-weak-test-tag main`).
- Commit per logical unit (grader+index, row+formatters, JSON+MCP, README),
  imperative subjects. No signing configured. End messages with:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- Do NOT push or open a PR.

## Steps

### Step 1: The per-file grade in TestReferenceIndex (test-first)

Failing tests first in `tests/Unit/TestReferenceIndexTest.php` against a new public
method `referencedWithoutBehaviouralAssertion(string $entryPointNode): bool`:

1. weak: a file whose only assertion is `$response->assertOk();` referencing a route
   → true for that route node.
2. weak: a file with **zero** assertions referencing a class → true.
3. not weak: same shape plus one `assertDatabaseHas` anywhere in the file → false.
4. not weak: a custom helper `$this->assertVideoPublished($video);` → false
   (unknown assert-ish name).
5. not weak: `assertTrue($video->published)` (non-literal) → false;
   `assertTrue(true)` alone → true.
6. not weak: Pest-style `expect($video->status)->toBe('published');` → false.
7. multi-file: entry point referenced by one weak and one non-weak file → false
   (ALL files must grade weak).
8. fileless: `addSource($src)` with null file, nothing else → false (boolean
   reference without a gradable file).

Then implement: a private static pure grader
`sourceLacksBehaviouralAssertions(string $source): bool` per the Design section, a
`array<string, bool>` per-file grade map filled in `addSource()` (grade once per
file; a file fed twice keeps `&&` of grades — simplest: only grade when the file is
first seen), and the public method combining `resolve()`'s tests list with the map.
Document the certainty direction in the method docblock ("uncertainty collapses to
plain referenced, never to the sub-tag").

**Verify**: `vendor/bin/phpunit --filter TestReferenceIndexTest` → all pass.

### Step 2: Row + text/markdown decoration

- `EntryPointRow`: add `public bool $assertionWeak` (constructor + `build()`:
  `assertionWeak: $tests?->referencedWithoutBehaviouralAssertion($node) ?? false`).
- `ImpactFormatter`: when `testReferenced === true && assertionWeak`, render
  `  [test-referenced — no behavioural assertion found]`; existing branches
  unchanged.
- `MarkdownFormatter`: same condition → `— 🟡 test-referenced, no behavioural
  assertion found`.
- Tests: one case per formatter file (weak → new wording; non-weak regression
  pinned), plus extend `FormatterContractTest`'s rich fixture: give ONE entry point
  a weak reference (its `richTestIndex()` gains a second, shallow-only source) and
  assert the wording surfaces in both text and markdown.

**Verify**: `vendor/bin/phpunit --filter 'ImpactFormatterTest|MarkdownFormatterTest|FormatterContractTest'` → all pass.

### Step 3: JSON + MCP structured content

- `JsonPresenter::detectChanges(array $result, string $base, ?TestReferenceIndex $tests = null)`:
  build `entryPointTestReferences` from `$result['entryPoints']` — per node, map the
  tri-state: null → omit; false → `'unreferenced'`; true →
  `'referenced-no-behavioural-assertion'` when weak else `'referenced'`. Add the
  empty map to `emptyDetectChanges()`. Update the array-shape PHPDoc.
- `DetectChangesCommand` `--json` branch: pass the `$tests` index it already built.
- `DetectChangesTool`: pass the index into the structured-content path and add the
  `entryPointTestReferences` field to the `outputSchema`, copying the sibling
  map-field style verbatim (plain object + description caveat).
- Tests: `JsonPresenterTest` (map content for referenced/weak/unreferenced/omitted),
  `FormatterContractTest` JSON test (key present, weak node carries the sub-state),
  `McpTest` (schema field present — mirror how sibling fields are asserted).

**Verify**: `vendor/bin/phpunit --filter 'JsonPresenterTest|McpTest|FormatterContractTest'` → all pass.

### Step 4: README

- JSON table: one row — `entryPointTestReferences` | object | per reached entry
  point, `referenced` / `referenced-no-behavioural-assertion` / `unreferenced` —
  advisory annotation, never an input to `risk`, the gate, or `affected-tests`.
- The tag-phrasing sentence ("Every reached entry point is tagged…"): add one clause
  introducing the sub-tag with the honesty wording ("…and a referenced entry point
  whose referencing tests contain no behavioural assertion the scan recognises is
  tagged `[test-referenced — no behavioural assertion found]` — a heuristic
  prompt, not a coverage verdict").

**Verify**: `grep -n 'entryPointTestReferences' README.md` → table row + nothing stale.

### Step 5: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` → exit 0;
`vendor/bin/pint --test` → exit 0; `vendor/bin/rector process --dry-run` → clean.

## Test plan

Steps 1–3 enumerate the cases; model after the existing files' style. The
FormatterContractTest extension is the cross-surface drift guard (all three surfaces
render the new signal from one fixture).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] ≥8 new grader/index tests (step 1 list), all passing
- [ ] All three surfaces render the sub-tag from the shared contract fixture
- [ ] `entryPointTestReferences` in JSON output, `emptyDetectChanges()`, and the MCP `outputSchema`
- [ ] `grep -rn "assertionWeak\|referencedWithoutBehaviouralAssertion" src/Analysis/AffectedTests.php src/Analysis/ImpactAnalyzer.php` → no matches (iron rule)
- [ ] `composer test` exits 0; `composer phpstan` exits 0; `vendor/bin/pint --test` exits 0
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated (unless reviewer maintains the index)

## STOP conditions

Stop and report back (do not improvise) if:

- The "Current state" excerpts don't match the live code (drift).
- `JsonPresenter`'s signature change breaks a caller outside the in-scope list —
  the call-site inventory above would be incomplete; report the extra caller.
- The MCP outputSchema addition fails on the Laravel 12 floor cells' schema builder
  (an API the sibling fields don't use) — copy the sibling style only; if that is
  impossible, report rather than invent.
- You find yourself wanting the sub-tag to influence `affected-tests` output or the
  risk level — explicitly forbidden.

## Maintenance notes

- The SHALLOW set is deliberately tiny; growing it (e.g. `assertRedirect()` with no
  argument) requires argument inspection — do it only with a test proving the
  no-argument form is distinguished from `assertRedirect('/target')`.
- Method-level attribution (grading only the referencing test method instead of the
  whole file) is the deferred precision upgrade if consumers report the sub-tag
  under-fires; the certainty rule transfers unchanged.
- The hihaho auditor's judgment history is the natural validation set for catalogue
  tuning — revisit the SHALLOW set against it before any expansion.
