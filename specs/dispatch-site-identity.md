# Dispatch-site identity

<!-- spec:planned-at 96d0b0ec01d886ab2ff1eac9f000e896197360b9 2026-08-13 -->

## Overview

An unfollowable job dispatch — one whose target is a variable, factory call, or closure — currently
survives the tracer as nothing but a counter increment. `richter:affected-tests` therefore reports
`the graph contains job dispatches that could not be followed` and exits 2, naming no site the reader
could go and look at. A project with one such dispatch gets that verdict on every run forever, and
runs its full suite forever.

This carries per-site identity (defining file, line, and the dispatching member) from the tracer
through to `CodeGraph`, so the reason can name the sites and a project can act on them.

## Assumptions

<!-- Filled by the Assumptions Audit. Each bullet is one AI-introduced inference, so the spec can be
     signed off by reading this section alone. -->

- **Site identity is already available at every increment site and only discarded.** Verified:
  `DispatchEdgeTracer.php:97` builds the dispatching member (`Class::method`) before descending, the
  `Node` handed to `jobsFromCall()` carries `getLine()`, and the consolidated builder loop
  (`CodeGraphBuilder.php:355`) knows the file it is reading. Nothing new has to be discovered.
- **Per-site identity does NOT enable tighter taint scoping.** Traced, and it changed this spec's
  design: the hidden edge is `dispatcher → <unknown>::handle`, so the only sound refinement is on the
  *target* side, and `AffectedTests::changeReachesDispatchable()` (`:298`) already applies exactly
  that one. Identity buys a nameable site and an acknowledgement key — not precision. An earlier
  framing of this work as "scope the taint per dispatcher" is therefore not implementable as stated.
- **`CodeGraph`'s `bool $hasUnresolvedDispatches` constructor parameter is replaced by the site list**,
  with `hasUnresolvedDispatches()` kept and derived from `!== []`. Chosen: a redundant bool alongside
  the list is a second source of truth that can disagree. This is a public-symbol signature change on
  a pre-1.0 package.
- **`GraphCache::FORMAT_VERSION` is bumped** because the cached payload's shape changes. Follows the
  repo's own rule; a consumer rebuilds once.
- **The rendered site list is capped at 15 with an `… and N more` tail.** Not invented: this follows
  the existing `LIST_CAP` in `ImpactFormatter.php:20` and `MarkdownFormatter.php:22`, and reuses their
  wording, so a capped list reads the same wherever it appears.
- **No acknowledgement mechanism ships.** Decided — see Resolved Questions 1.

---

## 1. Current state

`DispatchEdgeTracer` accumulates an `int` by reference and returns it as a bare count:

```php
// src/Tracers/DispatchEdgeTracer.php:62
/** @return array{edges: list<array{source: string, target: string, type: string}>, unresolved: int} */
```

It is incremented at four sites — an opaque single argument (`:259`), a non-array group argument
(`:268`), an opaque item inside a `chain`/`batch` (`:284`), and a non-`Name` class in `new` (`:294`).
Each one knows its dispatching member and its line; neither survives the `++`.

From there the count is summed (`CodeGraphBuilder.php:355`), carried across the child-process boundary
as an int (`TracerBranchRunner.php:101-103`, validated `is_int` and `>= 0`), collapsed to a bool
(`CodeGraphBuilder.php:189`), stored as a bool on `CodeGraph` (`:56`), serialised as a bool
(`:160`, `:168`), read back as a bool (`GraphCache.php:271`), and finally consumed as a bool:

```php
// src/Analysis/AffectedTests.php:140
if ($hasUnresolvedDispatches && self::changeReachesDispatchable($result, $changed)) {
    $reasons[] = 'the graph contains job dispatches that could not be followed';
}
```

`src/Mcp/Resources/GraphStatsResource.php:36` exposes the same bool.

There is no acknowledgement mechanism for a dispatch site. `payload_parity.ignore`
(`config/richter.php:190`) is the only per-finding suppression the package has, and it does not
extend here.

## 2. Proposed changes

### 2.1 The site record

One shape, threaded unchanged from tracer to graph:

```php
/** @var list<array{file: string, line: int, dispatcher: string}> */
```

`dispatcher` is the `Class::method` node id the dispatch was found in — the same id shape the graph
already uses, so a report can point at a node the rest of the tool understands. `file` is
project-relative, matching every other location the reports print.

`edgesForMethods()` cannot know the file (it receives methods and an FQCN), so it returns sites with
`file` unset and the consolidated loop stamps it, the same way that loop already owns the file path.

### 2.2 Ordering and cap

The list is sorted by `file`, then `line`, before it reaches `CodeGraph`. Two reasons: a report that
names sites must not reorder between runs, and the cached payload has to be byte-stable or the golden
graph test and the cache fingerprint both start flapping.

A cap applies at render time, not at capture time — the graph keeps every site so the count stays
honest, and the reason names the first 15 with an `… and N more` tail, matching `LIST_CAP` and its
wording in `ImpactFormatter.php:20` / `MarkdownFormatter.php:22`.

