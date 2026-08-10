# Facade resolution edges

## Overview

A call through an application facade — `Reports::generate()` where `App\Facades\Reports` extends
`Illuminate\Support\Facades\Facade` — currently dead-ends at the facade. `StaticCallEdgeTracer`
draws a `static-call` edge to `App\Facades\Reports::generate`, a member the facade class does not
declare, and nothing connects that node to the class the accessor names. Change the concrete
method and richter reports no callers; the facade users sit in files it parsed.

This adds a `facade-resolves-to` edge from the facade's member node to the concrete's member node,
so the caller reaches the code that actually runs.

## Assumptions

- **Brain covers this only along its route-anchored chain.** `GraphBuilder::maybeWireFacadeResolution()`
  emits the same edge type, but it takes a `CallChainEdge` — so it fires for a facade call inside a
  route-reached body and nowhere else. That is the half richter does not need. The overlap looks
  like coverage and is not, the same shape as the Blade lane.
- **The bridge is drawn, not substituted.** Rewriting the `static-call` target onto the concrete
  would be a smaller graph, but it erases the facade from the chain: a change to the facade itself
  (a repointed accessor) would then reach nobody, and `richter:trace` would not show that the call
  goes through a facade. Two edges, one fact each.
- **`::class` accessors only.** `getFacadeAccessor()` returning a container key (`return 'reports'`)
  is a string whose binding richter's own `binding` lane deliberately drops — it only records
  abstract/concrete pairs that name a class. Resolving string keys needs a string-keyed binding
  registry, which is a separate change. A string accessor draws nothing rather than guessing.
- **App concretes only.** An accessor naming a vendor class draws nothing, the same app-scoping
  every other tracer applies.

## 1. Current state

`StaticCallEdgeTracer::receiverFqcn()` (`src/Tracers/StaticCallEdgeTracer.php:114`) accepts any
loadable class inside the app namespace. A facade is one, so the call resolves to the facade and
stops there:

```
Caller::run  --static-call-->  App\Facades\Reports::generate        [drawn today]
                               App\Services\ReportGenerator::generate   [never connected]
```

No lane reads `getFacadeAccessor()` — the accessor name appears nowhere in `src/`.

## 2. Proposed change

A `FacadeEdgeTracer` in the accumulate-then-flush shape `ClassHierarchyTracer` and
`ConstantReferenceTracer` already use: it collects class-likes during the one consolidated AST pass,
then emits its edges once after the loop, when every class has been seen. No extra walk of the tree.

Per collected class it records the resolved parent name and the FQCN its `getFacadeAccessor()`
returns. After the loop, a class counts as a facade when `is_subclass_of()` places it under
`Illuminate\Support\Facades\Facade` — authoritative and depth-independent, where matching the base
class by name would miss a facade extending an intermediate base. An accessor declared on an
app-side base is found by walking the recorded parent chain.

For every `static-call` edge whose target is `Facade::method`:

```
App\Facades\Reports::generate  --facade-resolves-to-->  App\Services\ReportGenerator::generate
```

drawn only when the concrete actually has that method (`method_exists`, so a method reached through
a trait or a parent still counts). A facade method the concrete does not declare — `__call`-backed
magic — draws nothing rather than a phantom node.

The edge counts toward risk. It is a real call hop: changing the concrete method breaks the caller,
which is the opposite of the `override`/`model-to-policy` over-approximations that are excluded.

`SecondHopWalk` gains `facade-resolves-to` targets as candidates. A concrete reached only through a
facade is the same leaf the walk exists for: its node is in the graph and nothing reads its body.
The candidate set stays a subset of the static-call targets already measured.

## Edge Cases

| Case | Behaviour |
|---|---|
| `getFacadeAccessor()` returns `Concrete::class` | Edge drawn to `Concrete::method`. |
| `getFacadeAccessor()` returns `'a-container-key'` | No edge — no string-keyed binding registry. |
| Accessor declared on an app-side abstract facade base | Found by walking the recorded parent chain. |
| Accessor declared on a vendor base richter never scanned | No edge — the chain stops at the unscanned class. |
| Accessor names a vendor class | No edge — app-scoped, like every other tracer. |
| Concrete has no such method (`__call` magic) | No edge — a phantom member node helps nobody. |
| A non-facade class declaring `getFacadeAccessor()` | No edge — the `is_subclass_of` gate decides, not the method name. |
| Facade called with a variable receiver (`$facade::x()`) | No edge — no `static-call` edge exists to bridge from. |
| The same facade method called from ten places | One edge — the bridge is per facade member, not per caller. |
| Brain already drew `facade-resolves-to` for a route-reached call | Deduped. Brain's facade node and its concrete's hop node both carry `fqcn` + `method`, so `NodeNormalizer::canonicalId()` maps them onto the same `Fqcn::method` ids this bridge uses, and the type string is identical (`GraphBuilder.php:1149`). |
| The facade member node itself is walked by the second hop | Reads nothing (the facade declares no such method) and adds no edges. |

## Implementation

### Phase 1: Resolve facades to their concretes (Priority: HIGH)

**ID:** resolve · **Depends:** none

- [x] `FacadeEdgeTracer` with `collect()` / `resolutionEdges()`.
- [x] Tests — `::class` accessor, string accessor, inherited accessor, non-facade class, vendor
      concrete, missing method on the concrete.

### Phase 2: Wire it into the build (Priority: HIGH)

**ID:** wiring · **Depends:** resolve

- [x] Collect in the consolidated AST loop, flush after it.
- [x] `SecondHopWalk` accepts `facade-resolves-to` targets as candidates.
- [x] `GraphCache::FORMAT_VERSION` bump — the edge set grows for identical file inputs, so a stale
      entry served to the new code under-selects.
- [x] End-to-end test over the fixture project: a facade call reaches the concrete's method.
- [x] README coverage bullet.
