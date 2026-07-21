# Plan 043: HTML report data layer — depth-tagged edge lists and the reach map

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. Do **NOT** edit `plans/README.md`: the
> dispatcher maintains the plan index for this series.
>
> **Drift check (run first)**: `git diff --stat 2d8a437..HEAD -- src/Graph/CodeGraph.php src/Analysis/ImpactAnalyzer.php src/Analysis/JsonPresenter.php src/Mcp/Tools/DetectChangesTool.php`
> Expected: empty. This plan is planned directly on `2d8a437` and depends on
> no other plan. Any change to `CodeGraph::bfs()`/`walk()` or to the
> `detectChanges()` return block is unexplained drift — reconcile before
> starting, and treat a changed `bfs()` callback signature as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: LOW (purely additive — two new methods, three new internal result keys; no existing method changes behaviour)
- **Depends on**: nothing
- **Blocks**: 044 (`044-html-formatter-and-svg.md`) — the renderer consumes exactly what this plan produces
- **Category**: feature (data layer for the HTML report)
- **Planned at**: commit `2d8a437`, 2026-07-20

## Why this matters

`plans/research-gui-2026-07-20.md` settles the delivery shape: a
self-contained HTML report written by `richter:detect-changes --html=report.html`,
with the graph payload as an **HTML-only side channel** (research §5 decisions
1–3). `JsonPresenter` and the MCP `outputSchema()` stay untouched — no new
semver-pinned public field. This plan lands the *data* that side channel
carries; plan 044 lands the formatter and the SVG.

Two things are missing today, and both live one line away from code that
already computes them:

1. **No edge list.** `CodeGraph::bfs()` already hands the source node to its
   callback as the 4th argument. `walk()` — the shared body behind
   `callersOf`/`dependenciesOf` — declares only three parameters and drops it,
   so the walks emit `{depth, node, via}` with no `source`. A node-link diagram
   needs the edges.
2. **The `reach` map is computed and thrown away.** `ImpactAnalyzer` builds it
   at `:170`, collapses it to `impacted` and `relatedModels` at `:171-175`, and
   never returns it.

Why (2) is not optional: `impacted` deliberately **excludes** nodes reachable
only via the `RISK_EXCLUDED_EDGE_TYPES` (`ImpactAnalyzer.php:40`,
`isRiskBearing()` at `:557`). If the diagram derived its node set from the
`callers` walk alone, the number of drawn nodes would visibly disagree with the
"Impacted" stat tile. Classifying each drawn node against the reach map is what
makes the picture and the number agree: association-only nodes render in the
grey "Outside impact" ring and are not counted. That resolves the design trap
named in research §1 rather than documenting a divergence.

## Current state

- `src/Graph/CodeGraph.php:334-346` — the shared walk body, and the exact line
  that loses the edge:

  ```php
  private function walk(array $adjacency, array $from, int $maxDepth): array
  {
      $result = [];

      $this->bfs($adjacency, $from, $maxDepth, static function (array $hop, int $depth, bool $firstVisit) use (&$result): void {
          if ($firstVisit) {
              $result[] = ['depth' => $depth, 'node' => $hop['node'], 'via' => $hop['via']];
          }
      });

      // BFS already appends in non-decreasing depth order, so no sort is needed.
      return $result;
  }
  ```

- `src/Graph/CodeGraph.php:359-390` — `bfs()`, the primitive. Its callback
  contract is `callable(array{node: string, via: string}, int, bool, string): void`
  and it already passes `$current['node']` as the 4th argument at `:380`.
  It maintains a per-call `$seen` set, seeded with `$from` at depth 0, and
  terminates on cycles because a re-encountered node is never re-queued.
  **Do not modify this method.**
- `src/Graph/CodeGraph.php` — the two public walkers to mirror:

  ```php
  public function callersOf(array $from, int $maxDepth = 6): array
  {
      return $this->walk($this->upstream, $from, $maxDepth);
  }

  public function dependenciesOf(array $from, int $maxDepth = 6): array
  {
      return $this->walk($this->downstream, $from, $maxDepth);
  }
  ```

- `src/Graph/CodeGraph.php` — `reachedViaTypes()`, which runs `bfs()`
  over **both** adjacencies with **independent `$seen` sets** (one `bfs()` call
  per direction inside the `foreach`), records on every encounter, and unsets
  the seeds at the end.
