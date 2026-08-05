# Agent Surface Expansion: Trace, Affected-Tests over MCP, Resources, and Skills

<!-- spec:planned-at 566a95ef3c635bb22abe88bb9aa0da9e3e911a71 2026-08-05 +uncommitted -->

## Overview

Richter's analysis is deeper than what a coding agent can currently reach: the CLI computes
affected-test selection and shortest reach-chains, but MCP exposes only `impact` and
`detect-changes`, onboarding never proposes MCP registration, and no skill packages the
review workflow. This spec closes that packaging gap — two new MCP tools (`trace`,
`affected-tests`), read-only MCP resources for orientation, an opt-in `.mcp.json` step in
`richter-setup`, a `richter-review` skill, and entry-point annotations on `impact`
output — without touching the graph build
(no `GraphCache::FORMAT_VERSION` bump anywhere in scope). Motivated by a competitive gap
analysis of the agent-facing surface of graph-based code-intelligence tools.

## Assumptions

<!-- Audit ledger — one bullet per AI-introduced inference. Sign-off-ready by skimming this
section alone. -->

- **Trace is strictly directional with upstream-extent diagnostics** (user-confirmed,
  Resolved Question 1): `trace A B` queries only "A reaches B in call direction"; no
  reverse fallback. A no-path result reports `furthestReached` — the deepest caller
  reached from `to` within the depth limit, framed exactly as that and never as a pointer
  toward `from` (the walk has no directionality toward the target) — and hints that
  swapping the arguments queries the reverse.
- **Trace errors on an unresolvable symbol** — a deliberately stricter contract than
  `impact`, which returns an empty result for an unknown symbol (no seed check,
  src/Console/ImpactCommand.php:52-54): an empty trace would read as "no path". New
  contract for trace, both CLI and MCP.
- **Affected-tests MCP failure shape** (user-confirmed, Resolved Question 2): a base ref
  that cannot be resolved returns `determinable: false` + reasons (mirroring the CLI's
  exit-2 fail-safe), not an MCP error response — a deliberate, documented divergence from
  `DetectChangesTool`'s handling of the same exception.
- **Resource set** (user-confirmed, Resolved Question 3): three resources ship —
  entry-point inventory, graph stats, effective config.
- **Skill scope** (user-confirmed, Resolved Question 4): one new skill, `richter-review`,
  invoke-only; a separate pre-edit impact-check skill is out of scope.
- **Trace "no path" is data, not an error**: exit 0 with an explicit no-path result —
  consistent with richter's advisory philosophy ("a low or empty result is a signal").
  AI-chosen default; not load-bearing.
- **Trace CLI flags mirror `richter:impact`**: `--json`, `--markdown`, `--no-cache`; no
  `--max-depth` flag (the analyzer default of 6 applies, as it does for `impact`). GitNexus
  exposes `maxDepth` (default 10) on its trace tool; richter keeps flag parity with
  `impact` instead — adding a depth option later is non-breaking. AI-chosen default.
- **Trace output shape avoids `anyOf`/nullable**: `found: bool` with an empty `path` list
  (and an optional `furthestReached` object) when nothing is found, because
  `Illuminate\JsonSchema` lacks `anyOf()` on the framework floor (same constraint
  `DetectChangesTool.php:64-94` documents). Floor-driven technical choice.
- **MCP tools take no cache-bypass argument** — parity with the existing `ImpactTool`/
  `DetectChangesTool`, which never pass `fresh:` to `GraphCache::graph()`. Convention.
- **New MCP tests carry `#[Group('requires-mcp')]`** so the CI no-mcp matrix leg
  (`--exclude-group requires-mcp`) keeps passing. Verified convention
  (`tests/Feature/McpTest.php:21`, `.github/workflows/run-tests.yml:119`).
- **Assembly extraction lands on the existing class** as
  `AffectedTests::selectForCurrentDiff()` rather than a new class. Naming default.
- **Resource URIs use a `richter://` scheme** (overriding the `file://` default in
  `Laravel\Mcp\Server\Resource::$defaultUriScheme`). AI-chosen naming.
- **Skills ship untagged** (synced whenever the vendor is allowlisted), like
  `richter-setup`. Verified convention (no `tags` frontmatter; boost-core
  `VendorScanner::DEFAULT_SKILLS_PATH`).
- **Impact annotations reuse the `detect-changes` vocabulary verbatim** — same JSON key
  names and shapes, same renderers (user-accepted research recommendation, Resolved
  Question 5).
- **The impact entry-point section is always on; the `TestReferenceIndex` is lazy** —
  built only when the walk reached at least one entry point, so the common
  no-entry-surface call pays nothing new. Accepted recommendation.
- **`entryPointPaths` is always in impact's JSON/MCP shape; `--explain` toggles only the
  text/markdown chain rendering** — exact parity with how `detect-changes` treats the
  flag. AI-chosen consistency detail.
- **`entryPointAuthGates` is included** — `PublicWriteAuthCrossCheck` is diff-independent
  (verified: constructor takes only `CodeGraph`;
  src/Analysis/PublicWriteAuthCrossCheck.php:37), so the cross-check works for a
  symbol-scoped walk too.
- **Annotation composition honors the analyzer's complexity budget** — the
  `PublicWriteAuthCrossCheck` docblock records the beside-class precedent; a thin
  delegation inside `impact()` or a companion class, never an inflated analyzer.
