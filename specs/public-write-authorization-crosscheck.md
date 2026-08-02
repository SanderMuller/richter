<!-- spec:planned-at 2702157 2026-08-02 -->

# Cross-check a `public` / PUBLIC_WRITE security finding against richter's own authorization edges

## Overview

richter surfaces Brain's per-route security surface — `{exposure, riskLevel, issues[]}` —
verbatim as an advisory annotation on reached route entry points (`NodeMetadata::security()`
shape-checks Brain's data and passes it through; richter computes none of it). Brain derives
`exposure` from the route's **static middleware surface** and cannot see two things: the contents
of a middleware **group** (the `web`/`api` group bodies), and an **in-controller authorization gate
written with the policy-constant convention** (`Gate::authorize(PostPolicy::PUBLISH, $post)` /
`$user->can(PostPolicy::PUBLISH, $post)`). So Brain can classify a route `exposure: public` and emit a
`PUBLIC_WRITE` issue ("requires no authentication — anyone can call this endpoint") on a route that is
in fact authorization-gated. (Brain *does* model the string-ability form `$this->authorize('update',
$model)` itself, so that shape produces no false positive to correct — see "Why the coverage aligns"
below.)

richter's **own graph already contains evidence that contradicts this**: `PolicyEdgeTracer` emits an
`authorizes` edge from a controller/service method (or a Blade view) to the `App\Policies\*` class it
gates on — the constant convention `Gate::authorize(PostPolicy::PUBLISH, $post)` is exactly what it
captures. When a route carrying a `PUBLIC_WRITE` issue reaches such an `authorizes` edge, Brain's "no
authentication" is contradicted by richter's own graph.

This feature makes richter **cross-check** a `PUBLIC_WRITE` finding against its own `authorizes`
evidence and, on a hit, **attach a note pointing at the policy it found — evidence for the reader to
verify, not a verdict**. It **contradicts, it does not suppress**: the original finding stays visible,
so a genuinely public write is never hidden or down-graded.

**Why the coverage aligns with the false positive.** Brain already models the string-ability form
`$this->authorize('update', $model)` itself, so it does *not* emit a `public` false positive for that
shape — there is nothing to correct there. The false positive appears precisely for the forms Brain
can't see: the **policy-constant convention** (`Gate::authorize(PostPolicy::PUBLISH, $post)` /
`$user->can(PostPolicy::PUBLISH, $post)`) and middleware groups. The policy-constant convention is
exactly what `PolicyEdgeTracer` captures as an `authorizes` edge — so richter's evidence covers the
cases where Brain is wrong, and stays silent (no contradiction) where Brain is already right.

### Scope

- **In scope:** the `public`-exposure / no-authentication contradiction, using `authorizes` edges
  (policy gates) already in the graph.
- **Out of scope (deferred):**
  - `MISSING_THROTTLE` — genuinely true when it fires (the throttle really is absent); richter
    cannot confirm-or-deny it without resolving middleware **group contents**, which it does not do
    today (it reads only the middleware *alias* map from `bootstrap/app.php`/`Kernel`, and uses
    route→middleware edges only for Pennant gates). Left as a separate follow-up (its complaint is
    proportionality/attribution, not correctness).
  - Auth via **middleware** (an `auth` alias / group `Authenticate`) as a second contradiction
    signal — richter does not resolve group contents, so this is deferred; the policy-gate signal is
    present, clean, and sufficient for this patch.
  - **Suppressing** any finding — explicitly not done (see STOP Conditions).

## Motivating example (synthetic — no real domain)

```php
// routes/web.php  — in the `web` middleware group (which carries Authenticate + VerifyCsrfToken)
Route::post('/posts/{post}/publish', PublishPostController::class);

// app/Http/Controllers/PublishPostController.php
final class PublishPostController
{
    public function __invoke(Post $post): RedirectResponse
    {
        Gate::authorize(PostPolicy::PUBLISH, $post);   // ← authorization gate
        // ...
    }
}
```

Brain reads the route line, resolves neither the `web` group's `Authenticate` nor the in-controller
`Gate::authorize`, and annotates the route `exposure: public` with a `PUBLIC_WRITE` issue. But
`PolicyEdgeTracer` has already emitted:

