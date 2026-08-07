# Second-hop body walk

<!-- spec:planned-at a3473c28d25ca9471a82e56f15341d3dc69d492c 2026-08-07 -->

## Overview

A class richter places in the graph through its own `static-call` edge is a leaf: the node exists,
but nothing ever reads its method bodies, so whatever it calls is invisible. This adds one pass that
walks those bodies — the *called methods only* — and feeds the resulting edges back through the rest
of `build()`, which is what makes the inherited-method edge downstream of them appear too.

Two reported recall gaps close on the same change, and they were one cause all along: a `new X`
inside a statically-called method drawing no edge, and an inherited method's work never becoming
reachable through the subclass.

## Assumptions

- **Candidate set is `static-call` edge targets, at method granularity.** Measured, not chosen: on a
  4,159-file application, walking every app class costs ~78 s, adding `override` targets costs ~41 s,
  `static-call` target *classes* cost ~8.0 s, and `static-call` target *methods* cost ~4.5 s. Only the
  last is affordable, and it is also the most correct — a method nobody calls does not need walking.
- **`override` targets are excluded.** 2,155 of 4,091 app classes are an `override` target; walking
  them is route (b) under another name. Consistent with `override` already being risk-excluded as an
  over-approximation.
- **`inherits` targets are not a candidate source.** They do not exist yet when this pass runs —
  `ClassHierarchyTracer::inheritedEdgesFor()` is the *last* step of `build()` (`CodeGraphBuilder.php:160`)
  and is precisely what this pass feeds.
- ~~**Depth is 1 by default**, ~~**configurable as a number**~~, and **reaching the cap emits a stderr
  note**~~ — all three superseded during implementation; both rested on a model the code contradicts.
  See Resolved Questions 1 and 2, and Findings. The walk is a single round behind a boolean
  `richter.second_hop`, and emits no note.
- **The pass runs in the parent process, after the branch merge.** It needs the merged edge set to
  know what was placed, and the branch's retained ASTs live in a child process. Re-parsing the
  candidate files in the parent is what the ~4.5 s measurement already covers.
- **`GraphCache::FORMAT_VERSION` 8 → 9.** The pass only ever adds edges, so a stale entry would be
  served to new code and under-select.
- **The already-walked filter covers richter's own walk only, never Brain's.** Brain emits its
  call-chain edges prefixed (`action-to-service`, `command-to-…`), richter's `EntryPointTracer` emits
  the bare type. Only the bare types are unambiguous evidence of a body walk: `action-to-job` is
  emitted by Brain's call chain *and* by richter's per-file dispatch tracer, which reads no body — so
  a filter matching prefixed types would mark a class as walked when it was not, which is the bug
  this pass exists to fix. Measured saving on the unambiguous half: 37 of 1,080 methods (3.4%). A
  candidate Brain already walked is therefore re-walked; that is idempotent (deduped) and costs
  ~4 ms. How large that overlap is could not be measured here — Brain could not run against the
  measured application — so Phase `note`'s counter is what will size it.
- **Measurements are n=1 application, one machine, single iteration.** The application measured is not
  the one the findings were reported against; absolute seconds will differ, the ratios are the point.

---

## 1. Current state

`CodeGraphBuilder::build()` assembles the graph in a fixed order:

1. Brain's route-anchored analysis, canonicalised (`CodeGraphBuilder.php:76`).
2. Branch B — richter's own per-file tracers — merged in (`:130`).
3. Id rewrites: short controller ids, middleware aliases (`:142-143`).
4. `memberDeclarationEdges()` (`:149`), `declaresEdges()` (`:153`).
5. `inheritedEdgesFor()` over the whole merged set (`:160`).

A method body is read for the calls it makes in exactly two places: Brain's call-chain analysis,
which is anchored on routes, and `EntryPointTracer::trace()` (`EntryPointTracer.php:86`), which walks
every method of every class under `richter.entry_point_roots`. Neither covers a class that only
entered the graph as the target of a `static-call` edge.

Verified by controlled contrast on a throwaway application, full build including Brain:

| construction site | enclosing method | edge produced |
|---|---|---|
| `new Dto()` in a route-reachable controller | instance | **yes** (`action-to-service`) |
| `new Dto()` in a class reached only by a static call | static | no |
| `new Dto()` in a class reached only by a static call | **instance** | **no** |

