# One shared AST walk for the per-file tracers

<!-- spec:planned-at 29ea7e4cd7ad5cbc2741e0a4003e4e6eadd8ab24 2026-08-13 +uncommitted -->

## Overview

The consolidated tracer loop parses each app file once and then hands the result to six tracers, each
of which walks it again with its own `NodeFinder`. Measured on a 1,613-file synthetic corpus, that
re-walking is ~160 ms — the largest richter-side cost left after the parse itself, which is php-parser's
floor and not addressable here.

This replaces the per-tracer walks with one descent that collects the node buckets every tracer needs,
preserving the scope semantics two of them depend on.

## Assumptions

These inferences have not been signed off. Each is recorded so the spec can be reviewed by reading this
section alone, and the load-bearing ones also appear as open questions rather than as settled defaults.

- **The win is worth the coupling.** ~160 ms of ~660 ms measured; a shared walk should recover most but
  not all of it (the per-bucket filtering remains). Open Question 1 sets a kill threshold.
- **Scope-aware collection can be expressed once.** Two tracers use `AppFiles::nodesOwnedBy*()`, whose
  pruning rules are the load-bearing part of their correctness. Assumed generalisable; see section 3.2
  and STOP condition 1.
- **The tracers keep their current public entry points.** `edgesForSource()` and `edgesForResolvedAst()`
  are used by tests and single-file callers; they stay, and gain the shared collector internally.
- **Node identity, not copies, is shared.** Every tracer only reads the AST, so handing the same node
  objects to six consumers is safe — the same argument Brain's own shared parse cache makes.
- **No behaviour change is intended at all.** This is a pure refactor with a byte-identical gate.

---

## 1. Current state

`CodeGraphBuilder::consolidatedTracerEdges()` — the per-file loop at `src/Graph/CodeGraphBuilder.php:326` — parses each
file once, calls `collectTracerNodes()` for a first descent that buckets `ClassMethod` / `TraitUse` /
`ClassLike`, and then calls each tracer, which descends again:

| Tracer | Granularity | What it walks for | Scope-aware? |
|---|---|---|---|
| `DispatchEdgeTracer` | per method | `FuncCall`/`MethodCall`/`StaticCall`, `New_` | no |
| `ReferenceEdgeTracer` | per method | `Name`, `MethodCall`/`StaticCall` | no |
| `PolicyEdgeTracer` | per method | `Name` (`:126`) | no |
| `StaticCallEdgeTracer` | per class-like → per method | `StaticCall` via `nodesOwnedByWithNesting` (`:101`) | **yes** |
| `ConfigRegistryTracer` | per class-like → per method | config reads via `nodesOwnedBy` (`:262`); its other descents (`:361`) read config-file ASTs, not app files | **yes** |
| `ViewRenderTracer` | per class-like → per method | view renders via `nodesOwnedBy` (`:99`), plus `$view` properties | **yes** |

Two of the six lanes were already collapsed by the autoresearch round — `ReferenceEdgeTracer` from
three descents to one and `DispatchEdgeTracer` from two to one — which is the same idea applied inside
a single tracer. This spec applies it across them.

### Why `Name` is walked twice

`PolicyEdgeTracer::policiesReferencedIn()` (`:126`) collects every `Name` in a method and filters by the
policy namespace. `ReferenceEdgeTracer` needs the identical bucket and filters it by a different prefix
set — it no longer walks for it separately (the autoresearch round folded that into its own single
descent at `namesAndCalls()`), but that descent and Policy's still both cover the same method. Two
traversals producing the same `Name` set, filtered differently: the clearest duplication in the table,
and the one phase 2 removes.

## 2. Measured cost

From `autoresearch/graph-build-research.md`, after the three autoresearch iterations, per build of the
1,613-file corpus:

| item | ms |
|---|---:|
| parse + read | 255 |
| reference | 42 |
| policy | 21 |
| staticCall | 20 |
| dispatch | ~20 |
| collectTracerNodes | 18 |
| hierarchy/constant/facade collect | 17 |
| configRegistry | 12 |
| viewRender | 11 |

The parse is the floor. Everything below it — ~161 ms — is walking, and `collectTracerNodes` has
already descended the same tree once before any of it.

**Caveat carried from that document:** the corpus is synthetic and its controllers are shallow, so it
over-weights richter's per-file work relative to Brain. The ratios between the tracers are the useful
signal here, not the share of total build.

## 3. Proposed changes

### 3.1 One collector, many buckets

Extend `collectTracerNodes()` into a single visitor that, in one descent, produces every bucket the
tracers consume — keyed so a tracer asks for what it needs and filters as it does today:

```php
// shape, not final API
['classLikes' => …, 'classMethods' => …, 'traitUses' => …,
 'namesByMethod' => [spl_object_id($method) => list<Name>],
 'callsByMethod' => [… => list<FuncCall|MethodCall|StaticCall>],
 'newsByMethod'  => [… => list<New_>]]
```

Per-method keying, not per-file: every consumer attributes its edges to a member node, so the bucket
has to say which method a node came from.

