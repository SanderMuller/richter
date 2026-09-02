# Graph cache

Building the code graph is the dominant cost of every command. Richter caches the built graph on disk (default: `storage/framework/cache/richter/graph.json`), keyed by a content fingerprint of everything the build reads: `app/`, `routes/`, `resources/views`, the relevant config, and the package versions.

Any input change rebuilds automatically, so a hit can only ever serve the graph the current code produces. There is no TTL to tune and no stale window.

- The cache is on by default; set `richter.cache.enabled` to `false` to disable it.
- Config that changes what the build reads is part of the fingerprint, so switching a value rebuilds instead of serving a graph the current settings would not produce. All three `second_hop` scopes fingerprint differently, so an entry built at one is never served at another.
- `--no-cache` (on every command) bypasses it for one run, the escape hatch for an input the fingerprint does not cover.
- A corrupt or mismatched cache file reads as a miss and is rebuilt; it never fails a run.

## Baking an entry at deploy time

`richter:warm` builds the graph on purpose and leaves it on disk. Every other command builds it as a side effect of a report, so without this there is no way to ask for a graph — and no way to find out whether the one you baked is being used.

```bash
php artisan richter:warm          # build it and store it
php artisan richter:warm --check  # does the stored entry still match this tree?
php artisan richter:warm --json   # for a deploy step to branch on
```

```text
Built the code graph in 8.8s.
  fingerprint  6edb07f18e3d6fac36cc12a9671ab797
  nodes        94,318
  entry        /app/storage/framework/cache/richter/graph.json (10.4 MB)
```

A matching entry is not rebuilt — the fingerprint sweep is already the currency check — and the report says `already current` rather than implying a build. Both modes exit non-zero when the answer is no, so a deploy step can gate on them.

Point `cache.directory` inside the deployed artifact. The default sits under `storage/`, which hosted platforms commonly provision as ephemeral or per-container, so an entry baked there at deploy time may not be the one the runtime reads.

The entry is portable: it carries project-relative paths, not the build machine's. Bake it on one machine and ship the file.

### What a baked cache buys, and what it does not

It removes the build. It does not remove the fingerprint sweep, which runs on **every call in every process** — before the in-memory memo is consulted, so a long-lived worker pays it per call too. That is what makes staleness designed out rather than expired out.

What a warm hit still costs, and how it scales:

| Step | Scales with |
|---|---|
| The sweep | One content hash per input file, across `app/`, `routes/`, `resources/views` and `config/` — so, your file count and their total size |
| The decode | The entry's size on disk, which `richter:warm` prints |
| The revive | The graph's edge count |

Measure it on the host you care about rather than trusting a number from someone else's machine. The sweep is per-file reads, and a network filesystem is not a local SSD.

`richter:warm --check` is the instrument for that. It builds nothing and writes nothing, but it does sweep, decode and revive, which is exactly the residual and nothing else:

```bash
time php artisan richter:warm --check
```

Run it on the target host against a current entry, and you have a real number instead of an estimate.

### Two things silently invalidate a baked entry

The PHP version is part of the fingerprint, at full patch precision. A build container on 8.5.8 and a runtime on 8.5.9 miss on **every request, forever**, with nothing in the logs — the cache is failure-tolerant by design, so a miss just rebuilds. Any config difference that reaches the fingerprint does the same.

`--check` is how you see either:

```text
The cached entry does NOT match this tree — every run rebuilds.
  reason       inputs-changed
  differing non-file inputs: php (8.5.8 → 8.5.9)
```

It names the differing input rather than reporting that one differs. It also separates a stale entry from a broken one. A corrupt entry reports `UNUSABLE`, because a rebuild will not fix it and someone has to remove the file.

`--check` builds nothing and writes nothing, but it is not free either: it revives the stored graph to prove the entry revives — which is what also makes it the instrument for measuring a warm hit, above. Fine once per deploy; not something to put in a health-check endpoint.

Run one warm per deploy. Two concurrent warms both build and both rename, so the last one wins and the other may report a failure for work that succeeded.

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
