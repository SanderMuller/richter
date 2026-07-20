# Plan 039: Recognise enum-wrapper feature flags, not just the `Feature` facade

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2d8a437..HEAD -- src/Tracers/FeatureGateChecker.php src/Support/RichterConfig.php config/richter.php tests/Unit/FeatureGateCheckerTest.php README.md`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (annotation-only recall improvement; a miss is silent and safe — never
  feeds risk/gates/affected-tests. The only hazard is a false-positive flag note, guarded
  by requiring a detectable enum-case receiver)
- **Depends on**: none
- **Category**: recall (consumer dogfood, Finding 4)
- **Planned at**: commit `2d8a437`, 2026-07-20

## Why this matters

`FeatureGateChecker` annotates "this change sits behind flag X" only when the changed
source itself contains a `Laravel\Pennant\Feature::` facade call (`onFeatureFacade()`,
`:81-98`) or a Blade `@feature('x')` (`:105`). Many teams — including the reporting
consumer — wrap Pennant in an enum: `FeatureToggle::BETA_DASHBOARD->isActive()`, whose
`isActive()` body is `Feature::active($this->value)`. The **consumer** call site contains
no `Feature::` token, so a change to a flag-gated entry point is **never** annotated as
gated. The "behind an off-by-default flag → smaller live blast radius" signal silently
doesn't fire for the most common real-world flag convention.

## Design (decided — do not re-litigate)

**A config-driven flag-method allowlist**, mirroring `richter.dispatch_helpers`: a new
`richter.feature_gate_methods` (`list<string>`) of `FQCN::method` entries, e.g.
`App\Enums\FeatureToggle::isActive`. When the changed source contains a method call
`->method(...)` whose:

- method name matches an allowlisted method, **and**
- receiver is a **class-constant / enum-case fetch** (`FeatureToggle::BETA_DASHBOARD`)
  resolving to the allowlisted class,

annotate the flag. The **flag name** comes from the enum case via the existing
`flagName()` machinery (`:142-173`), which already resolves a `ClassConstFetch` to its
backing string, or keeps `FeatureToggle::BETA_DASHBOARD` verbatim when it can't load —
so an unresolvable flag still reads as gated rather than disappearing.

**Detectable shapes only (no type inference).** Match `EnumClass::CASE->method()` (a
`MethodCall` whose `->var` is a `ClassConstFetch` on the allowlisted class). A bare
`$service->isActive()` where the receiver type is unknown is **not** matched — resolving
it needs a parser/type system, and this is annotation-only where a miss is safe. Do not
guess.

**Same locality rule as today.** Only checks *in* the changed member's line span count
(`$lineRanges`, `:61`) — never "somewhere on the path." Preserve `withinRanges` gating.

**Config, not cache.** `FeatureGateChecker` runs at **diff time** (`ChangedSymbols::sourceFindings`,
`ChangedSymbols.php:201-214`), not during graph build, so this config does **not** enter
the `GraphCache` fingerprint. (STOP if you find the checker in `CodeGraphBuilder`.)

**Iron rule holds.** The feature-gate note is an advisory `findings` string; it does not
feed `risk`, the `--fail-on` gate, or `affected-tests`. Keep it that way — the new
allowlisted matches join the same `findings()` output, nothing else.

## Current state (excerpts — confirm against live code)

- `src/Tracers/FeatureGateChecker.php`
  - `:30` `FEATURE_METHODS` (the facade methods).
  - `:38-75` `findingsFor()` — the `CallLike` loop; the `onFeatureFacade()` gate at `:65`
    is what excludes the wrapper case.
  - `:81-98` `onFeatureFacade()`; `:120-173` `flagNames()`/`flagName()` (reuse for the
    enum case's backing value); `:179-187` `findings()` (the note wording).
- `src/Support/RichterConfig.php:27-31` — `dispatchHelpers()` via `stringList()`; mirror
  for `featureGateMethods()`.
- `config/richter.php:13` — the `dispatch_helpers` stanza to model the new one after.
- `tests/Unit/FeatureGateCheckerTest.php` — existing checker tests (facade + fluent +
  `@feature`); add the wrapper cases here.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'FeatureGateChecker|ChangedSymbols'` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | exit 0 / 0 / 0 changed |

## Scope

