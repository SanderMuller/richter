# Plan 035: Draft the upstream laravel-brain incremental/provenance issue + the profile decision procedure

> **Executor instructions**: This is a **document-producing plan** — no source
> code changes anywhere. Follow it step by step; if anything in the "STOP
> conditions" section occurs, stop and report. When done, update the status
> row for this plan in `plans/README.md` — unless a reviewer dispatched you
> and told you they maintain the index.
>
> **Worktree caveat**: the deliverables live under `internal/`, which is
> gitignored — files written there inside a disposable worktree are LOST when
> the worktree is removed. If you are running in a worktree, return the full
> document contents in your final report instead of (or in addition to)
> writing them; the reviewer saves them into the main tree. When running in
> the main working tree, write the files directly.
>
> **Drift check (run first)**: `git diff --stat bfbc331..HEAD -- src/Graph/CodeGraphBuilder.php src/Graph/GraphCache.php`
> Plans 033/034 may have landed (033 touches neither file; 034 adds phase
> events to the builder and an onProgress parameter to GraphCache) — that
> drift is EXPECTED and actually strengthens the issue draft (the profile
> flag exists). Treat only unexplained mismatches as STOP conditions.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW (documents only; the outward-facing act — posting the issue — stays with the maintainer)
- **Depends on**: none mechanically; reads better after 034 exists (the issue can point at `--profile`)
- **Category**: direction (tier-3 handoff #1 follow-through)
- **Planned at**: commit `bfbc331`, 2026-07-20

## Why this matters

The tier-3 evaluation (`internal/research-hihaho-tier3-handoff.md`) concluded that
Richter-side incremental rebuilding cannot deliver "a minute becomes seconds" alone:
the route-anchored core of every graph is produced by Brain's monolithic
`ProjectAnalyzer::analyze()`, which exposes no per-file provenance and accepts no
changed-file set. Whatever Richter's `--profile` numbers show, the upstream ask is
worth filing — either it is the whole unlock (Brain dominates the build) or it is
half of one (Richter-side provenance only helps for its own passes). This plan
packages (a) a ready-to-file upstream issue grounded in verified facts, and (b) the
decision procedure that turns a consumer's profile numbers into the next concrete
step, so the incremental question stops living in one person's head.

**Hard boundary**: the executor NEVER posts the issue. Posting on an external repo
is outward-facing and the maintainer's act (same rule as cutting release tags).

## Current state — verified facts the issue draft must be grounded in

All verified against `laramint/laravel-brain` v2.3.x sources in `vendor/` and
Richter at `bfbc331`:

- `vendor/laramint/laravel-brain/src/Analysis/ProjectAnalyzer.php:106` —
  `public function analyze(string $projectRoot, ?callable $onProgress = null): AnalysisResult`
  — whole-root input, no changed-file parameter, no incremental mode.
- `vendor/laramint/laravel-brain/src/Graph/Edge.php:9-15` — `Edge` carries
  `id, source, target, label, type` and **no source-file attribution**; node data
  bags carry `file` for some node types, but edge provenance does not exist.
- Richter consumes the result in `src/Graph/CodeGraphBuilder.php:63-66` (one
  `analyze()` call per build) and fingerprint-caches the whole graph
  (`src/Graph/GraphCache.php`) — but `detect-changes`/`affected-tests` always run
  against a diff, so the fingerprint almost always misses and the consumer pays the
  full `analyze()` every run (~1 minute reported on a large host app).
- Richter's own tracer passes are per-file and could carry provenance; Brain's
  portion cannot be attributed from the outside. (Full analysis:
  `internal/research-hihaho-tier3-handoff.md`, section #1.)
- Prior upstream contact: `laramint/laravel-brain#65` (strict-types binding fix)
  was PR'd from this project in July 2026 — reference it as precedent for the
  collaboration, not as leverage.
- If plan 034 has landed: `richter:detect-changes --profile` prints the
  phase-by-phase split (`brain-analyze` vs Richter's passes) — the issue can invite
  Brain's maintainer to see real numbers, and the decision procedure consumes them.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Verify Brain facts | `sed -n '100,112p' vendor/laramint/laravel-brain/src/Analysis/ProjectAnalyzer.php` and `sed -n '1,20p' vendor/laramint/laravel-brain/src/Graph/Edge.php` | signatures match the excerpts above |
| Check 034 landed | `grep -n "richter:phase" src/Graph/CodeGraphBuilder.php` | hits when 034 is integrated |

## Scope

**In scope** (deliverables — documents only):
- `internal/upstream-brain-incremental-issue.md` (create)
- `internal/research-hihaho-tier3-handoff.md` (append one section)

**Out of scope** (do NOT touch):
- Everything under `src/`, `tests/`, `config/`, `.github/`, `README.md`,
  `composer.json` — zero code in this plan.
- Posting the issue (`gh issue create` on any repo) — maintainer-only.
- Any provenance/incremental implementation or design beyond the decision
  procedure's branch descriptions.

## Git workflow

- `internal/` is gitignored: there is nothing to commit. No branch needed when
  running in the main tree; in a worktree, deliver via the report (see caveat).

## Steps

### Step 1: Re-verify the Brain facts

Run the two verification commands. If either signature/shape differs from the
excerpts (a Brain upgrade landed), update the facts and carry the corrected
versions into the draft — the issue must never claim something the installed
version contradicts.

**Verify**: both excerpts confirmed or corrected, noted in the report.

### Step 2: Write `internal/upstream-brain-incremental-issue.md`

A ready-to-file GitHub issue body for `laramint/laravel-brain`, structured as:

1. **Title suggestion**: e.g. "Incremental analysis support: per-file provenance
   on the graph, or an incremental analyze() mode".
2. **Use case** (the consumer story, product-facing): downstream tools that answer
   diff-shaped questions (richter is one) re-run `analyze()` on every invocation
   because any input change invalidates a whole-graph cache; on large apps that is
   ~a minute per run in interactive/agent loops.
3. **What Brain exposes today** (the verified facts: `analyze(string $projectRoot)`
   whole-root; `Edge` without source-file attribution; node `file` data partial) —
   neutral, cited by file, no internal richter process detail.
4. **Two independent asks, either helps** (make clear they are alternatives, not a
   demand for both):
   - (a) **Per-file provenance**: expose which source file's parse emitted each
     node/edge (an `Edge::$file` or a side-map on `AnalysisResult`) so consumers
     can implement their own incremental splicing + fail-safe fallback.
   - (b) **Incremental mode**: `analyze()` accepting a previous `AnalysisResult` +
     a changed-file set, returning an updated result — Brain owns the correctness
     of splicing (it knows its non-local analyses: route table, middleware,
     `$listen`, bindings).
5. **Honest costs/caveats**: non-local analyses make (b) genuinely hard; (a) is
   smaller but pushes correctness onto consumers; either needs a "when in doubt,
   full rebuild" posture.
6. **Offer**: richter can validate against a real large-app corpus and (when
   available) share `--profile` phase splits showing where the time goes.

Tone rules: product-facing, no internal session/process identifiers, no consumer
named without consent (say "a large host application" — do not name hihaho), no
richter roadmap internals. Reference #65 only as "we previously contributed the
strict-types binding fix".

**Verify**: the draft contains no string matching `hihaho` (grep it), and every
`file:line`-style claim in it matches step 1's verified excerpts.

### Step 3: Append the decision procedure to the research doc

Add a short section `## Decision procedure (post-#profile)` to
`internal/research-hihaho-tier3-handoff.md`:

- Input: one `--profile` run from a large host app (fresh build).
- Branch A — `brain-analyze` ≥ ~60% of total: file the upstream issue (step 2's
  draft), park all Richter-side incremental work, revisit only on an upstream
  release. Interim: MCP in-session memoization + CI cache persistence (already
  documented in section #1).
- Branch B — Richter's own passes dominate: write a design spike for
  pre-rewrite per-file provenance of the tracer passes only (explicitly excluding
  Brain's portion), with the fail-safe full-rebuild posture as a hard requirement;
  the spike precedes any implementation plan.
- Either branch: record the numbers in this doc when they arrive, so the decision
  is auditable.

**Verify**: section present; no code files touched (`git status --porcelain` shows
nothing tracked changed).

## Test plan

None — documents only. The verifications are the greps and `git status` above.

## Done criteria

ALL must hold:

- [ ] `internal/upstream-brain-incremental-issue.md` exists (or its full content is in the executor report, worktree case) with the six sections and zero `hihaho` mentions
- [ ] The research doc carries the decision-procedure section
- [ ] `git status --porcelain` shows no tracked-file changes
- [ ] The report states explicitly that the issue was NOT posted and who posts it (the maintainer)
- [ ] `plans/README.md` status row updated (unless reviewer maintains the index)

## STOP conditions

Stop and report back (do not improvise) if:

- The installed Brain version already exposes provenance or an incremental mode
  (step 1 finds the facts changed) — the issue premise would be stale; report what
  exists instead.
- You are tempted to run `gh issue create` — never; the draft is the deliverable.

## Maintenance notes

- When the maintainer posts the issue, record the URL in
  `internal/research-hihaho-tier3-handoff.md` next to the decision procedure
  (same pattern as laramint/laravel-brain#65 in the plans index).
- If Brain ships either ask, the decision procedure's branches collapse — rerun
  the tier-3 #1 evaluation against the new upstream API before any Richter work.
