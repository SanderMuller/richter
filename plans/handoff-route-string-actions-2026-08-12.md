# Consumer handoff: partially-qualified controller ids, and the risk-threshold scale (2026-08-12)

> **H1 was corrected on 2026-08-12** after the maintainer reproduced it and the
> original claim did not hold. See the note in that section.

> **What this is**: a consumer-side handoff, pinned at `e12ec05` (`v0.27.0`).
> Findings and proposed directions — **not** a plan. The maintainer converts these
> into numbered plans (with line-verified "Current state" excerpts) if a direction
> is accepted.
>
> Provenance: one consumer application, dogfooded across four
> consecutive releases — 0.20.1 → 0.24.0 → 0.25.0 → 0.26.0 → 0.27.0. Method was an
> A/B harness: two worktrees with identical source, only the package version
> differing, replaying real change diffs plus synthetic probes and the benchmark
> corpus. `benchmark` 7/7 green on every version. Domain nouns below are
> neutralised per `plans/README.md`.
>
> **First, the confirmations** — 0.26.0 and 0.27.0 both landed measurably:
> entry-points-are-callers took one diff to zero entry points (every one had been
> association reach) and cut another by nearly half, *below* its pre-0.25.0 figure; the config-registry over-report reverted impacted counts to ~0.24.0
> levels; `--depth` turned a "no path" into a real answer at depth 14; the
> middleware group count went from a small fraction of the guarded routes to all
> but two of them, checked against `route:list`; and the inline
> validation lane fired correctly on the first realistic case it was given. The
> anonymous-class fix showed up as a clean −2 impacted nodes. Nothing regressed.

## H1 — CORRECTED: partially-qualified controller ids, not a missing edge

> **Superseded 2026-08-12, after the maintainer failed to reproduce it.** The
> original claim — "a string-form route action draws no `route-to-controller`
> edge" — is **wrong**, and the three experiments cited for it were misread. The
> maintainer's fixture result is right: every string form draws an edge. What this
> consumer actually has is the **phantom partially-qualified id** the maintainer
> found next to it, and the graph dump below shows it is a recall bug, not a
> cosmetic one. Original text kept in git history; the numbers below replace it.

**Priority (consumer's view)**: P1 for recall. **Effort**: S (the fix the
maintainer already proposed). **Category**: precision → recall.

### The deciding datum you asked for

**The route nodes carry file and line.** From this consumer's graph cache:

```json
"route::GET::/post/{post}/review": {"file": "routes/legacy/routes.post.php", "line": 124, ...}
```

Non-empty `file`, non-zero `line` — so these come from the **AST path**, not
`discoverFromRouter`. The runtime-discovery hypothesis is out, and with it the
worry that this could not be reproduced in a fixture.

### What is actually in the graph

`route-to-controller` edges are drawn, at essentially 100% of routes in every route
file — including the legacy ones. The consumer's earlier "no edge" reading came from
querying the *correct* FQCN and from a substring search that did not surface the
node. Both were wrong.

The edges point at **partially-qualified ids**:

| route | edge target | real class |
|---|---|---|
| `/post/{post}/review` | `Post\ReviewController` | `App\Http\Controllers\Post\ReviewController` |
| `/api/validate/thing` | `App\Http\Controllers\Api\ThingController` | same — correct |

The group's `->namespace('Post')` segment is applied without the provider's
`App\Http\Controllers` root. Across all `route-to-controller` edges in that
application, **the clear majority point at a partially-qualified id** rather than a
real FQCN — every route declared in a namespaced group, which there is the bulk of
the legacy routing.

### Why this is recall, not cosmetics

Both nodes exist and are never connected: the phantom `Post\ReviewController`
(reached from the route) and the real `App\Http\Controllers\Post\ReviewController`
(holding every code edge). Their metadata even agrees on the file — the same path on
both — so the class *was* resolved on disk; only the id was built from the relative
namespace.

The consequence is a severed chain, not just an id a reviewer cannot open:

- those controllers report **0 entry surfaces**, so a change to one looks inert;
- 0.27.0's inline-validation lane cannot fire for them — it needs the routes
  upstream of the changed member. This is how the whole thing was found: the first
  realistic probe for the new lane produced nothing, and the lane was fine;
- the security annotations never reach them.

### On the proposed fix

