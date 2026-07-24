# Plan 049: Skip call-free methods in EntryPointTracer (fewer MethodTracer calls)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat -- src/Tracers/EntryPointTracer.php tests/Unit/EntryPointTracerTest.php`
> against the plan's "Planned at" commit. If `EntryPointTracer::trace()` or
> `methodsOf()` changed since, compare the "Current state" excerpts against the
> live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **State**: WRITTEN — not executed.
- **Priority**: P3 (measured a small win; see "Why / honest sizing")
- **Effort**: S
- **Risk**: LOW — **output-invariant**. A skipped method provably emits zero call edges, so the
  built graph, `affected-tests`, risk levels, and every benchmark case are byte-identical before
  and after. The only observable change is fewer `MethodTracer::traceMethod` calls.
- **Depends on**: nothing.
- **Category**: performance (graph-build wall-time)
- **Planned at**: commit `acb128b`, 2026-07-24
- **Context**: full findings in `an internal performance analysis` (§4, §5). Read it
  before executing — it explains why this is a *small* win and where the real lever is (upstream).

## Why this matters / honest sizing

`entry-point-tracer` is ~32% of a fresh graph build (`an internal performance analysis`
§3). It calls Brain's `MethodTracer::traceMethod($fqcn, $method)` once per **non-abstract method**
of every entry-point class — **1141 calls** on the host app (§4). A method whose body contains no call
node (`MethodCall`/`StaticCall`/`NullsafeMethodCall`/`FuncCall`/`New_`) cannot produce any call
edge, so tracing it is pure overhead.

**Measured ceiling: 8.5% of calls (97/1141) on the host app**, and those are the *cheapest* traces
(nothing to walk), so the wall-time saving is smaller still. This is a safe, cheap cleanup — **not**
the fix for the 120s build. The structural levers are upstream (Brain `traceMethod` memoization /
direct-callee mode; incremental `analyze()`) — see "Upstream follow-up" and the report §5–6. Do not
oversell this plan's impact; land it because it's free and correct, and re-measure.

## Current state

`src/Tracers/EntryPointTracer.php`:

- `trace()` (line ~99) loops `foreach ($this->methodsOf(...) as $method)` and calls
  `traceMethod()` for **every** returned method.
- `methodsOf()` (line ~159-182) returns the names of all non-abstract `ClassMethod`s in the class,
  with no regard to whether the body contains a call.

## Change

Filter out call-free methods at the point `methodsOf()` enumerates them, so `trace()` never issues a
`traceMethod` call that can only return `[]`.

1. **Extract a pure, unit-testable predicate** `methodHasCallNode(ClassMethod $m): bool` (private
   static) — true iff `new NodeFinder()->findFirstInstanceOf($m->stmts, T)` is non-null for any
   `T` in `[MethodCall, StaticCall, NullsafeMethodCall, FuncCall, New_]`. (Recursive find, so calls
   nested in closures/conditionals inside the method count.)
2. In `methodsOf()`, include a method name only when `! $method->isAbstract() && methodHasCallNode($method)`.
3. Add a one-line comment tying the skip to the report and asserting the invariant ("a body with no
   call node emits no call edge, so skipping it cannot change the graph").

Keep the existing `resolvedAstsByPath` map-first / parse-fallback path unchanged — the predicate
runs on whichever AST `methodsOf()` already has.

Nothing else changes: the dedupe, event-listener, and binding-edge paths are untouched.

## Test plan (test-first)

Add to `tests/Unit/EntryPointTracerTest.php`:

1. **Predicate unit test** — parse a small class fixture with (a) a method that only
   `return ['a' => 1];` / returns a scalar / assigns a property with no call, and (b) a method that
   makes a `$this->service->do()` / `Foo::bar()` / `new Baz()` call. Assert `methodsOf()` (or the
   predicate) includes only (b). Cover a closure-nested call inside an otherwise call-free method →
   must be treated as having a call (not skipped).
2. **Output-invariance test** — a fixture entry-point class with both kinds of method traced through
   `trace()` produces exactly the same edge set as before the change (the call-free method
   contributes nothing). If a fixture project already exercises `trace()`, assert its edge list is
   unchanged.

Write these red first (predicate absent / includes call-free methods), then implement.

## Verification

- `vendor/bin/pest tests/Unit/EntryPointTracerTest.php` — new cases green.
- `vendor/bin/pest` — full suite green (output-invariance: no other test shifts).
- `vendor/bin/phpstan analyse` + `vendor/bin/pint --dirty` + rector — clean.
- **Optional re-measure** (host app): rerun `php artisan richter:detect-changes --profile` and
  confirm `entry-point-tracer` dropped by roughly the call-free share; record it in the report §3.

## STOP conditions

- Any existing test's expected edges/entry-points/risk change → the skip is **not** output-invariant
  as claimed; stop and report (a call-free method that nonetheless produced an edge means the call-
  node set is incomplete — widen it, don't loosen the test).
- The predicate would skip a method Brain's `MethodTracer` treats as an edge source for a
  non-call construct not in the node set → stop; extend the node-type list rather than ship a
  regression.

## Upstream follow-up (not code in this plan)

The real entry-point-tracer win is Brain-side (report §5): extend
`internal/upstream-brain-incremental-issue.md` with a third ask — **`MethodTracer` should memoize /
share a visited-set across `traceMethod` calls, or expose a direct-callee (depth-1) mode** — so the
shared-downstream subtree isn't re-walked once per reaching entry method. That is the change that
would move `entry-point-tracer` materially, and richter cannot make it locally.
