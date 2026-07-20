# Plan 038: Only treat a string literal as a route when it's an argument to an HTTP/route callee

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2d8a437..HEAD -- src/Tracers/FrontendReferenceScanner.php src/Support/RichterConfig.php config/richter.php tests/Unit/FrontendReferenceScannerTest.php README.md`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2 — but **escalates to near-P1 once plan 036 lands**: today the
  over-seeded routes are masked by Finding 1 (affected-tests always says "run
  everything"); once 036 lets selection narrow, these false positives would
  **false-select unrelated backend tests**. Sequence 038 with/after 036.
- **Effort**: M
- **Risk**: MED (a precision fix; the failure mode is over-selection, the safe
  direction — but a too-narrow allowlist would *drop* real HTTP calls → under-report,
  so the default allowlist must cover the common idioms and be test-pinned)
- **Depends on**: none mechanically; **priority-coupled to 036**
- **Category**: correctness/precision (consumer dogfood, Finding 3)
- **Planned at**: commit `2d8a437`, 2026-07-20

## Why this matters

`FrontendReferenceScanner::uriCandidates()` collects a `/`-leading string literal (or
backtick template) whenever it sits in **any** call-argument position — the regex anchor
is `(?:\b(get|post|put|patch|delete)\s*\(|[(,])`, where the verb name only *pins the HTTP
method*; the URI is captured regardless of the callee. So non-HTTP calls seed routes:

- `translate('/preferences')` (i18n) → false-mapped to a `{param}` route.
- `someHelper('/api/v2/reports', 'other')` → false-mapped to a catch-all route.
- `console.log('/{post} was opened')` → matches a real route template.

Each becomes a route seed. In the impact report it inflates the touched-route surface;
after plan 036 unblocks narrowing, it would pull unrelated backend tests into
`affected-tests`. Plan 031 already tightened "any position" → "call-argument position";
this is the next tightening: **call-argument position of an HTTP/route callee.**

## Design (decided — do not re-litigate)

**A `/`-literal or backtick template is a URI candidate only when it is an argument to an
allowlisted HTTP/route callee.** The allowlist is config-driven, mirroring
`richter.dispatch_helpers`: a new `richter.frontend.http_callees` (`list<string>`) merged
with a built-in default set (built-in ∪ user, like `DispatchEdgeTracer` merges helpers).

**Default allowlist (built-in):** `route`, `fetch`, `axios`, `useFetch`, `$http`, `$`
(covers `$.ajax` / `$.get` / `$.post` / `$.getJSON`), `window`. Matching is on the
callee's **leading identifier segment** before the first `.`, so `axios` covers
`axios.get(...)`, `$http` covers `$http.post(...)`, `$` covers `$.get(...)`, `window`
covers `window.fetch(...)`. Bare callees (`route`, `fetch`, `useFetch`) match directly.
The HTTP method is still pinned when the callee's method segment is a verb
(`axios.get` → GET) or from an explicit verb.

**Config, not cache.** The frontend scanner runs at **diff time** (`ChangedSymbols` →
`FrontendChanges` → scanner), not during graph build, so this config does **not** enter
the `GraphCache` fingerprint — no cache invalidation needed. (STOP if you find the
scanner wired into `CodeGraphBuilder`/`GraphCache`.)

**Recall trade-off (documented in the scanner docblock).** Gating on the callee means a
URI passed to a non-allowlisted custom HTTP wrapper is no longer seeded until the project
adds the wrapper to `http_callees`. This is the intended precision/recall trade and
matches how `dispatch_helpers` handles custom dispatchers. The second-argument idiom
(`request(method, url)`) and array-tail forms are also no longer matched by a bare `,`
anchor — record this recall loss in the docblock alongside plan 031's existing notes.

**Out of scope (deferred):** tightening `{param}` matching so a literal only matches a
param route when no more-specific static route exists. Once the callee allowlist removes
the non-HTTP false positives, the `{param}` looseness only affects genuine HTTP calls and
is a smaller, separate concern — note it as a follow-up, don't build it here.

## Current state (excerpts — confirm against live code)

- `src/Tracers/FrontendReferenceScanner.php:188-214` — `uriCandidates()`, the two
  `preg_match_all` calls (plain literals `:192`, backtick templates `:199`) and the
  docblock `:165-186` that already documents the call-argument-anchor trade-offs.
- `src/Support/RichterConfig.php:27-31` — `dispatchHelpers()` via `stringList()`; the
  pattern to mirror for `frontendHttpCallees()`.
- `config/richter.php:35-45` — the `frontend` config block (`roots`,
  `generated_paths`, `pages_path`, `test_paths`) — add `http_callees` here with a doc
  comment.
- `src/Tracers/DispatchEdgeTracer.php:53-56` — the built-in ∪ configured merge pattern.
- `tests/Unit/FrontendReferenceScannerTest.php` — existing scanner tests (they already
  use `/videos/...` fixtures — migrate the ones you touch to neutral `/posts/...` per the
  anonymization rule below, and coordinate with plan 041).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'FrontendReferenceScanner|FrontendChanges|FrontendSeam'` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | exit 0 / 0 / 0 changed |

## Scope

**In scope:**
- `src/Tracers/FrontendReferenceScanner.php` (allowlist-gated matching + docblock)
- `src/Support/RichterConfig.php` (`frontendHttpCallees(): list<string>`)
- `config/richter.php` (`frontend.http_callees` default + comment)
- `tests/Unit/FrontendReferenceScannerTest.php` (FP-exclusion + real-call-inclusion +
  config-extension cases)
- `README.md` (frontend config table: the new key; note the callee gating)

**Out of scope:**
- `{param}` route-template matching precision (deferred follow-up).
- `GraphCache` fingerprint (scanner is diff-time; STOP if that's not true).
- The Wayfinder-import / Ziggy `route()` branches (`scan()` `:35-81`) — those are
  callee-specific already; only `uriCandidates()` over-fires.

## Git workflow

- Branch `advisor/038-frontend-http-callee-allowlist` from the local main tip; commit per
  logical unit (config + RichterConfig; scanner gating + tests; README). No signing. End:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Do NOT push or open a PR.

## Fixtures & anonymization (MANDATORY)

Neutral domain only. Map the consumer's reported FPs to: `translate('/preferences')`,
`someHelper('/api/v2/reports', 'other')`, `console.log('/{post} opened')` — all must be
**excluded**. Real calls that must still be seeded: `route('posts.show', {post})`,
`fetch('/posts')`, `axios.get('/api/reports')`, `useFetch('/posts/${id}')` (backtick).

## Steps

### Step 1: Config + typed accessor (test-first)

- `config/richter.php`: add `'http_callees' => []` under `frontend` with a comment
  ("Extra JS/TS callees, beyond the built-in HTTP/route helpers, whose string arguments
  are treated as backend endpoints. Match the callee's leading identifier, e.g.
  `myHttpClient`.").
- `RichterConfig::frontendHttpCallees(): array` via `stringList('richter.frontend.http_callees') ?? []`.
- Scanner merges built-in defaults ∪ configured, injected via constructor (mirror
  `DispatchEdgeTracer`); confirm the scanner's construction site passes the config.

### Step 2: Gate `uriCandidates()` on the allowlist (test-first)

Rewrite the two matches to capture the callee (leading identifier + optional `.method`)
immediately before `(`, and keep a candidate only when the leading identifier is in the
allowlist. Tests:

1. `translate('/preferences')`, `someHelper('/api/v2/reports')`, `console.log('/{post}')`
   → **no** URI candidates.
2. `fetch('/posts')`, `axios.get('/api/reports')`, `route(... )` (via existing branch),
   `useFetch('/posts/${id}')` → candidates present, method pinned where derivable.
3. A configured `http_callees: ['myClient']` makes `myClient.post('/posts')` a candidate;
   without it, not.
4. Regression: object-literal / non-call-position paths still excluded (plan 019/031
   behaviour holds).

Update the docblock to state the callee-gating rule and the recall losses (custom
wrappers need registering; `request(method, url)` second-arg and array-tail forms no
longer matched).

### Step 3: README + full regression

- README frontend config table: add `http_callees`; one sentence that literal-URI seeding
  now requires an HTTP/route callee (register custom wrappers via config).
- `composer test` → passed; `phpstan`/`pint --test`/`rector --dry-run` clean.

## Done criteria

- [ ] The three reported FP shapes yield zero URI candidates; the four real-call shapes
      still seed, method pinned where derivable
- [ ] `http_callees` config extends the allowlist (test-proven)
- [ ] Scanner docblock records the callee-gating rule + recall losses
- [ ] No `GraphCache` fingerprint change (scanner stays diff-time)
- [ ] `composer test` / `phpstan` / `pint --test` / `rector --dry-run` clean
- [ ] README frontend table updated; no out-of-scope files touched; `plans/README.md` row updated

## STOP conditions

- Drift vs the "Current state" excerpts.
- The scanner turns out to run during graph build (fingerprint implications — report before proceeding).
- A default-allowlist choice would drop a common real idiom that existing tests rely on —
  report the conflict rather than narrowing recall silently.

## Maintenance notes

- Keep the built-in allowlist to genuine HTTP/route entry idioms; project-specific wrappers
  belong in config, not the default set.
- The `{param}`-precision follow-up (a literal matches a param route only when no static
  route is more specific) is the natural next precision step if consumers still see
  param-route over-matching after this lands.