The unique-suffix match resolves **all but six** of the distinct phantoms here. A
file-identity match (the phantom's metadata already carries the right file) resolves
**exactly the same set** — so there is no reason to prefer it; keep the simpler
suffix predicate.

Those six have no real class node in the graph at all (never independently
discovered), so nothing can resolve them — which is precisely the "leave a genuinely
unresolvable id alone" case the fix intends. No special handling needed. They also
corroborate the `method_exists` autoload guard: in the wild it is exactly the
not-discoverable classes it declines, not a random slice.

Worth a benchmark fixture: this moves recall, not just presentation.

## H2 — Printed counts and scored counts are different numbers, so thresholds can invert the scale

**Priority (consumer's view)**: P2. **Effort**: S–M. **Category**: correctness of a
new feature's contract.

0.26.0 added `risk_thresholds` in response to this consumer's twelve-of-twelve-HIGH
finding — correct call, and the saturation is real. But on a **low-confidence**
report the thresholds are compared against the precise pinned-only counts, while the
report prints the full ones. Measured on one broad diff, holding one threshold at 9999 and moving the other:

```
impacted      20 -> high    200 -> medium     (scored value is < 200)
entry_points   3 -> high     20 -> medium     (scored value is <  20)
```

An order of magnitude apart. The shipped config says *"the report's own impacted
count, printed on every run, is the calibration data"* — following that literally
sets the bar ~10x too high and collapses everything to `medium`.

The consequence is worse than saturation, because low-confidence is the common state
for large diffs here (half the sample). **Any threshold pair that lifts a middling
change off HIGH inverts the ordering:**

| diff | printed reach | confidence | risk at a raised `high` |
|---|---|---|---|
| B — narrow, 9 files | small | pinned | **HIGH** |
| A — broad, 14 files | ~35x B's impacted count | low | MEDIUM |
| C — one class, wide fan-out | ~5x B's impacted count | low | MEDIUM |

The broadest, least-understood changes report the lowest risk. A constant HIGH is
useless; an inverted scale actively misleads — so this consumer has **left the key at
its defaults** and documented why, rather than tune it.

**Retested on 0.27.0.** The corrected advice ("raise `high` first, because only
raising `medium` can demote to `low`") is sound and does not reach this: raising only
`high` with `medium` untouched reproduces the table above exactly. The two failure
modes are separate — yours is demotion to `low`, this one is that pinned and unpinned
diffs are not on the same scale at all.

### Proposed direction (maintainer's call)

Surface the scored counts wherever the level is shown — the report line, and the JSON
(`scoredEntryPoints` / `scoredImpacted` beside the existing keys). Then the config's
"calibrate on your own numbers" instruction becomes true, and the pinned/unpinned
asymmetry becomes visible instead of silent. Scoring the printed counts instead would
also resolve it, but presumably conflicts with why the precise counts are used.

Related, smaller: the low-confidence **cap** at MEDIUM means the report is least
alarming exactly where it understands the diff least. A distinct level, or a "scored
on N of M changed members" line, would be more honest than a silent downgrade.

## H3 — The view lane misses the `$view` property form

**Priority (consumer's view)**: P3. **Effort**: S. **Category**: detection gap.

0.26.0's view lane names "a Livewire component, a **Filament page**, a mailable, an
action" as its targets. Verified working for the call form: touching a Blade file
rendered by `render(): view('...')` yields `analyzed`, 1 entry point, MEDIUM.

But Filament pages do not call `view()` — they declare
`protected static string $view = 'filament.pages.x';`. Every Filament page in this
application uses that form, so their Blade views still read UNRESOLVED on 0.26.0
and 0.27.0 alike (identical UNRESOLVED counts across 0.24.0, 0.26.0, 0.27.0). The
lane misses one of its own stated use cases.

A literal string property on a class extending a known page base is the same
no-guess shape the call form already accepts.

## Smaller observations

- **`EntryPointRootCoverage` fires only on zero coverage**, so it cannot see the
  partial case. Here, the directory holding the real callers of a registry-dispatched
  subsystem has plenty of graph presence — just no route-reachable path to the
  method that matters — so the note stays silent exactly where the gap is. The
  docblock's argument against a ratio threshold is sound; the observation is that
  zero-coverage is not the shape that actually bites.
- **Benchmark fixtures rot silently as the risk model moves.** One control here was
  captured on v0.13.0 and could no longer pass by v0.20.1; the only remedy was
  hand-editing `max_risk`. 0.27.0's `--control` guard prevents creating a vacuous
  cap but does not help an existing corpus drift. A `--rebaseline` that reports what
  moved and proposes new caps would close it.
- **`benchmark:add` writes `'max_risk' => 'high'` on signal fixtures too**
  (`BenchmarkAddCommand.php:90`, `:162`), while the shipped config comment describes
  `max_risk` as the control knob. Harmless — the cap is checked on every fixture and
  defaults to `high` — but it reads as contradictory, and an automated reviewer on
  the consumer's PR flagged exactly that. One clarifying clause in the config comment
  would settle it.
- **Correction to an earlier consumer claim.** After the 0.24.0 round this consumer
  reported that the v0.22.0 note about `PUBLIC_WRITE` and name-based auth matching
  was stale, because their routes had started classifying as `authed`. 0.26.0's note
  is right and that inference was wrong: their only auth middleware happens to share
  the framework's class basename, which is why Brain 2.4.0's basename match catches
  it. A subclass named anything else would still draw the false finding, and the
  ancestry walk is what covers it. Recorded here so the earlier report does not
  mislead.

## Harness note, possibly worth a docs line

0.27.0's setup guidance warns about `post-checkout` hooks reinstalling dependencies
mid-comparison. The same hazard applies to the comparison harness itself: replaying
several refs with `git checkout --force` reverts `composer.json`/`composer.lock` to
each replayed commit while `vendor/` keeps the version under test. Measurements stay
valid — artisan runs off `vendor/` — but the branch's own version bump gets silently
reverted, which happened twice in one session here before it was noticed. Worth
adding "and your own checkouts, not just hooks" to that warning.
