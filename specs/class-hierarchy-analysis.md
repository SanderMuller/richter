<!-- spec:planned-at d70ff29 2026-07-31 -->

# Class-Hierarchy Analysis (CHA) — reach concrete overrides through polymorphic dispatch

## Overview

richter's call graph resolves a method call to the **static type of the receiver**
(Brain's `MethodTracer` does this, and richter canonicalizes the result). When a
call lands on an **abstract class or interface** method, and the concrete instance
is chosen at runtime — a driver/handler registered in a config array, a factory,
`app()->make($runtimeClass)`, or plain constructor-injected polymorphism — the
concrete override that actually runs is **never linked into the graph**. The
concrete subclass therefore has no incoming edge: it looks unreachable from every
entry point, and a change to one of its overrides reads as "not placed" even
though every polymorphic call site executes it.

This is a generic, well-understood static-analysis gap (it is what Class-Hierarchy
Analysis solves). This spec adds a **CHA graph augmentation**: for a method call
that resolves to an abstract/interface method, richter draws an edge from that
method to the same method's **overrides in every known subclass/implementor**, so
the walk reaches the concrete code.

**Non-goal / honesty:** CHA links concrete overrides into the graph. Whether a
*specific* change then reaches an entry point still depends on the abstract
method (and its callers) themselves being reachable — CHA is necessary for
polymorphic placement, not by itself sufficient for every case. CHA never
*removes* reach; it only adds it (monotonic — see STOP Conditions).

**Generic-only constraint:** CHA reads only the class hierarchy. It must NOT
encode any app-specific dispatch convention (a particular config key, factory, or
naming scheme). If a design step would only help one app's pattern, it is out of
scope.

## Motivating example (synthetic — no real domain)

```php
abstract class ReportExporter
{
    abstract protected function body(Report $r): string;

    final public function export(Report $r): string   // template method
    {
        return $this->header() . $this->body($r);      // $this->body() -> ReportExporter::body (abstract)
    }
}

final class CsvExporter extends ReportExporter
{
    protected function body(Report $r): string { /* ... */ }   // <-- the override that actually runs
}

// config/exporters.php => ['csv' => CsvExporter::class, 'pdf' => PdfExporter::class]
$exporter = app(config('exporters.' . $format));   // runtime class-string
$exporter->export($report);                        // call on ReportExporter (abstract)
```

Today: `caller → ReportExporter::export → ReportExporter::body` (all abstract);
`CsvExporter::body` is orphaned (0 incoming edges). A change to `CsvExporter::body`
reaches no entry point. **With CHA:** an override edge `ReportExporter::body →
CsvExporter::body` is added, so the walk (and the impact/affected-tests walk from
the changed override, upstream) connects the override to the abstract call site.

## Data model

**New edge type: `override`.** Direction **ancestor → descendant override**:
`source = AbstractOrInterface::method`, `target = ConcreteClass::method`,
`type = 'override'`. This direction is required because richter's impact walk
seeds from a changed member and walks **upstream** (`callersOf`): with the edge
oriented ancestor→override, `callersOf(ConcreteClass::method)` yields
`AbstractOrInterface::method`, which then chains up through Brain's real call edges
to the entry point. It also makes `dependenciesOf(Abstract::method)` reach the
override (downstream reach). (Mirrors how the existing App-interface `implements`
edges are inverted via upstream adjacency.)

**An override is:** a method `M` declared in a concrete (non-abstract) class `C`
where some **ancestor** of `C` — a transitive parent class or a directly/transitively
implemented interface — also declares `M` (as a concrete or abstract method, or an
interface method signature). One `override` edge is emitted per (ancestor-declaring
`M`, `C::M`) pair.

## Design

A new `src/Tracers/ClassHierarchyTracer.php`, fed per-file from the existing
consolidated AST pass (`CodeGraphBuilder::consolidatedTracerEdges()`), then flushed
**once after all files are collected** (CHA is inherently cross-file: the inverse
subclass/implementor map spans files). It accumulates, per class-like:
`{ fqcn, parentFqcn|null, interfaceFqcns[], isAbstractOrInterface, declaredMethods[] }`
— all readable from the `ClassLike` node already bucketed in `collectTracerNodes()`
(`->extends`, `->implements`, `isAbstract()`/`Interface_`, method stmts). After the
file loop, it resolves ancestors transitively and emits the `override` edges.

Injected into the Branch-B `$edges` array before the immutable `CodeGraph` is
constructed — the same path as `binding` / `action-to-job` / `implements` edges.
Node ids are the canonical FQCN-cased `Class::method` form (matching how member
nodes are keyed).

## Implementation

### Phase 1: hierarchy collection + override-edge emission

**ID:** cha-core · **Depends:** — · **Priority:** HIGH

- [x] Add `src/Tracers/ClassHierarchyTracer.php`. A stateful collector:
      `collect(list<ClassLike>): void` records each class-like (parent, interfaces,
      overridable method names) from the AST; `overrideEdges(): list<array{source,target,type}>`
      resolves transitive ancestors across the accumulated records and emits
      `ancestor::M → descendant::M` `override` edges.
- [x] Any class/enum that declares a method an ancestor also declares is an override
      `target` (see Finding F1 — abstract *intermediates* are targets too, not only
      leaf concretes); interfaces and traits are never targets.
- [x] Resolve ancestors transitively (parent-of-parent; interface-extends-interface;
      interfaces of ancestors). A class-not-in-the-accumulator ancestor (e.g. a
      vendor base) contributes no methods — app-scoped per OQ3.
- [x] Unit tests in `tests/Unit/ClassHierarchyTracerTest.php` (11 tests, 30 assertions):
      abstract-parent override, interface implementation, cross-file accumulation,
      transitive grandparent, intermediate-abstract override, method-only-on-concrete
      (no edge), private/static/constructor excluded, unscanned vendor ancestor (no
      edge), anonymous class (skipped), no-hierarchy, trait excluded.

### Phase 2: wire into the builder + cache correctness

**ID:** cha-wire · **Depends:** cha-core · **Priority:** HIGH

- [x] Feed each file's class-likes to the tracer inside
      `consolidatedTracerEdges()` (`$hierarchyTracer->collect($nodes['classLikes'])`),
      and append `overrideEdges()` to the tracer-branch `$edges` after the file loop.
      Inside `buildTracerBranch()`, so the parallel worker path carries it too.
- [x] Bump `GraphCache::FORMAT_VERSION` 4 → 5 (new edges change graph output for
      identical inputs; warm caches must invalidate).
- [x] Feature test in `tests/Feature/ClassHierarchyGraphTest.php` (isolated temp-dir
      app, Brain-independent `buildTracerBranch`): asserts the override edges are in
      the built graph and that `callersOf(concrete::body)` reaches `abstract::body`
      via the `override` edge — fails closed if CHA is removed (no other caller).

### Phase 3: risk / impact classification

**ID:** cha-risk · **Depends:** cha-core · **Priority:** HIGH

- [x] OQ1 resolved: reachability-only. `override` added to
      `ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES` (with `model-relationship`/`declares`/`uses-trait`).
- [x] Pinning test `an_override_fan_out_is_reachability_not_risk` in
      `tests/Unit/ImpactAnalyzerTest.php`: an override-only fan-out yields
      `impacted === 0` / `risk === Low` (reach flows, risk does not inflate).

### Phase 4: docs + config toggle

**ID:** cha-docs · **Depends:** cha-wire, cha-risk · **Priority:** MEDIUM

- [x] Config toggle — N/A per OQ4 (default-on, no toggle). No `config/richter.php` /
      `RichterConfig` change.
- [x] README: added a "polymorphic overrides" bullet under "Coverage beyond Brain".
      Also fixed a stale example line that still showed the pre-`d70ff29` UNRESOLVED
      wording (README freshness caught in passing).

## Edge Cases

| Scenario | Expected behaviour |
|---|---|
| Concrete class overrides an abstract parent method | `override` edge abstract::M → concrete::M |
| Concrete class implements an interface method | `override` edge interface::M → concrete::M |
| Transitive: `C extends B extends A`, `A::m` abstract, `C::m` overrides | edge A::m → C::m (nearest-and-transitive ancestors that declare `m`) |
| Method declared only on the concrete class (no ancestor declares it) | no `override` edge (not an override) |
| Abstract class with no concrete subclasses in the project | no edges (nothing to link) |
| Interface with many implementors | edge to every implementor's override (over-approximation — intended, safe direction) |
| Ancestor is a vendor/framework class not scanned | OQ3 — no methods known, no edge (documented limitation) |
| A concrete class that is itself `final` | still emits override edges (finality is irrelevant to override reach) |
| Same method name, incompatible signature (not a true override) | OQ2 — matched by name only vs signature-aware |
| Static / private ancestor method "override" (not polymorphic) | private is not overridable → no edge; static hides rather than overrides → OQ2 |

## Resolved Questions

- **OQ1 (risk weighting) → REACHABILITY-ONLY.** `override` edges add reachability
  (their purpose) but are **excluded from the risk count**, alongside
  `model-relationship`/`declares`/`uses-trait` in
  `ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES`. An override edge is an over-approximated
  association; risk stays conservative against interface fan-out.
- **OQ2 (matching) → NAME-ONLY, skip private, static-is-not-override.** Match an
  ancestor↔concrete method by name; emit the edge when both declare it. Exclude
  `private` methods (never polymorphic) and treat `static` as hiding, not overriding
  (no edge). Over-approximates only in the safe (never-under-select) direction.
- **OQ3 (vendor ancestors) → APP-SCOPED ancestors only.** CHA links overrides only
  where the ancestor is a class-like richter scanned. A concrete class overriding a
  vendor-base method whose node richter never saw draws no edge — documented
  limitation, matching the App-scoping the existing `implements` emitter uses.
- **OQ4 (toggle) → DEFAULT-ON, no toggle.** CHA is monotonic and generic; ship it
  always-on. Revisit a toggle only if the over-approximation proves noisy on the
  corpus. (Phase 4's config task therefore reduces to README docs unless that
  proves necessary.)

## STOP Conditions

- CHA ever *removes* an edge or reduces reach for any input — it must be strictly
  additive (monotonic). If a change makes an existing fixture reach *fewer* nodes,
  stop.
- The full suite shows a pre-existing graph/impact fixture changing its expected
  reach in a way that is NOT explained by added override edges — stop and surface
  (it means CHA touched something it shouldn't).
- Cross-file aggregation requires holding all class records in memory before
  flushing; if that conflicts with the parallel tracer-branch worker boundary
  (the worker serializes edges, not accumulator state), stop and reconsider the
  integration point rather than forcing it.

## Findings

- **F1 (deviation, cha-core):** the phase originally said "only concrete classes emit
  as target." Widened to *any* class/enum (abstract intermediates included) that
  declares a method an ancestor also declares. Reason: an intermediate abstract's
  override (e.g. `B::m` between base `A::m` and concrete `C::m`) is itself orphaned
  relative to a base-typed call, so linking it too is correct and strictly additive.
  Interfaces (no body to reach) and traits (copied, not dispatched) are still never
  targets. Serves the spec's intent (reach overrides); monotonic.
- **F2 (refinement, cha-core):** `__construct` is excluded from override edges in
  addition to OQ2's private/static exclusions. A constructor is invoked on the
  concrete type directly (`new`), not virtually dispatched, so a `Base::__construct →
  every-subclass::__construct` fan-out would be pure noise with no dispatch reality.
- **F3 (note):** Pint's `fully_qualified_strict_types` added a docblock-only
  `use …\CodeGraphBuilder` for the `{@see}` reference. Harmless (no runtime dep); the
  Phase-2 wiring is the real, one-directional dependency (builder → tracer).
- **F4 (cha-wire):** `CodeGraph::callersOf()` returns `list<{depth, node, via}>`, not
  bare strings — the feature test reads the `node` column (and asserts `via ===
  'override'`). The byte-identical parallel-vs-serial guarantee holds: `overrideEdges()`
  order is deterministic given the file-iteration order, and the merged edges are
  usort-canonicalized in the `CodeGraph` constructor regardless.
- **F5 (cha-risk):** `override` added to `RISK_EXCLUDED_EDGE_TYPES`. Note the wider
  suite (Final Verification) is where a shared-fixture reach change from the new edges
  would surface — an *expected* CHA-explained change updates that fixture's
  expectation; an unexplained one is a STOP condition.
- **F6 (Final Verification):** all gates green — rector 0, pint clean, phpstan 0, full
  suite **812 tests / 1,900 assertions** (798 pre-CHA + 14 new). The shared fixture
  project's reach did NOT change (no STOP), confirming it has no polymorphic hierarchy
  CHA newly connects. Rector applied 5 behaviour-preserving refactors to the tracer
  (exclusive-type check, first-class-callable `AppFiles::resolveName(...)` — which also
  cleared a phpstan param-type-coverage flag — multi-continue split, newline spacing);
  suite re-run green after. Also fixed a stale README example (pre-`d70ff29` UNRESOLVED
  wording) found during the docs audit.
