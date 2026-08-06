# Static-call and inherited-method edges

<!-- spec:planned-at b3337263770bd1cc3c25098ecc18d879e697a120 2026-08-06 +uncommitted -->

## Overview

Two missing edge types that between them hide a four-link chain end to end: a class reached
only through `Foo::bar()` gets no node at all, and a method inherited (not overridden) from a parent
is never connected to the subclass its callers actually go through. Both are recall gaps of the kind
this package treats as correctness bugs — the report is quieter than the truth — and the first one is
worse than silence, because `detect-changes` actively states that nothing references a class that two
graphed callers do reference.

## Assumptions

<!-- Filled by the Assumptions Audit; each bullet is a sign-off item. -->

- **Both fixes belong in richter, not upstream in laravel-brain.** Richter already ships four tracers
  that add edges Brain does not (dispatch, policy, reference, constant), so a fifth is the
  established pattern and needs no upstream release. Verified: `CodeGraphBuilder`'s consolidated pass
  hands every tracer its per-file node bucket (`src/Graph/CodeGraphBuilder.php:296-321`).
- **Static-call edges are restricted to app classes.** Without it every `Carbon::now()`,
  `Str::of()`, `DB::table()` and facade call in the codebase becomes an edge. Gate on
  {@see AppNamespace::isInApp()}, the same gate the other tracers use.
- **Eloquent static queries stay Brain's.** `Model::find()` already produces a `model`-typed hop
  (`MethodTracer::handleStaticCall()`); re-emitting it under a new type would double-count the same
  call in two edge types. Excluded here.
- **Inherited-method edges are drawn only for member nodes already present in the edge set.**
  Confirmed. See Resolved Questions 1. *Load-bearing.*
- **Both new edge types count toward risk** (neither joins `RISK_EXCLUDED_EDGE_TYPES`). Confirmed —
  existing consumers can see a higher risk level on unchanged code once these chains are found. See
  Resolved Questions 2. *Load-bearing.*
- **`self::`/`static::` calls draw an edge to the declaring class's own member node.** Brain's
  `MethodTracer` deliberately does not recurse into a class's own private methods
  (`src/Tracers/EntryPointTracer.php:98-100`), so a private helper reached only via `self::helper()`
  is invisible today. `parent::m()` resolves to the parent's member node, which is exactly the F2
  connection made explicit at the call site.
- **The graph cache needs a `FORMAT_VERSION` bump** (7 → 8). Both changes alter the edge set for
  identical file inputs, which the content fingerprint cannot see — the same reasoning that bumped
  6 → 7 for the dispatch-tracer fix.

---

## 1. Current state

Traced on the working tree at the stamped commit.

### 1.1 A static call produces no hop

`MethodTracer::handleStaticCall()` (`vendor/laramint/laravel-brain/src/Analysis/MethodTracer.php:353`)
handles only special shapes and falls off the end for everything else:

| Shape | Result |
|---|---|
| `Job::dispatch()` | `job` hop |
| `Event::` / `Bus::` / `View::` / `Notification::` facades | typed hop |
| `Model::find()` and friends (`MODEL_STATIC_METHODS`) | `model` hop |
| **`AnyOtherClass::anyMethod()`** | **nothing** |

The asymmetry is inside one class: `handleNew()` (`:595`) *does* have a generic fall-through — line
`:627` emits a `__construct` hop for any non-model, non-framework FQCN. So `new Foo` is traced and
`Foo::bar()` is not, which reads as an oversight rather than a decision.

Consequence in richter's own output: a class whose only callers are static calls resolves to zero
nodes, and `detect-changes` reports

```
! app/Services/TargetRegistry.php is new and nothing in the graph references it yet
```

which is graph-scoped and literally true, but reads as a statement about the codebase. That finding
was added in 0.18.0 (`src/Analysis/ImpactAnalyzer.php`); it is the loudest symptom of this bug.

### 1.2 An inherited method is not connected to the subclass

