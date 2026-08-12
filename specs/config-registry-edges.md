# Config-registry edges

## Overview

A subsystem dispatched through a config-keyed class registry is reachable from nothing. `config/calculators.php`
names app classes as `::class` constants; a method resolves one with `config("calculators.{$id}")`. No
static call connects the two, so every class in the registry has no caller and a change to one reports
zero entry points however central it is. A registry this shape can name hundreds of classes, so the
gap scales with how central the subsystem is.

This links the lookup to the classes the file names.

## Assumptions

- **File granularity, not key granularity.** The registry's *keys* are frequently not statically
  enumerable — the observed file builds them by looping the class list and calling a static method on
  each — and the call's key is dynamic anyway. Its *values* are enumerable: a `::class` constant
  resolves through the config file's own `namespace` declaration. So a `config('calculators…')` call
  reaches every app class `config/calculators.php` names, and the lane does not try to pick one.
- **The fan-out is excluded from risk.** A runtime-chosen target is honestly "any of these", the same
  over-approximation `override` makes for polymorphic dispatch, and `override` is risk-excluded for
  exactly this reason. Reach and entry-point discovery still flow — that is the entire win, since it
  is what gives a registry-dispatched class any caller at all — but one edit to the resolver must not
  saturate the level on breadth.
- **App classes only.** A vendor class named in a config file is reached from everywhere; linking one
  would attach the framework to any method that reads a config value.

## 1. Current state

Nothing in `src/` reads an app config file for `::class` constants. `entry_point_roots` does not help:
it makes a directory *traced*, never *promoted*, so the classes gain edges among themselves and still
no entry point reaches them. Adding the directory widens impacted reach and still leaves entry points
at 0.

## 2. Proposed change

`ConfigRegistryTracer`, fed by the consolidated AST pass like the other per-file tracers.

1. `config/*.php` is scanned once per build (depth 0) for `::class` constants resolving to app classes,
   keyed by file basename. A file naming none is not a registry.
2. Per method, `config('x.y')` and `Config::get('x.y')` are matched. The statically known head of the
   key names the file: a whole literal, or the literal prefix of an interpolated string — which is the
   case this lane exists for. A fully dynamic argument names nothing and draws nothing.
3. One `config-registry` edge per (calling member, registry class).

`config/*.php` becomes a graph-build input in `GraphCache::inputFiles()`. Before this lane nothing the
build read lived there, so adding a class to a registry would otherwise have served the previous graph
and left the new class reporting no callers — the stale answer the fingerprint exists to design out.

## Edge Cases

| Case | Behaviour |
|---|---|
| `config("calculators.{$id}")` | Edge to every app class the file names. The key is dynamic; the file is not. |
| `config('calculators.basic')` | Same. A deeper static key does not narrow the match yet — see below. |
| `Config::get('calculators.x')` | Recognised, same as the helper. Matched case-insensitively, as PHP resolves method names. |
| Two classes in one file, only the second reads config | Attributed to the second. Class-scoped like `StaticCallEdgeTracer`, so the registry never hangs off a caller that does not read it. |
| `config($key)` | Nothing. Guessing a file would point the reader at an unrelated subsystem. |
| Config file naming only vendor classes | Not a registry. |
| Config file naming no class at all | Not a registry. |
| Registry keys built at runtime (`$class::identifier()`) | Irrelevant. Only the class list is read. |
| A class added to a registry file, no `app/` file touched | Rebuilds: `config/*.php` is a fingerprint input. |
| `config/` subdirectories | Not scanned. Laravel keeps none, and the one thing that appears there is a vendor-published tree nothing here reads. |

## Not in scope

Narrowing a fully static deeper key (`config('services.stripe.class')`) to that subtree. It needs the
array literal evaluated, which the registry shape above defeats anyway — that file's return value is
built by a loop. Cohesive config files make the file-level match harmless in the meantime.

## Implementation

### Phase 1: The lane (Priority: HIGH)

**ID:** lane · **Depends:** none

- [x] `ConfigRegistryTracer` with `registries()` and `edgesForClassLikes()`.
- [x] Tests — both call forms, interpolated and static keys, the dynamic-argument silence, the
      vendor-class and no-class silences, a project with no `config/`.

### Phase 2: Wire it in (Priority: HIGH)

**ID:** wiring · **Depends:** lane

- [x] Collected in the consolidated AST loop, registries scanned once up front.
- [x] `config-registry` joins `RISK_EXCLUDED_EDGE_TYPES`.
- [x] `config/*.php` added to `GraphCache::inputFiles()`; `FORMAT_VERSION` 11 → 12.
- [x] End-to-end test over the fixture: the registry class gains its caller, the lookup reaches it
      downstream, and editing the registry file invalidates the cache.
- [x] README and configuration docs.