```
App\Http\Controllers\PublishPostController::__invoke → App\Policies\PostPolicy   [authorizes]
```

The route's reachable node set contains the controller that owns that `authorizes` edge, so richter
can state: *Brain flags this route `PUBLIC_WRITE`, but richter sees a policy authorization
(`App\Policies\PostPolicy`) in this route's reach — verify whether it gates this write.* (Evidence for
the reader, not a verdict — the Brain finding stays shown above it.)

## Design

The cross-check lives in `ImpactAnalyzer` — it already holds the `CodeGraph` and assembles the
entry-point security surface (`entryPointAnnotations()`), and its output is computed fresh per run
(`detectChanges()` is **not** cached; `GraphCache` caches the `CodeGraph`, not the analysis). So this
is an **analyzer/report change, not a graph-build change → no `GraphCache::FORMAT_VERSION` bump**
(unlike a tracer change).

**Trigger (F2).** The cross-check runs for an entry point only when its security surface carries a
`PUBLIC_WRITE` **issue** — not merely `exposure === 'public'`. A public-by-design GET, or a public
route with unrelated issues, must not get an authorization note; the feature exists to contradict the
specific "no-authentication write" claim. Keying on the `PUBLIC_WRITE` issue type fails safe: if Brain
renames the type, the cross-check simply stops firing (no contradiction, finding unchanged).

**Gate detection (F1 — must not use the BFS-tree edge list).** `dependencyEdgesOf()` /
`callerEdgesOf()` return a BFS **tree** — one edge per first-reached node — so an `authorizes` edge to
a policy that was *already* reached by some other edge would be dropped, silently missing a real gate.
Instead: take the route's downstream **reachable node set** (`array_keys(reachedViaTypes([$route]))`,
which is the complete set, not a tree) and intersect it with **every** `authorizes` edge via a new
`CodeGraph` query (e.g. `edgesOfType('authorizes')` or `authorizesFrom(nodes)`). The gate evidence is
the target policy FQCNs of every `authorizes` edge whose **source** is in that reachable set. This is
independent of BFS traversal order.

Carry a new map `entryPointAuthGates: array<string, list<string>>` (entry-point node → sorted,
de-duplicated gating-policy FQCNs; populated only for entry points with a `PUBLIC_WRITE` issue **and**
a non-empty gate set) through `detectChanges()`'s result → `EntryPointRow` → the formatters.

**Wording (F3 — evidence, not verdict).** The finding is never removed or down-graded; the note is
**additional evidence** beside Brain's issue: *"richter: policy authorization (`App\Policies\X`) found
in this route's reach — verify whether it gates this write (Brain's route-surface analysis does not
resolve middleware groups or in-controller gates)."* An `authorizes` edge in the reach is strong
evidence of a gate but not proof it covers *this* write, so the reader sees Brain's finding and
richter's evidence side by side and judges.

## Implementation

### Phase 1: compute the auth-gate evidence in ImpactAnalyzer

**ID:** auth-xcheck-core · **Depends:** — · **Priority:** HIGH

- [x] Add a small `CodeGraph` query returning every `authorizes` edge (or the policy targets whose
      `authorizes`-edge source is in a given node set) — NOT `dependencyEdgesOf()`, whose BFS tree
      drops an `authorizes` edge to an already-reached policy (F1).
- [x] In `ImpactAnalyzer`, add a private helper returning `array<string, list<string>>` — for each
      entry point whose security surface carries a `PUBLIC_WRITE` issue (F2, not merely
      `exposure === 'public'`), the sorted-unique target FQCNs of `authorizes` edges whose source is
      in the route's reachable node set (`array_keys(reachedViaTypes([$entryPoint], $maxDepth))`).
      Omit entries with no gate.
- [x] Add the map to `detectChanges()`'s returned array under `entryPointAuthGates`, and to the
      docblock array shape + `emptyDetectChanges()`.
- [x] It reads only existing graph edges — no new edge types, no seed/risk changes; `impacted`/`risk`
      are untouched (STOP condition). Exposures other than `public` are unaffected by construction
      (only a `PUBLIC_WRITE` issue triggers it).
