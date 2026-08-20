# Blast radius of a symbol

`richter:impact` answers "what breaks if I change this?" before you change it.

```bash
php artisan richter:impact "App\Services\PostPublisher"
php artisan richter:impact PostPublisher                     # substrings work too
php artisan richter:impact "App\Services\PostPublisher" --explain    # chain from each reached entry surface
php artisan richter:impact "App\Services\PostPublisher" --json       # machine-readable, for scripting
php artisan richter:impact "App\Services\PostPublisher" --markdown   # PR-ready markdown
```

## Callers and dependencies

The report prints the symbol's callers (what breaks if you change it) and its dependencies (what it reaches), breadth-first. Each hop shows its depth (`d1`, `d2`, …) and the edge it was reached through, so a caller chain reads back to the entry point one hop at a time:

```text
Callers (what breaks if you change "App\Services\PostPublisher"):
  d1  App\Http\Controllers\PostController::publish  (via action-to-service)  — app/Http/Controllers/PostController.php
  d2  App\Http\Controllers\PostController  (via controller-to-action)  — app/Http/Controllers/PostController.php
  d3  route::POST::/posts/{post}/publish  (via route-to-controller)  — routes/web.php:24

Dependencies (what "App\Services\PostPublisher" reaches):
  d1  App\Events\PostPublished  (via action-to-event)  — app/Events/PostPublished.php
```

Every hop carries its defining file (and line, when known), project-relative, so you never have to grep for what a report names.

## Entry surfaces

Between the callers and dependencies, the report names the **entry surfaces** the callers walk reaches (routes, commands, schedules, and Livewire/Filament/Nova component classes), with the same annotations `detect-changes` carries: defining location, `[test-referenced]` / `[⚠ no test references this]` tags, security exposure and Pennant gates. A surface connected only by an association edge (a model relation or a model-to-policy link) is listed separately here too, as context rather than a caller.

`--explain` adds the shortest call chain from each surface down to the symbol.

The tags are orientation, not verdicts: `impact` reports no risk figure at all, and the section reads `(none)` when the walk reaches no surface.

## When a symbol matches nothing

A symbol that matches nothing is a lead rather than a dead end. The report names the nearest graph nodes, ranked by shared identifiers, so a lookup under the wrong root namespace surfaces the real node. When nothing in the graph resembles it, the report says how many nodes were scanned.

## `--json`

With `--json`, stdout is a single document:

```text
{target, callers, dependencies, entryPoints, associationEntryPoints, entryPointPaths,
 entryPointLocations, entryPointSecurity, entryPointGates, entryPointAuthGates,
 entryPointTestReferences}
```

The entry-point keys share `detect-changes`' vocabulary and shapes, so a consumer parses both reports identically, and each hop is `{depth, node, via, file?, line?}`. On failure, stdout is `{"error": "…"}`.