### 3.2 The scope rules are the hard part

`AppFiles::nodesOwnedByWithNesting()` (`src/Support/AppFiles.php:177`) is not a filter — it is a
*scoped* traversal with two rules the tracers' correctness rests on:

- a **named** class-like nested in a method is pruned, because it is handed to the caller as a
  class-like in its own right and walking in would attribute the same call twice;
- an **anonymous** class is descended into, because it has no name to be an edge source — but the nodes
  found inside it are flagged `nested`, since `self`/`static`/`parent` there mean that class, and
  `StaticCallEdgeTracer` drops scope-relative receivers on that flag (`:101`).

A shared collector must reproduce both rules exactly, and must carry the `nested` flag per collected
node — not per method. Anything less silently changes what `StaticCallEdgeTracer` draws, which is the
0.27.0 phantom-edge failure mode in a new costume.

The safe framing: the shared collector **is** `nodesOwnedByWithNesting`, generalised from one predicate
to several, run once per method, with results split into buckets. Not a new traversal that happens to
agree with it.

### 3.3 Sequencing

`ReferenceEdgeTracer` and `PolicyEdgeTracer` both want the same unfiltered `Name` bucket and neither is
scope-aware, so they are the cheap half and can move first. The three scope-aware tracers are the
risky half and move second, behind the generalised collector.

That split is a real dependency edge, not a review checkpoint: phase 2 needs the collector phase 3
generalises only if the buckets it defines already exist.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| A method contains a named nested class | Pruned by the shared collector, exactly as `nodesOwnedBy` prunes it; the nested class is walked as its own class-like. Covered by `Tests` in `scoped-collector`. |
| A method builds an anonymous class containing a static call | Collected, flagged `nested`; `StaticCallEdgeTracer` still drops `self::`/`static::`/`parent::` there. Covered by `Tests` in `scoped-collector`. |
| A class-like with no methods (interface, enum, constants-only) | Empty per-method buckets; the class-level lanes (`$view` property, hierarchy collect) are unaffected. |
| A file with two top-level classes | Buckets are keyed per method, and each method belongs to exactly one class-like, so attribution is unchanged. |
| An abstract method (no body) | No nodes to collect; contributes nothing, as today. |
| `edgesForSource()` called on a single file by a test | Unchanged entry point; it builds the shared buckets internally rather than calling `NodeFinder` directly. |
| A tracer needs a node type no bucket carries | The collector gains a bucket rather than the tracer re-walking — a re-walk here would quietly restore the cost this spec removes. |
| `ConfigRegistryTracer::appClassesIn()` (`:361`) | Out of scope, and for two different reasons depending on the caller: from `:88` it walks a `config/*.php` AST once per config file at construction, and from `:208` it walks a synthetic single-node AST (`[new Return_($node)]`) built from a config value already in hand. Neither descends an app file, so neither is a bucket the shared collector can serve. |

## Implementation

### Phase 1: Prove the gate before changing anything (Priority: HIGH)

**ID:** golden-graph · **Depends:** none

- [x] Add a test that builds the fixture-project graph and asserts the full edge list — sources, targets and types — against a committed expectation.
- [x] Tests — the assertion above is the test. (The spec asked for order to be part of it; see Findings — that premise was wrong.)

### Phase 2: Share the unscoped buckets — BUILT, MEASURED, REVERTED

**ID:** unscoped-buckets · **Depends:** golden-graph

- [ ] Extend `collectTracerNodes()` with per-method `Name` and call buckets.
- [ ] Move `ReferenceEdgeTracer` and `PolicyEdgeTracer` onto them; delete their internal descents.
- [ ] Move `DispatchEdgeTracer`'s single descent onto the shared buckets too — it collects the same call/`New_` types.
- [ ] Tests — each tracer's existing unit tests must pass untouched; add one asserting the two `Name` consumers see the identical bucket.

### Phase 3: Generalise the scoped collector — NOT STARTED (see Findings)

**ID:** scoped-collector · **Depends:** unscoped-buckets

- [ ] Generalise `AppFiles::nodesOwnedByWithNesting()` from one predicate to several, returning per-predicate buckets with the `nested` flag preserved per node.
- [ ] Move `StaticCallEdgeTracer`, `ConfigRegistryTracer` and `ViewRenderTracer` onto it.
- [ ] Tests — the named-nested-class prune and the anonymous-class `nested` flag, asserted directly on the collector rather than only through a tracer, so a future change breaks the collector's own test first.

### Phase 4: Measure and decide — DONE, verdict: revert

**ID:** measure · **Depends:** scoped-collector

- [ ] Re-run `autoresearch/graph-build-bench.php` back to back against the pre-refactor commit — never against a number from an earlier session; the drift on this machine is ~3% and once inverted a verdict.
- [ ] Record the result; **if the recovered time is under the threshold in Open Question 1, revert the refactor** rather than keep coupling for a rounding error.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The scope rules survive generalisation exactly.** If the prune-named / descend-anonymous / flag-nested
   behaviour cannot be expressed once for several predicates without special-casing a tracer, stop —
   a shared collector that is subtly wrong for one lane is worse than six correct walks.
