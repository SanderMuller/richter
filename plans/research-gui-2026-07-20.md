# Research: a GUI for richter results

Written 2026-07-20 against `2d8a437` (v0.9.0 line). Not a plan — no
executor instructions, no status row in `plans/README.md`. This answers
"can richter grow a visual results surface, and at what cost".

Reference design: the mock in the marketing image — a panel with tabs
**Overview / Graph / Paths / Changes / Advisory**, an impact-summary stat
row (Files / Functions / Blast Radius / Risks), three cards (Top Reached
Entry Points, Risky Changes, What to focus on), and a node-link blast-radius
diagram with a depth legend (Directly changed / Level 1 / Level 2 / Level 3+
/ Outside impact).

---

## 1. Where the data stands today

The semver-governed contract is `src/Analysis/JsonPresenter.php`
(`detectChanges()` at :28). Everything a GUI renders should come from
there, not from `ImpactAnalyzer`'s internal array — otherwise the GUI
becomes a second, undeclared public API.

Mapping each mock tab to what exists:

| Tab | Backing data | State |
|---|---|---|
| **Overview** | `changed`, `impacted`, `risk`, `entryPoints`, `entryPointSecurity`, `findings`, `lowConfidence`, `coarseCapApplied` | **Have it all** |
| **Paths** | `entryPointPaths` (+ `entryPointLocations`) | **Have it** — chains are target-first → seed-last, `via` is the edge type to the *next* hop (`CodeGraph.php:278-284`) |
| **Advisory** | `findings`, `gate` (`{failOn, failOnUnresolved, tripped, reasons}`), `entryPointTestReferences`, `unresolved` | **Have it** |
| **Changes** | `changed` (file → seed count), `coverage` (file → `analyzed`/`unresolved`) | **Partial** — file-level only. The mock's "member-level diff" needs `src/Changes/ChangedFileSymbols.php` / `MemberChange.php`, which never reach the JSON |
| **Graph** | — | **Missing.** `JsonPresenter.php:28` explicitly discards `callers`/`dependencies` |

### The one real backend gap: a depth-tagged edge list

`CodeGraph::bfs()` (`src/Graph/CodeGraph.php:359-390`) already passes the
parent node to its callback. `walk()` at :334-346 — which backs both
`callersOf` and `dependenciesOf` — ignores that 4th argument and emits only
`{depth, node, via}`. **Line :340 drops the source node on the floor.** That
single line is why no edge list exists.

Recovering it is ~10 lines: a `callerEdgesOf()` next to `walk()` recording
`{source, target, via, depth}` on first visit. No change to the BFS
primitive, the builder, or the cache. Keeping the `$firstVisit` guard yields
a BFS *tree* (one parent per node) — which is exactly the clean radial shape
the mock draws. Dropping the guard yields the true induced subgraph with
cross-edges.

Then thread it through `ImpactAnalyzer::detectChanges()` and
`JsonPresenter` — the actual work is the presenter layer, not the graph
layer.

**Design trap worth naming now**: `reachedViaTypes()`
(`ImpactAnalyzer.php:170`) is the closest thing to a blast radius the code
already computes — both directions, every edge type per node — and it is
collapsed to an integer at :171. Risk deliberately excludes
`model-relationship`-only nodes (:40, :557). If the diagram derives its node
set from `callers` alone, **the picture and the risk number will disagree
about what counts**. Surface the `reach` map, or accept and document the
divergence.

### Full graph dump: zero plumbing

`CodeGraph::toArray()` (:123) already emits `{edges, nodeMetadata,
hasUnresolvedDispatches}` — a valid node-link document — and `GraphCache`
persists exactly that to `storage/framework/cache/richter/graph.json`
(`GraphCache.php:167`). A whole-project graph explorer needs no PHP at all;
point a viewer at that file. Cost is bounded by the fact that the same
payload is already encoded and decoded on every run.

Caveat: the cache stores *the graph*, not *analysis results*. There is no
cached `detectChanges()` output. Any blast-radius walk still needs a PHP
process — fast (linear BFS, `maxDepth = 6`), but not free.

---

## 2. Delivery options

Richter today ships **no `resources/`, no Blade, no `package.json`, no
Vite, no routes**. `illuminate/routing` is a dependency purely to read the
registered route table (`TestReferenceIndex.php:430`) — there is no HTTP
surface. Any GUI is greenfield, so the delivery choice is the real decision.

### A. Self-contained HTML report file — `--html=report.html`

One command flag on `richter:detect-changes` writes a single file: the
JSON payload embedded in a `<script type="application/json">`, all CSS and
JS inline, no external requests.

- **Pro**: no routes, no auth surface, no host-app footprint, no asset
  pipeline shipped to consumers. Works in CI (upload artifact, link from a
  PR comment) — that *is* the "Share & collaborate" story. Trivially
  versioned and diffable-ish. Nothing to secure, because nothing is served.
- **Con**: static per-run; no drill-down that requires recomputation.
- **Fit**: high. Richter is a batch analyzer whose output is already a
  document. Matches the PHPUnit-coverage-HTML / `--markdown` precedent
  already in the codebase.

### B. Served route in the host app (Telescope / Pulse / Horizon model)

`hasRoutes()`, a `/richter` dashboard, publishable precompiled assets,
`Gate::define('viewRichter')`, env gating.

