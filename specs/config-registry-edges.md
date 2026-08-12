# Config-registry edges

## Overview

A subsystem dispatched through a config-keyed class registry is reachable from nothing. `config/calculators.php`
names app classes as `::class` constants; a method resolves one with `config("calculators.{$id}")`. No
static call connects the two, so every class in the registry has no caller and a change to one reports
zero entry points however central it is. A registry this shape can name hundreds of classes, so the
gap scales with how central the subsystem is.

This links the lookup to the classes the file names.

## Assumptions

- **Key granularity where the key is knowable, file granularity where it is not.** A fully literal
  key is looked up in the config file's own returned array, and only the app classes that value names
  are drawn. An interpolated key (`config("calculators.{$id}")`) names no key to look up, and a file
  whose array is built by a loop, spread from a default, or keyed by a constant cannot be walked at
  all — in both of those the whole file's class list is used. A registry's *keys* are frequently not
  statically enumerable; its *values* are, because a `::class` constant resolves through the config
  file's own `namespace` declaration.
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
| `config('settings.handler')` | Edge to the one class that key's value names. The key is literal, so it is looked up rather than approximated. |
| `config('settings.timezone')` | Nothing. The key resolves to a string; a string names no class. This is the shape that made the first release over-report. |
| `config('settings.driver')` where the value is `env('X')` | Nothing. The key was found and its value names no class — a determined answer, not an unknown. |
| `env('X', Basic::class)` as the value | Edge to `Basic`. The default IS what the application uses unless the environment overrides it, so the class is named right there. A value is judged by whether it names a class, not by the expression it is wrapped in. |
| `config('settings.absent')` in a fully literal array | Nothing. Every key there is a plain literal and nothing is spread in, so a miss is genuinely a miss. |
| `config('calculators.basic')` where the file returns a variable | Whole class list. The array cannot be walked, and over-approximating is the safe direction for a reach-adding lane. |
| Two literal keys into one file | Both answered. Reads are deduped on the (file, key) pair; deduping on the file name alone would drop one, source-order dependent. |
| `Config::get('calculators.x')` | Recognised, same as the helper. Matched case-insensitively, as PHP resolves method names. |
| Two classes in one file, only the second reads config | Attributed to the second. Class-scoped like `StaticCallEdgeTracer`, so the registry never hangs off a caller that does not read it. |
| A read inside an anonymous class | Attributed to the method that builds it, never to an invented member on the file's primary class. |
| `config($key)` | Nothing. Guessing a file would point the reader at an unrelated subsystem. |
| Config file naming only vendor classes | Not a registry. |
| Config file naming no class at all | Not a registry. |
| Registry keys built at runtime (`$class::identifier()`) | Irrelevant. Only the class list is read. |
| A class added to a registry file, no `app/` file touched | Rebuilds: `config/*.php` is a fingerprint input. |
| `config/` subdirectories | Not scanned. Laravel keeps none, and the one thing that appears there is a vendor-published tree nothing here reads. |

## Not in scope

Resolving a value the array literal does not state outright: a key merged in from a package's own
config, a class name assembled from a string, a `::class` reached through a constant. Those read as
"no class named here", which is the correct answer for the lane even when a runtime value would
differ — a class this cannot see, it also cannot link.

Narrowing a **numeric** path segment (`config('handlers.0')` into a list). Only string keys are
matched, so a list index reads as unwalkable and the whole file's class list is used. That is the
conservative direction and the failure mode of getting it wrong is the expensive one: PHP's implicit
indexing interacts with explicit integer keys in ways that are easy to model *almost* right, and an
almost-right answer here is a confidently narrow edge pointing at the wrong class. Worth doing only
against a real occurrence.

Narrowing an *interpolated* key by its literal prefix (`config("services.{$driver}.class")` → only
the `class` sub-keys). Possible in principle, and unnecessary so far: the prefix in the observed
shape is the file itself.

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
