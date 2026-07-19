# Plan 029: Spike — a real benchmark corpus and a CI replay, the evidence engine for every parked decision

> **Executor instructions**: This is a **design/spike plan** — the deliverable
> is a written design plus at most a thin CI wiring, not a feature build.
> Follow it step by step; if anything in the "STOP conditions" section occurs,
> stop and report. When done, update the status row for this plan in
> `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- config/richter.php src/Console/BenchmarkCommand.php src/Console/BenchmarkAddCommand.php .github/workflows`
> On drift, compare "Current state" against live code before proceeding.

## Status

- **Priority**: P2
- **Effort**: L (coarse — most of it is judgment work on a host app, outside this repo)
- **Risk**: LOW (additive; a corpus only reports)
- **Depends on**: none
- **Category**: direction (spike)
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

Richter's roadmap is evidence-starved by its own rules. Three decisions are
explicitly deferred "pending benchmark evidence": the security overlay's
phase 2 (risk interplay), configurable risk-floor namespaces, and
Livewire/Filament entry-point widening. The benchmark machinery to produce
that evidence shipped (`richter:benchmark` in 0.2.0, `richter:benchmark:add`
in 0.5.0) — but `benchmark_cases` defaults to `[]`, no CI workflow runs the
benchmark, and no process exists that fills the corpus. Until real fix
commits replay through the report on every change to the graph/tracers, "we
think this is trustworthy" stays unfalsifiable and every gated decision stays
parked. This spike designs the corpus process and wires the minimal CI so the
evidence engine exists.

## Current state

- `config/richter.php:61-69` — `benchmark_cases => []` with a commented
  example stanza (`key`, `fix_commit`, `bug_class`, `expect_signal`,
  `max_risk`).
- `richter:benchmark` replays each configured fix commit's diff through the
  report (`src/Console/BenchmarkCommand.php`); `richter:benchmark:add
  <commit> [--control] [--key=]` dry-runs a commit and prints a paste-ready
  stanza (`src/Console/BenchmarkAddCommand.php`) — the capture tooling is
  built and tested (`tests/Feature/CommandsTest.php`, `benchmark_*` tests).
- **The structural constraint**: benchmark cases replay commits of the *host
  app being analyzed* — Richter itself is a package, not a Laravel app, so
  its own repo history cannot be a corpus directly. The commits must exist in
  whatever checkout the command runs in, and the graph is built from that
  checkout.
- No workflow under `.github/workflows/` runs `richter:benchmark`.
- Decision-doc quotes this spike must honor:
  - `internal/evaluation-security-overlay-and-node-locations.md:90-94`:
    phase 2 risk interplay "should be its own change with benchmark
    evidence"; "Decide after phase 1 has real-world mileage."
  - `config/richter.php:18-19`: "the risk-floor namespace heuristics (Jobs,
    Listeners, …) in the analyzer are fixed" — changing that is
    benchmark-gated (maintainer roadmap).
  - The maintainer's stated next step is dogfooding on a real host app
    (hihaho); the corpus selection happens there, not in this repo.
- Prior related work: plan 006 exercised the benchmark **pass path**
  end-to-end with faked git; plan 016 built the scaffolder. What's missing is
  *real cases* and *a place they run*.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Replay locally | `vendor/bin/testbench richter:benchmark` (package context) | informational — likely "No benchmark cases configured" |
| Suite | `composer test` | `"result":"passed"` |

## Scope