### 2.3 The reason

```
the graph contains job dispatches that could not be followed:
  app/Services/Importer.php:42 (App\Services\Importer::run)
  app/Jobs/Fanout.php:88 (App\Jobs\Fanout::handle)
```

The exit-code contract is unchanged: this is still exit 2, still "run the full suite". The only
difference is that the reader now knows where to go.

### 2.4 No acknowledgement mechanism

A config key suppressing a named site is **deliberately not part of this change** (Resolved Question 1).
Suppressing the taint means asserting a hidden edge is harmless, and a wrong assertion under-selects
the test run — the one direction `affected-tests`' exit-code contract exists to prevent. With the site
named, the remedy is to restructure the dispatch into a form the tracer can follow, which is a fix
rather than a silenced warning.

This is the whole reason the change is worth making on its own: naming the site is what makes that
remedy available at all. It does not, by itself, let a project with a genuinely dynamic dispatch get a
scoped test run — that project still runs its full suite, correctly.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Two dispatch sites on the same line (a chain with two opaque items) | Both recorded; the pair is de-duplicated on `(file, line, dispatcher)` so the count matches distinct sites, not increments. Covered by `site-capture` Tests. |
| The same file traced twice (two changed files resolving to one class) | Cannot arise: the builder loop visits each app file exactly once, so no site is produced twice. De-duplication runs inside the tracer on `(dispatcher, line)` — enough for the real case, two opaque items of one statement. Two *files* declaring one FQCN would yield two records differing by `file`, which are genuinely two places and correctly kept. |
| A site in a file the parser could not read | Never reached — an unparseable file yields no AST and contributes no dispatch site. The separate `hasUnparseableFiles` taint already covers it. Covered by `site-capture` Tests. |
| The child tracer process returns a malformed site list | `TracerBranchRunner` validation rejects the payload and the build falls back to serial, exactly as it does for a malformed int today. Covered by `graph-surface` Tests. |
| A cache entry written by an older format version | Unreachable: `FORMAT_VERSION` is fingerprinted, so an older entry misses and is rebuilt. Covered by `graph-surface` Tests. |
| Zero sites | `hasUnresolvedDispatches()` returns false, the reason is not added, and the JSON key is an empty list rather than absent. Covered by `reason-naming` Tests. |
| Very many sites (a project dispatching dynamically throughout) | The graph holds all of them; the rendered reason truncates per §2.2. Covered by `reason-naming` Tests. |

## Implementation

### Phase 1: Capture the sites (Priority: HIGH)

**ID:** site-capture · **Depends:** none

- [x] Replace `int &$unresolved` with a site collector across `jobsFromCall()`, `jobsFromArg()`, `jobsFromArray()` and `jobFromNew()` — each increment becomes a record carrying the dispatching member and the call's line.
- [x] Return `unresolvedSites` from `edgesForMethods()` / `edgesForResolvedAst()` / `edgesForSource()` in place of `unresolved` — the count becomes derivable, so nothing needs both.
- [x] Stamp the file in the consolidated builder loop and de-duplicate on the full triple — `CodeGraphBuilder.php:355` already holds the path.
- [x] Sort by file then line before the branch result is assembled, per §2.2.
- [x] Tests — each of the four increment sites yields one record with the right member and line; a chain with two opaque items yields two; a repeated file yields one; an unparseable file yields none.
- [x] **Pulled forward from `graph-surface`:** the IPC contract, because it is the same edit — see Findings.

### Phase 2: Carry them to the graph (Priority: HIGH)

**ID:** graph-surface · **Depends:** site-capture

- [x] Widen the `TracerBranchRunner` IPC contract to carry the site list, with validation as strict as the current `is_int` / `>= 0` pair — a malformed list must fall back to the serial build, never reach the graph. *(Landed in `site-capture` — see Findings.)*
- [x] Replace `CodeGraph`'s bool constructor parameter with the site list; keep `hasUnresolvedDispatches()` deriving from it, and add an accessor for the sites.
- [x] Update `toArray()` / `fromArray()` and bump `GraphCache::FORMAT_VERSION` (15 → 16).
- [x] Surface the sites on the MCP graph-stats resource beside the existing honesty flags, and update its description.
- [x] Tests — a graph round-trips its sites through the cache byte-identically; a malformed child payload falls back; `hasUnresolvedDispatches()` still answers correctly at zero and non-zero; the golden graph test still passes.

### Phase 3: Name them in the reason (Priority: HIGH)

**ID:** reason-naming · **Depends:** graph-surface

- [x] Render the sites into the `affected-tests` non-determinable reason per §2.3, truncating per §2.2.
- [x] ~~Carry the sites in the `--json` payload~~ — **dropped, see Resolved Questions 3.** The reason string carries them and the MCP graph-stats resource carries the structured form; no payload key, so no fan-out.
- [x] Tests — the reason names the sites in file/line order; zero sites adds no reason and emits an empty list; the exit code is unchanged at 2; a truncated list reports the remainder.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The dispatching member and line are available at all four increment sites.** If any site turns
   out to lack one, the record for it would be partially blank and the reason would name a place the
   reader cannot open — decide the shape with the user rather than emitting an empty field.