- **Pro**: live, interactive, re-runs analysis on demand.
- **Con**: this is the expensive option. It adds an authorization surface,
  an asset build to maintain across Laravel majors, publishable paths that
  become semver-stable (`config keys, published asset paths … stable across
  patch and minor` — foundation guidelines), and a route that leaks the
  full code structure of the host app if misgated. Richter's audience is
  primarily AI agents and CI; a live dashboard serves neither.
- **Fit**: low for now. Do not lead with this.

### C. `richter:serve` — ephemeral local viewer

Generate the option-A file into a temp dir, boot PHP's built-in server (or
just `--open` it via `open`/`xdg-open`), no host-app integration.

- **Fit**: a thin convenience on top of A, not a separate architecture.
  Worth a flag, not a plan of its own.

### D. Separate viewer package / hosted site

`richter-ui` consuming the JSON contract.

- **Pro**: keeps the analyzer package free of any frontend.
- **Con**: a second release train, and the JSON contract has to carry
  everything with no escape hatch.
- **Fit**: only if the GUI grows beyond what a single file can hold.

**Recommendation: A, with C as a flag.** It is the only option whose cost is
proportional to the value, and it does not foreclose B or D later — both
would consume the same JSON payload.

---

## 3. Avoiding a frontend build step

The strongest argument against any GUI here is dragging npm/Vite into a
PHP package that currently has zero JS. Two ways out, both viable:

1. **Precompute the layout in PHP, emit SVG.** The mock's diagram is
   *concentric rings by depth* — depth is already the radius. Node
   placement is `angle = i/n * 2π, radius = f(depth)`; edges are lines or
   arcs. Deterministic, testable in PHPUnit (assert on the emitted SVG),
   no layout library, no build. Interactivity (hover, tab switching,
   filtering) is a few dozen lines of vanilla JS inline.
2. **Inline a force layout.** Only if organic layout matters more than
   determinism. Costs ~270KB of inlined d3 and gives up snapshot testing.

Option 1 is the better fit and is arguably the *more honest* rendering:
richter's blast radius genuinely is a depth-stratified BFS, not a physical
system.

---

## 4. Sizing

| Piece | Effort | Notes |
|---|---|---|
| `callerEdgesOf()` on `CodeGraph` | S | ~10 lines; the BFS already has the data |
| Thread edges through analyzer + `JsonPresenter` | S–M | New JSON field = semver-relevant; needs `outputSchema()` update in `Mcp/Tools/DetectChangesTool.php:64-94` |
| Member-level changes into JSON (Changes tab) | M | `ChangedFileSymbols`/`MemberChange` currently never surface |
| `HtmlFormatter` alongside `ImpactFormatter`/`MarkdownFormatter` | M | Reuse `EntryPointRow` — it is already the render-ready row both formatters consume |
| Radial SVG renderer | M | Pure PHP, snapshot-tested |
| `--html=` flag + `--open` | S | Mirrors existing `--json` / `--markdown` mutual exclusion |

Roughly **one M-sized plan for the data (edges + members), one for the
renderer**. No new runtime dependency in either.

---

## 5. Decisions (settled 2026-07-20)

1. **Delivery: self-contained HTML file.** `richter:detect-changes
   --html=report.html`, JSON/CSS/JS inlined, plus `--open`. No routes, no
   published assets, no auth surface. A served dashboard is not foreclosed —
   it would render the same payload — but it is not scheduled.
2. **Graph payload: HTML-only side channel.** `JsonPresenter` and the MCP
   `outputSchema()` stay untouched. The HTML embeds the JSON payload plus a
   `graph` key alongside it. No new semver-pinned field.
3. **Diagram: reach-classified, edge-laid-out.** Structure and depth rings
   come from the `callers`/`dependencies` walks (BFS tree, first-visit
   guard kept). Node classification comes from the `reach` map, so
   association-only nodes render as the grey "Outside impact" ring and the
   visible risk-bearing count equals the `impacted` tile. This resolves the
   §1 trap rather than documenting a divergence.
4. **Node caps: capped, never silently.** The drawn graph is capped and the
   report prints an explicit "N nodes hidden" note.
5. **Stat tiles use richter's own vocabulary** — Files / Impacted / Depth /
   Risk. The mock's "Functions" tile is dropped: `impacted` spans routes,
   commands, views and models, and no tile should promise a number the
   analyzer does not compute.

### Not reusable: laravel-brain's UI

Brain ships a full web UI (React 19 + d3 + dagre, `routes/brain.php` under
a `_laravel-brain` prefix, `BrainController::serve()`), but none of it is
consumable as a library: its `frontend/package.json` is `"private": true`
with no exported components, and `resources/assets/` is a set of committed
hashed build chunks — precisely the asset-pipeline churn decision 1 avoids.
Two things are worth taking as precedent: Brain's local-only gating
(`app->isLocal()` plus a localhost/`*.test` host allowlist) if a served
surface ever lands, and its visual language for cross-package consistency.
A richter panel inside Brain's SPA would require upstream changes to a
package outside this repo.

### Scope of the first release

`richter:detect-changes --html=` only. `richter:impact` reuses the same
renderer later and needs no new data (its hops already carry file/line via
`withHopLocations`); `richter:affected-tests` is deferred.

Plans: `043-html-report-data-layer.md`, `044-html-formatter-and-svg.md`.
