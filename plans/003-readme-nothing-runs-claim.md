# Plan 003: Correct the README's "Nothing runs" safety claim to match what the analysis actually does

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: This plan was written against a working tree
> with uncommitted changes on top of commit `50a0efa`, so a commit-range diff
> is not a reliable drift signal. Instead, compare every "Current state"
> excerpt below against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs (security-adjacent)
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

The README promises the tool is "safe on code you would not want to execute". That is false as implemented: while the analysis never *runs* the analyzed app's routes, jobs, or commands, several resolution steps use PHP reflection that triggers the host autoloader — and autoloading a class file executes that file's top-level statements, and `constant()` evaluates constant-initializer expressions. A user who trusts the claim and runs `richter:detect-changes` in CI against an untrusted pull-request branch executes attacker-controlled top-level code with the CI runner's privileges. The documented guarantee and the implementation must agree; the cheap, honest fix is to correct the documentation. (Making resolution fully static is a large change with accuracy trade-offs and is explicitly deferred — see Maintenance notes.)

## Current state

- `README.md:35` — the claim, verbatim:

```
Nothing runs: it is static analysis over a code graph, so it is fast enough for every branch and safe on code you would not want to execute.
```

- The reflection call sites that make it false (context, not files to modify):
  - `src/Support/AppFiles.php:92,96` — `class_exists($class)` + `constant("{$class}::{$constant}")` (constant initializers are evaluated).
  - `src/Tracers/DispatchEdgeTracer.php:255` — `class_exists($fqcn) && is_subclass_of($fqcn, ShouldQueue::class)`, reached for candidate job classes during every graph build (memoized at `:249`, but the first check per class autoloads).
  - `src/Tracers/EagerLoadStringChecker.php:244,260` — `class_exists($fqcn)` + `get_class_methods($fqcn)` over every file in `app/Models`.
  - `src/Graph/CodeGraphBuilder.php:306` — `method_exists($candidates[0], $method)` on controller candidates.
- `README.md:96-118` — the "Gating in CI" section with a `pull_request`-triggered workflow example that checks out and analyzes the PR's code. It currently carries no note about untrusted forks.
- README voice: direct, technical, no hedging; sentences carry their reasoning inline (see the surrounding "What it's for" section for tone).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Claim removed | `grep -c "safe on code you would not want to execute" README.md` | `0` |
| Full suite (unchanged) | `composer test` | exit 0 (docs-only change) |

## Scope

**In scope** (the only files you should modify):

- `README.md`

**Out of scope** (do NOT touch, even though they look related):

- Any `src/` file — replacing reflection with AST-only resolution is a deliberate deferral (see Maintenance notes), not part of this plan.
- `SECURITY.md` — the disclosure-policy stub; a behavioral safety note belongs in the README where the claim lives.
- `CHANGELOG.md` — CI-managed; never hand-edit.

## Git workflow

- Branch: `advisor/003-readme-nothing-runs-claim` off `main`.
- Commit style: imperative sentence-case, e.g. `Document what Richter is for in the README` (from `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Replace the claim at README.md:35

Replace the sentence

```
Nothing runs: it is static analysis over a code graph, so it is fast enough for every branch and safe on code you would not want to execute.
```

with:

```
The analysis never executes your application's routes, jobs, or commands — it is static analysis over a code graph, fast enough for every branch. It does, however, autoload classes from the analyzed checkout (to resolve constants, relation names, and queue interfaces), and autoloading runs a file's top-level code. Treat a checkout you would not `composer install` on as one you should not analyze either.
```

**Verify**: `grep -c "safe on code you would not want to execute" README.md` → `0`, and `grep -c "autoload" README.md` → at least `1`.

### Step 2: Add an untrusted-fork caution to the CI section

At the end of the "Gating in CI" section (after the paragraph following the YAML example, before the next `###` heading), add:

```
The workflow analyzes the pull request's code, and analysis autoloads classes from that checkout (see above). For a public repository, keep the trigger on `pull_request` (never `pull_request_target` with a privileged token) so fork-submitted code runs without access to your secrets.
```

**Verify**: `grep -c "pull_request_target" README.md` → `1`.

### Step 3: Full verification

**Verify**: `composer test` → exit 0 (proves the docs change touched nothing behavioral).

### Step 4: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "003" plans/README.md` → row shows DONE.

## Test plan

No code changes — no new tests. The done criteria are grep-based checks on the README plus a full-suite run proving nothing behavioral changed.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c "safe on code you would not want to execute" README.md` outputs `0`
- [ ] `grep -c "pull_request_target" README.md` outputs `1`
- [ ] `composer test` exits 0
- [ ] `git status --short` shows changes only in `README.md` plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- README.md:35 no longer contains the quoted sentence (the section may have been rewritten — locate the equivalent claim; if none exists, the finding is already fixed: report that instead of inventing an edit).
- The "Gating in CI" section no longer exists or its example changed away from a `pull_request` trigger.

## Maintenance notes

- **Deferred follow-up (recorded here deliberately):** a design spike for fully static resolution — replacing `class_exists`/`constant`/`get_class_methods`/`is_subclass_of`/`method_exists` with resolution from the already-parsed ASTs — would make the original claim true. It is L-effort, carries accuracy risk (relation-name and queueable checks), and should wait until audit findings 5 (interface/binding edge tests) and 10 (benchmark net) provide safety rails. Whether Brain's `ProjectAnalyzer`/`ContainerBindingAnalyzer` reflect internally also needs checking there.
- If that spike ever lands, this README section should be revisited to restore a stronger guarantee.
- Reviewer scrutiny: the new wording must not overcorrect into scariness — the tool is still safe for the overwhelmingly common case (analyzing code you already run locally); the caution is specifically about *untrusted* checkouts.
