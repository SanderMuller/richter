# View-render edges

## Overview

Laravel Brain connects a controller to the view it renders by walking the body a route led it to.
A class no route resolves to — a Livewire component, a Filament page, a mailable, an action — never
gets its body walked, so the view it renders has no caller. Editing that view then reports
UNRESOLVED: the file has a graph node, nothing points at it, and the report cannot say who is
affected. A change touching several component views reports every one of them as
`(0 graph nodes) (UNRESOLVED)`.

This reads the render call that is already in the source.

## Assumptions

- **The call is written out.** The mapping is often assumed to need a convention resolver (component
  FQCN → view name). It does not, for the majority: components typically `return view('livewire.x')`
  explicitly. Reading the call covers those with no framework knowledge at all, and it is the same
  lane for Filament, mailables and plain services.
- **Literal names only.** `view($this->template)` names no view. Guessing one would point a reviewer
  at an unrelated screen, the same discipline the config-registry lane applies to a dynamic key.
- **The Blade file must exist here.** A package-namespaced name (`mail::message`) resolves outside
  `resources/views`. Drawing an edge to it would mint a view node nothing else in the graph shares —
  reach that leads nowhere.
- **Brain's own edge type is reused.** The relation is the same one Brain models as `action-to-view`,
  and the merge dedupes on (source, target, type), so a route-anchored controller both lanes see
  yields one edge. A parallel type would put two hops between the same pair in every chain.
- **Risk-bearing, unlike the over-approximating lanes.** A literal view name is exact — this is not
  a "could be any of these" fan-out, so it has no reason to sit in `RISK_EXCLUDED_EDGE_TYPES`.

## 1. Current state

`BladeViewTracer` draws `view-to-view` (`@include` / `@extends`) and `PolicyEdgeTracer` draws
view → policy. Nothing draws member → view. Measured on the fixture project before this lane: the
merged graph held exactly one member → view edge, Brain's, for the one controller a route reaches.

## 2. Proposed change

`ViewRenderTracer`, fed by the consolidated AST pass like the other per-file tracers.

1. Per class-like (not per file — a second class in the file must not inherit the first's renders),
   each method is scanned for `view('name')` and `Illuminate\Support\Facades\View::make('name')`.
2. A literal first argument naming a Blade file under `resources/views` yields one `action-to-view`
   edge from the calling member to `BladeViews::nodeId($name)`.
3. Anything else draws nothing.

## Edge Cases

| Case | Behaviour |
|---|---|
| `view('livewire.status-panel')` in a component | Edge to `view::blade__livewire.status_panel`. The hyphen folds to `_`, matching Brain's slug rule. |
| `View::make('…')` | Recognised, same as the helper. Matched case-insensitively, as PHP resolves method names. |
| `view($this->template)` | Nothing. |
| `view("livewire.{$panel}")` | Nothing. A view name has no useful file-level fallback the way a config key has. |
| `view('mail::message')` | Nothing. No Blade file here, so the node would be one nothing else shares. |
| A controller Brain already covers | One edge, not two: same type, deduped at the merge. |
| Two classes in one file, only the second renders | Attributed to the second. |
| A render inside an anonymous class | Attributed to the method that builds it. An anonymous class has no name to be a source, and naming it after the file's primary class invents a member that does not exist — a caller a reviewer opens and cannot find. The builder is the true owner: its return value is what renders. |
| A changed component view | Seeds its view node, whose caller is now the component — an entry surface, since `Livewire`/`Filament` are entry-point namespaces. |

## Not in scope

Resolving a view by convention from the component's FQCN, for the minority that override `$view` or
rely on Livewire's implicit resolution. Worth revisiting only if the explicit-call majority proves
not to be a majority elsewhere.

## Implementation

### Phase 1: The lane (Priority: HIGH)

**ID:** lane · **Depends:** none

- [x] `ViewRenderTracer` with `edgesForClassLikes()`; `BladeViews::existsIn()` for the file gate.
- [x] Tests — both call forms, the case-insensitive facade method, computed and interpolated names,
      the missing-Blade-file silence, second-class attribution, and the reused edge type.

### Phase 2: Wire it in (Priority: HIGH)

**ID:** wiring · **Depends:** lane

- [x] Collected in the consolidated AST loop.
- [x] `FORMAT_VERSION` bumped — the lane adds edges, so a stale entry under-selects.
- [x] End-to-end test: a changed component view reaches the component, the component reaches the
      view downstream, and the controller Brain already covered still carries exactly one edge.
- [x] README and coverage docs.