Instance versus static makes no difference; reachability does. The inherited-method gap follows from
the same cause: `inheritedEdgesFor()` only emits for member nodes already present in the edge set
(`ClassHierarchyTracer.php:146-175`), so if the intermediate body is never walked, the subclass
member node never appears and no `inherits` edge is drawn.

## 2. Proposed change

A new pass between step 2 and step 3 above. Placing it *before* the rewrites is load-bearing: its
edges must go through `memberDeclarationEdges()`, `declaresEdges()` and `inheritedEdgesFor()` like
any other, and the last of those is what closes the inherited-method gap.

**Candidate selection**, over the merged edge set:

```
candidates = { target of every `static-call` edge }
            − { any node already the source of a bare-typed walk edge }
```

Every `static-call` target names a member by construction (`StaticCallEdgeTracer.php:105` emits
`"{$target}::{$callee}"`), so no membership guard is needed.

The bare walk types are the ones `EntryPointTracer::traceMethod()` passes through from Brain's
`CallChainEdge`: `service`, `repository`, `model`, `job`, `event`, `action`, `view`, `mail`,
`notification`, `enum`, `interface`, `trait`, `abstract_class`, `resource`, `references`,
`validates-with`. Deliberately *not* Brain's own prefixed forms (`action-to-service` and friends):
`action-to-job` is emitted both by Brain's call chain and by richter's per-file dispatch tracer,
which reads no body, so matching prefixed types would skip classes that do need walking.

**The walk itself needs no new machinery.** `EntryPointTracer::traceMethod()` (`:135`) already takes
`(fqcn, method, psr4, projectRoot)` and returns FQCN-keyed edges; it is root-agnostic. It is private,
so this pass needs it exposed — a public method taking a list of `FQCN::method` nodes, keeping one
copy of the try/catch-and-skip behaviour rather than a second.

**One round, behind `richter.second_hop` (default `true`).** Not an iteration: `StaticCallEdgeTracer`
runs per file over the whole app, so every static call is already an edge before the walk starts. In
a chain `A::x → B::y → C::z` both edges exist up front and both targets are candidates in the same
round — and a walked body yields Brain's call-chain vocabulary, never another `static-call`, so a
second round has nothing to find. Verified during implementation, not assumed.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Candidate FQCN maps to no file (a PSR-4 root other than `app/`) | Path derivation misses, parse returns null, candidate skipped — same as `methodsOf()` today. Covered by Phase `walk` tests. |
| Target method does not exist on the class (resolved to an inherited static) | `MethodTracer` throws; the existing catch-and-skip in `traceMethod()` swallows it. Covered by Phase `walk` tests. |
| Two candidates in the same file | Each is a separate `traceMethod()` call. Accepted cost — the ~4.5 s measurement already includes it. See Phase `walk` last task. |
| Cycle: `A::x` static-calls `B::y`, `B::y` static-calls `A::x` | Seen-set of already-walked `FQCN::method` nodes; a repeat is never queued. Covered by Phase `walk` tests. |
| Candidate Brain already walked | Not filtered — Brain's edge types cannot be told apart from richter's per-file tracers (section 2). Walked again, deduped, ~4 ms per occurrence. Phase `note`'s counter sizes the overlap. |
| Candidate is an interface or abstract method with no body | `MethodTracer` returns no edges. No special handling. |
| `second_hop` is `false` | Pass does not run; graph is byte-identical to today's. Covered by Phase `walk` tests. |
| Serial build, parallel build, and profiling | The pass lives in `build()` in the parent for all three, so parallel/serial parity holds by construction. Covered by Phase `walk` tests. |
| No candidates at all (an app with no app-level static calls) | Returns empty without invoking the walk. Covered by Phase `walk` tests. |
| The config changes between runs | `second_hop` is part of the cache fingerprint, so on and off never share an entry. Covered by `GraphCacheTest`. |

## Implementation

### Phase 1: Walk the called methods (Priority: HIGH)

**ID:** walk · **Depends:** none