2. **Widening the IPC payload does not break the parallel build's fallback.** The child process is a
   real `artisan` invocation; if the larger payload hits a pipe or serialisation limit the build must
   still fall back to serial rather than fail.
3. **`CodeGraph`'s constructor signature change breaks no internal caller left unmigrated.** It is a
   public symbol; if anything outside the migrated set constructs it positionally, stop and reconcile.

---

## Open Questions

None.

---

## Resolved Questions

1. **Should a project be able to acknowledge a named dispatch site and have it stop tainting the
   selection?** **Decision:** No — identity only; no config key, no suppression, no downgrade marker.
   **Rationale:** Acknowledgement is an assertion that a hidden dispatch edge is harmless, and a wrong
   assertion silently under-selects tests. Every other part of `affected-tests` fails toward running
   more, and a suppression key would be the single exception to that. Naming the site makes the real
   fix — restructuring the dispatch into a followable form — available, which is a repair rather than
   a silenced warning. Accepted consequence: a project with a genuinely dynamic dispatch keeps running
   its full suite.
2. **How many sites should a rendered reason name before truncating?** **Decision:** 15, with an
   `… and N more` tail. **Rationale:** Not a new number — `ImpactFormatter.php:20` and
   `MarkdownFormatter.php:22` already cap rendered breadth lists at 15 with that exact wording, on the
   reasoning that a long list buries the signal. Following it keeps every capped list in the reports
   reading the same.
3. **Should the `affected-tests --json` payload gain a structured `unresolvedDispatchSites` key?**
   **Decision:** No. **Rationale:** Surfaced during implementation, not at spec time. The reason
   string already names every site, and `graph-surface` put the structured form on the MCP
   graph-stats resource. A key here would make this the only one of six non-determinable reasons
   carrying structure while the rest stay prose — and buy that inconsistency with a fan-out across
   both `@return` shapes, the command, the MCP output schema and the ordered-key contract test.
   Structuring *all* the reasons is the coherent version of the idea and is a breaking payload
   change of its own, out of scope here.

## Findings

- **The `site-capture` / `graph-surface` boundary did not hold, and the IPC half moved into phase 1.**
  The phases were split as "tracer + builder" then "IPC + graph + cache", but the branch result's
  payload shape *is* the tracer's return shape — renaming `unresolved` to `unresolvedSites` breaks
  `TracerBranchRunner` in the same edit. Keeping them apart would have left the tree red at the phase
  boundary. Phase 1 therefore also carries the widened IPC contract and its `isSiteList()` validator;
  `graph-surface` keeps `CodeGraph`, the cache format and the MCP resource. Documented deviation, not
  a scope change — the task list is the same, only its grouping moved.
- **The site is the dispatch statement, not the opaque sub-expression.** The spec left this implicit
  by saying "the call's line". Made explicit in code: two opaque items of one `Bus::chain([...])` are
  one place a reader opens, so they de-duplicate to a single site, while two separate `dispatch()`
  statements in one method stay two. Both directions are tested.
- **`Node::getLine()` is deprecated in php-parser 5** in favour of `getStartLine()`; the spec's
  research cited the deprecated name. No behavioural difference.
- **`CodeGraph` still takes its bool** at the end of phase 1 — the builder derives it with
  `!== []`. Replacing the parameter is `graph-surface`'s task, so the tree stays green in between.
- **Inserting a method before an existing one stole its docblock — twice.** `validDispatchSites()`
  landed between `validEdges()`'s docblock and its signature, and `renderSites()` did the same to
  `changeReachesDispatchable()`. PHPStan caught both (a suddenly untyped return). Anchor on the
  previous method's closing brace, not on the next method's signature.
- **Rector rewrote the site validator into an unreadable double negative** (`ForeachToArrayAllRector`
  turning the guard loop into `array_all(… fn => !(! … || ! … || …))`). Rector must report zero
  changed files, so the loop was rewritten as a positive `array_all` predicate rather than accepting
  the generated form — same shape Rector wants, readable.
- **The reason is one line, not the indented block §2.3 sketched.** A reason is a single string in a
  `list<string>` that the text, JSON and MCP paths each render their own way; embedding newlines in
  one would break the plain-list rendering the other reasons rely on. Rendered as
  `…followed: app/Jobs/Fanout.php:88 (App\Jobs\Fanout::handle), app/Services/Importer.php:12 (…)`.
  Same information, same order; the spec's example was illustrative of content, not of layout.
- **The `--json` payload key was raised, then dropped.** Writing the sites into
  the `affected-tests` document would make it the only one of six non-determinable reasons carrying
  structure, while the rest stay prose — a lopsidedness the spec did not weigh, and a real fan-out
  (both `@return` shapes, the command, the MCP output schema, the ordered-key contract test). The
  structured form already exists on the MCP graph-stats resource as of `graph-surface`. Raised rather
  than decided either way.
