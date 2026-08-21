# Risk levels

The risk level answers one question: **what should a reviewer do about this change?**

It is decided by the HAZARD the change carries — an exact, tiered property of the diff — and, where it
carries none, by whether anything would catch a regression in what it reaches. Breadth still appears
in the report, as `Impact`, where it describes the change instead of grading it.

## The ladder

One decision ladder, evaluated top to bottom. No weights, no arithmetic, no tuning knob.

```
0. Nothing to assess              -> LOW     "no analysable change"
1. A hazard fired                 -> the tier x reach matrix, below
2. Reach is unplaced              -> MEDIUM  "could not place what this reaches"
3. A graded surface is            -> MEDIUM  those surfaces are named
   unreferenced, or could not
   be checked
4. Otherwise                      -> LOW
```

**Every level prints its cause.** A bare `MEDIUM` is not a result this report will render, in any
format, and `riskCause` carries the same sentence in `--json` and over MCP.

## Step 0 is not step 2

These two look alike and mean opposite things.

- **Step 0** is *nothing was analysed*: an empty diff, a cosmetic-only diff, an additive-only diff, or
  a brand-new class nothing calls. There is no question to answer.
- **Step 2** is *something that already existed was analysed and could not be placed*. Richter found a
  real change and could not name a single surface it reaches.

Only the second is a warning. Collapsing them would report `medium` for a whitespace commit and trip
`--fail-on=medium` on it.

A real change to a class the graph never charted is step 2, not step 0. Failing to place a change is a
placement failure, and reporting it as "nothing to assess" would be the one falsely-reassuring answer
this package exists to avoid.

## Hazards

A hazard is a property of the diff saying the change may break something. Every predicate is exact: a
lane that cannot read both sides of a comparison in full reports nothing rather than guessing, because
a false "authorization removed" is worse than the breadth number it replaced.

| Tier | Hazard | Lane | CWE |
|---|---|---|---|
| 3 | an authorization guard removed | `auth` | CWE-862 |
| 3 | an authentication middleware removed | `auth` | CWE-306 |
| 3 | `$hidden` narrowed | `model` | CWE-200 |
| 2 | mass-assignment surface widened (`$fillable`/`$guarded`) | `model` | CWE-915 |
| 2 | a `$casts` value changed on a surviving key | `model` | — |
| 2 | a validation constraint dropped | `boundary` | CWE-20 |
| 2 | a queued job's constructor changed | `boundary` | — |
| 2 | a public or protected member removed | `contract` | — |
| 2 | a resource key removed while a consumer reads it | `parity` | — |
| 2 | a model field never mirrored to its resource | `parity` | — |
| 2 | a form-request field removed | `parity` | — |
| 1 | a surviving member's signature changed | `contract` | — |

The tiers are fixed and not configurable. A tier is a fact about the change, and a project that could
re-tier one would be grading its own risk before reading it. `cwe` is null wherever no clean mapping
exists — a stretched CWE teaches a reader the mapping is decorative.

**A guard that MOVED is not a guard that was removed.** Authorization migrates: a controller's
`authorize()` becomes a form request's, a policy becomes a gate. Every removal predicate fires only
when the removed thing is not added somewhere else in the same diff.

The ability is what is compared, not the call shape, so `Gate::denies('publish')` rewritten as
`$user->cannot('publish')` draws nothing. **A policy constant counts as an ability**, which matters
more than it sounds: a project following Laravel's own convention writes `can(PostPolicy::UPDATE)`
everywhere and no string abilities at all, so a comparison keyed on literals alone would leave this
defence switched off for the entire codebase. A removed policy METHOD is named both ways — by its own
name and by any constant in its class whose value is that name — because a caller may spell it
either way.

## Reach, and the matrix

Each hazard carries its own reach class — not the diff's. Three states, two of them carrying positive
evidence:

| State | Meaning |
|---|---|
| `public-write` | a route Brain marks `PUBLIC_WRITE`, with no guard richter can point at, reaches the hazardous member |
| `gated` | a route reaches it, and a guard is visible |
| `no-known-path` | no reaching entry point was found |

