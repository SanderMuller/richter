# Plan 040: Make "exposure is route-only" explicit, so an un-tagged Livewire/Filament entry point isn't read as public

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2d8a437..HEAD -- src/Analysis/ImpactAnalyzer.php src/Analysis/JsonPresenter.php README.md`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3 (polish)
- **Effort**: S
- **Risk**: LOW (documentation + a behaviour-pinning test; no behaviour change)
- **Depends on**: none
- **Category**: docs/honesty (consumer dogfood, Finding 5)
- **Planned at**: commit `2d8a437`, 2026-07-20

## Why this matters

Route entry points get `[public]` / `[authed]` / `[admin]`; Livewire component entry
points (and Filament resources) get none — `entryPointSecurity` is `[]` for them
(`ImpactAnalyzer.php:565`: "security exists only for route nodes — Brain classifies
nothing else"). The asymmetry is defensible — a Livewire component's exposure comes from
its mount-time `authorize()` / middleware / route placement, which the graph doesn't
model — but it is **silently** so: a change to an admin-only Livewire panel reads
identically to a public one, and a consumer could mistake "no security tag" for "public,
no auth." The absence is honest data; the *undocumented* absence is the trap.

## Design (decided — do not re-litigate)

**Do not fabricate exposure for non-route entry points.** Inventing `[authed]`/`[admin]`
for a Livewire/Filament node from a heuristic risks a false "authed" on a genuinely public
component (or vice-versa) — worse than an honest blank. Instead:

1. **Document** the route-only scope wherever exposure tags are explained (README's
   entry-point/security section) — state plainly that exposure annotation covers route
   entry points only, and that a Livewire/Filament/queue entry point carrying no exposure
   tag means "not classified," **not** "public/unauthenticated."
2. **Pin the behaviour with a test** so it's an intentional, locked contract rather than
   an accident: a reached non-route entry point (a Livewire component) has **no**
   `entryPointSecurity` entry, while a co-reached route does. This also closes the current
   test gap around the asymmetry.

**Deferred (explicitly not built here):** a middleware-inheritance heuristic — a Livewire
component mounted on a route could inherit that route's gates via the graph path. The
`entryPointPaths` already surface the route above a reached component, so the information
isn't wholly absent today. Build the heuristic only if a consumer asks and only with tests
that prove it never over-claims exposure on a component reachable by more than one path.

## Current state (excerpts — confirm against live code)

- `src/Analysis/ImpactAnalyzer.php:562-597` — `entryPointAnnotations()`; `securityOf()`
  returns null for non-route nodes, so `$security` has no key for them. Docblock `:565`.
- `src/Analysis/JsonPresenter.php` — `entryPointSecurity` is emitted as-is (absent key =
  no tag). No change needed to the shape; the omission is already the honest signal.
- README — the section explaining `[public]`/`[authed]`/`[admin]` and the JSON
  `entryPointSecurity` key (locate the entry-point tags explanation and the JSON key table).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'ImpactAnalyzer|Detect|Json'` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | exit 0 / 0 / 0 changed |

## Scope

**In scope:**
- `README.md` (route-only exposure note in the tags + JSON-key explanations)
- One test file pinning the asymmetry (the analyzer/detect-changes test that already
  builds a graph with both a route and a Livewire entry point — extend it)

**Out of scope:**
- Any exposure heuristic for non-route entry points (deferred).
- `entryPointSecurity` shape / `JsonPresenter` output (already honest).

## Git workflow

- Branch `advisor/040-non-route-exposure-honesty` from the local main tip; one or two
  commits (test; README). No signing. End:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Do NOT push or open a PR.

## Fixtures & anonymization (MANDATORY)

Neutral domain only. Use `App\Livewire\StatusPanel` as the reached non-route entry point
and a `posts.show` route as the co-reached route entry point (never `IssuesPanel` /
`/services/...`).

## Steps

### Step 1: Pin the asymmetry (test-first)

In the analyzer/detect-changes test that produces `entryPointSecurity`, assert: a reached
route node carries a security shape; a reached `App\Livewire\StatusPanel` entry point has
no `entryPointSecurity` key. Comment the test to record that this is an intentional
route-only contract, not a gap to "fix" by fabricating exposure.

### Step 2: Document the scope

README: in the exposure-tags explanation and the `entryPointSecurity` JSON-key row, add a
sentence — exposure is classified for route entry points only; a Livewire/Filament/queue
entry point with no tag is "exposure not classified," not "public." Keep it to one or two
sentences; do not bloat.

### Step 3: Full regression

`composer test` → passed; `phpstan`/`pint --test`/`rector --dry-run` clean.

## Done criteria

- [ ] Test pins: route entry point has a security shape, Livewire entry point has none
- [ ] README states exposure is route-only and absence ≠ public, in both the tags and JSON-key spots
- [ ] `composer test` / `phpstan` / `pint --test` / `rector --dry-run` clean
- [ ] No source behaviour changed; no out-of-scope files touched; `plans/README.md` row updated

## STOP conditions

- Drift vs the "Current state" excerpts.
- The test reveals non-route nodes actually DO sometimes carry a security shape (the
  route-only claim would then be false — report it; the doc wording must match reality).

## Maintenance notes

- If the deferred middleware-inheritance heuristic is later built, it must be additive and
  provably non-over-claiming; keep the "absence ≠ public" documentation regardless, since
  no heuristic will classify every non-route surface.
