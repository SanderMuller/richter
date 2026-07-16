# detect-changes JSON output and opt-in fail-on gating

<!-- spec:planned-at d968dcca25290e24e1433d0f7022b6702e426696 2026-07-16 +uncommitted -->

## Overview

Give Richter a machine-readable surface and an opt-in CI gate without abandoning its advisory-by-default identity. Add `--json` to both `richter:impact` and `richter:detect-changes`, add opt-in `--fail-on=<level>` and `--fail-on-unresolved` exit codes to `richter:detect-changes`, and document a copy-paste GitHub Actions recipe in the README. Default behaviour is unchanged: no flags means advisory text and exit 0.

## Assumptions

<!-- Skim this ledger to sign off. The 3 architecture forks were decided via AskUserQuestion (Resolved Questions); the rest were refined by a Codex review of the spec (see Findings). -->

- **Scope is primitives + README recipe only.** No shipped `action.yml`, no PR-comment workflow. (Decided.)
- **`--json` on both commands.** (Decided.) `richter:detect-changes` JSON *excludes* the raw `callers`/`dependencies` walk internals and exposes the meaningful payload: `changed`, `coverage`, `entryPoints`, `impacted`, `relatedModels`, `risk`, `lowConfidence`, `coarseCapApplied`, `findings`, plus top-level `base`, `unresolved`, and an optional `gate` object.
- **Unresolved handling is a separate `--fail-on-unresolved` boolean flag,** independent of the risk threshold. (Decided.)
- **`--json` mode suppresses all non-JSON output; stdout is exactly one JSON document.** Progress/info/warn lines are *not emitted* in JSON mode (not merely routed to stderr) — every outcome, including empty diff, broken/invalid ref, invalid flag, and impact-no-match, is expressed as a JSON document on stdout. This is what makes it both pipe-safe and testable via `Artisan::output()`, which merges streams (`BufferedOutput` is not a `ConsoleOutputInterface`, so `getErrorStyle()` would fall back to the same buffer — routing to stderr would be untestable in-process). *(LOAD-BEARING.)*
- **Unified `--json` error contract:** in JSON mode, every command-level failure — invalid `--fail-on` value, invalid/option-injection base ref from `RichterConfig::baseRef()`, broken diff from `ChangedSymbols::resolve()`, *and* any unexpected graph-build/analyze `Throwable` — emits `{"error": "<message>"}` on stdout via an outer backstop (§4), never a framework stack trace. *(LOAD-BEARING — closes the pre-diff validation gap and the post-diff analyze gap.)*
- **Non-JSON error behaviour is preserved exactly** (two existing tests guard it): a broken diff warns and — with no gate flag — exits 0 (`CommandsTest.php:78-84`); an option-injection ref lets `InvalidArgumentException` propagate uncaught with no gate flag (`CommandsTest.php:86-92`). Gate flags add failure on top (below); they never relax these.
- **Gate default:** with no fail flags, `detect-changes` exits 0 as today and prints the existing `(advisory — not a gate)` suffix verbatim. Gating is purely additive.
- **Empty diff always passes the gate** (exit 0) regardless of `--fail-on`, because there is nothing to assess. The gate is evaluated only when a change set exists — this avoids the degenerate `--fail-on=low` tripping on an empty diff (`low` ≥ `low`). *(LOAD-BEARING for correct gate semantics.)*
- **Single non-zero exit code (`Command::FAILURE` = 1)** for every fail outcome (gate tripped, invalid `--fail-on` value, broken/invalid ref under a gate flag). Distinct per-reason codes were rejected — CI checks zero/non-zero and the reason is printed. Consistent with `BenchmarkCommand`, which already returns failure for both bad filters and failed fixtures.
- **Broken/invalid ref flips to FAILURE only when a gate flag is set.** With no gate flag the current warn-and-exit-0 (broken diff) / propagate (option-injection) behaviour is untouched. Under a gate, "couldn't assess" must not read as "pass". *(LOAD-BEARING — STOP condition.)*
- **JSON lists are uncapped** (full arrays), unlike the text formatter's `LIST_CAP = 15`. Matches the note at `ImpactFormatter.php:112`.
- **Empty-diff `--json`** emits a canonical zero-result object built by `JsonPresenter::emptyDetectChanges(string $base)` (`risk` `"low"`, empty collections, `unresolved` false) *without building the graph* — preserving the existing empty-path optimisation (`DetectChangesCommand.php:41` returns before `$builder->build()`).
- **`richter:impact --json` with no matching nodes** emits `{"target": "...", "callers": [], "dependencies": []}` — the natural serialisation of the always-present analyzer array (`ImpactAnalyzer.php:34-43`), never the text-mode prose branch (`ImpactFormatter.php:22`).
- **New public method `RiskLevel::atLeast(self): bool`** for the threshold comparison (reuses `rank()`/`exceeds()`).
- **New optional parameter `bool $gateActive = false` on `ImpactFormatter::detectChanges`,** appended after the existing `$tests` parameter (source-compatible). When true, the `(advisory — not a gate)` suffix (`:77`) is suppressed and the command appends an explicit `Gate:` verdict line.
- **New classes:** `SanderMuller\Richter\Analysis\Gate` (trip decision, unit-tested) and `SanderMuller\Richter\Analysis\JsonPresenter` (analyzer-result → JSON-ready array + empty-result factory).
- **No JSON schema-version field for the MVP;** the JSON shape is governed by semver from first release.
- **MCP tools stay text-only.** Reusing `JsonPresenter` for structured MCP content is a noted non-goal.