- [x] Unit tests in `tests/Unit/ImpactAnalyzerTest.php`: (a) a route with a `PUBLIC_WRITE` issue whose
      reach contains an `authorizes` edge → the policy FQCN appears in `entryPointAuthGates`; (b) same
      but no `authorizes` edge in reach → absent; (c) a `public` route with NO `PUBLIC_WRITE` issue but
      an `authorizes` edge → absent (trigger is the issue, not the exposure); (d) **the policy target
      is also reached by a non-`authorizes` edge that a BFS tree would visit first** → still detected
      (guards F1). Each asserted to fail if the cross-check is removed.
- [x] **Builder/fixture-level regression (F5):** a full-graph test (extend `CodeGraphBuilderTest` /
      the `tests/Fixtures/project` app, which already has a route→controller chain and policy edges)
      proving a *real* route's reachable set yields the policy evidence — so the route-id ↔ controller
      ↔ `App\Policies\*` join is exercised end-to-end, not just on a hand-built graph.

### Phase 2: render the contradiction note

**ID:** auth-xcheck-render · **Depends:** auth-xcheck-core · **Priority:** HIGH

- [x] `EntryPointRow` gains `authGates: list<string>` (empty by default), populated in `build()` from
      the new map keyed by entry-point node.
- [x] `MarkdownFormatter`, `ImpactFormatter`, and `HtmlFormatter` render a note under an entry point
      whose `authGates` is non-empty — evidence, not a verdict (F3): *"richter: policy authorization
      (`App\Policies\PostPolicy`) found in this route's reach — verify whether it gates this write
      (Brain's route-surface analysis does not resolve middleware groups or in-controller gates)."*
      The Brain `PUBLIC_WRITE` line is still rendered, unchanged, above it.
- [x] Formatter tests assert the note appears for a `PUBLIC_WRITE` route with a gate in reach and is
      absent otherwise. HTML is escaped per the existing 046 hardening.

### Phase 3: docs

**ID:** auth-xcheck-docs · **Depends:** auth-xcheck-core, auth-xcheck-render · **Priority:** MEDIUM

- [x] README: one line under the report/security description noting richter cross-checks a `public`
      route against its own `authorizes` edges and flags likely false positives; note the honest
      limitation that throttle and middleware-group auth are not verified.

## Edge Cases

| Scenario | Expected behaviour |
|---|---|
| Route with a `PUBLIC_WRITE` issue, `authorizes` edge in its reach | note naming the policy; Brain finding still shown, unchanged |
| Route with a `PUBLIC_WRITE` issue, no `authorizes` edge in reach | no note — a genuinely-ungated public write is not softened |
| `public` exposure but NO `PUBLIC_WRITE` issue (public-by-design GET) | not cross-checked (trigger is the issue, not the exposure); no-op |
| `authed`/`guest`/`admin` exposure | no `PUBLIC_WRITE` issue to contradict; no-op |
| Policy target also reached by a non-`authorizes` edge first (BFS-tree hazard) | still detected — reachable-set ∩ all-`authorizes`-edges, not the BFS tree (F1) |
| Non-route entry point (job/Filament/command) carrying security | security is routes-only upstream; no-op |
| Multiple `authorizes` edges in reach (several policies) | list all, sorted-unique |
| Gate lives in a form request / middleware richter does not model as `authorizes` | no note (PUBLIC_WRITE stays) — honest under-claim, never a false reassurance |
| Gate written as string-ability `$this->authorize('update', $post)` (no policy class) | no `authorizes` edge — but Brain models this form itself and emits no `public` false positive to correct, so the gap is harmless |
| `authorizes` edge in reach gates a different action than the write | note still shown (contradict-not-suppress keeps the finding visible; the note is evidence, not a verdict) |
| Entry point has no security surface at all | no-op |

## Resolved Questions

- **OQ1 (trigger) → the presence of a `PUBLIC_WRITE` issue, not `exposure === 'public'` (revised per
  codex F2).** Keying on exposure alone would annotate a public-by-design GET or a public route with
  unrelated issues — softening/perceived-severity risk the feature must avoid. The `PUBLIC_WRITE`
  issue is the specific "no-authentication write" claim this contradicts. Keying on it fails safe: if
  Brain renames the type the cross-check silently stops (finding unchanged), never mis-fires.
- **OQ2 (gate detection) → the route's reachable node SET intersected with all `authorizes` edges —
  NOT `dependencyEdgesOf()`'s BFS tree (revised per codex F1).** The tree emits one edge per
  first-reached node, so an `authorizes` edge to a policy already reached another way is dropped. Use
  `array_keys(reachedViaTypes([route]))` (the complete reachable set) and a `CodeGraph` all-edges-of-
  type query. Word the note "in this route's reach" so scope is not overstated; contradict-not-suppress
  keeps an over-broad match low-risk.
- **OQ3 (middleware auth as a second signal) → deferred.** richter does not resolve middleware group
  contents, so an `auth`-alias/group `Authenticate` contradiction is out of scope here. The policy-gate
  signal is verified present and clean; middleware-group resolution is a separate follow-up (and would
  also unlock `MISSING_THROTTLE` verification).
- **OQ4 (JSON contract) → not this patch.** The contradiction is a human-report annotation;
  `DetectChangesTool`/`JsonPresenter` keep their current shape. Revisit if a machine consumer needs it.

## STOP Conditions

- If satisfying any requirement needs **removing/suppressing** a Brain security finding rather than
  annotating alongside it — stop. Contradict-only is the whole safety premise.
- If no Brain security issue carries the `PUBLIC_WRITE` type in practice (the trigger never fires on
  the corpus) — stop and confirm the type string with a real report before shipping a no-op.
- If the only available way to find `authorizes` edges is `dependencyEdgesOf()`'s BFS tree (no
  reachable-set + all-edges-of-type path is feasible) — stop: the BFS tree drops gates and would make
  the contradiction flaky (F1). The reachable-set intersection is load-bearing.