`ClassHierarchyTracer` draws `Ancestor::m → Descendant::m` only for methods the descendant **itself
declares** and an ancestor also declares (`src/Tracers/ClassHierarchyTracer.php:76-91`), with
`static`, `private` and `__construct` excluded (`overridableMethods()`). A method the subclass
inherits without overriding therefore gets no edge in either direction.

Brain resolves `$service->inheritedMethod()` against the receiver's static type, producing a node
`Subclass::inheritedMethod` — while the code that runs lives at `Parent::inheritedMethod`, a
different node with no connection to it. The parent reports `Callers: (none)` even though every real
call arrives through the subclass, and the parent's own outbound work (including any F1 static call)
is disconnected from everything upstream.

**Richter already does this resolution for constants.** The README states it: class-constant reads are
"resolved to the declaring class, so an inherited constant still connects". The method lane simply
never got the same treatment — so this is an internal inconsistency with an existing pattern to copy,
not a new capability.

## 2. Static-call edges (`StaticCallEdgeTracer`)

A new per-file tracer, following the shape of `DispatchEdgeTracer`/`PolicyEdgeTracer`: it receives the
consolidated pass's `classMethods` bucket and the declaring FQCN, and returns edges.

```php
// src/Tracers/StaticCallEdgeTracer.php
public function edgesForMethods(array $classMethods, string $classFqcn): array
```

For every `StaticCall` inside each method, with `$call->class` a `Name` and `$call->name` an
`Identifier`:

| Receiver | Resolves to | Edge |
|---|---|---|
| `self` / `static` | the declaring class | `{classFqcn}::{caller} → {classFqcn}::{callee}` |
| `parent` | the parent FQCN (from the name-resolved AST) | `→ {parent}::{callee}` |
| any app class | the resolved FQCN | `→ {target}::{callee}` |
| a vendor/framework class | — | none (fails `AppNamespace::isInApp()`) |
| a model in `MODEL_STATIC_METHODS` | — | none (Brain already emits a `model` hop) |

Edge type: `static-call`. Source is the **member** node of the calling method, matching how
`DispatchEdgeTracer` sources its edges, so a changed caller method seeds the edge and a changed callee
is reached from it.

The target is the callee's **member** node (`Foo::bar`), not the class node: the class node is linked
to it anyway by the existing `declares` edge, so member-level targeting keeps the precision the rest of
the graph has while losing no reachability.

## 3. Inherited-method edges (`ClassHierarchyTracer::inheritedEdges()`)

The tracer already collects exactly the data this needs — `records[$fqcn] = ['parent' => …,
'interfaces' => […], 'methods' => […]]` — so this is a second emit method over the same records, not a
second collection pass.

Unlike `overrideEdges()`, this one is **driven by the edge set**, so it runs after the consolidated
loop with the accumulated edges passed in, the way `declaresEdges($edges)` already does:

```php
array_push($edges, ...$hierarchyTracer->inheritedEdges($edges));
```

For every member node `{Class}::{m}` appearing in the edge set where `Class` does not declare `m` but
an ancestor does, emit `{Class}::{m} → {Ancestor}::{m}` with type `inherits`, resolving to the
**nearest** declaring ancestor (the one that actually runs).

Direction check: `callersOf()` walks the `upstream` map, where `upstream[target]` holds sources. With
source `Subclass::m` and target `Ancestor::m`, `callersOf(Ancestor::m)` reaches `Subclass::m` and
continues up to the real callers, and `dependenciesOf(Subclass::m)` reaches the parent's outbound
work. Both directions are what F2 asks for.

## 4. Cache and risk semantics

- `GraphCache::FORMAT_VERSION` 7 → 8, with the reason recorded in the existing docblock list: the
  edge set grows for identical file inputs, so a stale pre-change entry would be served to the new
  code and miss the added reach — under-selection.
- Whether `static-call` and `inherits` join `ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES` is Open
  Questions 2. Note `override` is excluded there specifically because an interface with many
  implementors fans out widely; neither new type has that shape (a static call is a real invocation, and
  an inherited method has one nearest declaring ancestor).

## Edge Cases

