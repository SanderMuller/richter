# 052 — Make the counts the risk level was decided on visible

**Source**: [handoff-route-string-actions-2026-08-12.md](handoff-route-string-actions-2026-08-12.md) H2.
**Priority**: P2. **Effort**: S (direction A) / M (direction B). **Depends on**: none.
**Planned at**: `e12ec05` (v0.27.0), excerpts line-verified against that tree.

## Problem

`risk_thresholds` shipped in 0.26.0 so a saturating report could be recalibrated. The published
guidance tells the reader to calibrate against the counts the report prints. On a **low-confidence**
report those are not the counts the level was decided on, so following the instruction literally sets
the bar around an order of magnitude too high and collapses everything to `medium`.

The consequence is worse than the saturation it was meant to fix. Low confidence is the normal state
for a large diff, so the reports whose counts are quietly replaced are the broad ones — and any
threshold pair that lifts a middling change off `high` therefore ranks the broadest, least understood
changes *below* the narrow ones. A constant `high` is useless; an inverted order actively misleads.

This is a distinct failure mode from the one 0.27.0 corrected. That correction is about **demotion to
`low`** and is unaffected: raising only `high` reproduces the inversion exactly.

## Current state

`risk()` reads the configured thresholds and nothing else — `src/Analysis/ImpactAnalyzer.php:940-949`:

```php
private function risk(int $impacted, int $entryPoints, bool $touchesEntryClass): RiskLevel
{
    $thresholds = RichterConfig::riskThresholds();

    return match (true) {
        $entryPoints >= $thresholds['high']['entry_points'] || $impacted >= $thresholds['high']['impacted'] => RiskLevel::High,
        $entryPoints >= $thresholds['medium']['entry_points'] || $impacted >= $thresholds['medium']['impacted'] || $touchesEntryClass => RiskLevel::Medium,
        default => RiskLevel::Low,
    };
}
```

The substitution happens one level up, in `riskWithCoarseCap()` — `:451-464`:

```php
$risk = $this->risk($impacted, $entryPoints, $touchesEntryClass);

if (! $lowConfidence || $risk !== RiskLevel::High) {
    return [$risk, false];
}

[$preciseEntryPoints, $preciseImpacted] = $this->riskInputs($preciseSeeds, $maxDepth, $riskInputsMemo);

return $this->risk($preciseImpacted, $preciseEntryPoints, $touchesEntryClass) === RiskLevel::High
    ? [RiskLevel::High, false]
    : [RiskLevel::Medium, true];
```

The second `risk()` call decides the reported level from the **precise seeds alone**, while the
payload carries the full counts — `:351-355`:

```php
'impacted' => $impacted,
…
'risk' => $risk,
'lowConfidence' => $lowConfidence,
'coarseCapApplied' => $coarseCapApplied,
```

So `coarseCapApplied` already tells a reader that a substitution occurred. What it does not tell them
is what was substituted, which is exactly the number the calibration instruction asks them to use.

**Correction found while executing A.** The gap is not bounded to that path. `$riskEntryPointCount`
is taken at `:294`, and the list is extended twice afterwards — `:301-302`:

```php
$riskEntryPointCount = count($entryPoints);
…
$entryPoints = $this->withSelfListedEntryClasses($entryPoints, $changed, $perFileSeeds, $maxDepth, $riskInputsMemo);
$entryPoints = $this->withFrontendEntryPoints($entryPoints, $frontendSeeds);
```

So a changed class that self-lists as its own entry surface, or a changed frontend file contributing
the routes it references, makes the printed entry-point count exceed the scored one on a report that
is not low-confidence at all. Pinned in `ImpactAnalyzerTest` on the mixed self-listing case: two
printed entry points, one scored, `lowConfidence` false. The reporting consumer measured only the
low-confidence path because that is where they looked; the asymmetry is wider than reported, which
strengthens the case for A rather than changing it.

