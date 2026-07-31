<!-- spec:planned-at 12186e5 2026-08-01 -->

# Constant & enum-case member references — pin the change, drop the coarse class seed

## Overview

When a diff changes a **class constant** or an **enum case**, richter cannot pin the change to a
member node — only methods get member-level nodes (`MemberResolver` marks `constant`, `enum_case`,
and `property` as `resolvable: false`). So `ImpactAnalyzer` falls back to a **coarse whole-class
seed** and flags **low confidence** ("a changed member could not be pinned to a graph node, so part
of this is a coarse class-level estimate"). The blast radius is then the whole class instead of the
methods that actually read the constant.

This spec makes constant and enum-case changes **precise**: node each declared constant/enum-case
(`Class::CONST`, `Enum::Case`), draw a reader edge from every method that references it **resolved to
the constant's declaring class**, and flip those kinds to `resolvable: true`. A change to a constant
then seeds that member node and walks up to its readers — no coarse fallback, no low-confidence for
this case.

**Scope:** constants and enum cases only. **Properties stay coarse** (a bare `$this->x` is nodeable,
but the common `$fillable`/`$casts` case feeds Eloquent's dynamic `$model->attr` magic, which is not
statically resolvable — the payload-parity checker already special-cases the one tractable slice).
**Class-modifier changes stay coarse** (a class-declaration change genuinely affects the whole class;
there is no finer node). Both remain correct as-is.

**Generic-only:** the tracer reads only `const`/`case` declarations, `ClassConstFetch` reads, and the
class hierarchy — no per-app convention.

**Safety (cardinal rule):** it must never turn an honest coarse/low-confidence estimate into a false
"no impact" or silently under-select readers. Two guards make this hold: (1) reads resolve to the
**declaring class**, so an inherited-constant read still connects to a change on the ancestor that
declares it (§ Declaring-class resolution); (2) if a changed constant resolves to **no graph node**
(unresolvable read, dynamic access), `memberSeeds` returns empty and coverage reads **UNRESOLVED** —
honest, never "no impact".

## Motivating example (synthetic — no real domain)

```php
abstract class Money
{
    protected const int SCALE = 2;              // declared on the base

    public function round(float $v): float { return round($v, static::SCALE); }  // reads via static::
}

final class Pricing extends Money
{
    public const float VAT_RATE = 0.21;

    public function withTax(int $net): int { return (int) round($net * (1 + self::VAT_RATE)); } // reads VAT_RATE
    public function shippingLabel(): string { /* … reads neither constant … */ }
}

enum OrderStatus { case Draft; case Placed; case Shipped; }
final class OrderService
{
    public function isComplete(OrderStatus $s): bool { return $s === OrderStatus::Shipped; }
}
```

- Change `VAT_RATE` → pins to `Pricing::VAT_RATE`, read only by `Pricing::withTax` (not `shippingLabel`).
- Change `SCALE` (on `Money`) → the `static::SCALE` read in `Money::round` **and** any subclass read
  must resolve to `Money::SCALE`, so the change reaches every reader — the declaring-class case.
- Change the `Shipped` case → pins to `OrderStatus::Shipped`, read by `OrderService::isComplete`.

All three drop the coarse class seed and the low-confidence flag.

## Data model

- **Member nodes** `Class::CONST` and `Enum::Case` — canonical FQCN-cased member form, like
  `Class::method`.
- **Reader edge** `type = references-constant`, oriented **reader → declaring-class constant**:
  `source = ReaderClass::method`, `target = DeclaringClass::CONST` — where `DeclaringClass` is the
  class/interface that actually **declares** the constant (resolved through the hierarchy), NOT the
  class named in the read (`self`/`static`/`Child` when the constant is inherited). So
  `callersOf(DeclaringClass::CONST)` yields every reader regardless of how it named the owner.
- **`declares` edge** `DeclaringClass → DeclaringClass::CONST` for every declared constant/enum-case,
  so a constant with **no readers** still nodes (a leaf → "analyzed, reaches nothing", not
  UNRESOLVED).

## Declaring-class resolution (the load-bearing correctness element)

A `ClassConstFetch` names an owner (`self`, `static`, `$this`, `Parent`, `SomeClass`) and a name. The
edge target must be the class that **declares** that constant:

1. Resolve the named owner to an FQCN (`self`/`static`/`$this` → the enclosing class; a name → its
   resolved FQCN).
2. If that class declares the constant/case → it is the declaring class.
3. Otherwise walk its ancestors (transitive parent chain + implemented interfaces, transitively —
   constants are inherited from both) and take the **nearest** ancestor that declares it.
4. If no scanned (app) class in the chain declares it → **app-scoped miss**: draw **no** edge (a
   vendor constant, or an unresolvable read). A change to such a constant then finds no node and reads
   UNRESOLVED — honest, per the cardinal rule.

Enum **cases** are never inherited (enums cannot extend), so a case always resolves to its own enum;
enum **constants** follow the same interface-inheritance path as class constants.

This requires the complete class hierarchy, so the pass **accumulates across all files and flushes
once** (the same accumulate-then-flush shape as `ClassHierarchyTracer`).

## Design

A new `src/Tracers/ConstantReferenceTracer.php`, fed per file by the consolidated AST loop, that
accumulates: per class-like, `{ parent, interfaces, declaredConstants[] }` (its own small hierarchy
map — kept self-contained and independently testable, not coupled to `ClassHierarchyTracer`); and per
method, the list of `ClassConstFetch` reads `{ readerNode, ownerName, constName }`. After the file
loop, `edges()` resolves each read's declaring class (§ above) and emits the `references-constant`
reader edges plus the `declares` edges for every declared constant/case. Injected into the Branch-B
`$edges` array in `CodeGraphBuilder`, exactly like the CHA / binding / dispatch edges.

`MemberResolver` flips `KIND_CONSTANT` and `KIND_ENUM_CASE` to `resolvable: true`. `ImpactAnalyzer`
then seeds them precisely through the existing `resolvableMembers()` → `memberSeeds()` path, and
`needsCoarseSeed()` stops firing for them (leaving `property`/`class` coarse as before).

## Implementation

### Phase 1: collect + resolve + emit constant-reference edges

**ID:** cref-core · **Depends:** — · **Priority:** HIGH

- [x] Add `src/Tracers/ConstantReferenceTracer.php`: `collect(list<ClassLike>)` records parent/interfaces
      + declared const/case names and every method's `ClassConstFetch` reads; `edges()` resolves each
      read to its declaring class and returns the `references-constant` reader edges + `declares` edges.
- [x] Resolve `self`/`static`/`$this` → enclosing class, `parent` → its parent, a named owner via name
      resolution; inherited constants to the nearest declaring ancestor (parent chain + interfaces,
      transitive, BFS nearest-first); an owner not resolvable to a scanned app class → no edge. See
      Finding F1 (`$this::` handled via the `Variable`-named-`this` case, since it parses as an Expr).
- [x] Unit tests `tests/Unit/ConstantReferenceTracerTest.php` (10 tests, 25 assertions): same-class,
      `self::`/`static::`/`$this::`, `ClassName::`, **inherited constant** (targets the parent),
      **interface constant**, enum-case, `Foo::class` → none, dynamic `$x::C` → none, vendor owner →
      none, read-nowhere → declares only.

### Phase 2: wire the build + cache correctness

**ID:** cref-wire · **Depends:** cref-core · **Priority:** HIGH

- [x] Fed per file in `CodeGraphBuilder::consolidatedTracerEdges()` (`$constantTracer->collect(...)`),
      `edges()` appended after the loop, inside `buildTracerBranch()` (parallel worker carries it).
- [x] Bumped `GraphCache::FORMAT_VERSION` 5 → 6.
- [x] Feature test `tests/Feature/ConstantReferenceGraphTest.php` (3 tests): `Pricing::VAT_RATE` read
      only by `withTax` (not `shippingLabel`); `static::SCALE` in the base **and** the subclass both
      resolve to `Money::SCALE`; a read-nowhere constant nodes (via declares) with no method reader.

### Phase 3: resolve precisely + risk + drop the coarse seed

**ID:** cref-resolve · **Depends:** cref-wire · **Priority:** HIGH

- [x] `MemberResolver`: flipped `KIND_CONSTANT` and `KIND_ENUM_CASE` to `resolvable: true` (left
      `KIND_PROPERTY` / `KIND_CLASS` `false`); updated `MemberChange` docblock + `MemberResolverTest`.
- [x] `references-constant` is risk-BEARING (not added to `RISK_EXCLUDED_EDGE_TYPES`) per OQ1 — a
      precise value dependency, consistent with the existing `references` edge.
- [x] `tests/Unit/ImpactAnalyzerTest.php`: a constant change seeds its readers precisely and reaches
      the entry point with `lowConfidence` false + coverage "analyzed"; a constant with no graph node
      reads UNRESOLVED (not "no impact"). (Inherited-reader connection is pinned in the cref-wire
      feature test.) Full suite 828 green, zero ripples.

### Phase 4: docs

**ID:** cref-docs · **Depends:** cref-wire, cref-resolve · **Priority:** MEDIUM

- [x] README: added a "class-constant and enum-case reads" bullet under "Coverage beyond Brain".

## Findings

- **F1 (cref-core):** `$this::CONST` parses with `->class` as a `Variable` (not a `Name`), so it is
  handled by an explicit `Variable`-named-`this` case → enclosing class; every other `$var::C` is a
  dynamic owner and skipped.
- **F2 (cref-wire):** the `declares` edge (`Class → Class::CONST`) makes the declaring class a caller
  of its own constant, so `callersOf(Class::CONST)` always includes the class — the feature test
  asserts absence of a *method* reader for the read-nowhere case, not an empty caller list.
- **F3 (cref-resolve):** full suite 828 green, **zero ripples** — the `resolvable` flip and the new
  constant/enum-case nodes broke no shared-fixture assertion (no STOP condition). Most existing
  low-confidence tests pass the flag directly to a formatter, and the coarse-driver fixtures hard-code
  a `KIND_PROPERTY` change (still coarse), so they were unaffected.
- **F4 (adversarial review, CONFIRMED — cardinal-rule fix):** an independent review verified end-to-end
  that reads were gathered only from `$method->stmts`, so a `self::CONST` in a **parameter default**
  (or a method attribute) drew no reader edge — and with the `resolvable` flip that regressed to a
  false "analyzed / no impact". Fixed: `constFetches()` now walks the whole method node (params +
  attributes + body). Test: `a_constant_read_in_a_parameter_default_is_a_reader`.
- **F5 (review, refuted-as-safe but improved):** a `self`/`static`/`parent`/`$this` read inside a
  nested anonymous class was attributed to the enclosing class (over-selection — verifier deemed it
  safe). Fixed for precision: the depth-tracking visitor drops scope-relative reads nested in a
  class-like (a named read still connects). Test:
  `a_scope_relative_read_in_a_nested_anonymous_class_is_not_attributed_to_the_outer_class`.
- **F6 (review, refuted-as-safe but improved):** a trait-constant change would read UNRESOLVED (honest,
  affected-tests-safe) instead of coarse-reaching using classes. Fixed: `MemberResolver` keeps trait
  constants `resolvable: false` (the tracer skips traits). Test: `it_keeps_trait_constants_coarse`.
  Full suite **831 green** after all three fixes.

## Edge Cases

| Scenario | Expected |
|---|---|
| Same-class constant read (`self::C`, `$this::C`) | `references-constant` reader→`Owner::C` |
| `static::C` | resolve to the declaring class of `C` from the enclosing class's hierarchy |
| Inherited constant (declared on parent/interface, read in child) | edge targets the **declaring ancestor**, not the child |
| Cross-class read (`Other::C`) | edge → `Other`'s declaring class of `C` |
| Enum-case read (`Status::Shipped`) | edge → `Status::Shipped` (cases never inherited) |
| `Foo::class` | not a constant — no edge |
| Dynamic `$var::C` / `constant($name)` | unresolvable — no edge (change reads UNRESOLVED, honest) |
| Vendor/framework constant owner | app-scoped — no edge |
| Constant with no readers | declares-edge nodes it; change reads "analyzed", not UNRESOLVED |
| Property / `$fillable` / `$casts` change | still coarse (out of scope) |
| Class-modifier change | still coarse (correct) |
| Constant read in a parameter default / method attribute | reader edge drawn (the whole method is walked, not just its body) |
| Scope-relative read (`self`/`static`/`parent`/`$this`) nested in an anonymous class | dropped (its scope is the anon, not this class); a named read there still connects |
| Trait constant | stays coarse (`MemberResolver` keeps it non-resolvable) — a change reaches the using classes via the coarse seed, not UNRESOLVED |
| Constant redeclared in a subclass, read via `static::` | resolve to the lexically-nearest declaring class (accepted approximation for late-static-binding) |

## Resolved Questions

- **OQ1 (risk weighting) → RISK-BEARING.** `references-constant` is NOT added to
  `RISK_EXCLUDED_EDGE_TYPES`. A constant read is a *precise* value dependency (the reader's behaviour
  depends on the value), unlike CHA's over-approximated `override`; and the existing `references` edge
  is already risk-bearing. A widely-read constant honestly has a wide blast radius. (If a hub constant
  proves noisy in practice, revisit — but start honest.)
- **OQ2 (edge type) → NEW `references-constant`.** Distinct from the namespace-gated class
  `references` edge and separately classifiable for risk.
- **OQ3 (enum case == constant) → YES, one path.** Both are `ClassConstFetch`; cases resolve trivially
  to their own enum (no inheritance), constants resolve through the hierarchy.
- **OQ4 (read-nowhere) → declares edge nodes it.** A declared-but-unread constant/case nodes as a leaf
  → "analyzed, reaches nothing", never UNRESOLVED.

## STOP Conditions

- Any existing fixture's reach **shrinks**, or a previously-precise method seed turns coarse — the
  change must only *replace* a coarse constant/enum-case seed with a precise one and *add* nodes/edges.
- An **inherited-constant** change reaches **fewer** readers than the coarse whole-class seed did (the
  declaring-class resolution is wrong) — stop; this is the cardinal-rule regression the design exists
  to prevent.
- Flipping `resolvable` makes a constant change that resolves to no node read **"no impact"/"analyzed,
  0 reach"** with the file marked analyzed instead of UNRESOLVED — stop (the declares-edge node or the
  UNRESOLVED fallback is missing).
- The `ClassConstFetch` collection double-counts or regresses the relation-constant handling already in
  `ReferenceEdgeTracer` (a `Model::REL` in `with()`) — stop and reconcile.

## Findings

(empty — to be filled during implementation)