**In scope:**
- `src/Tracers/FeatureGateChecker.php` (allowlisted-method matching alongside the facade branch)
- `src/Support/RichterConfig.php` (`featureGateMethods(): list<string>`)
- `config/richter.php` (`feature_gate_methods` default `[]` + comment)
- `tests/Unit/FeatureGateCheckerTest.php` (wrapper detection + flag-name resolution + no-guess cases)
- `README.md` (config table row + one sentence on wrapper support)

**Out of scope:**
- Type-inferred receivers (`$var->isActive()`) — explicitly not matched.
- `entryPointGates` / route-middleware gates (a different, graph-sourced signal).
- `GraphCache` fingerprint.

## Git workflow

- Branch `advisor/039-feature-gate-method-allowlist` from the local main tip; commit per
  logical unit (config + accessor; checker matching + tests; README). No signing. End:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Do NOT push or open a PR.

## Fixtures & anonymization (MANDATORY)

Neutral domain only. Map the consumer's `FeatureFlag::VIDEO_ISSUES_PANEL->isActive()` to
`App\Enums\FeatureToggle::BETA_DASHBOARD->isActive()`, with the enum case backing a string
flag (`case BETA_DASHBOARD = 'beta-dashboard';`). Do **not** use `FeatureFlag` /
`VIDEO_*` naming.

## Steps

### Step 1: Config + accessor (test-first)

- `config/richter.php`: `'feature_gate_methods' => []` with a comment ("Project wrappers
  around Pennant, as `Enum\\Class::method`, e.g. `App\\Enums\\FeatureToggle::isActive`.
  A `EnumCase->method()` call then annotates the change as flag-gated.").
- `RichterConfig::featureGateMethods(): array` via `stringList(...) ?? []`.
- Inject into `FeatureGateChecker` (constructor param, defaulting to `[]`); confirm the
  construction sites in `ChangedSymbols` pass `RichterConfig::featureGateMethods()`.

### Step 2: Match the wrapper shape (test-first)

Extend `findingsFor()`: for a `MethodCall` whose name is an allowlisted method and whose
`->var` is a `ClassConstFetch` resolving (via `AppFiles::resolveName`) to the allowlisted
class, take the flag name from the case via `flagName()`. Respect `$lineRanges`. Tests:

1. `FeatureToggle::BETA_DASHBOARD->isActive()` with `feature_gate_methods:
   ['App\\Enums\\FeatureToggle::isActive']` → note names the resolved flag (`beta-dashboard`
   if loadable, else `FeatureToggle::BETA_DASHBOARD`).
2. Without the config entry → no note (regression: default behaviour unchanged).
3. Facade + `@feature` cases still annotate (regression).
4. `$service->isActive()` (non-enum receiver) → no note (no guessing).
5. Locality: the wrapper call in an **untouched** sibling method (outside `$lineRanges`)
   → no note.

### Step 3: README + full regression

- README config table: `feature_gate_methods` row; one sentence noting only `Feature`
  facade / `@feature` / configured wrapper methods are recognised.
- `composer test` → passed; `phpstan`/`pint --test`/`rector --dry-run` clean.

## Done criteria

- [ ] Configured wrapper call annotates the flag (resolved name where loadable); unconfigured is silent
- [ ] Facade / `@feature` / locality behaviour unchanged (regressions green)
- [ ] Non-enum receiver never annotated (no guessing)
- [ ] `grep -rn 'featureGate\|feature_gate' src/Analysis/ImpactAnalyzer.php src/Analysis/AffectedTests.php` → no matches (iron rule)
- [ ] `composer test` / `phpstan` / `pint --test` / `rector --dry-run` clean
- [ ] README config row added; no out-of-scope files touched; `plans/README.md` row updated

## STOP conditions

- Drift vs the "Current state" excerpts.
- The checker turns out to run during graph build (fingerprint implications — report first).
- You find yourself wanting to type-infer a bare `$var->method()` receiver — out of scope; a
  miss there is safe.

## Maintenance notes

- The allowlist is per-project config; ship no default entries (the wrapper class name is
  project-specific). The built-in `Feature` facade / `@feature` support stays as the zero-config baseline.
- If a project uses a helper *function* wrapper (`feature_active('x')`) rather than an enum
  method, that's a separate shape — add a function allowlist only if a consumer reports it.