## Two directions

### A — surface the scored counts (additive)

Add `scoredEntryPoints` / `scoredImpacted` beside the existing keys, always populated with whatever
the level was decided on. Render them only where they differ from the printed counts — a line
repeating identical numbers on every report is the kind a reader learns to skip.

- Nothing about the level changes, so no existing verdict moves and no benchmark cap shifts.
- The config's "calibrate on your own numbers" instruction becomes true as written.
- The pinned/unpinned asymmetry becomes visible instead of silent.
- It does **not** remove the inversion — a reader who calibrates on the scored numbers gets a
  consistent scale, one who does not still gets the old one.

The reporting consumer has said they would take this on its own.

### B — score the counts that are printed

Drop the second `risk()` call and let the cap be what its name says: a ceiling applied to the level,
not a re-derivation from a different input set.

**Narrowed after A shipped.** B was written as though both axes were the same defect. They are not,
and the entry-point half must not be done:

- **Entry points — reject.** The divergence there is designed and documented at
  `ImpactAnalyzer::withFrontendEntryPoints()`: surfaces are appended "after the risk inputs are
  frozen (like the self-listing) so they carry their annotations and feed test selection without ever
  moving `risk`: the backend behaviour behind them did not change." Scoring the printed count would
  make a frontend-only change raise backend risk, contradicting a rule the README, the config comment
  and this docblock all state. A self-listed entry class is separately covered: `touchesEntryClass`
  already floors it at `medium` (`:248-251`), so counting it again double-counts one change.
- **Impacted — still open**, and this is all B is.

- Changes the contract. A report that rates `high` on full counts and `medium` on precise ones is
  today reported `medium`; it would become `high`-capped-to-`medium`, which is the same label by a
  different route — but the boundary cases are not obviously identical and need enumerating.
- Needs the benchmark corpus re-run before and after: this is the one change here that can move a
  control's `max_risk`.

**And the question underneath it is not really "which counts".** With A shipped, a reader calibrating
on the scored numbers gets a consistent scale, and the reported ordering is then correct by
construction: a broad change whose breadth could not be pinned is not scored on that breadth. What
remains is the handoff's own smaller observation — the cap makes the report *least* alarming exactly
where it understands the diff least. Whether that is right is a question about what low confidence
should do to a level, not about which of two counts feeds the same comparison. Answer that first; B's
mechanics follow from it, and may turn out to be a distinct level rather than a changed input.

## Recommendation

**DONE — A shipped 2026-08-12.** What follows is why, and what B still needs.

**A first, as its own change.** It is additive, it makes the defect observable for anyone already
affected, and it is a prerequisite for judging B honestly — without the scored counts in the payload
there is no way to measure how often the two disagree, which is the number B's boundary analysis
needs. Treat B as a follow-up gated on that measurement, not as an alternative.

The related observation in the handoff — that the low-confidence cap makes the report *least*
alarming exactly where it understands the diff least — belongs with B, not A. A distinct level or a
"scored on N of M changed members" line is the same decision about what the cap means.

## Scope notes for whoever executes A

- Adding a payload key is a fan-out, not a one-line change: both formatters for both commands, the
  HTML formatter, `JsonPresenter` and its `@return` shapes, both MCP `outputSchema`s, and the
  ordered-key contract tests in `CommandsTest` / `McpTest` / `JsonPresenterTest`.
- **No `GraphCache::FORMAT_VERSION` bump.** Nothing here changes the graph or any tracer; this is
  presentation over values the analyzer already computes.
- The keys must be present on every report, not only the capped ones — an optional key would make the
  common case indistinguishable from a formatter that forgot to render it.

## STOP conditions

- If executing B without A first, stop: the boundary enumeration it needs rests on measurements only
  A makes available.
- If a benchmark control's `max_risk` moves while executing A, stop — A is defined as verdict-neutral,
  so a moved cap means something other than presentation changed.