**In scope** (files you may create/modify):
- `internal/spike-benchmark-corpus.md` (create — the main deliverable; note `internal/` is gitignored, matching the repo's other spike docs)
- `.github/workflows/benchmark.yml` (create ONLY if option B in step 2 is chosen and self-contained cases prove feasible)
- `tests/Fixtures/**` (only under option B, for a self-contained fixture case)

**Out of scope** (do NOT touch):
- `src/**` — no behavior changes ride on a spike.
- `config/richter.php` defaults — the package must keep shipping an empty
  corpus (cases are host-app-specific).
- Any host-app repository — the doc *describes* the host-app process; running
  it there is the maintainer's move.

## Git workflow

- Branch: `advisor/029-benchmark-corpus-spike` (only needed if option B adds
  tracked files; the doc itself is gitignored).
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Interrogate the replay mechanics

Read `src/Console/BenchmarkCommand.php`, `src/Analysis/BenchmarkCase.php` and
their tests. Answer precisely, in notes for the doc:

1. What exactly must be true of the checkout for a case to replay
   (commit reachable? `fetch-depth`? does the graph build from the *current*
   tree while the diff comes from the fix commit — and what skew does that
   introduce for old commits?).
2. What does a case actually assert (signal: resolves + reaches an entry
   point; control: risk ≤ max_risk) and what graph/tracer regressions would
   each catch or miss.
3. How stale can a case get (renamed files, deleted routes) and how it fails
   when it does (the SKIP path exists — `CommandsTest.php:35-47`).

**Verify**: the three answers are written down with `file:line` citations.

### Step 2: Design the corpus placement — decide between two options

Work out both, recommend one:

- **Option A — host-app corpus (the real one).** Cases live in the host
  app's `config/richter.php`; a host-app CI job runs `richter:benchmark` on a
  `fetch-depth: 0` checkout. Deliverable here: a step-by-step capture
  playbook (mine `git log` for fix commits; `benchmark:add` each; pick N
  signal + M control cases across bug classes — background-job, relation
  string, route handler, Blade; paste stanzas; wire the CI job YAML —
  write the YAML in the doc, ready to copy). Also define the review loop:
  when Richter's graph/tracers change, the host app bumps the package and its
  benchmark job is the regression net.
- **Option B — self-contained fixture case in THIS repo (the smoke tier).**
  Assess feasibility honestly: a case needs a real commit in *this* repo
  whose diff touches files the graph covers — the fixture project under
  `tests/Fixtures/project` is graphed in tests, and this repo's own history
  contains commits touching those fixture files. If a suitable existing
  commit exists (search `git log --oneline -- tests/Fixtures/project`), a
  package-CI benchmark job (Testbench, base_path→fixture, one case pinned to
  that sha) gives a permanent it-runs-in-CI smoke check. If it requires
  manufacturing commits or fighting base-path plumbing beyond ~a day, write
  that down and recommend against it.

**Verify**: the doc contains both options with a clear recommendation and the
copy-ready CI YAML for option A.

### Step 3: Connect the evidence to the parked decisions

One section: for each gated decision (security phase 2, risk-floor
namespaces, Livewire/Filament entry-point widening), state which corpus shape
unblocks it — which controls must exist so the decision's risk-model change
can prove "no control regresses" (e.g. risk-floor config needs controls in
the affected namespaces). This is the section that turns the corpus from
nice-to-have into the roadmap's gate-opener.

**Verify**: each of the three decisions maps to named case requirements.

### Step 4 (conditional): Wire option B if — and only if — step 2 found it cheap

If a suitable existing commit exists: add the workflow + test-level case and
prove it green locally. Otherwise skip; the doc is the deliverable.

**Verify** (if done): the benchmark job runs green locally
(`vendor/bin/phpunit --filter <the new test>` or the testbench invocation
documented in the workflow).

## Test plan

- Spike: no product tests. If step 4 lands, its case must run green and be
  resilient to unrelated history growth (pin by sha, tolerate SKIP with a
  loud failure only on FAIL).

## Done criteria

ALL must hold:

- [ ] `internal/spike-benchmark-corpus.md` exists and contains: replay mechanics (with citations), both corpus options with a recommendation, copy-ready host-app CI YAML, the capture playbook, and the decision-to-case mapping (step 3)
- [ ] `composer test` exits 0 (untouched or still green under option B)
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The replay mechanics (step 1) reveal that historical commits replay against
  the *current* graph in a way that makes old cases structurally meaningless
  (graph/diff skew) — that changes the whole corpus design and the maintainer
  should weigh in before more design work.
- Option B requires manufacturing synthetic commits in this repo's history.

## Maintenance notes

- The capture playbook should live wherever the host-app team looks first;
  `internal/` here is the draft location, not the final home.
- Every future risk-model-adjacent plan should cite this doc's
  decision-to-case mapping as its evidence checklist.
