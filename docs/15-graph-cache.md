# Graph cache

Building the code graph is the dominant cost of every command. Richter caches the built graph on disk (default: `storage/framework/cache/richter/graph.json`), keyed by a content fingerprint of everything the build reads: `app/`, `routes/`, `resources/views`, the relevant config, and the package versions.

Any input change rebuilds automatically, so a hit can only ever serve the graph the current code produces. There is no TTL to tune and no stale window.

- The cache is on by default; set `richter.cache.enabled` to `false` to disable it.
- Config that changes what the build reads is part of the fingerprint, so switching a value rebuilds instead of serving a graph the current settings would not produce. All three `second_hop` scopes fingerprint differently, so an entry built at one is never served at another.
- `--no-cache` (on every command) bypasses it for one run, the escape hatch for an input the fingerprint does not cover.
- A corrupt or mismatched cache file reads as a miss and is rebuilt; it never fails a run.

## Profiling a build

`--profile` (on `richter:detect-changes`) forces a build and prints a phase-by-phase timing split to stderr, for judging where build time goes on a given codebase. A cache hit leaves nothing to time, so it refuses one. It still reuses the stored merge base, which keeps the timings representative of the build this project gets.

The `brain-analyze` line names the path that ran: `full`, `scoped`, or `scoped-rejected`. A diff holding nothing the graph is built from says that instead of printing an empty table. Add `--no-cache` to time a cold build instead.

## Scoped rebuilds

A miss does not always cost a full analysis. The entry also stores the graph Laravel Brain produced and a record of the inputs it was built from, so a miss can compare the two records and ask which inputs actually differ, rather than only whether any did.

When every difference is a changed file under `app/` (nothing added, nothing deleted, no config or package change, nothing outside `app/`), Brain re-traces only the controllers those files declare and merges the result into the stored graph. Everything else is a full build.

A test asserts that the merged graph is identical either way. Two things are worth knowing about when it engages:

- **It helps a changed file that declares a controller.** A diff touching only services, models or jobs has no controllers to re-trace, so it is refused and costs nothing extra.
- **Every ambiguous case is refused.** A wrong refusal costs one full build, which is what a full build costs anyway. A wrong acceptance would produce a graph quietly missing edges, which is the failure this package exists to prevent, so `--no-cache` remains the way to force a full analysis if you ever suspect one.
- **A refusal says which precondition refused.** `--profile` prints it under the timing table as `no scoped rebuild: <reason>`, followed by the input that refused: the differing non-file input, the changed path outside `app/`, or the changed file the stored graph attributes nothing to. `no-cache-entry` is the ordinary first run and resolves itself; every other reason names something to look at.
- **`no-change` compares against the cached graph, not against git.** The refusal means every hashed input matches the entry on disk, which is whatever the last build stored. That covers profiling a tree you just warmed the cache on. It also covers a less obvious case: an edit that reproduces content some earlier run already built, which `git diff` still shows as a change. Both are correct refusals: the graph for that content is already cached. To measure a scoped build, make the edit new, and make it after the run that warmed the cache.
