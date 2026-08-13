# Incremental Brain analysis

<!-- spec:planned-at 29ea7e4cd7ad5cbc2741e0a4003e4e6eadd8ab24 2026-08-13 +uncommitted -->

## Overview

Laravel Brain 2.4.0 added `ProjectAnalyzer::scopedTo($changedFiles, $previous)` — trace only the
controllers declared in the changed files and merge the result into a previous run's graph. Richter
does not use it (verified: no reference anywhere in `src/`). Richter's primary invocation *is* a diff,
so today every run pays for tracing every controller in the project to answer a question about a
handful of files.

This wires that up: persist Brain's own graph beside richter's, and on a run whose changed-file set
meets the preconditions, ask Brain for a scoped rebuild instead of a full one.

## Assumptions

These inferences have not been signed off. Each is recorded so the spec can be reviewed by reading this
section alone, and the load-bearing ones also appear as open questions rather than as settled defaults.

- **Brain's `Graph` can be round-tripped through JSON by richter.** Verified reconstructable, not
  verified lossless — see Open Question 1. `Graph::toJson()` emits `meta`/`nodes`/`edges`; `Node` and
  `Edge` are public constructors carrying every field; `addNode()`/`addEdge()` are public.
- **The provenance the merge needs travels in `Node->data['file']`** (`GraphProvenance.php:55`), so it
  survives a faithful round-trip. Not separately verified against a real graph.
- **A scoped run is attempted only when richter can prove the file-set preconditions**, and any doubt
  falls back to a full build. Chosen, not requested.
- **The cache entry stores Brain's graph in the same file as richter's** rather than a sibling. Chosen
  for atomicity — two files can disagree after a partial write.
- **`FORMAT_VERSION` is bumped** because the cache payload gains a key. Follows the repo's own rule.
- **No new config key.** Chosen: the fallback is automatic and fail-closed, so there is nothing for a
  user to tune. Open Question 3 asks whether an escape hatch is wanted anyway.

---

## 1. Current state

`CodeGraphBuilder::build()` (`src/Graph/CodeGraphBuilder.php:79`) always runs a full analysis:

```php
$analysis = new ProjectAnalyzer()->analyze($projectRoot, $onProgress ?? …);
```

Brain's graph is consumed immediately — canonicalised into richter's own edge list at `:98-126` — and
then discarded. Nothing retains it.

`GraphCache` (`src/Graph/GraphCache.php`) persists **richter's merged `CodeGraph`** only:
`write()` at `:276` encodes `['fingerprint' => …] + $graph->toArray()`. The fingerprint at `:141`
hashes every build input, so any edit to any tracked file misses the cache and forces a full rebuild.
That is the structural problem: richter's primary invocation is a diff, so the content fingerprint
almost always misses and each run pays a full build. Until now there was nothing to do about it —
a full analysis was the only kind Brain offered.

## 2. What Brain gives us

`ProjectAnalyzer::scopedTo(array $changedFiles, Graph $previous): static` — fluent, consumed by the
next `analyze()` call and then reset (`:183-186`), so it cannot silently scope a later run.

Its docblock states the division of responsibility precisely:

> Everything else on the pass still runs in full — routes, commands, channels, the split — because
> those are a couple of percent of a scan between them. What it skips is tracing every controller in
> the project, which is nearly all of the rest.
>
> The caller owns the decision to use this: it is only sound when no file was added or deleted and
> nothing outside `app/` moved. Whether the changed files' own call graph survived the edit is not
> knowable up front, so that part is checked here and raises `ScopedRebuildNotApplicable` when it does
> not hold.

So the contract splits cleanly:

| Precondition | Who checks it |
|---|---|
| No file added or deleted; nothing outside `app/` moved | **richter** |
| The changed files' own call graph survived the edit | **Brain**, by comparing owned-edge key sets before and after (`ProjectAnalyzer.php:384-391`); throws on mismatch |

`IncrementalMerge::applyPartial()` (`:130`) substitutes each changed file's nodes in place and carries
untouched ones over **in the previous build's order**, explicitly so the merged graph is not merely
equivalent but identically ordered. That matters here: richter's whole correctness gate is a
byte-identical graph.

## 3. Proposed changes

### 3.1 Persist Brain's graph in the cache entry