|  | `public-write` | `gated` | `no-known-path` |
|---|---|---|---|
| **tier 3** | HIGH | HIGH | HIGH |
| **tier 2** | HIGH | MEDIUM | MEDIUM |
| **tier 1** | MEDIUM | MEDIUM | LOW |

**`no-known-path` is not `internal-only`.** Proving a member internal means proving a negative on a
graph that under-approximates by design. A member with no known path is unmeasured, not unreachable —
which is why tier 3 is HIGH everywhere. A removed guard is a removed guard, and capping it would
silence tier 3 on exactly the applications where reach is hardest to resolve.

A REMOVED member has no node in the head graph, so no path can reach it. Its reach comes from its
declaring class instead — the same stand-in the coarse-seed lane already makes for a change the graph
cannot pin.

## Verification, when there is no hazard

For a change carrying no hazard, the question becomes whether anything would catch a regression.
Richter grades every surface the level looks at, and `verification` in `--json` names each one.

What gets graded is **not** the printed entry-point list:

| Group | Graded? | Why |
|---|---|---|
| the entry points the change reaches | yes | the surfaces it actually reaches |
| a changed class that reached none of them | yes, on its own import | otherwise a change richter cannot place has no road to `low` at all |
| a frontend file's routes | no | the backend behaviour behind them did not change |
| registry and association surfaces | no | one change to a registered class would otherwise reach every admin page behind the registry |

The class fallback is **per class**, not per diff: one changed class reaching routes says nothing about
a sibling in the same diff that reaches nothing.

Only a **runnable** test file (`*Test.php`) counts as a reference. Richter indexes every PHP file under
`tests/`, fixtures and base cases included, and letting one of those grade a surface "referenced" would
open a false `low`.

Two states count as unverified rather than verified:

- **A reference state that could not be checked.** A miss while the router was unavailable means the
  check never ran. Reading it as "not unreferenced" would open `low` on a surface nothing checked.
- **No `tests/` directory at all.** Every surface grades unreferenced and the level reads `medium`.
  That is the intended reading, not a failure.

The weak-assertion sub-tag counts as **verified**. Its grader collapses every uncertainty to plain
"referenced" by design; building the level on the weaker reading would invert that discipline. The tag
still prints on the row.

## What drifts, and in which direction

A tier is a fact about the diff and never moves when richter learns to follow more edges — the whole
reason to score on it.

**Reach class and verification state both move, and upward.** A release that draws new edges can lift a
hazard from `no-known-path` to `public-write`, or reach a newly-visible surface that no test
references, and raise a level with nothing in your application having changed. Treat a level shift
right after a version bump as a coverage change first and a code change second, and pin the version in
CI if you need a `--fail-on` verdict to stay comparable across a release.

## Gating CI

Three flags, because blocking a removed guard, blocking a missing test, and blocking code richter
could not read are three different policies.

| Flag | Gates |
|---|---|
| `--fail-on=<level>` | the level |
| `--fail-on-hazard=<tier>` | hazards alone, whatever the level |
| `--fail-on-unresolved` | coverage, independently of both |

Coverage never floors the level. An UNRESOLVED file is its own gate, so a test-referenced change whose
dispatcher could not be followed still reports the level it earned, with the unresolved file named in
its own finding.

`--no-hazards` skips the hazard lanes. It changes the LEVEL, not only which section prints: the ladder
then falls through step 1 and decides on verification alone.

It does not silence the three parity lanes, which keep their own `payload_parity.enabled` key and
`--no-payload-parity` flag. Turning both off is what leaves `hazards` empty.

## Upgrading from the threshold model

`risk_thresholds` is retired and no longer read. The key is accepted and ignored for one release so an
upgrade does not fail on it; remove it.

The counts it graded are still reported, under `Impact`. They no longer decide anything, so there is
nothing left to calibrate — which is the point: an absolute bar pinned to a count moved whenever
richter learned to follow more edges.

`scoredEntryPoints`, `scoredImpacted` and `coarseCapApplied` are gone from the report and from `--json`.
They existed to name the counts the level was scored on, and nothing is scored on counts any more.
`lowConfidence` remains: it describes the seeding, not the scoring.

If you keep a [benchmark corpus](17-benchmark.md), a control capping at `max_risk: low` needs
re-grading to `medium` — a benign change richter cannot place now reports `medium` by design.