- **Implementation starts only after the parallel working-tree changes land** — see STOP
  condition 5.

---

## 1. Trace: shortest path between two symbols

### Current state

`CodeGraph::callerPathsTo(array $from, array $targets, int $maxDepth = 6)`
(src/Graph/CodeGraph.php:432) already reconstructs BFS-shortest chains from walk seeds up to
arbitrary targets, with first-visit parent pointers and cycle-safe termination. It is used
only by `ImpactAnalyzer::entryPointPathsFor()` (src/Analysis/ImpactAnalyzer.php:599) for
`detect-changes --explain`. Symbol resolution (FQCN, substring, `Class::member`) exists in
`ImpactAnalyzer::seedsFor()` / `candidateNodes()` / `memberSeeds()`
(src/Analysis/ImpactAnalyzer.php:764-793). Hop location decoration exists
(`withPathLocations()`, src/Analysis/ImpactAnalyzer.php:743).

### Proposed

A new public `ImpactAnalyzer::trace(string $from, string $to, int $maxDepth = 6)`:

1. Resolve both symbols through the same seeding path `impact()` uses. Either symbol
   resolving to zero nodes is an error — a **new contract, deliberately stricter than
   `impact`**, which returns an empty result for an unknown symbol (no seed check,
   src/Console/ImpactCommand.php:52-54; `ImpactTool` errors only on a missing argument).
   For trace, an empty result would read as "no path" — the one misleading answer an
   error avoids.
2. **Strictly directional** (user-confirmed, Resolved Question 1): one query, "`from`
   reaches `to` in call direction" — `callerPathsTo(from: <to-nodes>, targets:
   <from-nodes>)` — the reconstructed chain reads `from`-first, `to`-last, exactly like an
   `--explain` chain. No reverse fallback: an agent asking "does the route reach the
   service" must never receive a reversed answer it might misread.
3. When multiple nodes resolve for either symbol, the shortest chain across all resolved
   pairs wins; the resolved node lists are part of the result so the reader sees what
   matched.
4. **No path: report how far upstream connectivity extends, not just absence.**
   `found: false`, empty `path`, plus `furthestReached` — the deepest hop of the upstream
   walk from `to` (the last element of the existing `callersOf()` hop list, no `CodeGraph`
   change). Describe it exactly as that — *"the deepest caller reached from `to` within
   the depth limit"* — never as a pointer toward `from`: the BFS has no directionality
   toward the target, the deepest hop can lie on an unrelated caller branch, and
   equal-depth candidates tie-break arbitrarily. When `to` has no callers at all,
   `furthestReached` is omitted and the text output says so plainly. Text/markdown output
   also hints that swapping the arguments queries the reverse direction. A no-path result
   is data, not an error: exit 0.

Result shape (CLI `--json` and MCP structured content, identical):

```json
{
    "from": "App\\Http\\Controllers\\PostController",
    "to": "App\\Services\\PostPublisher",
    "resolvedFrom": ["App\\Http\\Controllers\\PostController::publish"],
    "resolvedTo": ["App\\Services\\PostPublisher"],
    "found": true,
    "path": [
        {"node": "App\\Http\\Controllers\\PostController::publish", "via": "action-to-service", "file": "app/Http/Controllers/PostController.php"},
        {"node": "App\\Services\\PostPublisher", "via": "", "file": "app/Services/PostPublisher.php"}
    ]
}
```

Not found: `"found": false, "path": []`, plus
`"furthestReached": {"node": "…", "depth": 4, "file": "…"}` when the walk reached
anything.

`via` on each hop is the edge to the next hop; the final hop carries `""` — the existing
`callerPathsTo` chain convention, unchanged.

New `richter:trace` command (src/Console/TraceCommand.php), registered via `hasCommands()`
in `RichterServiceProvider::configurePackage()` (src/RichterServiceProvider.php:20-27).
Signature mirrors `ImpactCommand`:

```
richter:trace {from} {to} {--json} {--markdown} {--no-cache}
```

Text output renders the chain one hop per line with `via` and location, like an `--explain`
block. `--json` follows the JSON-contract idioms of the sibling commands (single parseable
document on stdout, errors as `{"error": …}` + `FAILURE`).

New `TraceTool` (src/Mcp/Tools/TraceTool.php) follows the `ImpactTool` anatomy exactly:
`#[IsReadOnly]`, constructor-injected `GraphCache`, `schema()` with required `from`/`to`
strings, `outputSchema()` (list-of-objects `path`, no maps — no `anyOf` workaround needed),
`ResponseFactory(Response::text(…))->withStructuredContent(…)`, `Response::error()` for an
unresolvable symbol.

## 2. Affected-tests over MCP

### Current state

The entire selection assembly lives in `AffectedTestsCommand::handle()`
(src/Console/AffectedTestsCommand.php:46-126): untracked-file check
(`ChangedSymbols::untrackedRelevantFiles()`), base resolution (`RichterConfig::baseRef()`),
diff resolution (`ChangedSymbols::resolve()`), an empty-diff short-circuit, graph build,
`ImpactAnalyzer::detectChanges()`, then `AffectedTests::select()` with five context inputs
(`TestReferenceIndex`, `hasUnresolvedDispatches`, graph, optional `FrontendTestIndex`,
`hasUnparseableFiles`). The fail-safe semantics (docblock,
src/Analysis/AffectedTests.php:10-19) live partly in that assembly: the untracked-file check
and the base-resolution catch both force "not determinable" rather than a narrowed
selection.