- `src/Analysis/ImpactAnalyzer.php:153-154, 170-175` — the seed walks and the
  reach collapse:

  ```php
  $callers = $this->graph->callersOf($seeds, $maxDepth);
  $dependencies = $this->graph->dependenciesOf($seeds, $maxDepth);
  ...
  $reach = $this->graph->reachedViaTypes($seeds, $maxDepth);
  $impacted = count(array_filter($reach, $this->isRiskBearing(...)));
  $relatedModels = array_keys(array_filter(
      $reach,
      fn (array $types): bool => ! $this->isRiskBearing($types) && isset($types['model-relationship']),
  ));
  ```

  `$reach` goes out of scope at the end of the method. `$seeds` is computed
  just above the walks.
- `src/Analysis/ImpactAnalyzer.php` — the `@return array{…}` shape for
  `detectChanges()`, and `:189-205` — the return block, which already carries
  the **internal-only** `callers` and `dependencies` keys (consumed by
  `ImpactFormatter`/`MarkdownFormatter`, never by `JsonPresenter`).
- `src/Analysis/JsonPresenter.php:28` — the `@param` shape ends in `, ...}` and
  the class docblock states detect-changes "deliberately omits the raw
  caller/dependency walk internals". `detectChanges()` builds its return array
  key by key; it never spreads `$result`. **This is why new internal keys are
  invisible to `--json` — confirm by reading, and pin by test.**
- Test homes: the existing `CodeGraph` walk tests (constructed as
  `new CodeGraph([...])` from literal edge arrays — the pattern to follow),
  the `ImpactAnalyzer` tests, and the `JsonPresenter` tests. Locate the exact
  filenames with `ls tests/Unit` before writing.
- Conventions: `declare(strict_types=1)` on one line with `<?php`, `final`
  classes, "why" docblocks (not "what"), `#[Test]` attributes with
  `snake_case` method names, PHPStan max + larastan + strict-rules +
  type-perfect + cognitive-complexity.

## Design (decided)

### 1. `walkEdges()` + two public methods on `CodeGraph`

Private helper placed **immediately after `walk()`**, matching its style:

```php
/**
 * @param  array<string, list<array{node: string, via: string}>>  $adjacency
 * @param  list<string>  $from
 * @return list<array{source: string, target: string, via: string, depth: int}>
 */
private function walkEdges(array $adjacency, array $from, int $maxDepth): array
```

Body is `walk()`'s, with the 4th callback parameter kept and the first-visit
guard retained. Public wrappers next to `callersOf`/`dependenciesOf`:

- `callerEdgesOf(array $from, int $maxDepth = 6): list<array{source: string, target: string, via: string, depth: int}>` → `walkEdges($this->upstream, …)`
- `dependencyEdgesOf(array $from, int $maxDepth = 6): list<array{source: string, target: string, via: string, depth: int}>` → `walkEdges($this->downstream, …)`

Semantics to write into the docblocks (all deliberate, all test-pinned):

- **`$firstVisit` guard kept ⇒ a BFS tree.** Each *reached* node is emitted
  exactly once. **Correction (found during execution):** the reached node is
  the end the walk stepped *to*, which because of the orientation rule below is
  `source` for `callerEdgesOf` and `target` for `dependencyEdgesOf` — *not*
  `target` in both, as an earlier draft of this plan said. Asserting uniqueness
  on `target` for the caller walk is wrong: a diamond (`A→C`, `B→C`) legitimately
  emits two edges both targeting `C`. That is the clean radial shape the report
  draws; the true induced subgraph with cross-edges is explicitly *not* wanted.
- **`source`/`target` are graph-edge direction, not walk direction.** For the
  upstream walk the traversal steps from a node to its caller, so the emitted
  edge is `{source: caller, target: callee}` — i.e. `source` is
  `$hop['node']` and `target` is `$fromNode` for `callerEdgesOf`, and the
  reverse for `dependencyEdgesOf`. **Pin this orientation with a test before
  writing the implementation**, and state the chosen convention verbatim in
  both docblocks — plan 044 draws arrows from it.
- **The two walks have independent `$seen` sets.** A node reachable both
  upstream and downstream appears in **both** lists, possibly at **different
  depths**. This is correct and mirrors `reachedViaTypes()`'s two-`bfs()`
  structure. The consumer merges by taking the **minimum** depth per node.
  Document this in both docblocks and in `walkEdges()`'s.