`GraphCache::write()` gains a `brainGraph` key holding `json_decode($analysis->fullGraph->toJson())`.
`read()` validates and reconstructs it into a `Graph` via `addNode()`/`addEdge()`, applying the same
fail-closed discipline the existing `validEdges()`/`validNodeMetadata()` use: any mis-shaped entry
discards the whole entry rather than half-loading it.

This requires `build()` to hand the Brain graph back to the cache, which it currently does not — see
3.4.

### 3.2 The merge base — the part that does not work today

**A cache hit and a scoped rebuild are mutually exclusive as the cache is currently written**, and this
is the spec's central design problem rather than a detail.

`fingerprint()` (`:141-173`) folds everything into one opaque hash: format version, PHP version, both
package versions, the effective root namespace and config, and a content hash per input file.
`read($fingerprint)` serves an entry only on an exact match. So:

- fingerprint **matches** → the cached richter graph is served whole and no build runs at all; there is
  nothing for `scopedTo()` to do;
- fingerprint **misses** → `read()` returns null, and there is no previous graph to merge into.

Any design that just "reads the previous graph" gets null every time. Bypassing the fingerprint check
is worse than useless: it also covers package versions, Brain config, routes and views — inputs a
file-set check would not look at — so a scoped run could merge onto a graph built by a different
version of Brain.

**The fix is to make the entry say what it was built from.** The cache entry gains the
`path => contentHash` map that `fingerprint()` already computes internally, plus the non-file inputs as
their own value. A later run then answers a sharper question than "same or not":

| Comparison | Meaning |
|---|---|
| Non-file inputs differ (version, config, namespace, format) | **No reuse.** Full build. |
| File map differs by a key added or removed | **No reuse** — Brain's precondition names added/deleted files explicitly. |
| File map differs only in values, and every differing path is under `app/` | **Scoped rebuild** over exactly those paths. |
| Nothing differs | Ordinary cache hit; serve the graph whole, as today. |

This also **removes the need for a separate file-set check**: the map diff yields additions, deletions
and modifications directly, which is strictly better than inferring them from the changed-file list —
that list describes the git diff, whereas the map describes what actually differs from the graph on
disk. Those are not the same set when the working tree has moved on since the entry was written.

### 3.3 Decide scoped-vs-full in richter

A new `Support\ScopedRebuild` (name provisional) answers one question: *given the previous entry's
input record and the current one, is a scoped rebuild sound, and over which files?* It returns the
file list, or null for a full build, applying the table above.

Deliberately conservative in every ambiguous case. A miss costs one full build, which is today's
behaviour. A false positive produces a wrong graph.

### 3.4 Thread it through the builder

`build()` gains an optional previous-graph parameter. When `ScopedRebuild` returns a file list:

```php
$analyzer = new ProjectAnalyzer();

try {
    $analysis = $analyzer->scopedTo($scopedFiles, $previousBrainGraph)->analyze($projectRoot, …);
} catch (ScopedRebuildNotApplicable) {
    // Brain found the edit moved a call, so the previous graph's edges cannot be reused. One full
    // analyze() — the same cost as today, never a wrong graph.
    $analysis = new ProjectAnalyzer()->analyze($projectRoot, …);
}
```

A fresh `ProjectAnalyzer` on the retry, not the same instance: `scopedTo` state is consumed on the
first `analyze()`, but constructing a new one makes that independent of Brain's internals.

The tracer branch is **unaffected** — it is richter's own per-file pass and already parses only what it
needs. This spec changes Brain's half only.

### 3.5 How big is the prize? — not yet measured

**This is the spec's weakest number, and phase 4 exists to settle it.** There is no current measurement
of what `brain-analyze` costs as a share of a build. Earlier phase splits were taken against an older
laravel-brain, before the performance work that landed in 2.4.0 — the same work that collapsed
`entry-point-tracer` from a third of the build to 2% — so they cannot be carried forward.

The synthetic corpus in `autoresearch/` puts `brain-analyze` at 21–25%, and that corpus is documented as
over-weighting richter's own per-file work: its classes are shallow, which is precisely the input
`analyze()` is cheap on. Treat it as a floor, not an estimate.

So no part of this spec is justified by a share-of-build figure. Phase 4 measures the scoped path
against the full path directly, on the corpus, and that measurement decides whether this ships.

### 3.6 Emit the outcome