### Proposed

Extract the assembly into `AffectedTests::selectForCurrentDiff(GraphCache $graphs, ?string
$requestedBase, bool $fresh = false): array{base: string, determinable: bool, reasons:
list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints:
int, untrackedFiles: list<string>}` so CLI and MCP share one implementation of the
fail-safe contract:

- The untracked-file check, empty-diff short-circuit, and the
  `InvalidArgumentException|RuntimeException` → undetermined-with-reason mapping move into
  the shared method. The catch spans base resolution *and* `ChangedSymbols::resolve()`,
  as it does today, and precedence is preserved exactly as characterized (see Findings —
  the original claim here was mis-traced): `baseRef()` validates only argument *shape*, so
  an unresolvable-but-well-formed ref fails inside `resolve()`, which the untracked
  short-circuit precedes — the **untracked reason wins** that corner and `base` stays the
  requested ref; only a malformed (`-`-prefixed) base throws before the untracked check
  and wins with `base` degraded to the requested string or `''`
  (src/Console/AffectedTestsCommand.php:63-86). Unexpected `Throwable`s keep escaping to
  the caller (CLI backstop / MCP error).
- `untrackedFiles` exists so the command renders its stderr note from the same single
  `git status` pass (no second `ChangedSymbols::untrackedRelevantFiles()` call, no window
  where note and selection disagree). Both the CLI `--json` document and the MCP
  structured content strip that key — stdout stays byte-identical, the note stays
  stderr-only.
- `AffectedTestsCommand` becomes presentation only: option validation, the stderr
  untracked-files note (rendered from `untrackedFiles`), `emit()` fan-out, exit codes.
  Behaviour stays byte-identical — the existing `tests/Feature/CommandsTest.php`
  affected-tests suite (13 tests) is the regression net and must pass unmodified. Two
  corners were uncovered today and get characterization tests *before* the refactor:
  an unresolvable base *and* an untracked file together (the untracked reason wins), and
  a malformed `-`-prefixed base *and* an untracked file (the shape-validation failure
  wins).

New `AffectedTestsTool` (src/Mcp/Tools/AffectedTestsTool.php): optional `base` string
argument; result = the `selectForCurrentDiff()` shape minus `untrackedFiles` (matching the
CLI `--json` document, which strips that key the same way).
MCP has no exit codes — `determinable` carries the contract, and the tool description
must state it: *"when `determinable` is false, run the full suite"*. All output fields are
scalars and lists, so `outputSchema()` needs no map workaround. An unresolvable base ref
returns `determinable: false` + reason, not `Response::error` (user-confirmed, Resolved
Question 2) — one result shape for every non-determinable cause; the deliberate divergence
from `DetectChangesTool`'s handling of the same exception gets a code comment saying so.

## 3. MCP resources

`RichterServer` (src/Mcp/RichterServer.php) gains a `protected array $resources = […]`
(base-class support verified: `vendor/laravel/mcp/src/Server.php:96`, `resources/list` +
`resources/read` methods wired at `:113-115`). Each resource extends
`Laravel\Mcp\Server\Resource`, sets `$uri`/`$mimeType = 'application/json'`, and implements
the reflective `handle(Request): Response` contract (per the vendor stub;
container-resolved, so `GraphCache` injects). Reading a resource may build the graph
(cached; first read in a session pays the build, like the tools).

All three ship (user-confirmed, Resolved Question 3), in a new dir src/Mcp/Resources/:

| Resource | URI | Content |
|---|---|---|
| `EntryPointsResource` | `richter://graph/entry-points` | Every statically-known entry-point node with kind and `file:line` where known — the inventory an agent orients on before asking impact questions. **Must match the entry-point definition `detect-changes` reports**: `route::`/`command::`/`schedule::` nodes *plus* Livewire/Filament component classes — not the narrow prefix list alone (`isEntryPointNode()`, src/Analysis/ImpactAnalyzer.php:794, covers only the prefixes; the UI-component classification lives beside it). Self-listed entry classes are diff-relative by nature and stay out of a static inventory; the resource description says so. |
| `GraphStatsResource` | `richter://graph/stats` | Node count, edge counts by `via` type, `hasUnparseableFiles`, `hasUnresolvedDispatches` — "how complete is the graph here" at a glance. |
| `ConfigResource` | `richter://config` | The effective richter config subset that shapes analysis: `default_base`, `entry_point_roots`, `dispatch_helpers`, `feature_gate_methods`, `frontend.*`, `cache.enabled`, `parallel`. Paths and names only — richter config carries no secrets. |

Full JSON, no caps — an inventory that silently truncates would contradict the honesty
model; size is acceptable for a resource read.

## 4. `.mcp.json` step in richter-setup

`resources/boost/skills/richter-setup/SKILL.md` gains a new opt-in step (after the CI
step, same propose-then-confirm framing):

- Check whether `laravel/mcp` is installed (it is `suggest`-only,
  composer.json:124-126; the server registers via the `class_exists(Mcp::class)` guard,
  src/RichterServiceProvider.php:36-43). If absent, the proposal starts with
  `composer require --dev laravel/mcp`.