| Scenario | Handling |
|----------|----------|
| `Foo::bar()` where `Foo` is a vendor/framework class | No edge — `isInApp()` gate. Phase `static-calls`, Tests: a facade and a Carbon-style call draw nothing. |
| `Model::find()` / other `MODEL_STATIC_METHODS` | No edge — Brain already emits a `model` hop; re-emitting double-counts. Phase `static-calls`, Tests: a model static query draws no `static-call` edge. |
| `self::helper()` / `static::helper()` | Edge to the declaring class's own member node — closes the private-helper gap Brain's non-recursion leaves. Phase `static-calls`, Tests. |
| `parent::handle()` | Edge to the parent's member node. Phase `static-calls`, Tests. |
| `$class::method()` (variable receiver) | No edge — not statically resolvable; the same silence every other tracer keeps for a dynamic target. Phase `static-calls`, Tests. |
| First-class callable `Foo::bar(...)` | Treated as a call (it is a reference to that method); no arg inspection needed. Phase `static-calls`, Tests. |
| A subclass that overrides the method | No `inherits` edge — `overrideEdges()` already covers it, and both would double-link. Phase `inherited`, Tests. |
| A multi-level chain (`C extends B extends A`, only `A` declares `m`) | One edge to the **nearest** declaring ancestor. Phase `inherited`, Tests. |
| Ancestor outside `app/` (a vendor base class) | No edge — ancestors are app-scoped, matching `overrideEdges()`'s existing rule. Phase `inherited`, Tests. |
| A member node for a method nothing declares (Brain-invented or misparsed) | No edge; the lookup simply misses. Phase `inherited`, Tests. |
| An interface method with no body | Ancestors include interfaces; an `inherits` edge to an interface method is harmless reachability but adds no code — excluded to keep the type meaning "this is where the code runs". Phase `inherited`, Tests. |
| A trait-provided method | Excluded — traits are copied into the using class, not inherited; `uses-trait` already links them. Phase `inherited`, Tests. |

## Implementation

### Phase 1: Static-call edges (Priority: HIGH)

**ID:** static-calls · **Depends:** none

- [x] Add `src/Tracers/StaticCallEdgeTracer.php` with `edgesForMethods()`, following `DispatchEdgeTracer`'s bucket-fed shape — resolve the receiver (`self`/`static`/`parent`/name), gate on `AppNamespace::isInApp()`, skip `MODEL_STATIC_METHODS` on model-shaped targets.
- [x] Wire it into the consolidated pass in `CodeGraphBuilder` (`:310-320`), beside the dispatch/policy/reference tracers.
- [x] Tests — a plain `Foo::bar()` draws a member-level `static-call` edge; `self::`/`static::`/`parent::` resolve correctly; vendor targets, model statics and variable receivers draw nothing; a first-class callable is treated as a call; the fixture-project graph gains the expected edge end to end.

### Phase 2: Inherited-method edges (Priority: HIGH)

**ID:** inherited · **Depends:** static-calls

Depends on Phase 1 only because both edit `CodeGraphBuilder`'s build method — they are otherwise
independent, and serialising them keeps the two from colliding in the same file.

- [x] Add `ClassHierarchyTracer::inheritedEdges(array $edges)` over the existing `records`, resolving each undeclared member node to its nearest declaring app ancestor; skip overridden methods, interface methods and trait methods.
- [x] Call it after the consolidated loop in `CodeGraphBuilder`, next to `overrideEdges()`.
- [x] Tests — a parent's inherited method gains the subclass's callers; a multi-level chain resolves to the nearest declarer; an overridden method draws no `inherits` edge; a vendor ancestor draws nothing; the four-link chain (caller → subclass → inherited parent method → static callee) is walkable end to end in the fixture graph.

### Phase 3: Cache, risk and docs (Priority: HIGH)

**ID:** wiring · **Depends:** static-calls, inherited

