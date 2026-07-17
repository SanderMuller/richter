# Plan 007: Replace the per-seed full-graph regex scan in nodesContaining with a token index

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
- **Risk**: LOW (semantics pinned by an existing test; the regex stays as the final filter)
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

`CodeGraph::nodesContaining()` builds a boundary regex and filters **every node key in the graph** on each call. `ImpactAnalyzer::detectChanges()` calls it once per changed member (`memberSeeds`), once per direct seed, and once per coarse seed — so a wide refactor diff against a large host graph pays O(changed-members × total-nodes) regex executions on top of the (now cached) build. A token index cuts each lookup to the nodes that actually contain the needle's identifier tokens, while keeping the existing regex as the final filter on that small candidate set — so the boundary semantics ("Video" matches `model::App\Models\Video` but never `VideoContainer` or `SuperVideo`) are preserved *exactly*, by construction.

## Current state

- `src/Graph/CodeGraph.php:72-84` — the scan, verbatim:

```php
public function nodesContaining(string $needle): array
{
    if ($needle === '') {
        return [];
    }

    $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($needle, '/') . '(?![A-Za-z0-9_])/i';

    return array_values(array_filter(
        array_keys($this->nodes),
        static fn (string $node): bool => preg_match($pattern, $node) === 1,
    ));
}
```

  Semantics to preserve exactly: case-insensitive; the needle must sit at identifier boundaries on both sides (boundary characters are anything outside `[A-Za-z0-9_]` — so `\`, `:`, `.`, `-`, start/end of string all qualify). Needles arrive as full FQCNs (`App\Models\Video`), bare class names or substrings (`UserPolicy`, from the CLI), member ids (`App\Models\Video::query` via `seedsFor`), and view node ids.
- `src/Graph/CodeGraph.php:12-34` — node storage: `$this->nodes` is `array<string, true>` filled in the constructor from edge endpoints. Nodes never change after construction (the class has no mutators; `readonly` semantics by convention).
- Callers (context, do not modify): `src/Analysis/ImpactAnalyzer.php:314-342` — `memberSeeds()` filters `candidateNodes($fqcn)` by `str_ends_with($node, '::' . $method)`; `seedsFor()` and `candidateNodes()` pass through to `nodesContaining()`. `impact()` (`:34`) makes one call per invocation; `detectChanges()` (`:62`) makes several per changed file.
- The behavioral pin: `tests/Unit/ImpactAnalyzerTest.php:456-474` — asserts `App\Models\Video` seeds only `Video` (not sibling `VideoContainer`) and that the needle `Video` matches neither `SuperVideo` nor `VideoContainer`. `tests/Unit/CodeGraphWalkTest.php` covers the walks but not `nodesContaining` directly.
- Repo conventions: `final` classes, docblocks explain constraints, PHPUnit 12 `#[Test]`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Graph + analyzer tests | `vendor/bin/phpunit --filter 'CodeGraphWalkTest|ImpactAnalyzerTest'` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Graph/CodeGraph.php`
- `tests/Unit/CodeGraphWalkTest.php` (new direct tests for `nodesContaining`)

**Out of scope** (do NOT touch, even though they look related):

- `src/Analysis/ImpactAnalyzer.php` — its call pattern is fine once each call is cheap.
- `GraphCache` / serialization (`toArray`/`fromArray`) — the index is derived state and must NOT be serialized; it rebuilds from nodes.
- Changing the matching semantics in any way (e.g. "smarter" FQCN-aware matching) — semantics-preserving only.

## Git workflow

- Branch: `advisor/007-node-lookup-index` off `main`.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Pin current behavior with direct tests

Add tests to `tests/Unit/CodeGraphWalkTest.php` (same construction style as its existing tests: `new CodeGraph([...edges...])`) covering, at minimum:

1. Boundary semantics: graph with nodes `model::App\Models\Video`, `App\Models\VideoContainer`, `App\Models\SuperVideo` — `nodesContaining('Video')` returns only the first; `nodesContaining('App\Models\Video')` returns only the first.
2. Case-insensitivity: `nodesContaining('video')` also returns `model::App\Models\Video`.
3. Member needles: node `App\Models\Video::query` — `nodesContaining('Video::query')` matches it; `nodesContaining('query')` also matches it (boundary `:` on the left).
4. Empty needle: returns `[]`.
5. A needle consisting only of non-identifier characters (e.g. `::`): assert whatever the current code returns (run it to find out — expected: every node containing `::` at boundaries; pin the actual result).

**Verify**: `vendor/bin/phpunit --filter CodeGraphWalkTest` → all pass against the *unmodified* code. If any expectation surprises, adjust the test to pin the actual current behavior — this step documents, not changes.

### Step 2: Build the token index and route lookups through it

In `CodeGraph`:

1. Add a lazily-built index property: `array<string, list<string>> $nodesByToken` — token → node keys. Tokens are the maximal runs of `[A-Za-z0-9_]+` in a node key, lowercased: `preg_split('/[^A-Za-z0-9_]+/', strtolower($node), -1, PREG_SPLIT_NO_EMPTY)`, deduplicated per node. Build it on first `nodesContaining()` call (`??=`-style lazy init in a private method), NOT in the constructor — `callersOf`/`dependenciesOf`-only usage (the walks) must not pay for it.
2. In `nodesContaining()`: tokenize the needle the same way. If the needle yields **no** tokens, fall back to the existing full scan (rare, degenerate needles — keeps behavior identical). Otherwise, pick the needle token with the *shortest* posting list, take those nodes as candidates, and run the **existing regex** over just the candidates. Multi-token needles (`App\Models\Video` → `app`, `models`, `video`) need candidates containing *all* tokens, but intersecting lists is unnecessary — filtering the shortest single posting list through the regex is already correct, because the regex enforces the full needle match.
3. Keep the method's public signature, return shape (`array_values`-style list), and the docblock's example intact; extend the docblock with one sentence on the index (constraint: the index is an over-approximation; the regex is the source of truth).

**Verify**: `vendor/bin/phpunit --filter 'CodeGraphWalkTest|ImpactAnalyzerTest'` → all pass, zero assertions modified.

### Step 3: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 4: Update the index file

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "007" plans/README.md` → row shows DONE.

## Test plan

- New direct `nodesContaining` tests (step 1) in `tests/Unit/CodeGraphWalkTest.php`: boundary semantics, case-insensitivity, member needles, empty needle, token-less needle fallback.
- The existing `ImpactAnalyzerTest` boundary assertions (lines 456-474) are the integration pin.
- Verification: `composer test` → green with zero modified assertions.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter CodeGraphWalkTest` exits 0 and includes ≥5 new `nodesContaining` tests
- [ ] `composer test` exits 0, 0 failures, zero existing assertions modified
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in the two in-scope files plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `nodesContaining` excerpt no longer matches the live code.
- Any step-1 pin test or existing assertion fails after step 2 — the index diverged from the regex; report the failing needle/node pair rather than special-casing it.
- The tokenizer and the regex boundary definition turn out to disagree on some character class (they must both treat exactly `[A-Za-z0-9_]` as identifier characters — if a discrepancy is found, report it).
- Memory pressure: if building the index on a synthetic large graph (only if you have reason to test one) changes any existing test's behavior.

## Maintenance notes

- The index is derived, in-memory-only state. If `CodeGraph` ever gains mutators or the cache starts serializing derived state, the lazy init must be revisited.
- Reviewer scrutiny: the no-token fallback path (step 2.2) is the correctness escape hatch — confirm it routes to the *original* full scan, not to an empty result.
- Future work this interacts with: if seeds ever become exact-id lookups (a stricter `seedsFor`), the index could shrink to an exact map — but that changes semantics and belongs to a separate, deliberate finding.
