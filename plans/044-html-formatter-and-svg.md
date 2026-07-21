# Plan 044: The self-contained HTML report — `--html`, `--open`, and a PHP-computed radial SVG

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. Do NOT edit `plans/README.md` — the dispatcher
> maintains the index.
>
> **Drift check (run first)**: `git diff --stat 2d8a437..HEAD -- src/Console/DetectChangesCommand.php src/Analysis/EntryPointRow.php src/Analysis/ImpactFormatter.php src/Analysis/MarkdownFormatter.php src/Analysis/ImpactAnalyzer.php src/Graph/CodeGraph.php`
> Plan 043 lands before this one and touches `ImpactAnalyzer` and `CodeGraph`
> (adding `edges` / `reach` / `seeds` to the `detectChanges()` internal result,
> plus `callerEdgesOf()` / `dependencyEdgesOf()` on the graph) — that drift is
> EXPECTED; reconcile and continue. Treat only unexplained mismatches as STOP
> conditions.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW-MEDIUM (additive command surface, but a new file with meaningful surface area: a formatter, a layout engine, and an inline asset payload)
- **Depends on**: **043** (`plans/043-html-report-data-layer.md`). This plan consumes three keys that plan 043 adds to the `ImpactAnalyzer::detectChanges()` internal result array:
  - `edges` — `list<array{source: string, target: string, via: string, depth: int}>`, the merged caller + dependency BFS-tree walks
  - `reach` — `array<string, array<string, true>>`, node id → set of edge types by which it was reached
  - `seeds` — `list<string>`, the directly-changed node ids

  If any of those keys is absent when this plan starts, that is a STOP condition — do not re-derive them here.
- **Category**: feature (the GUI surface decided in `plans/research-gui-2026-07-20.md` §5)
- **Planned at**: commit `2d8a437`, 2026-07-20

## Why this matters

Richter's output today is three text-shaped surfaces (plain, markdown, JSON).
`plans/research-gui-2026-07-20.md` settled the GUI question: **one
self-contained HTML file**, no routes, no published assets, no auth surface, no
npm. This plan is the renderer half of that decision (043 was the data half).

The value is not decoration. Two things only a visual surface delivers:

1. The blast radius is a depth-stratified BFS, and a reviewer reading a flat
   list of 42 impacted nodes cannot see the *shape* — which entry points sit at
   depth 1 versus depth 4, and which of the reached nodes are association-only.
2. Member-level changes (`ChangedFileSymbols` / `MemberChange`) have never
   surfaced anywhere. They are the explanation for a low-confidence result, and
   today a consumer gets the verdict ("coarse class-level estimate") without the
   evidence (which member could not be pinned).

The honesty rules that govern the text surfaces govern this one identically:
capped lists say they are capped, unresolved never reads as "no impact", and
the diagram's visible risk-bearing node count must equal the `Impacted` tile.

## Current state

- `src/Console/DetectChangesCommand.php:30-38` — the `$signature` heredoc.
  `--profile` is currently the last option.
- `src/Console/DetectChangesCommand.php:43-76` — `handle()`. The mutual-exclusion
  pattern to mirror, including its careful JSON-contract handling:

  ```php
  $json = (bool) $this->option('json');

  if ($json && (bool) $this->option('markdown')) {
      // With --json present the usage error honours the JSON contract: stdout stays one parseable document.
      return $this->emitFailure($json, 'The --json and --markdown options are mutually exclusive.');
  }
  ```

  `emitFailure()` routes to `JsonPresenter::encode(['error' => …])` on stdout
  under `--json` and `$this->error()` otherwise. Reuse it — do not write a
  second error path.
- `src/Console/DetectChangesCommand.php:105-113` — `handleText()` already holds
  everything the HTML formatter needs in one scope: `$result`, `$changed` (the
  `list<ChangedFileSymbols>` from `ChangedSymbols::resolve($base)`), `$base`,
  `$tests`, `$gateActive`. The gate array itself is computed just below.
  **`$changed` reaches the formatter from here, NOT via the analyzer result.**