`--profile` gains a `brain-analyze` extra saying which path ran (`scoped` with the file count, `full`,
or `scoped-rejected` when Brain threw). Without it a scoped build that silently falls back looks
identical to one that never tried, and the first question anyone asks about this feature is whether it
engaged.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| No previous Brain graph or input record cached (first run, cleared cache, format bump) | Full build. Covered by `Tests` in `cache-payload`. |
| A changed file is new | Full build — Brain names added files as out of contract. `ChangedFileSymbols::$isNewFile`. |
| A changed file was deleted | Full build — a removed key in the input record (3.2), so no git-diff plumbing is needed to see it. |
| A changed file is outside `app/` (`routes/`, `config/`, a Blade view) | Full build — Brain's precondition names this explicitly. |
| The edit moved a call, so owned edges differ | Brain throws `ScopedRebuildNotApplicable`; richter retries full. Covered by `Tests` in `scoped-build`. |
| Cached Brain graph is corrupt or half-written | The whole cache entry is discarded, exactly as a corrupt edge list already is; full build. |
| A file changed *between* the cache write and this run | The input-record comparison is computed fresh at read time, so such a file appears as a differing value and is either scoped or forces a full build — never silently reused. |
| Non-UTF-8 in a node label | `Graph::toJson()` uses `JSON_PARTIAL_OUTPUT_ON_ERROR`, so the round-trip can be lossy — Open Question 1 and a STOP condition. |
| Long-lived MCP server builds twice in one process | Unchanged behaviour; the in-memory memo still short-circuits before any of this. |
| `richter.cache.enabled` is false | No previous graph is ever read or written; always full. |

## Implementation

### Phase 1: Round-trip Brain's graph through the cache (Priority: HIGH)

**ID:** cache-payload · **Depends:** none

- [ ] Add a `BrainGraphCodec` (`src/Graph/`) — `toArray(Graph): array` and `fromArray(mixed): ?Graph`, the second fail-closed on any mis-shaped node/edge, mirroring `GraphCache::validEdges()`.
- [ ] Split `fingerprint()` into the record it already computes and the hash over it, so the per-file map and the non-file inputs are available separately without changing the hash a single byte.
- [ ] Extend `GraphCache::write()`/`read()` with a `brainGraph` key and an `inputs` record; a payload missing either reads as "no merge base", never as an error.
- [ ] Add a merge-base read path — fetch the stored entry by cache file rather than by fingerprint equality, returning the graph *and* its input record for comparison.
- [ ] Bump `GraphCache::FORMAT_VERSION` with a history line — the payload gained two keys, and an entry written before them offers no merge base.
- [ ] Tests — the hash is byte-identical before and after the split (a characterisation test, so the refactor cannot silently invalidate every cache in the wild); round-trip a real fixture-project Brain graph and assert node ids, edge ids and `data['file']` provenance survive; a truncated payload yields null, not a partial graph.

### Phase 2: The soundness decision (Priority: HIGH)

**ID:** scope-decision · **Depends:** none

- [ ] Add `Support\ScopedRebuild::filesFor(array $previousInputs, array $currentInputs): ?array` implementing the comparison table in 3.2 — non-file inputs must match exactly, keys must match exactly, and every differing value must be under `app/`.
- [ ] Tests — one case per row of that table: a version bump, a config change, an added key, a removed key, a change outside `app/`, and the positive case. Brain `realpath`-normalises whatever it is handed (`ProjectAnalyzer.php:201`) before matching against provenance, so also prove the returned paths *resolve to* the files the provenance map keys on — a path resolving to nothing scopes to zero files, which is a green run against a stale graph and the most dangerous failure this class can have.

### Phase 3: Use it in the build (Priority: HIGH)

**ID:** scoped-build · **Depends:** cache-payload, scope-decision

- [ ] Thread the previous Brain graph from `GraphCache` into `CodeGraphBuilder::build()`.
- [ ] Call `scopedTo(...)` when the decision says so; catch `ScopedRebuildNotApplicable` and retry with a fresh `ProjectAnalyzer`.
- [ ] Emit the path taken as a `brain-analyze` phase extra.
- [ ] Tests — **the load-bearing one**: on the fixture project, a scoped build and a full build must produce a byte-identical `CodeGraph` (same edges, same order, same metadata). Plus: the rejection path retries and still produces that graph; the profile extra names the path.

### Phase 4: Measure it (Priority: HIGH)