- Propose the `.mcp.json` entry from the README (`php artisan mcp:start richter`).
  **Merge into an existing `.mcp.json`, never overwrite**; if the entry already exists,
  say so and do nothing (idempotent, like the config step).
- Opt-in like CI: never proposed as part of the config step's confirmation.

## 5. richter-review skill

New `resources/boost/skills/richter-review/SKILL.md` (+ no templates), packaged exactly
like richter-setup: `disable-model-invocation: true`, description opening with
`"Invoke-only — run with `/richter-review` …"`, `metadata.schema-required: "^1"`. Review
skill only — a pre-edit impact-check skill is out of scope (user-confirmed, Resolved
Question 4). Outline:

1. Run the report — MCP `detect-changes` when available, else
   `php artisan richter:detect-changes --json --explain` (and `--base` when reviewing
   against a non-default base).
2. Triage reached entry points: unexpected reach first, then `[⚠ no test references this]`
   tags, then security/gate annotations (advisory framing preserved — the skill must not
   re-brand annotations as verdicts).
3. Walk findings (eager-load strings, payload parity, flag-gated changes) and UNRESOLVED
   files — an UNRESOLVED file is a review item, not a pass.
4. Suggest the affected-test selection (`richter:affected-tests`, or the new MCP tool) and
   flag unreferenced entry points as coverage gaps to consider.
5. Close with an advisory verdict: what the change reaches, what to look at, what to test.
   Never a gate; the skill recommends, the reviewer decides.

## 6. Entry-point annotations in `impact` output

### Current state

`impact()` returns `{target, callers, dependencies}` plus no-match diagnostics
(`suggestions`, `graphNodeCount` — src/Analysis/ImpactAnalyzer.php:58-71), and every
renderer is annotation-free. Entry points the callers walk reaches appear only as raw
hops: CLI text prints them uncapped, while markdown sorts hops *by node name* and
collapses everything past 15 into `<details>` (MarkdownFormatter.php:22, hopList :320) —
whether a reached route is visible or buried is alphabetical accident. Meanwhile
`detectChanges()` composes a full annotation set from private methods of the same class:
`entryPointsAmong()` (:568), `entryPointAnnotations()` (locations/security/gates, :676),
the diff-independent `PublicWriteAuthCrossCheck->gatesByEntryPoint()`
(PublicWriteAuthCrossCheck.php:37), `entryPointPathsFor()` (:599), and the test-reference
tags (`JsonPresenter::entryPointTestReferences()` fed by `TestReferenceIndex::fromTests()`
— a full `tests/` Finder scan, the one annotation with real cost).

`JsonPresenter::impact()` (JsonPresenter.php:21) picks the three contract keys explicitly;
its comment is the binding rule: an extra structured-content key is a schema violation
(MCP clients validate results against the declared `outputSchema`), so widening the
contract is a deliberate **three-place lockstep change** — analyzer result → picked keys →
`ImpactTool::outputSchema()` (ImpactTool.php:53).

### Proposed

- Text and markdown `impact` output gain an **"Entry surfaces reached (N)"** section after
  Callers, reusing the detect-changes renderers (`ImpactFormatter::entryPointList()`,
  `MarkdownFormatter::entryPointChecklist()`): location, exposure and gate badges,
  auth-gate notes, test tags. Always on — the burial problem is exactly what it solves,
  and the section itself uses the same capped-`<details>` rendering as the detect-changes
  checklist (never truncation).
- `--json` and MCP structured content gain **additive keys with the `detect-changes`
  vocabulary verbatim**: `entryPoints`, `entryPointLocations`, `entryPointSecurity`,
  `entryPointGates`, `entryPointAuthGates`, `entryPointTestReferences`,
  `entryPointPaths` — an agent that parses one report parses both. Keys are always
  present (empty when nothing is reached), matching detect-changes.
- `richter:impact --explain` renders the shortest chain per reached entry point in
  text/markdown; the JSON shape carries `entryPointPaths` regardless — exact parity with
  the detect-changes flag semantics.
- The `TestReferenceIndex` is built **lazily**: only when the walk reached at least one
  entry point.
- Advisory framing carries verbatim: security tags are routes-only, absence means *not
  classified*, and nothing here feeds a risk figure — `impact` reports no risk at all.
- Composition respects the analyzer's complexity budget (see the Assumptions ledger): the
  `PublicWriteAuthCrossCheck` docblock records the beside-class precedent.
- `ImpactTool`'s description is updated alongside the schema: what the annotations mean
  and their advisory limits.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Trace: a symbol resolves to no graph node | Error — CLI `FAILURE` + `{"error": …}` under `--json`; MCP `Response::error()`. Deliberately stricter than `impact`, which returns an empty result: an empty trace would read as "no path". (trace-core / trace-mcp Tests) |