- `src/Analysis/EntryPointRow.php` — `build($entryPoints, $paths, $locations, $security, $gates, $tests)`
  returns `list<EntryPointRow>` sorted by plain label, each carrying
  `node, label, path, location, testReferenced, security, gates, assertionWeak`.
  Its label helper strips a console command's `$signature` down to its name.
  Both existing formatters consume this; so does the new one. Do not
  reimplement labelling.
- `src/Analysis/MarkdownFormatter.php` and `src/Analysis/ImpactFormatter.php` —
  the sibling style to match: `final` class, all-static, one public
  `detectChanges()` entry, small private static helpers, heavy "why" docblocks,
  a list-cap class constant, and a `@phpstan-import-type SecurityShape from NodeMetadata`.
- `src/Graph/CodeGraph.php:278-284` — the path-direction contract, verbatim:

  > Each chain runs target-first and seed-last in call direction … Every hop's
  > `via` is the type of the edge to the NEXT hop in the chain; the final
  > (seed) hop carries `''`.

  `MarkdownFormatter`'s path-chain helper is the correct consumption: the `via`
  of hop *i-1* labels the arrow *into* hop *i*. Get this wrong and the Paths
  panel reads backwards.
- `src/Changes/MemberChange.php` — `{name, kind, change, resolvable}`, with
  `KIND_METHOD|KIND_PROPERTY|KIND_CONSTANT|KIND_ENUM_CASE|KIND_CLASS`,
  `CHANGE_ADDED|CHANGE_MODIFIED|CHANGE_REMOVED`, and `isAdditive()`.
- `src/Changes/ChangedFileSymbols.php` — `{file, fqcn, members, cosmeticOnly, directSeeds, findings, unresolvedFrontendReferences}`,
  plus `resolvableMembers()` and `needsCoarseSeed()`. The class docblock states
  the low-confidence rule this plan must surface: *a file with no resolvable
  member change but a real non-resolvable one drives the coarse, low-confidence
  class seed*.
- The shared rich formatter fixture from plan 025 (see
  `plans/025-formatter-shared-fixture-and-rows.md`): 20 entry points (over the
  list cap), a multi-hop explain chain, a security issue with file/line, a
  Pennant gate, an unresolved changed file, related models, findings, coarse-capped
  low confidence. **Reuse it**; extend rather than fork.
- The feature tests use a faked-git end-to-end pattern (`Process::fake` +
  `withoutMockingConsoleOutput()` + `Artisan::call` / `Artisan::output()`).
- `plans/024-markdown-path-escaping.md` — precedent for exactly this class of
  bug in the sibling formatter: the docblock claimed repo-derived values could
  not contain structural characters, and that claim was false for diff-derived
  file paths. In HTML the blast radius of the same mistake is larger (broken
  markup, or script injection from a filename). **Every** interpolated value
  here is untrusted project data.
- The package ships **no** `resources/`, no Blade, no `package.json`, no Vite,
  no routes. It must still ship none of those when this plan lands.

## Design (decided)

### Command surface

Two additive options, after `--profile`:

```
{--html= : Write a self-contained HTML report to this path (all CSS/JS inline; opens offline)}
{--open : Open the --html report in the default browser after writing it}
```

- `--html` is mutually exclusive with both `--json` and `--markdown`, checked in
  `handle()` alongside the existing check, routed through `emitFailure($json, …)`
  so the `--json` contract holds (stdout stays one parseable document).
- `--open` without `--html` is a usage error, same route. Do not silently ignore it.
- The HTML path runs inside `handleText()` (it has `$changed` in scope, which
  the JSON path deliberately does not need). When `--html` is set, the file is
  written and a single confirmation line goes to stdout
  (`Report written to <path>`) — the text report is NOT also printed. The gate
  still evaluates and its verdict still prints and still drives the exit code:
  `--html` is a rendering choice, never a gate bypass.
- `--open` shells out via the platform opener (`open` on Darwin, `xdg-open` on
  Linux, `start` on Windows) through Laravel's `Process` facade, so the feature
  test can `Process::fake()` it. A failure to open is a warning, never a
  non-zero exit — the file is already on disk.