**ID:** measure · **Depends:** scoped-build

- [ ] Extend `autoresearch/graph-build-bench.php` with a warm-previous-graph mode — build once, touch one file, rebuild — and report `brain-analyze` for scoped vs full.
- [ ] Record the numbers in the autoresearch research doc; **if the scoped path does not beat the full path on `brain-analyze`, stop and report** rather than shipping the complexity.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The fingerprint can be split into a comparable record without changing its value.** If extracting
   the per-file map and non-file inputs changes the hash, every cache entry in the wild invalidates on
   upgrade — acceptable only as a deliberate `FORMAT_VERSION` decision, never as a side effect.
2. **Brain's `Graph` round-trips losslessly through richter's cache.** If `toJson()`'s
   `JSON_PARTIAL_OUTPUT_ON_ERROR` or any unexported field means the reconstructed graph is not
   equivalent, the merge input is wrong and every scoped build after it is wrong. Do not "mostly"
   round-trip it.
3. **A scoped build's merged graph is byte-identical to a full build's.** This is the whole
   correctness gate. A graph that is equivalent-but-reordered is a failure here, not a nitpick —
   `applyPartial` preserves order specifically so this holds.
4. **`ScopedRebuildNotApplicable` is the only way Brain signals inapplicability.** If a scoped run can
   return a quietly-wrong graph instead of throwing, richter cannot use the feature at all.
5. **The scoped path measurably beats the full path.** If phase 4 shows it does not, this spec has
   bought complexity for nothing.

---

## Open Questions

1. *(resolved — see Resolved Questions 3)*
2. *(resolved — see Resolved Questions 1)*
3. **Should there be an opt-out?** The fallback is automatic and fail-closed, so nothing needs tuning.
   But a `richter.incremental` flag would let a consumer bisect a suspected wrong graph without
   downgrading the package. Cheap to add, one more key to document.
4. *(resolved — see Resolved Questions 2)*

---

## Resolved Questions

1. **Does richter know which files a diff deleted, at the point the decision is made?**
   **Decision:** It does not need to. **Rationale:** The merge-base design in 3.2 compares the stored
   input record against the current one, and a deletion appears as a removed key — no git-diff plumbing
   required. It is also the better source: the git diff describes the branch, the input record
   describes what actually differs from the graph on disk, and those are not the same set once the
   working tree has moved on.

3. **Is Brain's `Graph → JSON → Graph` round-trip lossless in practice?**
   **Decision:** Yes, on the public surface, verified empirically (see Findings). **Rationale:** A
   fixture-project graph round-trips byte-identically through `toJson()` → `addNode()`/`addEdge()`/
   `setMeta()` → `toJson()`, with all `data['file']` provenance intact. No upstream `Graph::fromJson()`
   is needed.

2. **Does the fingerprint still make sense unchanged?**
   **Decision:** The hash is unchanged; the entry additionally stores the record the hash was computed
   over. **Rationale:** An opaque hash answers "same or not" and nothing else, which is exactly why a
   scoped path could never obtain a merge base. Storing the record makes the difference inspectable
   without weakening the equality check that governs an ordinary cache hit.

---

## Findings

**Status: gate checked, nothing built.** STOP condition 2 was the cheapest of the load-bearing ones and
gates every phase, so it was tested before any implementation started. It passes. The rest of the spec
is not started — it is a cache-format change with a `FORMAT_VERSION` bump, and starting it inside a
release cycle without room to finish and measure it would put a half-migrated cache in front of
consumers. Phase 1 is the next thing to pick up, with Resolved Question 3 already banked.

**STOP condition 2 — verified, not assumed.** Built the fixture project's Brain graph, serialised it with
`Graph::toJson()`, reconstructed it through the public `addNode()`/`addEdge()`/`setMeta()` surface, and
re-serialised:

```
nodes 29 -> 29, edges 21 -> 21
json identical: YES
nodes carrying data['file']: 28 -> 28
```

Byte-identical, and every node's `data['file']` provenance — the field `GraphProvenance` keys the merge
on — survives. The `JSON_PARTIAL_OUTPUT_ON_ERROR` concern does not bite on this input; it remains a
theoretical risk for a project with invalid UTF-8 in a symbol name, which is why the phase 1 test should
keep asserting the round-trip rather than treating this one run as settling it forever.