- [x] Bump `GraphCache::FORMAT_VERSION` 7 → 8 with the reason appended to the docblock's numbered list.
- [x] Apply the Open Questions 2 decision on `RISK_EXCLUDED_EDGE_TYPES`.
- [x] Soften the new-file finding text so it cannot read as a claim about the codebase — it is graph-scoped, and F1 proved a reader takes it literally.
- [x] README: add both edge types to the "Coverage beyond Brain" list, and note the risk-semantics decision if Phase 3 changes it.
- [x] Tests — the fingerprint changes across the version bump; the finding text matches the new wording.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **Static calls really produce no hop today.** If a fixture shows Brain already emitting an edge for
   a plain `Foo::bar()`, the premise is wrong and this becomes a filtering problem, not a missing
   edge — stop rather than adding a duplicate edge type.
2. **`ClassHierarchyTracer::records` carries enough to resolve inheritance.** It records one `parent`
   plus interfaces per class; if the nearest-declarer walk needs data it does not keep (e.g. abstract
   vs concrete, or trait-provided method names), extend `collect()` deliberately rather than guessing
   from the FQCN.
3. **Neither edge type explodes the graph.** If either measurably inflates edge counts on the fixture
   project (or the benchmark corpus), stop and re-scope — an over-reporting graph trains readers to
   ignore the report, which is the failure mode on the other side of this one.

---

## Open Questions

None.

---

## Resolved Questions

1. **Should `inherits` edges be drawn only for member nodes already in the edge set?** **Decision:**
   Yes — edge-set-driven, emitted after the consolidated loop. **Rationale:** minimal fan-out, and it
   avoids phantom `Subclass::m` nodes for methods nothing calls; a phantom node would still be matched
   by `seedsFor()`'s substring lookup, so a changed member could seed against a node no caller reaches.
2. **Do the new edge types count toward risk?** **Decision:** Yes, both count; neither joins
   `RISK_EXCLUDED_EDGE_TYPES`. **Rationale:** both are real execution links — a static call is an
   invocation, an inherited method is the code that runs — unlike `override` (over-approximated
   fan-out) and `declares`/`uses-trait` (association). Accepted cost, same shape as the 0.18.0
   new-file change: a consumer can see a higher risk level on unchanged code once the graph finds
   these chains, because the old number omitted real reach.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **The inherited pass had to move out of the tracer branch entirely.** The spec placed it beside
  `overrideEdges()`, but that runs inside the tracer branch, where the edge set is only branch B's own
  — the subclass member node it must resolve arrives from Brain (branch A) or from the source tracer
  later in the same branch, so nothing matched. Worse for the common case: a controller that INHERITS
  its action reaches its member node purely through Brain's route→controller edges. The pass now runs
  in `build()` after `declaresEdges()`, over the whole merged set, and the tracer carries its
  parent/declared map out through the branch payload (`inheritance`) so the parallel child process can
  hand it to the parent. That widened a three-key worker contract to four across
  `buildTracerBranch()`, `TracerBranchRunner::validate()` and the `consolidatedTracerEdges()` shape —
  validated fail-closed like the edges, since a wrong map would draw `inherits` edges to methods that
  do not run.
- **New fixture files must be `git add`ed the moment they exist.** Seven unrelated feature tests went
  red because `ChangedSymbols::untrackedRelevantFiles()` picked up the new `Acme\` fixture files as
  untracked work under an `app/` path, which changes command output. The failure looks like a
  regression in the frontend/command lanes and is not.
- **The app-namespace gate alone lets phantom targets through — an existence check was needed.** An
  UNQUALIFIED receiver with no matching import resolves against the current namespace, so
  `Carbon::now()` written without its `use` becomes `App\Services\Carbon`: inside the app namespace by
  spelling, nonexistent in fact, and drawing that edge invents a node that `seedsFor()` would then
  match. Caught by the vendor-receiver test, not by review. The tracer now requires the target class to
  autoload — but only on the plain-name branch: `self`/`static` ARE the class being parsed and `parent`
  comes from the `extends` clause, so those three need no check (and requiring one would break on a
  parent richter has not scanned). Nothing real is lost: a target richter cannot autoload has no node
  from any other tracer either, so the edge could only ever point at a phantom. Memoised per process,
  the same guard `DispatchTarget` already relies on — though in the opposite direction, since there an
  unloadable class must fail toward "could be a target".