- Scope guard: `richter:detect-changes` only. No MCP surface (`JsonPresenter`
  and `DetectChangesTool::outputSchema()` are untouched — research decision 2),
  no `richter:impact`, no `richter:affected-tests`.

### `src/Analysis/HtmlFormatter.php`

```php
public static function detectChanges(
    array $result,
    array $changed,          // list<ChangedFileSymbols> — straight from the command
    string $base,
    ?TestReferenceIndex $tests = null,
    bool $gateActive = false,
    ?array $gate = null,     // {failOn, failOnUnresolved, tripped, reasons}
): string
```

Returns the complete document. Static, `final`, no state — a sibling of
`MarkdownFormatter` in every respect.

**Five tabs**, tab switching via ~20 lines of inline vanilla JS (a `click`
listener toggling a `hidden` attribute). No framework, no CDN, no webfont —
`font-family` is a system stack. The file must render fully from `file://` with
the network off.

1. **Overview** — a stat row of four tiles, using richter's own vocabulary
   (research decision 5; the mock's "Functions" tile is dropped because
   `impacted` spans routes, commands, views and models):
   - `Files` = `count($result['changed'])`
   - `Impacted` = `$result['impacted']`
   - `Depth` = the maximum `depth` across `$result['edges']` (0 when empty)
   - `Risk` = `$result['risk']->value`, upper-cased, colour-coded

   Below the tiles: top reached entry points built from `EntryPointRow::build()`,
   each with its path hop count, location, test-reference tag, exposure badge
   (`entryPointSecurity`) and Pennant flags (`entryPointGates`); then a "What to
   focus on" card assembled from `findings`, `lowConfidence` (+ `coarseCapApplied`),
   and the rows whose `testReferenced === false` or `assertionWeak === true`.
   Advisory phrasing is non-negotiable and is copied from the sibling formatters:
   "no test references this", never "untested".

2. **Graph** — the radial SVG (below), with the depth legend and, when capped,
   the hidden-node note.

3. **Paths** — `entryPointPaths`, rendered target-first → seed-last, each arrow
   labelled with the *previous* hop's `via`, and the final seed hop carrying
   `''` (so the last arrow is unlabelled). Mirror `MarkdownFormatter`'s
   path-chain index arithmetic exactly. Hops with `file`/`line` render the location.

