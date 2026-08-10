# Plan 051: richter-side graph-build perf transfers from the Brain autoresearch handoff

> **Executor instructions**: Follow this plan step by step. Run every verification command and
> confirm the expected result before moving on. If a "STOP conditions" item occurs, stop and report.
> When done, update the status row in `plans/README.md`. **Each lever below is independently
> shippable — do them in order (A → B → C); A needs nothing, B/C have Brain-release dependencies
> noted inline.**
>
> **Drift check (run first)**: confirm the cited lines still match — `src/Support/AppFiles.php:29`
> (own `ParserFactory`), `src/Graph/GraphCache.php:~98` (`hash_file('xxh128', …)` per input file),
> `src/Graph/CodeGraphBuilder.php` `consolidatedTracerEdges()`. On a mismatch, re-derive against the
> live code before proceeding.

## Status

- **State**: CLOSED 2026-08-10. **Lever B EXECUTED** in its safe form (commit `bb12564`).
  **Lever A REJECTED on measurement** — the release gate lifted and the win did not survive it (see
  the 2026-08-10 re-evaluation below). **Lever C REJECTED with it**, same cause.

## Re-evaluation after the Brain release (2026-08-10)

Both deferrals were gated on "the Brain autoresearch work releases". It has: `laramint/laravel-brain`
v2.4.0 ships the shared parse cache (`PhpFileParser::$sharedCache`, keyed on path+mtime+size, with
eviction). So the stated gate is open. The win is gone anyway, for a reason the plan could not have
known: **plan 050 moved richter's tracer branch into a child process.**

### The premise that expired

Lever A rests on this sentence: *"Every file `consolidated-tracers` parses was already parsed by
Brain's `analyze()` earlier in the same build."* Since plan 050 that is false on the default path.
`TracerBranchRunner::start()` spawns `richter:internal-tracer-branch` as a child `artisan` process
whenever `richter.parallel` is on (the default), there is an `artisan` entrypoint, and no progress
listener is attached. Brain's `analyze()` never runs in that child, so its shared cache is cold
there. The re-parse the lever removes is **cross-process**, and no in-process cache can reach it.

### Measurements

Synthetic Laravel-shaped app, 1,340 files under `app/`, 120 routed controllers, PHP 8.4, warm page
cache. Serial path (`richter.parallel` false) so the phase events are available:

| phase | seconds |
|---|---|
| `brain-analyze` | 0.26 |
| `consolidated-tracers` | 0.44 |
| `rewrites-and-members` | 0.03 |
| TOTAL | 0.73 |

Brain parsed **262 of 1,340** files — the route-reached fraction, ~20%. That is the *ceiling* on
cache hits the swap could convert, and 20% is the shape richter exists for: if apps were mostly
route-reachable, most of richter's lanes would be unnecessary.

Parser cost over the same 1,340 files:

| | seconds |
|---|---|
| richter `AppFiles::parseResolved` (own `ParserFactory` + NameResolver) | 0.204 |
| Brain `PhpFileParser::parse`, cold | 0.191 |
| Brain `PhpFileParser::parse`, all cache hits | 0.003 |

Brain's parser is **6% faster cold**, not slower — the extra useMap visitor does not cost what it
looks like it should. A cache hit saves ~0.14 ms per file. So the swap is never a regression; it is
simply not worth much:

| scenario | saving | share of build |
|---|---|---|
| default path (child process, 0 hits) | 0.012 s | 2% |
| serial, 20% route-reached (measured shape) | 0.047 s | 7% |
| serial, 50% route-reached | 0.100 s | 14% |
| serial, 80% route-reached | 0.153 s | 21% |

Lever A's other candidate site, `memberDeclarationEdges()` — which does re-parse app files in the
**parent** process, where Brain's cache is warm — sits inside `rewrites-and-members` at 0.03 s
total. The parse is a fraction of that. Noise.

### Verdict