---

## 1. Current state

- `richter:detect-changes` (`src/Console/DetectChangesCommand.php:27-52`) resolves the base ref at `:29` (**outside** the `try`), catches only `RuntimeException` at `:33`, and on every path it *reaches* returns `self::SUCCESS`: broken diff warns and exits 0 (`:33-39`), empty diff prints a line and exits 0 (`:41-45`, before `$builder->build()` at `:47`), otherwise prints `ImpactFormatter::detectChanges(...)` and exits 0 (`:49-51`). The one path that does **not** return `SUCCESS` today: `RichterConfig::baseRef()` (`src/Support/RichterConfig.php`, working-tree version) throws `InvalidArgumentException` on an option-shaped ref from `:29`, ahead of the `try`, so it propagates uncaught.
- `richter:impact` (`src/Console/ImpactCommand.php:18-28`) prints `Building code graph…` (`:22`) then the text report, exit 0.
- `ImpactAnalyzer::detectChanges()` (`src/Analysis/ImpactAnalyzer.php:61-210`) already returns a fully structured, PHPStan-typed array (`:47-59`) with `risk` a `RiskLevel`. `impact()` returns `array{target, callers, dependencies}` (`:34-43`). No analysis changes are needed.
- `ImpactFormatter` (`src/Analysis/ImpactFormatter.php`): risk line hard-codes the advisory suffix (`:77`), caps lists at 15 (`LIST_CAP`, `:17`, `:117-133`), and documents that the machine arrays are uncapped (`:112`).
- `RiskLevel` (`src/Analysis/RiskLevel.php`) is a string enum (`low`/`medium`/`high`) with `exceeds(self)` (`:11`).
- Tests (`tests/Feature/CommandsTest.php`, working tree): the `runArtisan()` → `PendingCommand` helper is at `:143-150`. `PendingCommand::expectsOutputToContain` consumes one write per call and cannot separate stdout from stderr. The **full-output capture pattern** is at `:94-120` (`detect_changes_reports_a_real_diff_end_to_end`): `withoutMockingConsoleOutput()` + `Artisan::call()` + `Artisan::output()` — this is the harness the `--json` tests reuse (`json_decode(Artisan::output())`). Regression guards to keep green: broken base ref (`:78-84`, warn + success), option-injection ref (`:86-92`, `InvalidArgumentException`). `BenchmarkCommand` establishes non-zero exits (`:42`, `:68`).

## 2. JSON payloads

### `richter:impact --json` (stdout)

```json
{
  "target": "App\\Models\\User",
  "callers": [{ "depth": 1, "node": "...", "via": "..." }],
  "dependencies": [{ "depth": 1, "node": "...", "via": "..." }]
}
```