- [x] Add `richter.second_hop_depth` (default `1`) to `config/richter.php` and a validated accessor on `RichterConfig` — non-negative int, mirroring the `parallel()`/`entryPointRoots()` shape at `RichterConfig.php:78,138`.
- [x] Expose the method walk on `EntryPointTracer` — a public method taking a list of `FQCN::method` nodes and returning edges, wrapping the existing private `traceMethod()` (`:135`) so the catch-and-skip stays in one place.
- [x] Add the candidate selector — `static-call` targets, minus nodes already the source of a bare-typed walk edge (type list and the reason it excludes Brain's prefixed forms in section 2).
- [x] Wire the pass into `build()` between the branch merge (`:130-135`) and the id rewrites (`:142`), so its edges reach `memberDeclarationEdges`, `declaresEdges` and `inheritedEdgesFor`.
- [x] Bump `GraphCache::FORMAT_VERSION` 8 → 9 (`GraphCache.php:46`) with the reason in the version log.
- [x] Tests — a statically-called method's `new X` draws an edge; the inherited-method chain becomes walkable end to end (the four-link fixture case); `second_hop_depth: 0` produces today's graph exactly; a cycle terminates; a candidate whose file is missing is skipped; parallel and serial builds agree.
- [x] Consider an AST cache keyed by file if the per-method cost warrants it — measured at 4.3 ms/method, and several candidates can share a file. Skip unless the phase timing says otherwise.

### Phase 2: Report what the cap left behind (Priority: HIGH)

**ID:** note · **Depends:** walk

- [x] Return the count of methods the walk could not read from the pass.
- [x] Emit a `second-hop-walk` phase to the `--profile` output, with the edge count and the unread count, so the cost is measurable on a real application.
- [x] ~~Add the stderr note~~ — dropped; no reachable signal (Resolved Question 2).
- [x] Tests — the unread count travels out of the walk; an empty candidate set reports zero.
- [x] Document the lane and its boundary in the README, replacing the "not re-walked for what it constructs" limitation added in 0.20.1 with what now holds.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The pass's edges reach `inheritedEdgesFor()`.** If the wiring lands after `CodeGraphBuilder.php:160`
   instead of before it, the inherited-method gap does not close and only half the work ships. Verify
   with the four-link fixture, not by reading the call order.
2. **`EntryPointTracer::traceMethod()` works for an arbitrary app class.** The spec assumes it is
   root-agnostic — it takes an FQCN and a psr4 map, with no dependency on the configured roots. If it
   turns out to need root context, the walk needs its own collaborator and the estimate changes.
3. **One iteration stays near the measured ~4.5 s on a 4k-file application.** If the built pass is
   materially slower than the probe that produced that figure, the probe missed a cost and the
   depth-1 default needs re-deciding before shipping.

---

## Open Questions

1. **Will the cap note fire on nearly every large application?** At depth 1, "candidates remain" is
   likely the normal state, and a note that always fires teaches its reader to skip it — the exact
   trap the coverage note was designed around by measuring rather than diffing. Phase `note` adds the
   counter before the note, so this is answerable on a real application during implementation. If it
   fires nearly always, the options are: raise the default depth, report the count only under
   `--profile`, or drop the note.

---

## Findings

- **The spec's chain model was wrong, and the build proved it twice.** Both the depth knob and the
  note rested on candidates appearing *after* a round of walking. They cannot: the static-call
  tracer is exhaustive per file, and the walk's own output is in a different edge vocabulary. Written
  up in Resolved Questions 1 and 2. The lesson is the one this package keeps relearning — a claim
  about how the code behaves has to be measured before it is designed against, and "the tracer runs
  per file" was readable at spec time.
- **`richter.second_hop` had to join the cache fingerprint.** Not in the spec. Without it a cache
  entry built with the walk on is served to a run configured with it off, and vice versa — the graph
  would not match the config. Covered by its own test in `GraphCacheTest`.
- **`traceMethod()` now returns `null` for an unreadable body** rather than an empty array, so the
  two cases are distinguishable. The count survives (the `--profile` counter reports it) even though
  the note it was built for did not; it costs nothing and is the only way to ever see this failure
  mode.
- **`SecondHopWalk` takes a closure, not the tracer.** `EntryPointTracer` is `final readonly` by the
  package's own convention, so it cannot be subclassed for a test double, and one collaborator method
  does not earn this package its first interface. A first-class callable matches how the codebase
  already passes behaviour around (`$this->graph->hasNode(...)`).
- **Fixture chain added to `acme-project`:** `ReportRegistry` (statically called, never otherwise
  reached) → `SettingsMappingService` → inherited `SettingsApiService::assemble` → `ClientFactory`.
  The first end-to-end assertion drafted for it passed with the walk switched off — the middleware
  reached the factory through the older chain — so it was rewritten to assert on the registry, which
  only the new path can reach. Mutation-verified in both directions.