4. **Changes** — member-level, from the `list<ChangedFileSymbols>`. Per file:
   the path, the `fqcn`, the per-file `coverage` state (`analyzed` / `unresolved`,
   the latter styled as a warning with the standing "not graphed, never 'no
   impact'" wording), a `cosmeticOnly` badge, an `unresolvedFrontendReferences`
   badge, per-file `findings`, and a table of `MemberChange` rows
   (`name`, `kind`, `change`, and a `resolvable` column).
   **A member with `resolvable === false` on a non-additive change is
   highlighted and annotated as the driver of the coarse class-level seed** —
   this panel is where a low-confidence verdict gets its evidence.

5. **Advisory** — `findings`, the `unresolved` boolean, `entryPointTestReferences`
   (via the same `EntryPointRow` rows), and — only when `$gateActive` — the gate
   block: `failOn`, `failOnUnresolved`, `tripped`, and each reason.

### `src/Analysis/RadialLayout.php`

Pure and separately testable; the formatter does no geometry.

```php
public static function compute(array $edges, array $reach, array $seeds, int $cap = self::MAX_NODES): LayoutResult
```

Rules:

- **Depth** per node = the minimum depth at which it appears across the merged
  caller and dependency walks. Seeds are depth 0.
- **Classification comes from `reach`, not from the edge list** (this resolves
  the §1 trap in the research doc). A node whose reaching edge-type set is a
  subset of the analyzer's risk-excluded types is **association-only**: it
  renders in the grey "Outside impact" ring and is *not counted*. Every other
  node is risk-bearing and *is* counted. Seeds are always risk-bearing.

  The excluded set is `ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES`, currently
  `['model-relationship', 'declares', 'uses-trait']` — read it, do not trust
  this line, and note it is **three** types, not just `model-relationship`
  (`uses-trait` is excluded so a hub trait cannot saturate the count).
  **Do not copy the list into `RadialLayout`.** Duplicating it means a future
  edit to the constant changes `impacted` while the diagram keeps the old
  classification, and the tile and the picture drift apart silently — the exact
  failure this design exists to prevent. Either read the constant (promote it
  from `private` to `public` on `ImpactAnalyzer`, it is `@internal`) or have the
  caller pass the predicate in. Plan 043's invariant test calls the private
  `isRiskBearing()` by reflection for the same reason.
- **Geometry**: `radius = RING_STEP * depth` (association-only nodes sit on an
  outer ring beyond the deepest risk-bearing ring); within a ring of `n` nodes,
  node `i` is at `angle = i / n * 2π`. Deterministic ordering: sort each ring by
  node id before assigning angles, so the SVG is stable run to run and
  snapshot-testable.
- **Cap**: `private const int MAX_NODES = 300;` — a named constant, never a
  magic number. When the node set exceeds it, keep the lowest-depth nodes first
  (shallow = closest to the change = most relevant), tie-broken by node id, and
  return `hiddenCount` on the result. Edges whose endpoints are not both drawn
  are dropped. **The formatter must render "N nodes hidden — the diagram is
  capped at 300; the counts above are not."** Silent truncation reading as full
  coverage is precisely the failure mode this tool exists to prevent.
- Returns positioned nodes (`id, label, x, y, depth, associationOnly, location`)
  and drawable edges (`x1, y1, x2, y2, via`) plus `hiddenCount` and
  `riskBearingCount`.
- **Invariant**: `riskBearingCount` of the *drawn* set, when under the cap,
  equals `$result['impacted']`. Pinned by a test (step 4).
- Labels come from the id conventions (`route::GET::/uri`, `command::sig`,
  `view::dot.name`, `model::Short`, bare/member FQCNs). Reuse
  `EntryPointRow`'s label logic rather than reimplementing it — if that helper
  is private, promote it to a small public static on `EntryPointRow` (it is
  `@internal`, so this is not a semver event) instead of copying the body into
  a second class.
- Where `entryPointLocations` has file+line for a node, the SVG node carries it
  as a `<title>` tooltip.

Keep methods small. PHPStan runs cognitive-complexity — see Risks.

### The shell template

A PHP heredoc in a small `private static function document(string $title, string $body): string`,
with `<style>` and `<script>` inline. **No Blade, no `resources/` directory** —
the package has no view layer and must not gain one.

### Escaping

One private static `e(string $value): string` = `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`,
applied to **every** interpolated value without exception: file paths, FQCNs,
route URIs, finding messages, security messages, member names, the base ref.
The class docblock must state that all interpolated data is untrusted
project-derived input — the inverse of the claim plan 024 had to retract in
`MarkdownFormatter`. SVG text content goes through the same helper; attribute
values are numeric (coordinates) or escaped.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused (formatter) | `vendor/bin/phpunit --filter 'HtmlFormatter\|RadialLayout'` | OK |
| Focused (command) | `vendor/bin/phpunit --filter Commands` | OK |
| Full suite | `composer test` | 0 failures |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |
| Rector (check) | `vendor/bin/rector process --dry-run` | 0 changed files |
| Full gate | `composer qa-check` | all four clean |

## Scope

**In scope** (the only files you should modify or create):
- `src/Analysis/HtmlFormatter.php` (new)
- `src/Analysis/RadialLayout.php` (new)
- `src/Analysis/EntryPointRow.php` (only to expose the existing label helper)
- `src/Console/DetectChangesCommand.php`
- `README.md` (one short subsection under the existing `--markdown` prose)
- `CHANGELOG.md` (one Unreleased entry)
- `tests/Unit/HtmlFormatterTest.php` (new), `tests/Unit/RadialLayoutTest.php` (new)
- the shared formatter-fixture test file (to allow reuse of the rich fixture)
- the feature test file covering the commands

**Out of scope** (do NOT touch):
- `src/Analysis/JsonPresenter.php` and `src/Mcp/**` — research decision 2: the
  graph payload is an HTML-only side channel; no new semver-pinned JSON field,
  no `outputSchema()` change.
- `src/Analysis/ImpactAnalyzer.php`, `src/Graph/CodeGraph.php` — plan 043 owns
  the data. If you need a field they do not provide, STOP.
- `src/Analysis/ImpactFormatter.php`, `src/Analysis/MarkdownFormatter.php` —
  no behaviour change to the existing surfaces.
- Any `package.json`, `resources/`, route file, or published asset.
- `plans/README.md` — the dispatcher maintains the index.

## Git workflow

- Branch: `advisor/044-html-formatter-and-svg`, created FROM the local main tip
  (which must already contain plan 043).
- Commits per logical unit, imperative subjects, end with:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`
- Do NOT push or open a PR.

## Steps

### Step 1: `RadialLayout` — geometry and classification (test-first)

New `tests/Unit/RadialLayoutTest.php` with hand-built `edges` / `reach` / `seeds`
arrays (no graph build — this class is pure):

- `seeds_sit_at_the_centre_and_depth_is_the_minimum_across_walks` — a node
  appearing at depth 3 in the caller walk and depth 1 in the dependency walk
  lands on ring 1.
- `a_node_reached_only_by_association_edges_is_not_counted` — `reach` entry of
  `['model-relationship' => true]` ⇒ `associationOnly === true`, excluded from
  `riskBearingCount`. A node reached by `['model-relationship' => true, 'calls' => true]`
  IS counted.
- `nodes_in_a_ring_are_evenly_spaced_and_deterministically_ordered` — two runs
  over the same input produce identical coordinates.
- `the_layout_is_capped_and_reports_the_hidden_count` — build `MAX_NODES + 25`
  nodes; assert exactly `MAX_NODES` positioned and `hiddenCount === 25`, and
  that the retained set is the shallowest.
- `an_empty_diff_produces_an_empty_layout` — no nodes, no edges, `hiddenCount === 0`.

Then implement `RadialLayout`.

**Verify**: `vendor/bin/phpunit --filter RadialLayout` → all pass;
`composer phpstan` → exit 0.

### Step 2: `HtmlFormatter` — document shell and the five tabs (test-first)

New `tests/Unit/HtmlFormatterTest.php`, building on the shared rich fixture
(extract it to a shared trait or a small fixture class if reuse across two test
files needs it — that is a legitimate in-scope edit), plus a
`list<ChangedFileSymbols>` carrying: one file with a resolvable modified method,
one with a non-resolvable modified enum case (the coarse-seed driver), one
`cosmeticOnly`, and one with `unresolvedFrontendReferences` and a per-file finding.

Assertions:

- `the_report_is_self_contained` — the output contains no `http://`, no
  `https://`, no `<link `, and no `src=` on any `<script>`. This is the whole
  delivery premise; pin it.
- `the_five_tabs_are_present` — Overview, Graph, Paths, Changes, Advisory.
- `the_stat_row_uses_richters_own_vocabulary` — `Files`, `Impacted`, `Depth`,
  `Risk` present; `Functions` absent.
- `a_path_chain_runs_target_first_and_labels_each_arrow_with_the_previous_hops_via` —
  using the fixture's multi-hop chain, assert the rendered order
  (entry point → controller → service) and that the `route-to-controller` label
  sits on the FIRST arrow, not the second. This is the panel's correctness test.
- `member_level_changes_render_per_file` — member name, kind and change all present.
- `an_unpinnable_member_is_named_as_the_low_confidence_driver` — the enum-case
  member appears alongside the coarse/low-confidence explanation.
- `an_unresolved_file_never_reads_as_no_impact` — assert on the standing wording.
- `the_gate_block_renders_only_when_a_gate_is_active` — both directions.
- `the_empty_diff_case_renders_a_valid_document` — empty `changed`, empty
  entry points, zeroed tiles; no PHP notice, no broken markup.

Then implement `HtmlFormatter`. Split per-tab rendering into one small private
static per tab, each delegating to smaller helpers — the cognitive-complexity
rule will fail the build otherwise.

**Verify**: `vendor/bin/phpunit --filter HtmlFormatter` → all pass.

### Step 3: Escaping (test-first, and its own step deliberately)

Add to `tests/Unit/HtmlFormatterTest.php`:

`untrusted_project_data_is_escaped_everywhere_it_is_interpolated` — a fixture
carrying, at minimum:

- a changed file path `app/Weird<name>&"x".php`
- an FQCN-shaped node `App\Odd<T>\Svc::run`
- a route entry point `route::GET::/search?q=<script>alert(1)</script>`
- a finding message containing `<b>` and `&`
- a security issue message containing a double quote
- a member name containing `<`

Assert the raw substrings `<script>alert(1)</script>` and `<b>` do NOT appear in
the output, that `&lt;` / `&amp;` / `&quot;` DO, and that the document still
contains all five tab markers (i.e. the markup was not broken by the payload).
Add the same fixture to the SVG path so node `<title>` and text content are
covered.

See `plans/024-markdown-path-escaping.md` — the sibling formatter shipped with a
docblock asserting repo-derived values were structurally safe, and that claim
was wrong for diff-derived paths. Do not repeat it in HTML.

**Verify**: `vendor/bin/phpunit --filter untrusted_project_data_is_escaped` →
passes; the whole `HtmlFormatterTest` → passes.

### Step 4: The SVG diagram and its invariant (test-first)

Add to `tests/Unit/HtmlFormatterTest.php`:

- `the_svg_is_a_stable_snapshot` — a small fixed fixture rendered twice compares
  equal, and the emitted `<svg>` fragment matches a stored expected string
  (inline in the test, not a separate golden file — keeps the diff reviewable).
  This is the payoff of PHP-side layout over a force layout.
- `the_drawn_risk_bearing_node_count_equals_the_impacted_tile` — **the required
  invariant.** Build a fixture whose `reach` mixes association-only and
  risk-bearing nodes, set `impacted` to the risk-bearing count, render, and
  assert the number of risk-bearing nodes in the SVG equals the `Impacted` tile
  value. Under the cap only; over the cap the note explains the gap.
- `an_over_cap_graph_prints_the_hidden_node_note` — and its sibling
  `an_under_cap_graph_prints_no_hidden_node_note`. Both paths, per the caps rule.
- `association_only_nodes_render_on_the_outside_impact_ring` — grey class present,
  not counted.

Then wire `RadialLayout` into `HtmlFormatter`'s Graph tab and emit the SVG.

**Verify**: `vendor/bin/phpunit --filter HtmlFormatter` → all pass.

### Step 5: `--html` and `--open` on the command (test-first)

Add to the feature test file, using the faked-git pattern:

- `detect_changes_html_and_json_are_mutually_exclusive` — `--html=… --json`
  exits `FAILURE` and stdout is a single parseable JSON document whose `error`
  names the conflict. (The required mutual-exclusion test.)
- `detect_changes_html_and_markdown_are_mutually_exclusive` — non-JSON error path.
- `detect_changes_open_without_html_is_a_usage_error`.
- `detect_changes_html_writes_a_self_contained_file` — write into a temp path,
  assert the file exists, contains `<!DOCTYPE html>` and all five tab markers,
  contains no external URL, and that stdout carries the confirmation line but
  NOT the plain-text report.
- `detect_changes_html_still_evaluates_the_gate` — `--html --fail-on=low` on a
  tripping fixture exits `FAILURE` and the verdict prints.
- `detect_changes_open_invokes_the_platform_opener` — `Process::fake()`, assert
  the opener ran; and a faked failure yields a warning with exit code unchanged.

Then implement: the two signature lines, the `handle()` exclusivity checks
routed through `emitFailure()`, and the write/open branch inside `handleText()`.

**Verify**: `vendor/bin/phpunit --filter Commands` → all pass.

### Step 6: Docs and full regression

- `README.md`: a short subsection after the `--markdown` prose — what `--html`
  writes, that it is one offline-openable file with nothing external, that
  `--open` launches it, that the diagram is capped and says so, and that the
  HTML is a rendering surface, not the semver-governed contract (that remains
  `--json`).
- `CHANGELOG.md`: one Unreleased entry.

**Verify**: `composer qa-check` → Rector dry-run 0 changed, Pint clean, PHPStan
exit 0, `composer test` 0 failures.

## Test plan

Steps 1–5 enumerate the cases. The six the plan **requires** by name, all of
which must exist and pass at the end:

1. SVG snapshot stability (step 4)
2. Drawn risk-bearing node count == `Impacted` tile (step 4)
3. Cap note present over-cap, absent under-cap (steps 1 and 4)
4. Escaping with a hostile-character fixture (step 3)
5. Empty-diff case renders a valid document (steps 1 and 2)
6. `--html` + `--json` mutual exclusion (step 5)

## Done criteria

ALL must hold:

- [ ] `--html=<path>` writes one file; `grep -cE 'https?://|<link |script[^>]*src=' <file>` → 0
- [ ] All six required tests above exist and pass
- [ ] `--html` composes with `--fail-on` (gate still evaluated, exit code preserved)
- [ ] No `package.json`, no `resources/`, no route file added (`git status`)
- [ ] `src/Analysis/JsonPresenter.php` and `src/Mcp/**` unmodified (`git diff --stat`)
- [ ] The node cap is a named class constant, not a literal
- [ ] `composer qa-check` clean end to end
- [ ] No files outside the in-scope list modified (`git status`)
- [ ] `plans/README.md` NOT modified — the dispatcher maintains the index

## STOP conditions

Stop and report back (do not improvise) if:

- `ImpactAnalyzer::detectChanges()` does not return `edges`, `reach` and `seeds`
  (plan 043 has not landed, or landed with a different shape). Report the actual
  keys; do not re-derive the walk here.
- The impacted-count invariant cannot be made to hold — i.e. the risk-bearing
  set derived from `reach` genuinely differs from `impacted`. That is a real
  disagreement between the picture and the number (the §1 trap in the research
  doc), and research decision 3 chose to resolve it rather than document a
  divergence. Report the two sets and the divergent nodes; do not "fix" it by
  printing `impacted` next to a differently-sized diagram.
- PHPStan's cognitive-complexity rule cannot be satisfied without collapsing the
  formatter's tab methods into something unreadable. Report the offending method
  and the threshold rather than adding a baseline entry or an ignore.
- Making the report self-contained appears to require any external asset, font,
  or npm dependency. The whole delivery decision rests on it not doing so.
- Rendering any tab requires a field that would have to be added to
  `JsonPresenter` — the HTML is a side channel; adding a semver-pinned field is
  a different plan.

## Risks and mitigations

- **Cognitive complexity** is the realistic build-breaker here. A formatter is
  naturally branchy, and this one has five tabs. Mitigation: one small private
  static per tab, each delegating; extract every `match`/badge helper the way
  `MarkdownFormatter`'s risk and exposure badges already do. Budget time for
  this — it is not incidental cleanup.
- **Snapshot brittleness**: the SVG snapshot will churn on any styling change.
  Keep the snapshot fixture tiny (3–4 nodes) and assert structure plus
  coordinates, not the whole document.
- **Scope creep toward a served dashboard.** Research option B is explicitly not
  scheduled. If a requirement seems to need live re-computation, it belongs in a
  later plan, not this one.

## Maintenance notes

- The HTML surface is **not** semver-governed the way `--json` is. Say so in the
  README so a consumer does not start scraping it. If that changes, it needs a
  documented contract of its own.
- `richter:impact` can reuse `HtmlFormatter` later at low cost — its hops
  already carry file/line via `withHopLocations`. `richter:affected-tests` is
  deferred (research §5, "Scope of the first release").
- If the node cap is ever raised, the hidden-node note wording and both cap
  tests move with it — the constant and the note are one unit.
