# Decision: runtime router reads as exposure evidence

**Question:** should richter read the booted router's real, expanded
middleware stack and use it as cross-check evidence on security exposure —
closing the documented false positive where a route reads `[public]` because
its guard arrives through a named middleware group?

**Verdict:** build, scoped as a new evidence source for the existing
cross-check door — see [the verdict](#verdict).

## The gap (traced)

Exposure is inherited from Laravel Brain's static view of a route's
middleware surface. Two shapes still read `[public]` on a route that is in
fact authenticated (docs/19-troubleshooting.md, docs/06-report-annotations.md):

1. A middleware descending from one of the three other framework auth
   middlewares under a name of its own — richter already closes this by
   walking the ancestry of all four bases and noting the applied middleware
   as evidence.
2. **A guard applied through a named middleware group** (`web`, `api`):
   Brain resolves aliases only, and neither Brain nor richter expands a
   group. This one is open. The documented remedy is manual configuration
   (`laravel-brain.security.auth_middleware`), which the adopter must
   discover route by route.

The false positive matters because exposure is not annotation-only: a
`PUBLIC_WRITE` on a route reaching a hazardous member grades that hazard's
reach `public-write`, and a tier-2 hazard at `public-write` is HIGH
(docs/08-risk-levels.md, the tier × reach matrix).

## Why a runtime read fits richter

Richter already crossed this line once, deliberately: the middleware-group
membership note reads **the application's registered route table** because
"the count comes from the application's registered route table, because the
graph cannot answer it" (docs/06). The precedents to copy from that lane:

- The runtime read runs at annotation time, not graph-build time — it never
  enters the cache fingerprint, so the cache guarantee is untouched.
- A run pointed at a checkout other than the running application falls back
  silently (the group note falls back to the graph's subset; this check
  would stay silent).
- The result is left out whenever it cannot be vouched for — an unreadable
  router costs the note, never produces a wrong one.

And the *consumption* side already exists. The reach class `gated` is earned
when "Brain classifies it `authed`, `admin` or `internal`, **or the
cross-check correlated a policy or auth middleware to it**" (docs/08). Two
cross-checks feed that door today: the policy-in-reach check
(`entryPointAuthGates`) and the auth-ancestry walk. A runtime-stack
correlation is a third evidence source for the same door — not a new
mechanism.

## Story pass

| Story | Personas | Outcome change? |
|---|---|---|
| RICH-007 pre-PR self-check | C2, C3 | **Yes.** A tier-2 hazard behind a group-guarded route stops grading `public-write`/HIGH on a guard the runtime can prove; the exposure note names the group instead of reading `[public]`. |
| RICH-008 reviewer orientation | C3 | **Yes.** The posted report carries "auth via middleware group 'web'" as evidence beside Brain's finding, so the reviewer verifies instead of chasing a phantom. |
| RICH-009 CI gate | C4 | **Yes, and needs care.** Fewer false HIGHs under `--fail-on=high`. This moves levels DOWN for affected routes — the opposite of richter's usual upward drift — so it re-baselines gates and must be a named release change. |
| RICH-014 setup / tuning | C5 | **Yes.** The route-by-route `laravel-brain.security.auth_middleware` chase becomes unnecessary for shapes the booted router proves; the config remains the fix at the source for Brain itself. |
| RICH-017 benchmark | C5 | **Guard, not gain.** A level-model change re-baselines controls by design; signal fixtures pinning `public-write` reach need re-grading. |
| RICH-020 annotations orient | C1–C3 | **Yes — the core story.** More truthful evidence, same contract: the note is evidence to verify, never a suppression; Brain's finding stays shown. |
| RICH-002/004/011, others | — | No — payload shapes gain one more entry in the existing evidence maps at most. |

Six stories move. This clears the bar comfortably (two-tier was built on
two).

## Constraints any design must keep

- **Evidence, never suppression.** Brain's finding stays shown; the note
  sits beside it — the exact contract of the two existing cross-checks
  (RICH-020).
- **The level moves only through the existing door.** The runtime
  correlation feeds the `gated` reach class the way the existing
  cross-checks do. Tiers never move; `gated` must still be earned by every
  reaching entry point (one unguarded route keeps `no-guard-found`).
- **Absence semantics unchanged.** A route the runtime read cannot see, or a
  run against a non-running checkout, says nothing — never "public", never
  "gated" (RICH-019/RICH-020 admissions rules).
- **Recognition vocabulary is the existing one.** A stack entry counts as a
  guard through the same recognition richter already has: the four framework
  auth bases and their ancestry, the framework/package guard vocabulary, and
  `laravel-brain.security.auth_middleware`. No new guard list.
- **Cache untouched.** Annotation-time read, like the group-count note; the
  fingerprint never covers it, and it never needs to.
- **Gates re-baseline.** The release note must say levels can move down for
  group-guarded routes; benchmark corpora with `public-write` expectations
  re-grade rather than read the green/red shift as regression (RICH-017's
  own rule).

## Design sketch

At annotation time, when the running application is the analyzed checkout
(the group-note precondition): resolve each classified route's full
middleware stack through the router's own group/alias expansion. Scope is
**every `[public]`-classified route**, plus any route carrying a
`PUBLIC_WRITE` issue: when the resolved stack contains a recognized auth
middleware, attach the evidence note ("runtime middleware stack shows `auth`
via group 'web'") and hand the correlation to the reach classifier through
the same path `entryPointAuthGates` uses. The broadened scope is level-safe:
`no-guard-found` and `gated` score identically, so only the `public-write`
flip moves a level. Silent on any router failure. No config key; the
behaviour is on wherever the precondition holds, because it only ever adds
evidence.

## Verdict

Build it: a third evidence source for the existing cross-check door, gated
on the running-app precondition, silent on failure, suppressing nothing.
The one design decision to settle at spec time is whether the correlation
feeds `gated` immediately or ships evidence-only for one release and earns
the reach input after a dogfooding pass — the benchmark-control re-grade
argues for deciding this deliberately, not by default.

## Addendum (2026-09-01, Brain 2.6)

laravel-brain 2.6 — released while this record's spec shipped — closed the named-group and
renamed-descendant shapes statically: the ancestry walk now covers all four framework auth bases
and named middleware groups are expanded. The runtime lane's premise narrows but holds: it proves
guards against the BOOTED router, which also sees runtime-registered groups and aliases,
controller `HasMiddleware`, and `withoutMiddleware()` exclusions that no static parse carries, and
it corroborates the shapes Brain now covers. The story pass and constraints above are unchanged.