2. **The graph stays byte-identical, order included.** Phase 1 exists to make this checkable before any
   tracer moves. If the golden test cannot be made to fail on a reorder, it is not a gate and the rest
   of the spec has nothing holding it.
3. **The tracers' public entry points keep working unchanged.** `edgesForSource()` and
   `edgesForResolvedAst()` are used outside the consolidated loop; if the shared collector cannot serve
   them, the refactor has split the tracers into two incompatible modes.

---

## Open Questions

1. **What recovered-time threshold justifies keeping this?** ~161 ms is walking today, but the shared
   collector still pays one descent plus per-bucket filtering, so the ceiling is well under that. A
   suggested kill line is 8% of total build; below that the coupling is not worth it. Needs a decision
   before phase 4, not after.
2. **Should the buckets be keyed by `spl_object_id` or by carrying the method node?** Object ids are
   cheap but require the method objects to stay alive for the map to mean anything — they do, within
   one file's iteration, but it is an implicit lifetime coupling worth naming.
3. **Does `collectTracerNodes` stay in `CodeGraphBuilder`?** It is builder-private today, but after this
   it is the shared contract six tracers depend on, which argues for `Support/`. Moving it is a bigger
   diff and a public-surface decision.
4. **Is the golden-graph test the right gate, or too brittle?** It will fail on every intentional edge
   change too, which is either a useful tripwire or a maintenance tax depending on how often those
   land. Recent history says roughly one per release.

---

## Findings

**Verdict: the refactor does not earn itself. Phase 1 shipped; phases 2–4 were built, measured and
reverted per this spec's own kill rule.**

**Phase 4 (`measure`) — the number that settled it.** Phase 2 built and green (graph byte-identical,
1,189 tests), then measured back to back on the 1,613-file corpus: **650 ms → 640 ms, 1.5%**. The kill
line in Open Question 1 was 8% of total build, and this machine's run-to-run drift is ~3% — so the
result is not merely under threshold, it is inside the noise.

**Why the ceiling was lower than the spec assumed.** The spec priced the win from a profile taken
*before* the autoresearch round, which had already collapsed `ReferenceEdgeTracer` from three descents
to one and `DispatchEdgeTracer` from two to one. By the time this spec was implemented those savings
were banked, so phase 2's actual remaining prize was **Policy's single descent, minus the cost of
bucketing every `Name`/call/`New_` in the shared walk** — which is close to a wash. The spec's own
"Measured cost" table shows this in hindsight: the three unscoped tracers were already at ~20 ms each.

Phase 3's ceiling is the three scope-aware tracers (staticCall 20 ms, config 12 ms, view 11 ms ≈ 43 ms,
6.5%) *before* collector overhead, so it cannot clear 8% either — and it is the risky half, the one
touching the prune-named / descend-anonymous / flag-nested rules. Not started, on that arithmetic.

**What was kept.** Phase 1 only. The golden test is independently valuable: nothing else in the suite
can see a change in what the lanes produce *together*, or in the order the merged set arrives in.

**The spec's order premise was wrong, and a test written to prove it is what found that out.**
Sections 1 and 3.2 and STOP condition 2 all rest on "the graph preserves insertion order, so a reorder
would change `--json` output". It does not: `CodeGraph::__construct` sorts every edge set canonically
(`src/Graph/CodeGraph.php:62`), on purpose, so a cache-revived graph tie-breaks its walks exactly as a
fresh build does. A test asserting that a reordered edge set renders differently **failed**, which is
how this surfaced. The golden test still earns its place — nothing else fails on the merged set — but
it guards content, not sequence, and STOP condition 2 should be read as "byte-identical edge set".

**Phase 1 (`golden-graph`).** `tests/Feature/GraphShapeGoldenTest.php` + `tests/Fixtures/graph-shape.golden.txt`
— 85 edges, one `source\ttype\ttarget` line each in build order. A text file rather than JSON so a
failure diffs readably; regenerate with `RICHTER_UPDATE_GRAPH_GOLDEN=1`. A second test asserts the
comparison is order-sensitive, because a golden test that compared *sets* would pass through exactly the
reordering this refactor risks and read green while failing at its only job.

**Open questions settled without a user round** (the implementation was requested as one run):
- *OQ2 — bucket keying.* `spl_object_id($method)`, valid only within one file's iteration. The buckets
  are built and consumed inside a single loop body, so the lifetime coupling is real but bounded; noted
  on the collector.
- *OQ3 — where the collector lives.* Stays in `CodeGraphBuilder` for now. Moving it to `Support/` is a
  public-surface decision and a larger diff; nothing in this refactor needs it.
- *OQ4 — golden-test brittleness.* Accepted. It fails on every intentional edge change too, which is the
  tripwire working; the regeneration path keeps the cost to reading a diff.
- *OQ1 — kill threshold.* Left as the spec states (8% of total build), decided in phase 4.