| Trace: no path within depth 6 | `found: false`, empty `path`, exit 0 — with `furthestReached` naming the deepest caller reached from `to` when the walk reached anything, and a swap-the-arguments hint in text output. (trace-core Tests) |
| Trace: `to` has no callers at all | `found: false`, `furthestReached` omitted; text output says the target has no callers rather than implying a partial chain. (trace-core Tests) |
| Trace: path exists only in the reverse direction | Still `found: false` — strict semantics (Resolved Question 1); the swap hint covers discoverability. (trace-core Tests) |
| Trace: `from` and `to` resolve to the same node | Single-node path (`callerPathsTo` already returns the seed-only chain when a target is a seed, src/Graph/CodeGraph.php:452-465). (trace-core Tests) |
| Trace: a symbol resolves to multiple nodes | The `to`-side nodes seed the walk, the `from`-side nodes are its targets (per §1's `callerPathsTo` orientation); shortest chain across pairs wins; `resolvedFrom`/`resolvedTo` expose what matched. (trace-core Tests) |
| Affected-tests MCP: untracked relevant file present | `determinable: false` + reason — the CLI's exit-2 fail-safe, carried by the field since MCP has no exit codes. (affected-tests-mcp Tests) |
| Affected-tests MCP: empty diff | `determinable: true`, empty lists, no graph build (short-circuit preserved in the shared method). (affected-tests-mcp Tests) |
| Affected-tests MCP: base ref unresolvable | `determinable: false` + reason (Resolved Question 2) — never `Response::error` for this cause. (affected-tests-mcp Tests) |
| laravel/mcp not installed | Nothing new: the server never registers (`class_exists` guard); `richter:trace` CLI works regardless. (existing pattern, no new test) |
| Long MCP session, files change between calls | Inherited behaviour, unchanged: `GraphCache` memoizes in-process for the session (the documented MCP trade-off, README "MCP server" section); new tools get the same semantics as `DetectChangesTool`, no worse. |
| Resource read before any graph build | Builds the graph on read (cached singleton) — first read pays the build; resource descriptions say so. (mcp-resources Tests) |
| CI no-mcp matrix leg | Every new MCP test carries `#[Group('requires-mcp')]`; the schema-drift guard pattern (McpTest.php:142-176) is extended to the two new tools. (trace-mcp / affected-tests-mcp / mcp-resources Tests) |
| `.mcp.json` already exists with other servers | Skill proposes a merge, never an overwrite; existing richter entry → no-op. (agent-skills, verified by skill text review) |
| Review skill on a project without laravel/mcp | Falls back to the artisan CLI (`--json`) — the skill works without MCP. (agent-skills, skill text) |
| Impact: no entry point in the callers walk | The section renders as `Entry surfaces reached (0)` + `(none)` — always-on, so absence reads as a checked fact rather than a missing feature (deviation from this row's original "no section" wording; see Findings); JSON keys present but empty (detect-changes parity); `TestReferenceIndex` never built. (impact-annotations Tests) |
| Impact: no-match, nearest-node `suggestions` path | Unaffected — the annotation section exists only when callers exist. (impact-annotations Tests) |
| Impact: entry point buried past markdown's 15-hop collapse | The summary section names it above the hop lists — and is itself capped-`<details>`, never truncated. (impact-annotations Tests) |
| Impact: UI-component members among callers | Class-normalised via `entryPointsAmong()`, exactly as detect-changes reports them. (impact-annotations Tests) |
| Impact MCP: added structured-content keys | Same-release lockstep with `ImpactTool::outputSchema()` (§6) — clients validate against the declared schema; the drift guard is extended deliberately. (impact-annotations Tests) |

## Implementation

### Phase 1: Trace core — analyzer + CLI (Priority: HIGH)

**ID:** trace-core · **Depends:** none

- [x] Add `ImpactAnalyzer::trace(string $from, string $to, int $maxDepth = 6)` — resolve
      both symbols via the existing `seedsFor()` path, one strictly-directional
      `callerPathsTo()` query, shortest-chain-across-pairs selection,
      `withPathLocations()` decoration, and `furthestReached` derivation (the deepest hop
      of `callersOf(<to-nodes>)`) on the no-path branch.
- [x] Add `JsonPresenter::trace()` — the result shape above; keys stable, semver-governed
      like the other JSON contracts.
- [x] Add `ImpactFormatter::trace()` — the human-readable chain rendering the CLI prints
      and the MCP tool reuses as text content (the `ImpactTool` anatomy pairs
      `ImpactFormatter` text with `JsonPresenter` structured content,
      src/Mcp/Tools/ImpactTool.php:47-48).
- [x] Create `src/Console/TraceCommand.php` (`richter:trace {from} {to} {--json}
      {--markdown} {--no-cache}`) — text/markdown/JSON rendering, JSON-contract error
      idioms from `ImpactCommand`.
- [x] Register `TraceCommand` in `RichterServiceProvider::configurePackage()`.
- [x] Add a `MarkdownFormatter::trace()` rendering (chain as a list, PR-pasteable).
- [x] Tests — a new `tests/Feature/TraceCommandTest.php`, **not** `CommandsTest.php`
      (Phase 2 appends its characterization test there, and the two phases run in
      parallel — same-file appends would collide): path found, no path (exit 0,
      `found: false`, `furthestReached` present), reverse-only path stays `found: false`,
      target with no callers (`furthestReached` omitted), unknown symbol (error),
      same-node trace, multi-candidate resolution, `--json` document shape, `--json`
      error shape, `--json` + `--markdown` mutual exclusion (the ImpactCommand idiom,
      src/Console/ImpactCommand.php:36-42).

### Phase 2: Affected-tests assembly extraction (Priority: HIGH)

**ID:** affected-tests-assembly · **Depends:** none

- [x] Add characterization tests first for the both-fail corners (uncovered today; they
      pin what the refactor could silently change): an unresolvable-but-well-formed base
      + an untracked file → the untracked reason wins, `base` stays the requested ref; a
      malformed `-`-prefixed base + an untracked file → the shape-validation failure
      wins, `base` degrades to the requested string.
- [x] Extract the selection assembly from `AffectedTestsCommand::handle()` into
      `AffectedTests::selectForCurrentDiff(GraphCache, ?string $requestedBase, bool $fresh
      = false)` — untracked-file check, base resolution, empty-diff short-circuit, graph
      build, `detectChanges()`, index construction, `select()`; base/diff-resolution
      failures (`InvalidArgumentException|RuntimeException`) map to
      undetermined-with-reason inside the method, preserving today's precedence; the
      shape carries `untrackedFiles` for the command's stderr note; unexpected
      `Throwable`s escape.
- [x] Rewire `AffectedTestsCommand` to delegate — presentation (stderr note from
      `untrackedFiles`, stripped from `--json`), exit codes only. Beyond the new
      characterization test, no edits to the existing 13 affected-tests feature tests in
      `tests/Feature/CommandsTest.php` (write-disjoint with trace-core holds because
      trace-core's tests live in the new `tests/Feature/TraceCommandTest.php`, never in
      `CommandsTest.php`): they are the regression net and must pass unmodified.
- [x] Tests — the characterization test above, plus the existing suite unchanged
      (regression evidence); add unit coverage for the new seam only if a behaviour
      question arises that the feature tests don't already pin.

### Phase 3: Trace over MCP (Priority: HIGH)

**ID:** trace-mcp · **Depends:** trace-core

- [x] Create `src/Mcp/Tools/TraceTool.php` — `ImpactTool` anatomy: `#[IsReadOnly]`,
      `GraphCache` constructor injection, `schema()` (required `from`/`to` strings),
      `outputSchema()` (no maps, no `anyOf` needed), text from `ImpactFormatter::trace()`
      + structured content from `JsonPresenter::trace()`, `Response::error()` on
      unresolvable symbols. The tool
      description must state the agent-facing semantics: strictly directional (swap the
      arguments for the reverse direction), and `furthestReached` on a `found: false`
      result is the deepest caller reached from `to` within the depth limit — not a
      pointer toward `from` — the same explicitness P4 requires for the
      `determinable: false` contract.
- [x] Register in `RichterServer::$tools` and extend `RichterServer::$instructions` (it
      enumerates the tools by name, src/Mcp/RichterServer.php:16).
- [x] Tests — McpTest additions under `#[Group('requires-mcp')]`: happy path with
      `assertStructuredContent`, error path, and the outputSchema drift guard.

### Phase 4: Affected-tests over MCP (Priority: HIGH)

**ID:** affected-tests-mcp · **Depends:** affected-tests-assembly, trace-mcp

- [x] Create `src/Mcp/Tools/AffectedTestsTool.php` — optional `base` argument, delegates to
      `AffectedTests::selectForCurrentDiff()`, structured content = the CLI `--json` shape
      (minus `untrackedFiles`, like the CLI strips it); text content is a brief
      tool-composed summary (the selection list, or the undetermined reasons) — no
      affected-tests text formatter exists to reuse, and the CLI's rendering lives in
      `emit()`. Description states the `determinable: false` → run-the-full-suite
      contract; an unresolvable base is `determinable: false`, never `Response::error`
      (Resolved Question 2), with a comment naming the deliberate divergence from
      `DetectChangesTool`.
- [x] Register in `RichterServer::$tools` and extend `$instructions` for the new tool
      (this phase depends on trace-mcp solely to serialise
      `RichterServer.php`/`McpTest.php` edits).
- [x] Tests — McpTest additions (`requires-mcp`): determinable selection with faked git
      (`Process::fake` pattern), untracked-file → `determinable: false`, empty diff,
      unresolvable base → `determinable: false` (Resolved Question 2), outputSchema drift
      guard.

### Phase 5: MCP resources (Priority: HIGH)

**ID:** mcp-resources · **Depends:** affected-tests-mcp

- [x] Add a public node enumeration to `CodeGraph` — none exists today: `nodeCount()`
      (src/Graph/CodeGraph.php:187) and `nearestNodes()` (:206) arrived with the parallel
      work stream, but `nodesContaining()` still needs a needle (:172) and no full listing
      is exposed. An additive read-only accessor, explicitly inside STOP 3's allowance.
- [x] Expose a public entry-point enumeration on `ImpactAnalyzer` matching the definition
      `detect-changes` reports: the `isEntryPointNode()` prefixes *plus* Livewire/Filament
      component classes (single source — reuse the predicates, don't duplicate the
      prefix list).
- [x] Create `src/Mcp/Resources/EntryPointsResource.php`, `GraphStatsResource.php`,
      `ConfigResource.php` — `richter://` URIs, `application/json`, `handle(Request)`
      returning the content in §3; resource descriptions note the first-read graph build.
- [x] Declare `protected array $resources` on `RichterServer` and note the resources in
      `$instructions`.
- [x] Tests — McpTest additions (`requires-mcp`) via the `RichterServer::resource(…)`
      testing helper: each resource returns parseable JSON with the documented keys;
      entry-points resource lists a fixture entry point with location.

### Phase 6: Agent skills (Priority: HIGH)

**ID:** agent-skills · **Depends:** none

- [x] Add the opt-in `.mcp.json` step to `resources/boost/skills/richter-setup/SKILL.md`
      (§4): laravel/mcp presence check, `composer require --dev` proposal when absent,
      merge-never-overwrite, idempotent, propose-then-confirm.
- [x] Create `resources/boost/skills/richter-review/SKILL.md` (§5) — invoke-only
      frontmatter identical in shape to richter-setup; CLI fallback when MCP is absent;
      advisory framing rules (annotations stay advisory; the skill never gates).
- [x] Tests — none executable (docs); verification = the skill checklist in CLAUDE.md
      ("When adding or editing a fixture, doc example, or spec") plus a boost sync dry-run
      if available locally.

### Phase 7: Entry-point annotations in impact output (Priority: HIGH)

**ID:** impact-annotations · **Depends:** mcp-resources

<!-- The single mcp-resources edge transitively serializes every file this phase touches:
ImpactAnalyzer/formatters/JsonPresenter via trace-core, McpTest via the 3→4→5 chain,
CommandsTest via affected-tests-assembly. -->

- [x] Compose the annotation set for `impact()` — `entryPointsAmong()` over the computed
      callers, `entryPointAnnotations()`, `gatesByEntryPoint()`, `entryPointPathsFor()` —
      per §6's complexity-budget rule (beside-class or thin delegation), with the
      `TestReferenceIndex` built lazily (only when entry points were reached).
- [x] Extend `JsonPresenter::impact()`'s picked keys and `ImpactTool::outputSchema()` in
      the same change (the three-place lockstep, §6) — map-shaped fields per the
      `DetectChangesTool` `object()` pattern — and update the tool description
      (annotations + advisory limits).
- [x] Add the "Entry surfaces reached (N)" section to `ImpactFormatter::impact()` and
      `MarkdownFormatter::impact()`, reusing `entryPointList()`/`entryPointChecklist()`.
- [x] Add `--explain` to `richter:impact` — text/markdown chain rendering only;
      `entryPointPaths` is in the JSON shape regardless.
- [x] Tests — CommandsTest impact additions + McpTest (`requires-mcp`): section renders
      with location/security/gate/test tags; no entry points → the section reads `(none)` (always-on), empty keys, no
      test-index build; no-match `suggestions` path unaffected; `--explain` chains;
      UI-component callers normalise to class; structured content matches the widened
      outputSchema (drift guard extended).

### Phase 8: README documentation (Priority: HIGH)

**ID:** readme-docs · **Depends:** trace-mcp, affected-tests-mcp, mcp-resources, agent-skills, impact-annotations

- [x] Add a `richter:trace` subsection under Usage (example chain, `--json` shape, the
      no-path result).
- [x] Update the "MCP server" section: four tools, the resources table, the
      `determinable: false` contract for agents, unchanged registration snippet.
- [x] Mention `/richter-review` and the `.mcp.json` setup step in the "Set up Richter"
      section (keep the two-paste-prompt structure intact).
- [x] Update the `composer.json` `suggest.laravel/mcp` text (composer.json:125) — it
      currently names only "the impact and detect-changes tools"; after this spec it
      undersells the MCP surface (four tools + resources).
- [x] Document the impact annotations in the README's `richter:impact` section: the entry
      surfaces block, the `--explain` flag, the new JSON keys (named as sharing the
      detect-changes vocabulary), and the carried advisory caveats.
- [x] Tests — none executable; run the repo's docs staleness audit habit (pre-release
      skill covers it) mentally against the new sections: every documented flag and key
      must exist in the shipped code.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The laravel/mcp v0.8 floor supports the same `Resource`/tool API this spec builds
   against** — resources exist at 0.8 (verified: `Server/Resource.php`, `Prompt.php`
   present in the v0.8.0 tree), but the *API shape* (reflective `handle()`, `$uri`,
   `$mimeType`, `PendingTestResponse::resource()`) was verified on v0.9.1 only. If the CI
   prefer-lowest leg breaks on a 0.8 API difference, stop — the fix may be raising the
   floor (a `composer.json` + `conflict` change with consumer impact), which is the user's
   call, not a workaround.
2. **The assembly extraction preserves byte-identical CLI behaviour** — if any existing
   affected-tests feature test — including the Phase 2 characterization test once added —
   needs modification to pass, stop: the seam is wrong, and changing the test would erase
   the regression net this phase depends on.
3. **`callerPathsTo()` serves arbitrary-target trace queries as-is** — if trace needs
   changes to `CodeGraph`'s BFS or walk semantics (not just a new caller), stop:
   `CodeGraph` feeds the cached build path, and the no-FORMAT_VERSION-bump premise of this
   spec no longer holds. Additive read-only accessors (the Phase 5 node enumeration) are
   explicitly fine — only BFS/walk changes or changes to the built/serialized graph shape
   trip this condition.
4. **Boost skill discovery needs nothing beyond the SKILL.md convention** — if shipping
   `richter-review` requires manifest or engine changes in boost-core, stop; that is a
   different package's release train.
5. **The parallel working-tree changes have landed before implementation** — at spec time
   (2026-08-05) `ImpactAnalyzer`, `JsonPresenter`, `ImpactCommand`, `AffectedTests`,
   `README.md`, `composer.json`, `config/richter.php` and the richter-setup skill carry
   uncommitted changes from a parallel work stream (non-`App\` root-namespace support and
   new-file seeding), and this spec's citations — including §6's five-key `impact()`
   shape and key-picking `JsonPresenter::impact()` — were verified against that working
   tree. If implementation starts and those changes are absent, reverted, or further
   drifted, stop and re-verify the baseline before building on it.

---

## Open Questions

None.

---

## Resolved Questions

1. **Trace direction: auto-fallback to reverse, strictly directional, or both directions
   always?** **Decision:** Strictly directional, with upstream-extent diagnostics on the
   no-path result (`furthestReached` + a swap-the-arguments hint). **Rationale:** Matches
   the directed-path semantics of the comparable GitNexus `trace` tool (verified: no
   direction parameter, no fallback, and its no-path answer names the furthest reachable
   node); an agent asking "does the route reach the service" must never receive a reversed
   answer it might misread, and `furthestReached` tells the reader how far upstream
   connectivity extends instead of just that the chain wasn't found — framed as the
   deepest caller reached from `to`, never as a pointer toward the target (the walk has
   no directionality toward it).
2. **Affected-tests MCP failure shape for an unresolvable base ref?** **Decision:**
   `determinable: false` + reasons, never `Response::error` for this cause. **Rationale:**
   Mirrors the CLI's exit-2 fail-safe contract — one result shape for every
   non-determinable cause, and "run the full suite" is the actionable answer; an MCP error
   reads as "tool broke". The divergence from `DetectChangesTool`'s handling of the same
   exception is deliberate and documented in code.
3. **Which MCP resources ship?** **Decision:** All three — entry-point inventory, graph
   stats, effective config. **Rationale:** Together they answer "what surfaces exist, how
   complete is the graph, how is richter configured" without burning tool calls; each is a
   thin presenter over existing data.
4. **Skill scope?** **Decision:** `richter-review` only; no pre-edit impact-check skill in
   this spec. **Rationale:** The pre-edit check is a single `richter:impact` call an agent
   can already make — packaging it adds little until real usage shows the prompt shape
   that helps.
5. **Where does entry-point annotation of `impact` output live, and in what shape?**
   **Decision:** In this spec, as its own phase (`impact-annotations`): an always-on
   "Entry surfaces reached" section reusing the detect-changes renderers; additive
   JSON/MCP keys with the detect-changes vocabulary verbatim, including
   `entryPointAuthGates` and an always-present `entryPointPaths`; `--explain` toggling
   text rendering only; a lazily-built `TestReferenceIndex`. **Rationale:** The feature
   rewrites the same files trace-core and the MCP chain already touch — a separate spec
   would fight this one file-for-file — and vocabulary parity means an agent parses both
   reports identically. Adopted from the dedicated research and its evaluation
   (2026-08-05), which the user accepted by asking for the feature to be added here.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **trace-core (2026-08-05):** Implemented as specified. One nuance the spec's JSON
  example glossed: a shortest chain may end on a to-side *member* node rather than the
  class node (`App\Jobs\ProcessPostJob::handle` — the dispatch edge targets `handle`);
  `resolvedTo` carries both, and the feature test asserts membership in `resolvedTo`
  instead of a literal class-node ending. `richter:trace` reuses `WarnsAboutRootNamespace`
  (the F1 stderr note) like the sibling commands.
- **affected-tests-assembly (2026-08-05):** The spec's precedence claim for the both-fail
  corner was **mis-traced** (inherited from a review finding) and is corrected in §2 and
  Phase 2: `baseRef()` validates only argument shape, so an unresolvable-but-well-formed
  ref fails in `resolve()` — *after* the untracked short-circuit — meaning the untracked
  reason wins that corner. The characterization tests
  (`affected_tests_untracked_file_wins_the_reason_over_an_unresolvable_base`,
  `affected_tests_a_malformed_base_wins_the_reason_over_an_untracked_file`) pinned actual
  behaviour before the refactor; the extraction preserves it.
- **impact-annotations (2026-08-05):** Implemented as specified, with one vocabulary note:
  `entryPointAuthGates` is in impact's JSON/MCP contract per the ledger, although
  detect-changes' own JSON document does not carry that key (there it renders in
  text/markdown only) — a deliberate, additive asymmetry; adding the key to
  detect-changes later would be non-breaking. The composition stayed inside `impact()`
  as thin delegation (four existing private methods + the cross-check beside-class) —
  no new companion class needed. **Deviation (documented):** `ImpactAnalyzer` sat at
  exactly its 80-point complexity budget before this spec, so the checked P5 task
  "public entry-point enumeration on ImpactAnalyzer" could not land there — the trace
  body lives in a new `SymbolTracer` beside-class (the analyzer's `trace()` delegates,
  public API unchanged), and the inventory composition lives in its only consumer
  (`EntryPointsResource`), single-sourced through two new `@internal`-public analyzer
  predicates (`isEntryPointNode()`, `uiComponentClassOf()`). Also: the pre-existing
  `JsonPresenterTest` impact fixtures were updated to the widened analyzer shape — a
  deliberate contract change, not a regression-net violation (STOP 2 covers the
  affected-tests suite, which passed unmodified). A second small deviation surfaced in
  the final fresh-eyes review: the zero-entry-surface case renders an explicit
  `Entry surfaces reached (0)` + `(none)` section instead of the edge-row's original "no
  section rendered" — an explicit absence reads as a checked fact, a missing section
  reads as a missing feature. Code, tests, and README agree; the edge row is updated.