Full/uncapped. No-match: `{ "target": "...", "callers": [], "dependencies": [] }`. `Building code graph…` is suppressed in JSON mode.

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
  "gate": { "failOn": "high", "failOnUnresolved": true, "tripped": false, "reasons": [] }
}
```

- `gate` present only when at least one fail flag is set (`failOn` is `null` when only `--fail-on-unresolved` is set).
- Empty diff: canonical zero object (empty collections, `impacted` 0, `risk` `"low"`, `unresolved` false), `gate.tripped` false when flags set.
- Any command-level failure: `{ "error": "<message>" }` (no other keys).

## 3. Gate evaluation

New `SanderMuller\Richter\Analysis\Gate`:

```php
/** @return array{tripped: bool, reasons: list<string>} */
public static function evaluate(
    RiskLevel $risk,
    int $unresolvedCount,
    ?RiskLevel $failOn,
    bool $failOnUnresolved,
): array;
```

- Risk gate trips when `$failOn !== null && $risk->atLeast($failOn)`.
- Unresolved gate trips when `$failOnUnresolved && $unresolvedCount > 0`.
- Both can trip; `reasons` lists each (e.g. `"risk high ≥ medium"`, `"{$unresolvedCount} changed file(s) UNRESOLVED"`). `tripped` is their OR. The count is derived from the `coverage` map of the `detectChanges()` result, so `Gate` recomputes nothing.
- **Not evaluated on the empty-diff path** — an empty diff always passes (see Assumptions). The command maps `tripped` → `self::FAILURE`, else `self::SUCCESS`.

New `RiskLevel::atLeast(self $other): bool` → `$this === $other || $this->exceeds($other)`.

## 4. Command wiring

### Signatures

- `DetectChangesCommand`: add `{--json : Emit the report as JSON on stdout}`, `{--fail-on= : Exit non-zero when risk is at least this level (low|medium|high); advisory by default}`, `{--fail-on-unresolved : Exit non-zero when any changed PHP file is UNRESOLVED}`.
- `ImpactCommand`: add `{--json : Emit the blast radius as JSON on stdout}`.

### Control flow (`detect-changes`)

1. **Validate `--fail-on`**: empty → `null` (off); else `RiskLevel::tryFrom(...)`; non-empty-but-invalid → failure (JSON: `{"error":…}` on stdout; text: `$this->error(…)`), return `FAILURE`. Do this before any git work.
2. **Resolve ref + diff** inside a single `try` catching `InvalidArgumentException|RuntimeException`:
   - **JSON mode** (catch): emit `{"error": <message>}` on stdout; return `FAILURE` if a gate flag is set, else `SUCCESS`.
   - **Text mode** (catch): `RuntimeException` → `$this->warn(<message>)`, return `FAILURE` if a gate flag is set else `SUCCESS` (preserves `:78-84`); `InvalidArgumentException` → if a gate flag is set `$this->error(<message>)` + `FAILURE`, else **rethrow** (preserves `:86-92`).
3. **Empty diff**: JSON → `JsonPresenter::emptyDetectChanges($base)` (+ non-tripped `gate` object if flags set) on stdout; text → the existing "No changed PHP files…" line (suppressed in JSON mode). Gate not evaluated. Return `SUCCESS`. No graph build.
4. **Analyze**: build graph, run `detectChanges()`.
5. **Gate**: if any fail flag set, `Gate::evaluate(...)`; exit code from `tripped`.
6. **Render**: JSON → `json_encode(JsonPresenter::detectChanges($result, $base) + gate)`; text → `ImpactFormatter::detectChanges($result, $tests, gateActive: <any flag set>)` then, when gated, an appended `Gate: PASS|FAIL` line with reasons.

**JSON-mode backstop:** in `--json` mode the whole handler (steps 1–6) also runs inside an outer `catch (\Throwable $e)` that emits `{"error": <message>}` on stdout and returns `FAILURE`. Step 2 above handles the *expected* ref/diff exceptions with advisory/gate-aware exit codes; the outer catch is the backstop for *unexpected* ones — `$builder->build()` or `detectChanges()` throwing on malformed source (steps 4–5 run outside the step-2 `try`) — so stdout is never anything but a single JSON document. Non-JSON mode is unchanged: build/analyze exceptions propagate to the framework as today.

`impact` mirrors JSON mode: suppress `Building code graph…`, wrap in the same `Throwable` backstop, and emit `JsonPresenter::impact($result)`.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Empty diff, `--json` | Canonical zero object on stdout, exit 0 — Phase 1 Tests. |
| Empty diff, `--fail-on=low` or `=high` | Gate not evaluated; passes, exit 0 — Phase 2 Tests. |
| Broken `--base`, no gate flag, text | Warn to stderr-style output, exit 0 (unchanged; guard `:78-84`) — Phase 2 Tests. |
| Broken `--base`, gate flag, text | Warn, exit `FAILURE` — Phase 2 Tests. |
| Broken `--base`, `--json` (any) | `{"error":…}` on stdout; exit 0 (no gate) / `FAILURE` (gate) — Phase 2 Tests. |
| Option-injection `--base`, no gate flag, text | `InvalidArgumentException` propagates (unchanged; guard `:86-92`) — Phase 2 Tests. |
| Option-injection `--base`, `--json` | `{"error":…}` on stdout, not a stack trace — Phase 2 Tests. |
| `--fail-on=medium`, risk `high` | Gate trips, exit `FAILURE` — Phase 2 (Gate) Tests. |
| `--fail-on=high`, risk `medium` | Gate passes, exit 0 — Phase 2 (Gate) Tests. |
| `--fail-on-unresolved`, a file unresolved, risk `low` | Trips on unresolved alone, exit `FAILURE` — Phase 2 (Gate) Tests. |
| Unresolved file, `--fail-on-unresolved` not set | Coverage note printed, exit 0 — Phase 2 (Gate) Tests. |
| Both gates trip | Both reasons listed, single `FAILURE` — Phase 2 (Gate) Tests. |
| `--fail-on=bogus` (text and `--json`) | Usage error → `{"error":…}` (JSON) / `error()` (text), `FAILURE`, no graph build — Phase 2 Tests. |
| `impact --json`, no matching nodes | `{"target":…,"callers":[],"dependencies":[]}`, exit 0 — Phase 1 Tests. |
| Reach list > 15 entries, `--json` | JSON arrays uncapped (no `… and N more`) — Phase 1 Tests (unit). |

## Implementation

### Phase 1: JSON output on both commands (Priority: HIGH) — DONE

- [x] Add `RiskLevel::atLeast(self): bool` — reuses `exceeds()`/`rank()`; used by Phase 2 but lives with the enum.
- [x] Add `SanderMuller\Richter\Analysis\JsonPresenter`: `impact(array): array`; `detectChanges(array $result, string $base): array` (risk enum → value, drop `callers`/`dependencies`, add `unresolved` = any coverage `unresolved`, add `base`, uncapped); `emptyDetectChanges(string $base): array` (canonical zero object, no analyzer result needed); plus an `encode()` helper centralising the flags.
- [x] `ImpactCommand`: add `--json`; suppress `Building code graph…` in JSON mode; emit presenter output to stdout under a `Throwable` backstop.
- [x] `DetectChangesCommand`: add `--json`; split into `handleText`/`handleJson`; JSON emits empty-object on empty diff (before any graph build) and `{"error":…}` on caught failures (per §4). Text path unchanged.
- [x] `json_encode` flags chosen: `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` (see Findings).
- [x] Tests — unit `JsonPresenterTest` (impact pass-through + no-match empties, detectChanges risk→string / no callers-deps / `unresolved` flag / >15 uncapped, `emptyDetectChanges`, `encode`) and `RiskLevelTest` (`atLeast` at/above/below threshold). Feature `CommandsTest` (via `withoutMockingConsoleOutput()` + `Artisan::output()`): `impact --json` no-match → parseable JSON with empty arrays, exit 0; `detect-changes --json --base=HEAD` → canonical empty object, exit 0; `detect-changes --base=--upload-pack=evil --json` → parseable `{"error":…}` with no leaked exception, exit 0. **Deviation:** the spec's "bind a throwing `CodeGraphBuilder`" tests are infeasible — see Findings.

### Phase 2: Opt-in fail-on gating on detect-changes (Priority: HIGH) — DONE

- [x] Add `SanderMuller\Richter\Analysis\Gate::evaluate(...)` returning `{tripped, reasons}`.
- [x] Add `--fail-on=` and `--fail-on-unresolved`; validate `--fail-on` (invalid → `{"error":…}`/`error()` + `FAILURE`, before git work).
- [x] Wire the try/catch error policy from §4 (JSON vs text; gate-flag-aware exit codes; preserve both regression guards).
- [x] Evaluate the gate after analysis (skip on empty diff); set exit code; append the `Gate:` line (text) / `gate` object (JSON).
- [x] Add `bool $gateActive = false` to `ImpactFormatter::detectChanges` (after `$tests`); suppress the advisory suffix (`:77`) when true.
- [x] Tests — unit (`Gate::evaluate`): the full ladder (`fail-on` low/medium/high × risk low/medium/high via `atLeast`), unresolved-count trips independently of risk, both-trip case, and the reason strings (incl. the `{count} changed file(s) UNRESOLVED` text). Feature: `--fail-on=bogus` → `assertFailed()` (and JSON `{"error"}`); broken `--base` + `--fail-on=low` → `assertFailed()`; broken `--base` **without** a gate flag → `assertSuccessful()` (regression guard); confirm the existing `:78-84` / `:86-92` guards still pass; **in `--json` mode, both `--base=--upload-pack=evil` and a broken ref emit a parseable `{"error":…}` on stdout with no stack trace or human chatter (exit 0 with no gate flag, `FAILURE` with one)**; a faked real diff (per `:94-120`) with `--fail-on=<level>` tuned to the produced risk asserts the exit code and the `gate`/`Gate:` output.

### Phase 3: README CI recipe (Priority: MEDIUM) — DONE

- [x] Add a "Gating in CI" subsection under Usage: a GitHub Actions job that fetches the base ref and runs `php artisan richter:detect-changes --base=origin/${{ github.base_ref }} --fail-on=high --fail-on-unresolved`, optionally capturing `--json`. State that gating is opt-in, advisory is the default, and no Action ships with the package.
- [x] Reconcile the intro's advisory line — it currently reads "`richter:detect-changes` never fails your build"; qualify it with "unless you opt into `--fail-on` / `--fail-on-unresolved`" so the README doesn't contradict the new flags.
- [x] Tests — none (docs). Manually confirm the snippet is valid YAML and the flags match the shipped signatures.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **Broken/invalid-ref-under-gate reversal** — the plan flips the documented "advisory exits successfully" behaviour to `FAILURE` *only when a gate flag is set*, and must preserve the two regression guards (`CommandsTest.php:78-84`, `:86-92`) exactly in no-gate mode. If the no-gate paths can't be preserved, stop.
2. **`--json` stdout purity via suppression** — the design relies on emitting nothing but the JSON document on stdout in JSON mode (suppression, not stderr routing), so `json_decode(Artisan::output())` succeeds in tests. If any framework path still writes non-JSON to the captured buffer in JSON mode, stop rather than ship unparseable output.
3. **Analyzer result array is the JSON contract** — `JsonPresenter` serialises `ImpactAnalyzer::detectChanges()`'s return shape (`:47-59`). The working tree was dirty at planning time (see Findings); re-confirm that shape and the `CommandsTest.php` line references against the working tree before building.

---

## Open Questions

None. The three architecture forks are resolved below; the Codex review's findings are folded into the spec (see Findings).

---

## Resolved Questions

1. **How much of the CI/CD integration should the spec cover?** **Decision:** Primitives (`--json` + `--fail-on`) plus a README recipe; no shipped Action or PR-comment workflow. **Rationale:** Leanest surface; an `action.yml` is a versioning/maintenance burden and a static PR template can't run the tool.
2. **Should an UNRESOLVED file trip `--fail-on`?** **Decision:** A separate `--fail-on-unresolved` boolean flag, independent of the risk threshold. **Rationale:** Keeps the risk ladder and coverage-completeness orthogonal.
3. **Which commands get `--json`?** **Decision:** Both `detect-changes` and `impact`. **Rationale:** Symmetric surface; supports scripting `impact` outside an MCP client.
4. **How should `--json` keep stdout parseable while still showing progress/errors?** **Decision:** Suppress all non-JSON output in JSON mode and express every outcome (including errors) as a JSON document on stdout. **Rationale:** Routing to stderr is untestable in-process (testbench buffers merge streams) and pointless for a machine consumer; suppression is simpler, pipe-safe, and testable via `Artisan::output()`.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **Codex review (spec, pre-implementation)** surfaced 6 warranted findings, all folded in: (B1) `--json` error contract now covers pre-diff validation failures (`baseRef()` throwing) — §4 step 2; (B2) invalid `--fail-on` emits `{"error"}` in JSON mode — §4 step 1; (S3) stdout/stderr can't be separated under testbench → switched from stderr-routing to suppression, tests use `Artisan::output()` — Assumptions + §4; (S4) empty-diff JSON now built by `JsonPresenter::emptyDetectChanges` with no graph build — Phase 1; (S5) `impact --json` no-match shape specified + tested — §2; (S6) corrected `CommandsTest.php` line references (`runArtisan` at `:143`, capture pattern at `:94-120`). Codex confirmed the single-`FAILURE` exit design and the semver-safe API additions.
- **Self-review add (not caught by Codex):** empty diff + `--fail-on=low` would naively trip (`low ≥ low`); the gate is now skipped entirely on the empty path.
- Working tree was dirty at planning time — uncommitted edits to `src/Support/RichterConfig.php` (option-injection guard in `baseRef()`), `src/Analysis/BenchmarkCase.php`, and `tests/Feature/CommandsTest.php` (added broken-ref/option-injection/end-to-end tests). The `spec:planned-at` stamp carries `+uncommitted`; these edits are reflected in §1. Re-verify `file:line` references before building (STOP condition 3).
- **Drift preflight (impl start):** HEAD advanced `d968dcc → d5cb1c9` (the planning-time uncommitted work, incl. this spec, was committed). Re-read every cited file: `ImpactAnalyzer`/`CodeGraphBuilder` diffs are comment-only (`detectChanges()` return shape unchanged — JSON contract intact); `CommandsTest.php` matches the spec's references; the core files being modified (`DetectChangesCommand`, `ImpactCommand`, `ImpactFormatter`, `RiskLevel`) are unchanged since planning. No material drift. Baseline suite green (239/239) before starting.
- **Phase 1 `json_encode` flags:** `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` — pretty/unescaped for human-diffable CI logs and readable FQCNs (`App\Models\User`, not `App\\Models\\User` escaping of slashes); `THROW_ON_ERROR` so an un-encodable payload surfaces rather than emitting `false`.
- **Deviation — infeasible "throwing `CodeGraphBuilder`" tests (Phase 1 & 2):** `CodeGraphBuilder` is `final` and the commands instantiate/consume it internally (`$builder->build()` with no injectable `onProgress`), so a throwing/spying builder cannot be substituted via the container or Mockery. The spec's two injection-based tests (prove empty path skips the build; prove the JSON `Throwable` backstop covers a build failure) are therefore not written as specified. Intent is covered instead by: (a) the empty-path optimisation is preserved structurally (JSON `handleJson` returns before `$builder->build()`, verified by code + the canonical-empty-object test) and by the pre-existing `detect_changes_reports_an_empty_diff` guard; (b) all JSON error emission — expected *and* backstop — funnels through the one `JsonPresenter::encode(['error' => …])` path, exercised by the option-injection `--json` test; the `catch (Throwable)` backstop only routes additional exception types into that same proven path. This is a minimal, documented deviation that serves the spec's intent (stdout stays a single parseable JSON document on any failure).
- **Phase 2 done:** `Gate` evaluator + `--fail-on` / `--fail-on-unresolved` wired into `DetectChangesCommand` (text + JSON), gate-aware exit codes on the error paths, `ImpactFormatter::detectChanges` `$gateActive` suffix. Tests: `GateTest` (7 cases) + 8 new `CommandsTest` feature tests. Two naming collisions with PHPUnit/Laravel base classes forced renames — the `JsonPresenterTest` helper `result()` → `detectChangesResult()` (PHPUnit `TestCase::result()` is `final`), and the command helper `fail()` → `emitFailure()` (Laravel `Command::fail()` is `public`, can't be overridden as `private`).
- **Codex review (code, post-implementation)** — 3 rounds, converged clean. Round 1: [P1] JSON mode treated *post-diff* graph/analyze `RuntimeException`s as advisory (exit 0) because the expected-exception catch wrapped the whole `emitJson()` — fixed by splitting ref/diff resolution (advisory) from processing (a `Throwable` backstop → FAILURE); [P2] `--fail-on=` (empty) silently disabled the gate — now a usage error. Round 2: bare `--fail-on` (valueless) still failed open — now detected via `hasParameterOption` and failed closed. Round 3: clean. Tests added: `detect_changes_fails_on_an_explicitly_empty_fail_on_value`, `detect_changes_fails_on_a_valueless_fail_on_flag`. The [P1] post-diff-failure path itself remains non-injection-testable (final `CodeGraphBuilder`, same limitation noted above) — verified by code inspection; expected paths stay green.
- **Phase 3 done — CI-recipe base-ref deviation:** the spec task sketched `--base=origin/${{ github.base_ref }}`, but the recipe ships `--base=${{ github.event.pull_request.base.sha }}` instead. Reason: with `fetch-depth: 0` the base SHA is guaranteed in history, whereas `origin/<base_ref>` is not fetched by default and would need an extra `git fetch` step. The SHA is a valid ref for `merge-base`/`diff` and doesn't start with `-`, so it clears the option-injection guard. YAML validated (Ruby Psych); the four flags (`--base`, `--json`, `--fail-on`, `--fail-on-unresolved`) match the shipped signature.
