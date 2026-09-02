---
name: richter-review
description: "Invoke-only — run with `/richter-review` or when asked to review a branch with richter; never activates on its own. Graph-backed branch review: runs richter's change-impact report, triages the reached entry points (unexpected reach, missing test references, security and gate annotations), walks the findings, and closes with an advisory verdict. Recommends, never gates."
disable-model-invocation: true
metadata:
  schema-required: "^1"
---

# Review the current branch with richter

richter computes what a diff can reach — the entry points, the flows, the findings in the changed
source itself. This skill turns that report into a review: start from what the change reaches
instead of a cold diff, look hardest at what was *not* expected, and close with an advisory
verdict. The report is evidence, not a judge; the reviewer decides.

## When this runs

Invoke-only: `/richter-review`, or when the user asks to "review this branch with richter." It
never auto-activates.

## Rules (non-negotiable)

- **Advisory, always.** richter's annotations stay advisory in the review: a security tag is
  Brain's static classification (routes only — absence means *not classified*, never "public");
  a `[⚠ no test references this]` tag flags a missing reference, not proof of untested code;
  `risk` is a coarse signal. Never re-brand an annotation as a verdict, and never turn this
  review into a gate.
- **UNRESOLVED is a review item, not a pass.** A changed file the graph cannot place means the
  analysis is blind there — say so and review that file by hand.
- **Prefer MCP, fall back to the CLI.** When the `richter` MCP server is connected, use its
  `detect-changes` / `impact` / `trace` / `affected-tests` tools (structured content, cached graph
  across calls). Without MCP, shell out: `php artisan richter:detect-changes --json --explain`
  (add `--base=<ref>` when reviewing against a non-default base).

## Step 1 — Run the report

Get the branch report (`detect-changes`, JSON + explain). Read `hazards` FIRST — each one names a
thing that may break, with its tier and the reach class it was graded at. Then `riskCause`, which
says in one line why the level is what it is. `lowConfidence` and any UNRESOLVED file calibrate how
much of the rest can be trusted.

`verification` names exactly what the level graded and whether a test references each entry. The
printed entry-point list is wider than that on purpose — a frontend file's routes and registry
surfaces are reach worth reviewing that the level does not grade.

## Step 2 — Triage the reached entry points

In order of review value:

1. **Unexpected reach first.** Any entry point the author would not name when asked "what does
   this change touch?" is the highest-value review target — walk its `entryPointPaths` chain to
   see which edge carries the reach, and judge whether that coupling is intended.
2. **Test references.** For each reached entry point tagged unreferenced (or referenced without a
   behavioural assertion), ask whether the changed behaviour deserves a test there — the tag is a
   prompt, not a coverage verdict.
3. **Security and gate annotations.** A reached route tagged `[public]`/`PUBLIC_WRITE` deserves a
   look at its auth story (mind the cross-check note when richter's own `authorizes` edges
   contradict Brain); a flag-gated route (`[gated: …]`) means the live blast radius is smaller
   than the graph suggests — say which flag.

## Step 3 — Walk the findings

Each Findings line is a concrete review item in the changed source itself: a relation string that
names no relation, a model field a mirroring resource never exposes, a flag-gated change, an
Inertia page rendered by a changed member. Verify each against the diff — findings are heuristic
evidence to check, not conclusions to repeat.

## Step 4 — Suggest the test selection

Run `affected-tests` (tool or CLI). When determinable, suggest the selection as the minimal
pre-push run; when not determinable, say why and recommend the full suite — never a narrowed
guess. Mention the unreferenced-entry-point count as the selection's known blind spot.

Read `testsShare` before you call the selection minimal. A selection covering most of the suite is
still determinable — the field says how large it is, and the verdict says whether to trust it. When
the share is high, say so and let the reviewer decide whether a selective run is worth it.

## Step 5 — Close with an advisory verdict

Summarize: what the change reaches (entry points, with the unexpected ones called out), what to
look at before merging (findings, untested reached surfaces, UNRESOLVED files), and what to run.
State it as a recommendation — the reviewer, not the report, decides.
