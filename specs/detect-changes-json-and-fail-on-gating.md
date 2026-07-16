# detect-changes JSON output and opt-in fail-on gating

<!-- spec:planned-at d968dcca25290e24e1433d0f7022b6702e426696 2026-07-16 +uncommitted -->

## Overview

Give Richter a machine-readable surface and an opt-in CI gate without abandoning its advisory-by-default identity. Add `--json` to both `richter:impact` and `richter:detect-changes`, add opt-in `--fail-on=<level>` and `--fail-on-unresolved` exit codes to `richter:detect-changes`, and document a copy-paste GitHub Actions recipe in the README. Default behaviour is unchanged: no flags means advisory text and exit 0.

## Assumptions

<!-- Skim this ledger to sign off. Each bullet is an inference the spec makes; the 3 architecture forks were already decided via AskUserQuestion (see Resolved Questions). -->

- **Scope is primitives + README recipe only.** No shipped `action.yml`, no PR-comment workflow. (Decided — see Resolved Questions.)
- **`--json` on both commands.** (Decided.) `richter:detect-changes` JSON *excludes* the raw `callers`/`dependencies` walk internals and exposes the meaningful payload: `changed`, `coverage`, `entryPoints`, `impacted`, `relatedModels`, `risk`, `lowConfidence`, `coarseCapApplied`, `findings`, plus a top-level `base`, `unresolved`, and an optional `gate` object.
- **Unresolved handling is a separate `--fail-on-unresolved` boolean flag,** independent of the risk threshold. (Decided.)
- **Gate default:** with no fail flags, `detect-changes` exits 0 as today and prints the existing `(advisory — not a gate)` suffix verbatim. Gating is purely additive.
- **Single non-zero exit code (`Command::FAILURE` = 1)** for every fail outcome (gate tripped, invalid `--fail-on` value, broken diff under a gate flag). Distinct per-reason codes were considered and rejected — CI only checks zero/non-zero, and the reason is printed. *(LOAD-BEARING for the recipe's semantics.)*
- **Broken diff (bad `--base`, git failure) flips from SUCCESS to FAILURE only when a gate flag is set.** With no gate flag, the current warn-and-exit-0 behaviour (`DetectChangesCommand.php:33-39`) is untouched. Rationale: under a gate, "couldn't assess" must not read as "pass" — the same stance as `--fail-on-unresolved`. *(LOAD-BEARING — reverses a documented behaviour; STOP condition.)*
- **`--json` routes all human chatter to stderr** ("Building code graph…", "No changed PHP files…", warnings) so stdout is a single parseable JSON document. Non-JSON mode is unchanged. *(LOAD-BEARING for pipe-ability.)*
- **JSON lists are uncapped** (full arrays), unlike the text formatter's `LIST_CAP = 15`. This matches the existing note at `ImpactFormatter.php:112` ("machine-readable result arrays are untouched — only the text is capped").
- **Empty-diff `--json`** emits a canonical zero-result object (`"risk": "low"`, empty collections, `"unresolved": false`), not `{}` or a `{"status":"no-changes"}` sentinel — so consumers parse one stable shape.
- **Broken-diff `--json`** emits `{"error": "<message>"}` on stdout (message also on stderr).
- **Invalid `--fail-on=<bogus>`** is a usage error: message + FAILURE, no graph build.
- **When a gate flag is set, the formatter's risk-line suffix `(advisory — not a gate)` is suppressed** via a new optional `bool $gateActive = false` parameter, and the command appends an explicit `Gate:` verdict line. Default output is byte-for-byte unchanged.
- **No JSON schema-version field for the MVP.** The JSON shape is governed by semver from first release.
- **New public method `RiskLevel::atLeast(self): bool`** for the threshold comparison (reuses the existing `rank()`/`exceeds()`).
- **New classes:** `SanderMuller\Richter\Analysis\Gate` (trip decision, unit-tested in isolation) and `SanderMuller\Richter\Analysis\JsonPresenter` (analyzer-result → JSON-ready array).
- **MCP tools stay text-only.** Reusing `JsonPresenter` to give the MCP tools structured content is a noted non-goal, not part of this spec.

---

## 1. Current state

- `richter:detect-changes` (`src/Console/DetectChangesCommand.php:27-52`) always returns `self::SUCCESS`: on a broken diff it warns and exits 0 (`:33-39`), on an empty diff it prints a line and exits 0 (`:41-45`), otherwise it prints `ImpactFormatter::detectChanges(...)` and exits 0 (`:49-51`). The class docblock states "self-review aid — never a gate".
- `richter:impact` (`src/Console/ImpactCommand.php:18-28`) prints `Building code graph…` (`:22`) then the text report, exit 0.
- `ImpactAnalyzer::detectChanges()` (`src/Analysis/ImpactAnalyzer.php:61-210`) already returns a fully structured, PHPStan-typed array (`:47-59`) with `risk` as a `RiskLevel` enum. `impact()` returns `array{target, callers, dependencies}` (`:34-43`). No analysis changes are needed — this feature only adds presentation and exit-code logic on top.
- `ImpactFormatter` (`src/Analysis/ImpactFormatter.php`) renders those arrays as text; the risk line hard-codes the advisory suffix (`:77`), caps lists at 15 (`LIST_CAP`, `:17`, `:117-133`), and already documents that the machine arrays are uncapped (`:112`).
- `RiskLevel` (`src/Analysis/RiskLevel.php`) is a string enum (`low`/`medium`/`high`) with `exceeds(self)` (`:11`) over a private `rank()`.
- Tests use the `runArtisan()` → `PendingCommand` helper with `expectsOutputToContain(...)` + `assertSuccessful()`/`assertFailed()` (`tests/Feature/CommandsTest.php:81-88`). `benchmark` already exits non-zero on a bad case filter (`:26-29`), so non-zero exit codes are an established pattern; only `detect-changes` carries the always-SUCCESS guarantee.

## 2. JSON payloads

### `richter:impact --json` (stdout)

```json
{
  "target": "App\\Models\\User",
  "callers": [{ "depth": 1, "node": "...", "via": "..." }],
  "dependencies": [{ "depth": 1, "node": "...", "via": "..." }]
}
```

Full/uncapped. `Building code graph…` goes to stderr.

### `richter:detect-changes --json` (stdout)

```json
{
  "base": "origin/main",
  "changed": { "app/Jobs/ProcessVideoJob.php": 3 },
  "coverage": { "app/Jobs/ProcessVideoJob.php": "analyzed" },
  "entryPoints": ["command::video:process", "App\\Listeners\\SendVideoNotification"],
  "impacted": 12,
  "relatedModels": ["App\\Models\\Video"],
  "risk": "medium",
  "lowConfidence": false,
  "coarseCapApplied": false,
  "findings": ["app/Jobs/ProcessVideoJob.php: ..."],
  "unresolved": false,
  "gate": {
    "failOn": "high",
    "failOnUnresolved": true,
    "tripped": false,
    "reasons": []
  }
}
```

- `gate` is present only when at least one of `--fail-on` / `--fail-on-unresolved` is set; otherwise the key is omitted.
- Empty diff: `changed`/`coverage`/`entryPoints`/`relatedModels`/`findings` empty, `impacted` 0, `risk` `"low"`, `unresolved` false.
- Broken diff: `{ "error": "<message>" }` (no other keys).

## 3. Gate evaluation

New `SanderMuller\Richter\Analysis\Gate`:

```php
/** @return array{tripped: bool, reasons: list<string>} */
public static function evaluate(
    RiskLevel $risk,
    bool $anyUnresolved,
    ?RiskLevel $failOn,
    bool $failOnUnresolved,
): array;
```

- Risk gate trips when `$failOn !== null && $risk->atLeast($failOn)`.
- Unresolved gate trips when `$failOnUnresolved && $anyUnresolved`.
- Both can trip; `reasons` lists each in human-readable form (e.g. `"risk high ≥ medium"`, `"1 changed file UNRESOLVED"`).
- `tripped` is the OR of the two. The command maps `tripped` → `self::FAILURE`, else `self::SUCCESS`.

New `RiskLevel::atLeast(self $other): bool` → `$this === $other || $this->exceeds($other)`.

## 4. Command wiring

- `DetectChangesCommand` signature gains: `{--json : Emit the report as JSON on stdout}`, `{--fail-on= : Exit non-zero when risk is at least this level (low|medium|high); advisory by default}`, `{--fail-on-unresolved : Exit non-zero when any changed PHP file is UNRESOLVED}`.
- `ImpactCommand` signature gains `{--json : Emit the blast radius as JSON on stdout}`.
- Validate `--fail-on`: `RiskLevel::tryFrom((string) $option)`; non-empty-but-invalid → `$this->error(...)` + `self::FAILURE`, before building the graph.
- In `--json` mode, status/info/warn lines write to the error output stream, not stdout.
- The gate is evaluated only by `detect-changes` and only when a fail flag is present; `impact` never gates.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Empty diff, `--json` | Canonical zero-result object on stdout, exit 0 — Phase 1 Tests. |
| Empty diff, `--fail-on=high` | Risk `low`, no unresolved → gate passes, exit 0 — Phase 2 Tests. |
| Broken `--base`, no gate flag | Warn to stderr, exit 0 (unchanged) — Phase 2 Tests. |
| Broken `--base`, any gate flag | Error to stderr (+ `{"error":…}` if `--json`), exit `FAILURE` — Phase 2 Tests. |
| `--fail-on=high`, risk `medium` | Gate passes, exit 0 — Phase 2 (Gate) Tests. |
| `--fail-on=medium`, risk `high` | Gate trips, exit `FAILURE` — Phase 2 (Gate) Tests. |
| `--fail-on-unresolved` set, file unresolved, risk `low` | Gate trips on unresolved alone, exit `FAILURE` — Phase 2 (Gate) Tests. |
| Unresolved file, `--fail-on-unresolved` *not* set | Coverage note printed, exit 0 — Phase 2 (Gate) Tests. |
| Both gates trip | Both reasons listed, single `FAILURE` — Phase 2 (Gate) Tests. |
| `--fail-on=bogus` | Usage error, `FAILURE`, no graph build — Phase 2 Tests. |
| `--json` on `impact` | `Building code graph…` on stderr; stdout is pure JSON — Phase 1 Tests. |
| Reach list > 15 entries, `--json` | JSON arrays uncapped (no `… and N more`) — Phase 1 Tests. |

## Implementation

### Phase 1: JSON output on both commands (Priority: HIGH)

- [ ] Add `RiskLevel::atLeast(self): bool` — reuses `rank()`; needed by Phase 2 but lives with the enum.
- [ ] Add `SanderMuller\Richter\Analysis\JsonPresenter` with `impact(array): array` and `detectChanges(array, ?string $base): array` — convert `risk` enum → value, drop `callers`/`dependencies` for detect-changes, add `unresolved` (any coverage === `unresolved`) and `base`. Uncapped.
- [ ] Add `--json` to `ImpactCommand`; route `Building code graph…` to stderr in JSON mode; `json_encode` the presenter output to stdout (`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`? decide in Findings — default pretty for human-diffable CI logs).
- [ ] Add `--json` to `DetectChangesCommand`; route info/warn to stderr in JSON mode; emit canonical empty-result object on empty diff and `{"error":…}` on broken diff.
- [ ] Tests — unit: `JsonPresenter` shape for a synthetic `impact` and `detectChanges` result (risk→string, no callers/deps in detect-changes, `unresolved` flag, uncapped list beyond 15). Feature: `impact --json` and `detect-changes --json` on the empty diff produce valid JSON (`json_decode` non-null) with `assertSuccessful()`, and stdout carries no human chatter.

### Phase 2: Opt-in fail-on gating on detect-changes (Priority: HIGH)

- [ ] Add `SanderMuller\Richter\Analysis\Gate::evaluate(...)` returning `{tripped, reasons}`.
- [ ] Add `--fail-on=` and `--fail-on-unresolved` to `DetectChangesCommand`; validate `--fail-on` via `RiskLevel::tryFrom` (invalid → error + `FAILURE`).
- [ ] Wire the gate: evaluate after the report, set exit code from `tripped`; under a gate flag, a broken diff returns `FAILURE` instead of the default warn-and-0.
- [ ] Add optional `bool $gateActive = false` to `ImpactFormatter::detectChanges`; when true, suppress the `(advisory — not a gate)` suffix (`:77`). Command appends a `Gate: PASS/FAIL` line with reasons in text mode; JSON mode adds the `gate` object.
- [ ] Tests — unit: `Gate::evaluate` across the risk ladder (`fail-on` low/medium/high × risk low/medium/high), unresolved-independent trips, both-trip case, and `RiskLevel::atLeast`. Feature: `--fail-on=bogus` → `assertFailed()`; broken `--base` with `--fail-on=low` → `assertFailed()`; broken `--base` without a gate flag → `assertSuccessful()` (regression guard on the unchanged default).

### Phase 3: README CI recipe (Priority: MEDIUM)

- [ ] Add a "Gating in CI" subsection under Usage: a GitHub Actions job that fetches the base ref, runs `php artisan richter:detect-changes --base=origin/${{ github.base_ref }} --fail-on=high --fail-on-unresolved`, and (optionally) captures `--json` for a downstream step. State plainly that gating is opt-in, advisory is the default, and no Action ships with the package.
- [ ] Update the intro's "advisory, not a gate" line to note that `--fail-on` opts into gating, so the README doesn't contradict the new flag.
- [ ] Tests — none (docs). Manually confirm the snippet is valid YAML and the flags match the shipped signatures.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **Broken-diff-under-gate reversal** — the plan flips `DetectChangesCommand`'s documented "advisory tooling exits successfully" behaviour (`:33-39`) to `FAILURE` *only when a gate flag is set*. If the default (no-flag) path can't be preserved exactly, stop — the advisory identity is the core contract.
2. **`--json` stdout purity** — if the framework/testbench cannot route info/warn to stderr and something still prints to stdout ahead of the JSON, the payload is unparseable; stop rather than shipping polluted output.
3. **Analyzer result array is the JSON contract** — `JsonPresenter` serialises `ImpactAnalyzer::detectChanges()`'s return shape (`:47-59`). If that shape is mid-refactor (the working tree was dirty at planning time — see Findings), re-confirm it before pinning the JSON keys.

---

## Open Questions

None. The three architecture forks are resolved below; remaining choices are recorded in `## Assumptions` for skim sign-off.

---

## Resolved Questions

1. **How much of the CI/CD integration should the spec cover?** **Decision:** Primitives (`--json` + `--fail-on`) plus a README recipe; no shipped Action or PR-comment workflow. **Rationale:** Leanest surface; an `action.yml` is a versioning/maintenance burden the primitives don't require, and a static PR template can't run the tool.
2. **Should an UNRESOLVED file trip `--fail-on`?** **Decision:** A separate `--fail-on-unresolved` boolean flag, independent of the risk threshold. **Rationale:** Keeps the risk ladder and the coverage-completeness concern orthogonal, so a team can gate on either without conflating them.
3. **Which commands get `--json`?** **Decision:** Both `detect-changes` and `impact`. **Rationale:** Symmetric surface; supports scripting `impact` outside an MCP client.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- Working tree was dirty at planning time (`git status` showed modifications to `src/Analysis/BenchmarkCase.php` and `tests/Feature/CommandsTest.php`, plus the pre-existing `.config/boost.php` / `AGENTS.md` / `CLAUDE.md` / `README.md` edits). The `spec:planned-at` stamp carries `+uncommitted`; re-verify `file:line` references against the working tree before building, per STOP condition 3.