Not worth doing. On the default path it buys 2%, which is the cold-parse delta and nothing to do
with caching; the cache-hit win only exists on a path most users never take, and even there it is
7% for a richter-shaped app. Against that: it swaps the grammar target (`createForHostVersion` →
`createForNewestSupportedVersion`, which moves the parseable/unparseable boundary and therefore the
graph) and couples two packages through shared AST objects.

Two things checked while evaluating, worth recording because they were the plausible blockers and
neither is one:

- **AST sharing is safe.** Brain's cache hands out the same AST objects to every consumer. All three
  of richter's visitors are typed `enterNode(Node $node): null` / `leaveNode(Node $node): null`, so
  none can replace a node; richter reads the tree, exactly as Brain does.
- **Name resolution is equivalent.** Both run `NameResolver` with `preserveOriginalNames => true,
  replaceNodes => false`. The one difference was the error handler, and it was a real bug rather
  than an equivalence risk — see below.

**Lever C falls with A**: its value was gated on "once Brain's wins make `consolidated-tracers` the
dominant phase". It is already the dominant phase (0.44 s of 0.73 s) and that changes nothing about
the cross-process problem; a per-file result cache in the child would still start cold every build.

### What the evaluation did produce

A crash, found while comparing the two parsers' configuration. richter passed `null` as the
`NameResolver` error handler (the throwing one) where Brain passes `Collecting`. A file that parses
but is semantically invalid — two `use` statements binding one alias — threw out of
`AppFiles::parseResolved()`, and none of its fourteen call sites catches it: one such file anywhere
under `app/` aborted the whole graph build, and one inside a diff aborted `detect-changes`. Fixed
and released in 0.23.0 (`ec2abfb`), with the file deliberately **not** counted unparseable, since
that flag is a global determinability blocker.

### What would reopen this

Only one thing: the tracer branch running in the same process as `analyze()` again. If plan 050 is
ever reverted, or if a future build merges the two phases, re-run the numbers above — the cache-hit
column is the whole case.

## Evaluation verdict (2026-07-24)

Evaluated against the live code + richter-specific measurements. A first pass rejected B; deeper
research ("research better") corrected that — B's *naive* form is unsafe, but a safe form exists and
was shipped.

- **Lever B — EXECUTED (safe form); the plan's naive form REJECTED.**
  - **Rejected: mtime+size *as the fingerprint key*** (what the plan sketched). It reintroduces a
    false-hit hole (content swap preserving size **and** mtime) and overturns `GraphCache`'s
    documented "staleness designed out" invariant. Do not do this.
  - **Shipped instead: an in-process stat-cache that *accelerates* the content-hash.** `fileHash()`
    reuses a file's hash only when its full stat signature (**inode, size, mtime, ctime**) is
    unchanged and not racily-recent; otherwise it re-hashes. Because a content write bumps **ctime**
    even when mtime is preserved (`cp -p`/`touch -r`/archive-restore can't fake ctime), the
    fingerprint VALUE is **byte-identical** to hashing every file — the invariant is *preserved*, not
    relaxed, so no `FORMAT_VERSION` bump. `clearstatcache()` makes a long-lived process see post-edit
    metadata. (My first-pass rejection missed ctime and used the wrong denominator — see below.)
  - **Where the win lands (corrected):** *not* the full cold build (there the ~85 ms content read is
    <1% and dwarfed by the build it precedes). It lands on the **cache-hit / MCP** path: `GraphCache`
    is the MCP singleton and recomputes the fingerprint on **every** tool call (`GraphCache.php:71`,
    before the memo check), so an agent doing N calls on an unchanged tree paid ~85 ms × N; now it
    pays stat-speed after the first. Larger cold. Measured basis (the host app, 2,323 files, warm):
    content-hash 81–90 ms vs stat 4.4 ms.
- **Lever A — DEFER to the Brain release.** Its win needs Brain's disk-backed shared parser (in an
  unreleased upstream Brain performance branch); against released Brain v2.3.1 the swap is
  perf-neutral and carries a name-resolution-equivalence correctness risk. No benefit now, real risk
  now → wait.