- `$from === []` yields `[]` (the `bfs()` queue is empty). Seeds are at depth 0
  and are never a *reached* node; the shallowest emitted edge has `depth` 1.
  **Correction (found during execution):** a seed is still an edge *target* in
  the caller direction — its caller points at it — so "seeds are never a target"
  (an earlier draft's wording) is false for `callerEdgesOf`.
- `maxDepth` bounds `depth` inclusively — `bfs()` refuses to expand a node
  already at `>= $maxDepth`, so `max(depth) <= $maxDepth`.

No change to `bfs()`, `walk()`, `callersOf`, `dependenciesOf`, the builder or
the cache.

### 2. Three additive keys on the analyzer result

In `ImpactAnalyzer::detectChanges()`, keep `$reach` alive past `:175` and add
to the return block at `:189-205`:

- `'seeds' => $seeds` — `list<string>`; the directly-changed ring (depth 0).
  The renderer cannot recover this from the edge lists alone.
- `'reach' => $reach` — `array<string, array<string, true>>`, exactly as
  `reachedViaTypes()` returns it (seeds already unset). The renderer classifies
  each node risk-bearing vs. association-only from this.
- `'edges' => [...$callerEdges, ...$dependencyEdges]` — the merged list, from
  two new calls placed next to the existing walks at `:153-154`:

  ```php
  $callerEdges = $this->graph->callerEdgesOf($seeds, $maxDepth);
  $dependencyEdges = $this->graph->dependencyEdgesOf($seeds, $maxDepth);
  ```

  Merged, **not** deduplicated here — the two lists differ in orientation but
  may name the same node at different depths; min-depth collapsing is the
  renderer's job (plan 044) because it is a presentation decision. Say so in
  the docblock.

Update the `@return array{…}` shape with the three keys.

These sit alongside `callers`/`dependencies` as **internal analyzer-result
keys**. `JsonPresenter::detectChanges()` must keep ignoring them via its `, ...}`
shape — enforced by a regression test, not by hope.

**Cognitive complexity**: `detectChanges()` is already long and the ruleset is
strict. The additions are three assignments and three array entries with no new
branching, so complexity should not move. If PHPStan's cognitive-complexity
rule trips anyway, that is a STOP condition — do not refactor the method to
satisfy it inside this plan.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused (graph) | `vendor/bin/phpunit --filter CodeGraph` | OK |
| Focused (analyzer + presenter) | `vendor/bin/phpunit --filter 'ImpactAnalyzer\|JsonPresenter'` | OK |
| Full suite | `composer test` | 0 failures |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |
| Rector (check) | `vendor/bin/rector process --dry-run` | 0 changed files |
| Full gate | `composer qa-check` | exit 0 |

## Scope

**In scope** (the only files you should modify):
- `src/Graph/CodeGraph.php`
- `src/Analysis/ImpactAnalyzer.php`
- the `CodeGraph` walk test file
- the `ImpactAnalyzer` test file
- the `JsonPresenter` test file

**Out of scope** (do NOT touch — each is a deliberate decision, not an oversight):
- `src/Analysis/JsonPresenter.php` and `src/Mcp/Tools/DetectChangesTool.php`
  (`outputSchema()`). Research §5 decision 2: the graph payload is an HTML-only
  side channel; there is no new public JSON field.
- `src/Analysis/ImpactFormatter.php`, `src/Analysis/MarkdownFormatter.php`,
  `src/Console/**` — no consumer changes in this plan.
- **Member-level change data.** `src/Changes/ChangedFileSymbols.php` /
  `MemberChange.php` are already passed into the command; plan 044's formatter
  takes the `ChangedSymbols` list **directly**, not routed through the analyzer
  result. Do not add a `members` key here.
- **Node caps.** A rendering concern (research §5 decision 4) — plan 044.
- `CodeGraph::bfs()`, `walk()`, `callersOf()`, `dependenciesOf()`,
  `reachedViaTypes()` — read them, change none of them.
- The graph cache format / `FORMAT_VERSION` — nothing persisted changes.

## Git workflow

- Branch: `advisor/043-html-report-data-layer`, created FROM the local main tip.
- Commits per logical unit, imperative subjects, end with:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`
- Do NOT push or open a PR.

## Steps

### Step 1: Edge-list tests first

Write these failing tests before touching `CodeGraph`. Use the literal-edge
`new CodeGraph([...])` pattern already in the file.

1. `caller_edges_carry_the_source_node_the_walk_traversed_from` — a two-hop
   chain `route → C::index → S::run`; `callerEdgesOf(['App\Services\S::run'])`
   returns edges naming both hops with the correct `via` types and
   `depth` 1 and 2. **Assert the full associative array with `assertSame`**, so
   the `source`/`target` orientation is pinned exactly.
2. `dependency_edges_walk_the_downstream_direction` — the mirror, from the
   route seed downward.
3. `edge_walks_emit_each_reached_node_exactly_once` — a diamond
   (`A→C`, `B→C`, `Root→A`, `Root→B`): assert uniqueness on `source` for
   `callerEdgesOf` and on `target` for `dependencyEdgesOf`. (Renamed from
   `caller_edges_form_a_bfs_tree_with_one_source_per_target` during execution —
   see the correction above; the original name asserted a false invariant.)
4. `caller_edges_terminate_on_a_cycle` — `A→B→C→A`; the call returns (no
   timeout) and each node appears at most once as a target.
5. `edge_walks_respect_max_depth` — a 5-long chain with `maxDepth: 2` yields
   exactly the depth-1 and depth-2 edges and nothing deeper.
6. `edge_walks_return_nothing_for_no_seeds` — `callerEdgesOf([])` and
   `dependencyEdgesOf([])` both `=== []`.
7. `a_node_reachable_both_ways_appears_in_both_edge_lists` — the documented
   subtlety. Build a graph where node `X` is both an ancestor and a descendant
   of the seed at **different distances** (e.g. seed `S`: `X→S` at upstream
   depth 1, and `S→M→X` at downstream depth 2). Assert `X` is a target in
   `callerEdgesOf(['S'])` at depth 1 and in `dependencyEdgesOf(['S'])` at
   depth 2, and add an inline comment that the consumer takes the minimum.

**Verify**: `vendor/bin/phpunit --filter CodeGraph` → the seven new
tests fail (undefined method), existing tests pass.

### Step 2: Implement `walkEdges()` + the two public methods

Add `walkEdges()` after `walk()`, and `callerEdgesOf`/`dependencyEdgesOf` after
`dependenciesOf()`. Docblocks must state: BFS tree (one source per target), the
`source`/`target` orientation convention, the independent-`$seen` /
duplicate-node / min-depth rule, and that `bfs()` is unchanged.

**Verify**: `vendor/bin/phpunit --filter CodeGraph` → all pass;
`composer phpstan` → exit 0 (type-perfect will reject a loose `array` return —
the `list<array{...}>` shape must be exact).

### Step 3: Surface `seeds`, `reach` and `edges` from the analyzer

Test-first in the `ImpactAnalyzer` test file — follow its existing
`detectChanges()` fixture style:

- `detect_changes_returns_the_seed_set_and_the_reach_map` — assert `seeds`
  contains the changed symbol's node and `reach` keys the reached nodes with
  their edge-type sets (and does **not** key a seed).
- `detect_changes_returns_the_merged_caller_and_dependency_edges` — assert
  `edges` contains a known upstream edge and a known downstream edge with the
  right `depth`.
- `the_reach_map_classifies_a_relationship_only_node_outside_impact` — the key
  agreement property: a node reachable only via `model-relationship` is present
  in `reach`, is **not** risk-bearing, and is not counted in `impacted`.
  Assert `count(array_filter($result['reach'], /* risk-bearing predicate */))`
  equals `$result['impacted']`. This is the invariant plan 044's node
  classification rests on — pin it here so a future edge-type change breaks a
  test rather than the picture.

Then implement: add the two `…EdgesOf` calls next to `:153-154`, add the three
keys to the return block, extend the `@return` shape.

**Verify**: `vendor/bin/phpunit --filter ImpactAnalyzer` → all pass.

### Step 4: Regression — `--json` output is byte-identical

The load-bearing guarantee of this plan. In the `JsonPresenter` test file:

`detect_changes_json_ignores_the_new_internal_graph_keys` — take an existing
analyzer-result fixture from that file, run `JsonPresenter::detectChanges()`
on it, then run it again on the same array **plus** `seeds`, `reach` and
`edges` entries, and assert the two results are `assertSame`-identical **and**
that `JsonPresenter::encode(...)` of both produces identical strings. Also
assert `array_key_exists('edges', $encoded) === false` (and the same for
`reach`, `seeds`).

If the repo has a feature test that asserts the full `--json` document against
a snapshot, the fact that it passes unchanged **is** the byte-identity proof —
say so in the commit message rather than adding a redundant test.

**Verify**: `vendor/bin/phpunit --filter 'JsonPresenter\|Commands'` →
all pass, with **zero** snapshot/expectation edits in the diff
(`git diff --stat tests/` must show new tests only).

### Step 5: Full gate

**Verify**: `composer qa-check` → exit 0.

## Test plan

| Case | Test | Step |
|---|---|---|
| Edge shape + `source`/`target` orientation | `caller_edges_carry_the_source_node…` (`assertSame` on full arrays) | 1 |
| Depth values | same + `dependency_edges_walk_the_downstream_direction` | 1 |
| BFS-tree property (each reached node once) | `edge_walks_emit_each_reached_node_exactly_once` | 1 |
| Cycle termination | `caller_edges_terminate_on_a_cycle` | 1 |
| `maxDepth` respected | `edge_walks_respect_max_depth` | 1 |
| Empty seeds | `edge_walks_return_nothing_for_no_seeds` | 1 |
| Both-directions duplicate node at differing depths | `a_node_reachable_both_ways_appears_in_both_edge_lists` | 1 |
| `seeds`/`reach`/`edges` surfaced | two `ImpactAnalyzer` cases | 3 |
| reach-count == `impacted` invariant | `the_reach_map_classifies_a_relationship_only_node_outside_impact` | 3 |
| `--json` unchanged | `detect_changes_json_ignores_the_new_internal_graph_keys` + untouched existing expectations | 4 |

## Done criteria

ALL must hold:

- [ ] `CodeGraph::callerEdgesOf()` and `dependencyEdgesOf()` exist, delegate to a private `walkEdges()`, and `bfs()`/`walk()`/`callersOf()`/`dependenciesOf()` are byte-unchanged (`git diff src/Graph/CodeGraph.php` shows additions only)
- [ ] `ImpactAnalyzer::detectChanges()` returns `seeds`, `reach`, `edges`, and the `@return` shape lists them
- [ ] `count(array_filter($reach, isRiskBearing)) === $result['impacted']` pinned by test
- [ ] `--json` output byte-identical: no existing JSON expectation in `tests/` modified (`git diff --stat tests/` shows new tests only)
- [ ] `src/Analysis/JsonPresenter.php` and `src/Mcp/Tools/DetectChangesTool.php` unmodified (`git status`)
- [ ] `composer qa-check` clean
- [ ] No files outside the in-scope list modified (`git status`)
- [ ] `plans/README.md` **not** touched (the dispatcher owns the index)

## STOP conditions

Stop and report back (do not improvise) if:

- The drift check is non-empty in `CodeGraph.php` or `ImpactAnalyzer.php` — in
  particular if `bfs()`'s callback signature no longer passes the source node
  as the 4th argument. The whole plan rests on that argument existing.
- Satisfying the new edge lists appears to require changing `bfs()`, `walk()`,
  or either existing public walker. It does not; if you believe it does,
  report what you found.
- PHPStan's cognitive-complexity rule trips on `detectChanges()` after the
  additions. Report it — do not refactor `detectChanges()` inside this plan.
- Any existing `--json` expectation needs editing to make the suite green.
  That means the new keys are leaking into the public payload — a contract
  break, not a test to update.
- You find yourself needing member-level change data (`ChangedFileSymbols` /
  `MemberChange`) to complete a step. It is out of scope by decision; plan 044
  consumes `ChangedSymbols` directly.

## Maintenance notes

- The `source`/`target` orientation chosen in step 1 becomes a soft contract
  for plan 044's arrow rendering. Changing it later silently reverses every
  arrow in the report — it deserves a test-name-level comment and a changelog
  line if it ever moves.
- `RISK_EXCLUDED_EDGE_TYPES` (`ImpactAnalyzer.php:40`) now drives both the
  `impacted` tile and the report's node classification. Adding an edge type to
  that list changes the picture as well as the number — the step-3 invariant
  test is what keeps them in sync.
- These three keys are internal, like `callers`/`dependencies`. If a future
  plan wants them in the public JSON, that is a semver decision requiring a
  matching `outputSchema()` update in `src/Mcp/Tools/DetectChangesTool.php` —
  deliberately not taken here (research §5 decision 2).
