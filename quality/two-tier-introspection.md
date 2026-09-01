# Decision: two-tier output for the MCP structured content

**Question:** should richter split its machine-readable reports into a cheap
index tier and an on-demand detail tier ("two-tier introspection")?

**Verdict:** build, scoped narrowly — confirmed by measurement on a consumer
application (see [the verdict](#verdict)). Do not touch CLI `--json`.

## What "two-tier" means here

Tier 1 is a bounded summary: counts, the worst findings, the top-K entries per
list, and an explicit marker for everything not shown. Tier 2 is a drill-down:
the full detail for one named thing, fetched on demand. The pattern targets a
consumer with a bounded context window, not a slow producer.

## Where richter already tiers

The pattern is not new to this codebase. Four places already apply it:

1. **Prose list caps.** `ImpactFormatter` caps the entry-surface and
   summarized context lists at `LIST_CAP` with an "and N more" tail. The
   caps apply to text only — the comment in
   `src/Analysis/ImpactFormatter.php` states the machine-readable arrays
   stay untouched. The caller/dependency hop sections are NOT capped today
   (`hops()` renders every hop), a gap the spec closes alongside the
   structured content, since the same prose is the MCP response's text
   block.
2. **Hub folding in `task-slice`.** `droppedHubCount` is "a count to read, not
   a list to open" — a tier-1 field by design.
3. **MCP resources.** `richter://graph/stats` and `richter://graph/entry-points`
   are cheap orientation before any tool call (RICH-012).
4. **Lazy test scanning.** `ImpactTool` runs the `tests/` scan only when the
   walk reached an entry surface.

What does **not** tier: the MCP tools' `structuredContent` (the full
`JsonPresenter` document, identical to CLI `--json`) and CLI `--json` itself.
On an H-hub query, `entryPointPaths` alone carries one full call chain per
reached surface.

## Speed is not the problem

`GraphCache` serves a fingerprinted on-disk graph; the MCP session holds it in
memory across calls. A tier split buys richter no meaningful latency. The only
axis on which two-tier can pay is **consumer context budget** — which makes
this a C1 concern and nothing else.

## The story pass

For each story: does a tiered machine payload change the outcome for its
personas?

| Story | Personas | Outcome change with tiering? |
|---|---|---|
| RICH-001 impact (CLI) | C2, C3 | Partially — the entry-surface lists are capped, but the caller/dependency hop sections are not (`hops()` renders every hop); the verdict's prose-cap change covers them. |
| RICH-002 impact (MCP, H-hub) | **C1** | **Yes.** The uncapped `structuredContent` of a hub query can displace the agent's working context. Tier 1 keeps the agent's attention on the worst findings; tier 2 fetches one entry's path on demand. |
| RICH-003 task-slice | C1, C2 | Marginal — the command already folds hubs to counts. Tiering added value only if `kept` itself fans out with no hub paths configured. |
| RICH-004 detect-changes (MCP, mid-build) | C1 | **Yes, same mechanism as RICH-002** when the diff touches a hub. |
| RICH-005 impact --explain | C2, C3 | No — human-read surfaces, caps exist. |
| RICH-006 trace | C1–C3 | No — a single path is small by construction. |
| RICH-007 pre-PR CLI | C2, C3 | No. |
| RICH-008 PR comment | C3, C4 | No — markdown already caps. |
| RICH-009 CI gate | C4 | No — the gate reads the `gate` object and the exit code, not list bodies. |
| RICH-010 affected-tests | C4, C1 | **No, and tiering is forbidden here.** A selection is never narrowed; a "top-K tests" tier would violate the command's core promise. |
| RICH-011 `--json` scripting | C4 | **No, and tiering is forbidden here.** The story's contract is the full, uncapped document. A script has a disk, not a context window. |
| RICH-012 MCP resources | C1 | Already tier 1. |
| RICH-013 exploration | C3 | No — capped lists and narrower-query leads cover it, once the hop sections gain their cap. |
| RICH-014 setup / tuning | C5 | No — the adopter's channel is stderr diagnostics, untouched by payload tiers. |
| RICH-015 frontend bridge | C2, C4 | No — touched-route lists are diff-bounded, not graph-fanout-bounded. |
| RICH-016 HTML report | C3 | Already tiered — the ring diagram caps at 300 nodes and says so. |
| RICH-017 benchmark | C5 | No — verdicts are per-case booleans and caps. |
| RICH-018 payload parity | C1–C3 | No — hazard lists are diff-bounded. |
| RICH-019 risk causality | C1–C4 | No — `risk`/`riskCause` are scalars and stay in every tier. |
| RICH-020 advisory annotations | C1–C3 | Partially — the per-entry annotation maps are what a tier-1 cap restricts to the shown entries, with totals. Already accounted for in the design sketch. |

Result: two stories gain (RICH-002, RICH-004), both C1 × H-hub, both on the
MCP `structuredContent` of `impact` and `detect-changes`. Two stories forbid
the change on their surfaces (RICH-010, RICH-011). Everything else is
indifferent.

## Constraints any design must keep

- **Honest bounds.** Richter's core stance is honest degradation. A bounded
  list must say it is bounded and carry the true total. An agent must never be
  able to read a capped list as complete (C1's feared failure).
- **No narrowed selections.** `affectedTests` and the `gate` object stay
  complete in every tier (RICH-010, RICH-009).
- **One vocabulary.** Tier-1 fields reuse the existing JSON keys and shapes;
  a tier-2 response for one entry point is the same shape as today's map value
  (C2's obligation, RICH-011's shared-vocabulary promise).
- **CLI `--json` unchanged.** The complete document is that surface's contract.

## Design sketch (tier boundary)

Tier 1 — the default MCP tool response, bounded:

- All scalar and verdict fields unchanged: `target`, `risk`, `gate`, hazards,
  `unresolved`, honesty flags.
- Breadth arrays (`callers`, `dependencies`, `entryPoints`) capped at the
  existing `LIST_CAP` ordering (worst/nearest first) plus `callersTotal`,
  `entryPointsTotal` counts.
- The per-entry maps (`entryPointPaths`, `entryPointSecurity`,
  `entryPointTestReferences`, …) restricted to the entries shown, plus the
  totals.
- A `bounded: true` marker whenever anything was held back.

Tier 2 — drill-down, two candidate shapes:

- a parameter on the existing tools (`impact` with `full: true`, or
  `entries: [...]` naming the entry points to expand), or
- the existing `trace` tool, which already answers "how does this surface
  reach the symbol" for one pair.

Prefer the parameter: it keeps one tool per question and lets an agent that
knows it has budget opt into today's behaviour with one flag.

## Verdict

Build it, scoped to the MCP `structuredContent` of `impact` and
`detect-changes` (and `task-slice`'s `kept` fan-out if measurement shows it
leaks), plus one prose change the text-block gap above forces: the uncapped
caller/dependency hop sections gain the same cap in every prose format,
because the MCP response carries that prose as its text block. Nothing else
changes: CLI `--json`, `affected-tests`, `trace`, the gate object, and every
already-capped prose section keep their current contracts.

**Measured pressure (2026-08-31, richter 0.63.1, a large production Laravel
application).** `richter:impact --json` on a hub model returned a **~1.9 MB**
document — roughly 470k tokens, far beyond any agent context window: ~3,000
caller hops (~480 KB), ~3,300 dependency hops (~440 KB), ~325 entry points
with `entryPointPaths` at ~215 KB. The MCP `structuredContent` is this same
document. The run itself took ~9 s wall with a warm graph cache, so size, not
speed, is confirmed as the axis. The breadth arrays dominate (~49% of the
payload), which is exactly what the tier-1 cap bounds.

**Not in scope:** any LLM-driven summarisation. Tier 1 is a mechanical cap
with totals — deterministic, testable, and free.