- **Lever C — DEFER to the Brain release.** Its value materialises only once Brain's wins make
  `consolidated-tracers` the dominant phase; it also shares B's mtime+size staleness hazard (a per-file
  result cache keyed on mtime+size can serve a stale edge set for a preserved-mtime content swap), so
  it needs the same conscious-invariant-relaxation treatment. Sequence after A + Brain incremental.
- **Watch item / CI enablement** — release-gated verification and host-app docs; no richter code now.

Net: 051 stays parked until the Brain autoresearch work releases (then re-evaluate A and C with real
measurements). B is closed. The near-term graph-build wins already shipped are 049 + 050.

## Original plan (retained for when Brain releases)
- **Priority**: P2 (lever A), P3 (B, C — Brain-release-gated for full value)
- **Effort**: A=S, B=M, C=M–L
- **Risk**: A LOW · B MED (cache-correctness) · C MED (cache-correctness)
- **Depends on**: nothing for A. B and C realise most of their value only once the upstream
  laravel-brain performance work **releases** (disk-backed shared parser; incremental analyze).
- **Category**: performance (graph-build wall-time)
- **Source**: `the Brain autoresearch handoff` (the Brain autoresearch agent's reply) +
  `an internal performance analysis`. Read both before executing.
- **Planned at**: commit `d83341e`, 2026-07-24

## Why this matters — the split inverted

The Brain autoresearch loop collapsed the two Brain hot paths (`brain-analyze` 12.2s→~1s,
`entry-point-tracer` 6.76s→0.19s on the Brain branch). That **inverts** the report's ≈90/10
Brain/richter execution split to ≈43/57: **`consolidated-tracers` (~1.65s) is now the single largest
phase of the build**, and it is richter's own code. Consequences already banked:

- **Plan 049 (skip call-free methods)** now trims a ~0.19s phase — keep it (shipped, output-invariant)
  but expect ~no measurable win once the Brain wins land. Do not invest further there.
- **Plan 050 (parallel branches)** still holds, but with `brain-analyze`≈1s the overlap saves less;
  it stays correct and free (serial fallback), no action.
- **The upstream `traceMethod` memoization ask (report §5.A/B) is moot** — the Brain agent measured a
  re-walk factor of only 1.4× and shared parse/resolution caches already collapsed per-call cost. Do
  **not** pursue a depth-1 redesign. (Leave the drafted issue's other two asks — provenance,
  incremental — standing.)

## Lever A — route `AppFiles` parsing through Brain's `PhpFileParser` (do first)

**Finding (verified):** `AppFiles.php:29` parses with its own `new ParserFactory()->createForHostVersion()`,
bypassing Brain's parser — while `EntryPointTracer.php:86` already uses `LaraMint\LaravelBrain\Parser\PhpFileParser`.
Every file `consolidated-tracers` parses was **already parsed by Brain's `analyze()`** earlier in the
same build; on the Brain branch that parser is disk-cache-backed, so the re-parse could come from
cache. This aims squarely at the phase that is now largest.

**Change:** route `AppFiles::parseResolved()` through Brain's `PhpFileParser` instead of a private
`ParserFactory`.

**Hard caveats (both are STOP-worthy if unmet):**
- `AppFiles::parseResolved()` currently runs its **own** `NameResolver` traversal. Brain's parser on
  the branch returns **NameResolver-annotated** ASTs already. Double-resolution or attribute
  conflicts must be ruled out — verify richter's visitors tolerate (or expect) the pre-resolved
  attributes, and drop richter's redundant NameResolver pass only if Brain's resolution is
  equivalent. **The built graph must stay byte-identical** (assert against the current fixture suite).
- Full value depends on Brain's shared/disk-backed parse cache, which is **on the branch, not
  released**. Against released Brain (v2.3.1) the swap may be perf-neutral — measure before claiming
  a win; land it as a correctness-preserving prerequisite that *arms* the win when Brain releases.

**Test:** full graph-build suite unchanged (byte-identical graph); a focused `AppFiles::parseResolved`
test proving the AST/name-resolution output matches the prior parser on a fixture with namespaced
symbols, closures, and imports.

## Lever B — `GraphCache` fingerprint: mtime+size fast-path instead of full content hash

**Finding (verified):** `GraphCache.php:~98` computes `hash_file('xxh128', …)` for **every** input
file on every run — a full content read of the app just to test the cache key. phpstan's loop
measured this exact pattern at ~13% of a warm run.

**Change:** replace the per-file content hash with an **mtime+size** fast-path guarded against the
same-second race (git's discipline: trust `mtime+size` unless `mtime == now`, in which case fall back
to hashing that file). Keep the content-hash path as the fallback so correctness never regresses.

**Hard caveats:**
- The fingerprint is a **cache-correctness** primitive — a false cache *hit* serves a stale graph,
  the silent under-reporting richter exists to prevent. The racy-clean guard is mandatory, and a
  `--no-cache` run must still bypass entirely.
- Bump `GraphCache::FORMAT_VERSION` (the fingerprint scheme changed).

**Test:** a modified file (new mtime) misses; an untouched tree hits; a same-second modification is
not falsely hit (freeze/inject the `mtime==now` case); `--no-cache` bypasses. Model on the existing
`GraphCacheTest`.

## Lever C — per-file tracer-result caching in `consolidated-tracers`

**Finding:** `consolidatedTracerEdges()` is per-file work (dispatch/policy/reference/interface edges)
over files that are ~all unchanged between runs (the diff *is* the workload — 72 of ~1,900 files in
the report's case). Keying each tracer's per-file derivations by file identity (mtime+size+
schema-version, same discipline as B) shrinks a warm `consolidated-tracers` toward the changed-file
count.

**Change:** cache each file's tracer edge contributions keyed by `(path, mtime, size, schema-version)`;
recompute only changed files, reuse the rest. Fail-safe: any inconsistency → recompute that file.

**Hard caveats:**
- Composes with A (shared ASTs) and with Brain's incremental `analyze()` once released — sequence C
  **after** A and after the Brain incremental work lands, or its benefit is partial.
- Same cache-correctness bar as B: a stale per-file result silently under-reports. Schema-version in
  the key + fail-to-recompute posture are mandatory.

**Test:** byte-identical edges vs the uncached path on the fixture; a changed file recomputes, an
unchanged file is served from cache; a schema-version bump invalidates all.

## Watch item — graph-format v2 edge-id change (compatibility, not a lever)

Brain's incremental work introduces **content-addressed edge ids (graph format v2)**. richter's
`GraphCache` fingerprint already includes the laravel-brain package version
(`GraphCache.php:~90`), so a Brain bump auto-invalidates the cache — the cache side is safe. **Verify**
richter never persists or diffs Brain edge ids *across* Brain versions (benchmark/impact do not, but
confirm), and re-run the suite against the format-v2 Brain build when it releases. No code expected;
this is a release-gated verification.

## CI enablement (deployment, optional, no richter code)

The Brain branch ships an AST cache (`LARAVEL_BRAIN_AST_CACHE=true` + persisted
`storage/framework/cache/laravel-brain-ast`) and benefits from `opcache.jit=tracing` on the build
step (−16..30% measured). Document these in the host-app benchmark-corpus CI guidance once Brain
releases; not a change to richter's source.

## STOP conditions

- Any lever changes the built graph (edges/order/metadata/flags) vs the current suite → not
  output-invariant as required; stop and report.
- A false cache **hit** is observed in B or C testing → stop; a stale-serving cache is a correctness
  failure, not a perf tuning knob.
- Lever A's parser swap forces richter to accept a *different* name-resolution result → stop; keep
  the graph byte-identical or don't swap.