- If threading `entryPointAuthGates` forces a change to the cached `CodeGraph` or a
  `GraphCache::FORMAT_VERSION` bump — stop: this must be analyzer-only. A required bump means the
  design leaked into the graph build and the integration point is wrong.
- If the `authorizes`-edge walk measurably changes `impacted`/`risk`/seed output for any existing
  fixture — stop. This reads edges for annotation only; it must not touch the risk model.

## Findings

- **Codex spec review (pre-implementation), 6 findings, all adopted or noted:**
  - **F1 (correctness, adopted):** `dependencyEdgesOf()` returns a BFS tree, dropping an `authorizes`
    edge to an already-reached policy. Design switched to reachable-set (`reachedViaTypes` keys) ∩ all
    `authorizes` edges; added a dedicated unit test (policy reached another way first) + a STOP.
  - **F2 (over-trigger, adopted):** trigger revised from `exposure === 'public'` to the presence of a
    `PUBLIC_WRITE` issue, so a public-by-design GET is never annotated.
  - **F3 (over-claim, adopted):** note wording softened from "likely false positive" to evidence —
    "policy authorization found in reach; verify whether it gates this write."
  - **F4 (accuracy, adopted):** Overview no longer lists string-ability `$this->authorize('x',$m)`
    among gates Brain can't see (Brain models that form itself).
  - **F5 (test gap, adopted):** added a builder/fixture-level regression proving the real
    route↔controller↔`App\Policies\*` id-join, not just hand-built graphs.
  - **F6 (repo hygiene, noted — not a spec change):** the untracked `plans/handoff-watcher-prewarm-…`
    file carries provenance and must not be committed to the public repo. Commits here stage named
    files only, so it is not swept in; flagged to the user.
- **Codex code review (post-implementation), 4 findings:** (1, adopted) `authGatesContradicting()` now
  guards `str_starts_with($entryPoint, 'route::')` so the routes-only contract is enforced, not merely
  assumed; (2, adopted) added the missing plain-text `ImpactFormatter` note test (Markdown + HTML were
  covered); (3, adopted) `.codex/` added to `.gitignore` so codex's local config can't be committed;
  (4, noted) the untracked provenance doc — same as F6, left uncommitted. Codex confirmed the two
  invariants: the reachable-set/`outgoingTargetsOfType` walk is not subject to the BFS-tree drop, and
  the map is annotation-only (no seeds/impacted/risk/JSON change).
