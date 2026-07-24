# Plan 048: Note a model field that never reaches its API Resource

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat acb128b..HEAD -- src/Changes/EloquentConfig.php src/Changes/ChangedSymbols.php src/Changes/ChangedFileSymbols.php src/Analysis/ImpactAnalyzer.php src/Analysis/BenchmarkCase.php src/Graph/CodeGraph.php src/Support/RichterConfig.php src/Console/DetectChangesCommand.php src/Console/BenchmarkAddCommand.php config/richter.php tests/Fixtures/project`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: L
- **Risk**: LOW–MEDIUM (advisory `findings` only — never feeds `risk`, `--fail-on` or
  `affected-tests`. The hazard is a *wrong* finding, which teaches readers to ignore the
  lane; every design decision below trades recall away to prevent one)
- **Depends on**: none
- **Category**: recall (consumer handoff, [handoff-payload-parity-2026-07-23.md](handoff-payload-parity-2026-07-23.md) H1)
- **Planned at**: commit `acb128b`, 2026-07-23 (excerpts re-verified against `acb128b` after
  `1fd5e6b` — "Decouple change-replay from the git repo root" — landed and moved
  `ChangedSymbols`; worktree carries uncommitted `plans/` changes only)

## Why this matters

A per-record setting is added to a model (column + `$fillable` + cast) and is writable
through its form, but is never added to the Resource that builds the client payload. The
write succeeds, the client never receives the key, renders the default, and the setting
appears to silently revert. No exception, no failing test.

The reporting consumer's curated corpus holds five real-bug fixtures; **two are this exact
class** — the only class that repeats, 40% of their corpus. richter cannot see it today:
`ReferenceEdgeTracer` maps `App\Http\Resources\` to a class-level `resource` edge and
nothing more (`:48-55`), and its own docblock (`:19-28`) already names this as "the exact
blind spot behind a payload field silently going missing". Nothing in `src/` parses a
`toArray()` return array — `JsonResource` appears nowhere outside that one namespace string.

## Design (decided — do not re-litigate)

Every decision below came out of a maintainer interview on 2026-07-23. The rationale for
each is in "Resolved questions"; do not reopen them mid-implementation.

**Findings lane, never risk.** An advisory `findings` string. `EloquentConfig`'s
additive/LOW classification of a column add is *correct for risk* and must not change —
this reinforces it, it does not fight it.

**Trigger.** A diff that adds a name to a model's `$fillable`, `$casts` or `casts()`.
Fires on **any added name**, regardless of whether the edit was addition-only: a mixed
rename-plus-add PR is exactly when a forgotten resource key is most likely. New model
files are **skipped entirely** — a new model has no established payload contract to fall
out of sync with.

**"New file" means `$isNew`, never `$baseSrc === null`.** As of `1fd5e6b`,
`classifyFile()` takes a `bool $isNew` (sourced from the diff's `--- /dev/null` by
`UnifiedDiffParser`, `:16-20`/`:125`) and returns a coarse class seed early when
`$baseSrc === null && ! $isNew` — an unreadable base is an I/O failure, not a new file.
Key the skip off `$isNew`, and put the field capture **after** that early return so an
unreadable base never produces parity data.

**Field set.** The union of `$fillable` + `$casts` + `casts()` keys, with `Model::COLUMN`
constants resolved by reflection. That union is both the trigger source and the mirror
ratio's denominator. Keying off `casts()` too is what keeps `$guarded`-style apps from
zero coverage.

**Association — graph wiring first, name fallback second.**

1. Candidates are resources reached from the changed model's node via `callersOf(...,
   maxDepth: 2)` and those callers' outgoing `resource`-typed edges. **Depth 2, not the
   analyzer's default 6** — the point of preferring wiring over names is locality; a hub
   model at depth 6 pulls in unrelated features' resources.
2. **Only when step 1 yields nothing**, fall back to resources whose FQCN carries the
   model's short name as a class-name or namespace segment, scanning `App\Http\Resources`
   **and** `App\Transformers` (the two namespaces `ReferenceEdgeTracer::NAMESPACE_TYPES`
   already treats as resources).

**Mirror gate.** A candidate counts as a mirror when it exposes a key for
`mirror_threshold` of the model's *pre-existing* field names (default **1.0** — an exact
predicate, so the no-guess rule every other checker follows stays intact). A minimum of
shared pre-existing fields applies: **1 for graph-wired candidates, 2 for name-fallback
candidates** — wiring is independent evidence the two belong together, a name match is not.

**`toArray()` parsing — skip on unknown keys, not on complexity.** Collect literal string
keys (and constant keys, resolved) from a plain `return [...]`. If the array contains any
construct that can inject keys the parser cannot enumerate — a spread, `array_merge`,
`mergeWhen`, `parent::toArray()`, `only()` — **skip the whole resource**. A `when()` used
as the *value* of a literal key injects no key and is counted normally; real editor
payloads use it constantly, and blanket-skipping it would give away most of the recall.

**Diff-time inputs, graph-time association.** The check runs in `ImpactAnalyzer`, where
the graph is in hand. The model's field set and added names are computed at classify time
and carried on `ChangedFileSymbols` — the analyzer never sees source, and re-reading the
model from disk would be wrong under benchmark replay, where `ChangedSymbols::resolve()`
is given an explicit historical head. No `GraphCache::inputFiles()` change and **no
`FORMAT_VERSION` bump** (`GraphCache.php:32`); treat any drift toward build-time as a STOP
condition.

**Iron rule holds.** The parity note joins the same `findings` list. It must never touch
`risk`, the `--fail-on` gate, or `affected-tests`.

## Current state (excerpts — confirm against live code)

- `src/Changes/EloquentConfig.php`
  - `:34` `CONFIG_PROPERTIES = ['fillable', 'casts']`; `:36` `isConfigMember()`.
  - `:42` `isAdditionOnlyEdit()` — returns a **bool only**; the added names are never exposed.
  - `:68` `memberNodeFor()`, `:108` `arrayOf()`, `:143` `toMap()` (list items key on their
    value, so `$fillable` and `casts()` both yield field names as keys), `:172` `canonical()`
    — turns `Post::TITLE` into the symbolic `cc:App\Models\Post::TITLE`, never its value.
- `src/Changes/ChangedSymbols.php` (line numbers are post-`1fd5e6b`)
  - `:67-70` the per-run checker instances; `:186` `classifyFile()`'s signature (three
    optional checker params **plus `bool $isNew = false`**); `:187-197` the
    unreadable-base early return; `:271` `sourceFindings()`; `:280-282` the findings spread.
- `src/Changes/UnifiedDiffParser.php:16-20` and `:125` — where `isNew` comes from.
- `src/Changes/ChangedFileSymbols.php:25-33` — the constructor to extend.
- `src/Analysis/ImpactAnalyzer.php`
  - `:67` and `:82-101` the `detectChanges()` result shape (`findings: list<string>`).
  - `:205-211` where findings are assembled and prefixed `"{$file->file}: {$finding}"` —
    a model-triggered parity note must **not** inherit the model's path prefix.
- `src/Graph/CodeGraph.php:93` `locationOf()` (resource file path, free), `:257`
  `callersOf(array $from, int $maxDepth = 6)`, `:297` `dependencyEdgesOf()`.
- `src/Tracers/ReferenceEdgeTracer.php:48-55` `NAMESPACE_TYPES` — `App\Http\Resources\`
  and `App\Transformers\` both map to `resource`.
- `src/Tracers/EagerLoadStringChecker.php:60-61` (`$modelsPath` constructor), `:211-240`
  (directory scan + `class_exists` with degrade-to-skip) — the precedent for both the
  cross-file read and the reflection resolution.
- `src/Support/RichterConfig.php:29-37` (`stringList()`-backed accessors), `:86-99` and
  `:116-130` (the scalar null/`''`→default, wrong-type→throw pattern), `:148` `stringList()`.
- `src/Analysis/BenchmarkCase.php:16-22` constructor, `:24-41` `fromArray()`, `:72-102`
  `evaluate()` — already receives the whole result including `findings`.
- `src/Console/BenchmarkAddCommand.php:130-147` `printStanza()`.
- `src/Console/DetectChangesCommand.php:35-45` the option list.
- `src/Mcp/Tools/DetectChangesTool.php:32-38` — the MCP tool takes **only** `base`; it has
  no `--no-cache` counterpart either, so the new CLI option needs **no MCP parity**.
- `config/richter.php:79-93` the `benchmark_cases` stanza (model the new config block's
  comment style on it).
- `tests/Fixtures/project/app/Models/Post.php` — **no `$fillable` and no `casts()`**, only
  two `HasMany` relations plus relation constants.
- `tests/Fixtures/project/app/Http/Resources/Api/v2/Post/ReviewResource.php` — `toArray()`
  returns a single nested-resource key.
- `tests/Fixtures/project/app/Http/Controllers/Post/ReviewController.php` — `show(Post $post)`
  loads a relation and returns `ReviewResource::make($post)`; the canonical wiring shape
  the graph association must resolve.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'PayloadParity\|EloquentConfig\|ChangedSymbols\|ImpactAnalyzer\|BenchmarkCase'` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | exit 0 / 0 / 0 changed |

## Scope

**In scope:**
- `src/Changes/EloquentConfig.php` (expose added names + the resolved field set)
- `src/Changes/ChangedSymbols.php`, `src/Changes/ChangedFileSymbols.php` (carry them)
- `src/Analysis/PayloadParityChecker.php` (new)
- `src/Analysis/ImpactAnalyzer.php` (invoke it, append to `findings`)
- `src/Support/RichterConfig.php`, `config/richter.php` (`payload_parity` block)
- `src/Console/DetectChangesCommand.php` (`--no-payload-parity`)
- `src/Analysis/BenchmarkCase.php`, `src/Console/BenchmarkAddCommand.php` (`expect_finding`)
- `tests/Fixtures/project` (extend `Post` + `ReviewResource`, add a narrow control resource)
- `tests/Unit/*` for each of the above

**Out of scope:**
- `README.md` and `plans/README.md` prose beyond the plan's own status row — the maintainer
  chose plan-file-only; the implementer still adds the config-table row and Findings
  sentence if they are touching README anyway, but it is not a gate here.
- The **inverse rule** (a field validated/persisted but in *no* resource) — needs FormRequest
  `rules()` parsing, which does not exist at all today.
- Promoting `findings` from `list<string>` to objects — a breaking JSON-contract change,
  its own plan. Per-field suppression is served by `payload_parity.ignore`.
- Fixing the benchmark replay skew (see "Known limits").
- `GraphCache::inputFiles()` / `FORMAT_VERSION`.
- MCP tool options (the tool exposes only `base`).

## Git workflow

- Branch `advisor/048-payload-parity-detection` from the local main tip; commit per logical
  unit (config + accessors; field capture; checker; analyzer wiring + fixtures; benchmark).
  No signing. End: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
  Do NOT push or open a PR.

## Fixtures & anonymization (MANDATORY)

Neutral domain only, per plan 041. Extend the existing `Post` model with synthetic scalar
columns (`title`, `slug`, `status`, `layout`) and broaden `ReviewResource`; add a narrow
control resource alongside it. Never the consumer's product nouns, never their real column
names — the two originating consumer bugs are described only as "a per-record setting" in
this plan and must stay that way in code and comments.

## Known limits (state them, do not try to fix them here)

- **Benchmark replay reads the working tree.** `BenchmarkCommand` never checks the fix
  commit out (`:86` diffs `$commit^...$commit` against the *current* graph), so replaying
  a bug-introducing commit sees post-fix resources on disk. `expect_finding`'s positive
  assertion is therefore only meaningful for a gap still open at HEAD; identification is
  scored by the synthetic fixtures in Phase 4, not by replay.
- `$guarded`-only models are covered solely through their `casts()` declarations.
- A resource that composes its whole payload from nested resources has a near-zero mirror
  ratio and will never fire. If the consumer's two originating resources turn out to be
  that shape, the rule catches neither — measure before assuming success.

## Edge cases

| Scenario | Handling |
|---|---|
| Model gains 3 fields, 2 mirror resources miss all 3 | One finding per resource, fields listed (Phase 3 tests) |
| New model file (`$isNew` true) | Skipped entirely — no established payload contract (Phase 2 tests) |
| Unreadable base on an existing model (`$baseSrc === null && ! $isNew`) | Early-returns to a coarse seed before capture; no parity data, no finding (Phase 2 tests) |
| Model has exactly one field, and it is the added one | 0 pre-existing fields → below the minimum → silent (Phase 3 tests) |
| Name-fallback candidate sharing exactly 1 pre-existing field | Below the fallback minimum of 2 → silent (Phase 3 tests) |
| `toArray()` contains a spread / `array_merge` / `mergeWhen` / `parent::toArray()` / `only()` | Whole resource skipped, no finding (Phase 3 tests) |
| `when()` as a literal key's value | Key counted normally (Phase 3 tests) |
| Model uses `Post::TITLE` constants, resource uses `'title'` strings | Constants resolved by reflection, compared as strings (Phase 3 tests) |
| Resource class unloadable / file missing / unparseable | Silent skip, no finding, no note (Phase 3 tests) |
| Graph yields resources **and** names would match others | Graph wins; the fallback runs only on an empty graph result (Phase 3 tests) |
| Mixed edit (adds `layout`, renames `slug`) | Still fires for `layout` — classification is a risk question, not a payload one (Phase 2 tests) |
| Field or resource in `payload_parity.ignore` | Suppressed, and an ignored field is excluded from the ratio denominator (Phase 3 tests) |
| `payload_parity.enabled` false, or `--no-payload-parity` passed | Checker never constructed; `findings` unchanged (Phase 4 tests) |
| App with no resources and no `resource` edges | Both candidate paths empty → silent, no cost beyond one empty lookup (Phase 3 tests) |

## Steps

### Phase 1: Config surface (Priority: HIGH)

**ID:** `config` · **Depends:** none

- [ ] `config/richter.php`: a `payload_parity` block — `enabled` (true), `mirror_threshold`
  (1.0), `ignore` (`[]`) — with a comment in the style of the `benchmark_cases` stanza
  (`:79-88`) explaining that the lane is advisory-only and that `ignore` accepts either
  `App\Models\Post::field` or a resource FQCN.
- [ ] `RichterConfig::payloadParityEnabled(): bool` — null → true, non-bool → throw, mirroring
  `cacheEnabled()` (`:101-114`).
- [ ] `RichterConfig::payloadParityMirrorThreshold(): float` — null → 1.0; a non-numeric or
  out-of-`[0,1]` value throws with the same message shape the other accessors use.
- [ ] `RichterConfig::payloadParityIgnore(): list<string>` — via `stringList()` (`:148`) `?? []`.
- [ ] Tests — `tests/Unit/RichterConfigTest.php`: defaults for all three, wrong-type throws,
  out-of-range threshold throws, and an `ignore` entry of each accepted shape round-trips.

### Phase 2: Capture the added fields and the field set (Priority: HIGH)

**ID:** `field-capture` · **Depends:** none

- [ ] `EloquentConfig::fieldSet(string $source): array` — the union of `$fillable`, `$casts`
  and `casts()` field names for the single declaring class, constants resolved by reflection
  (`class_exists` + `constant()`, degrade-to-skip on failure, as `EagerLoadStringChecker:211-240`
  does). Returns `[]` when nothing is statically enumerable.
- [ ] `EloquentConfig::addedNames(string $headSrc, string $baseSrc): list<string>` — names
  present in the head field set and absent from the base one. Independent of
  `isAdditionOnlyEdit()`: a MODIFIED classification still yields its added names.
- [ ] `ChangedFileSymbols`: two new constructor params (defaulted, so no call site breaks) —
  the model's head-side field set and the added names.
- [ ] `ChangedSymbols::classifyFile()` populates them for a changed `app/Models` file with a
  base side, **after** the `$baseSrc === null && ! $isNew` early return (`:187-197`); a new
  file (`$isNew`) leaves both empty.
- [ ] Tests — `tests/Unit/EloquentConfigTest.php` (or the existing `ChangedSymbolsTest`
  neighbourhood): union across all three members, constants resolved, a mixed rename+add
  still reports the added name, an unloadable constant class yields an empty set, a new
  model file (`isNew: true`) carries nothing, and an unreadable base (`baseSrc: null`,
  `isNew: false`) still coarse-seeds and carries nothing.

### Phase 3: The parity checker (Priority: HIGH)

**ID:** `parity-checker` · **Depends:** `config`, `field-capture`

- [ ] `src/Analysis/PayloadParityChecker.php` — `final class` (non-readonly: it needs a
  per-run candidate/parse cache, exactly as `EagerLoadStringChecker` does), constructed with
  the `CodeGraph`, the threshold, the ignore list, and an optional resource-root override
  for tests.
- [ ] Candidate discovery: `callersOf([$modelFqcn], maxDepth: 2)` → those callers' outgoing
  `resource`-typed edges → resource FQCNs → `locationOf()` for the file path. Only when the
  result is empty, fall back to an FQCN-segment name scan over `App\Http\Resources` and
  `App\Transformers`.
- [ ] `toArray()` key extraction: literal string keys plus reflection-resolved constant keys
  from a plain `return [...]`; abort the whole resource on any unknown-key construct
  (spread, `array_merge`, `mergeWhen`, `parent::toArray()`, `only()`). A `when()` value does
  not abort.
- [ ] Mirror test: pre-existing fields (field set minus added names minus ignored fields) must
  be present at ≥ threshold, with a minimum of 1 shared field for graph-wired candidates and
  2 for name-fallback candidates. Emit one finding per resource, listing every added field
  the resource lacks, and naming the **resource's** path.
- [ ] Tests — `tests/Unit/PayloadParityCheckerTest.php`: fires and names the field on a wired
  mirror; silent on the narrow control; silent on each unknown-key construct; `when()` value
  counted; constants resolved on either side; unloadable/missing/unparseable resource silent;
  graph result preferred over names; both minimums enforced; every `ignore` shape suppresses.

### Phase 4: Wire it into the report (Priority: HIGH)

**ID:** `wiring` · **Depends:** `parity-checker`

- [ ] `ImpactAnalyzer::detectChanges()` constructs the checker (unless disabled) and appends
  its findings alongside the per-file loop at `:205-211`, **without** the `"{$file->file}: "`
  prefix — the note names the resource itself.
- [ ] `DetectChangesCommand`: a `--no-payload-parity` option in the signature block
  (`:35-45`), threaded to the analyzer. No MCP change (the tool exposes only `base`).
- [ ] Fixtures: extend `tests/Fixtures/project/app/Models/Post.php` with `$fillable` +
  `casts()` over neutral columns; broaden `ReviewResource` into a mirror that omits one; add
  a deliberately narrow control resource next to it.
- [ ] Fix up any existing graph/analyzer/formatter test that asserts `Post`'s members or
  edges — expected churn from extending the shared fixture.
- [ ] Tests — `tests/Unit/ImpactAnalyzerTest.php`: the finding appears in `result['findings']`
  with the resource path; the control resource produces nothing; `enabled: false` and the CLI
  option each suppress the lane entirely; `risk`, `entryPoints` and `impacted` are byte-identical
  with the lane on and off.

### Phase 5: Score identification in the benchmark (Priority: MEDIUM)

**ID:** `benchmark` · **Depends:** none

- [ ] `BenchmarkCase`: an optional `expectFinding` (plain substring), validated in
  `fromArray()` (`:24-41`) alongside the existing fields — a non-string, non-null value throws
  in the established message shape.
- [ ] `BenchmarkCase::evaluate()`: when set, fail the case unless some entry of
  `$result['findings']` contains the substring.
- [ ] `BenchmarkAddCommand::printStanza()` (`:130-147`): emit the `expect_finding` line, escaped
  like the others, when the operator supplied one.
- [ ] `config/richter.php`: add the key to the commented `benchmark_cases` example.
- [ ] Tests — `tests/Unit/BenchmarkCaseTest.php`: absent key behaves exactly as today; a
  matching finding passes; a non-matching one fails with a readable reason; a non-string value
  throws.

## Done criteria

- [ ] A wired mirror resource missing an added field produces one finding naming the resource
  file and the field; the narrow control stays silent
- [ ] Every skip rule holds: new model file, unknown-key constructs, below-minimum shares,
  unloadable resource, ignore entries
- [ ] `grep -rn 'payloadParity\|payload_parity' src/Analysis/AffectedTests.php src/Analysis/RiskLevel.php src/Console/Gate.php` → no matches (iron rule)
- [ ] `grep -n 'FORMAT_VERSION' src/Graph/GraphCache.php` → still `4`
- [ ] Report output with the lane disabled is identical to today's, field for field
- [ ] `composer test` / `phpstan` / `pint --test` / `rector --dry-run` clean
- [ ] `plans/README.md` row updated

## STOP conditions

- Drift vs the "Current state" excerpts.
- You find yourself needing `GraphCache::inputFiles()` or a `FORMAT_VERSION` bump — the check
  is diff-time by design; reading migrations or `database/` is a STOP, not an optimisation.
- The mirror gate needs to fall below 1.0 by default to make the fixtures pass. The default is
  an exact predicate on purpose; a fixture that only passes at 0.7 means the *fixture* is wrong.
- You want to type-infer a resource's backing model, or partially parse a `toArray()` you had
  to skip. Both are guessing; under-report instead.
- The parity finding starts influencing `risk`, `--fail-on` or `affected-tests` in any way.

## Resolved questions

1. **Is a thresholded heuristic acceptable at all, given every checker is no-guess?**
   **Decision:** ship a configurable `mirror_threshold` defaulting to **1.0**.
   **Rationale:** at 1.0 "the resource is a total mirror" is an exact predicate, not a ratio
   guess, so the no-guess rule holds; the knob exists for apps that later want to loosen it
   on their own evidence.
2. **Should `findings` become objects first, for per-field suppression?**
   **Decision:** no — keep `list<string>`; suppression is config-side via `ignore`.
   **Rationale:** `findings: list<string>` is baked into the public JSON contract
   (`JsonPresenter.php:29/55`) and both formatters; promoting it is a breaking change that
   deserves its own semver-scoped plan rather than riding along here.
3. **Do `$guarded` models get zero coverage?**
   **Decision:** trigger on `casts()` as well as `$fillable`. **Rationale:** `$guarded` apps
   still declare casts for new typed columns, so coverage degrades rather than vanishing.
4. **Which resources belong to the changed model?** **Decision:** graph wiring at depth 2
   first, FQCN-segment name match as a fallback only when the graph yields nothing.
   **Rationale:** the consumer's own layout puts the model name in a *namespace segment*
   (`Api\v2\Post\ReviewResource`), so a `{Model}Resource` rule alone would miss it; but a
   thin route-model-bound controller emits no model edge at all, so wiring alone has a
   recall hole. Neither covers both app shapes.
5. **Where does the check run?** **Decision:** `ImpactAnalyzer`, with the field set carried on
   `ChangedFileSymbols`. **Rationale:** the analyzer never sees source and re-reading the model
   from disk is wrong under replay, where `resolve()` is given a historical head; injecting the
   graph downward into `ChangedSymbols` would force a build-order change in four commands and
   buy only co-location.
6. **Skip `toArray()` on `when()`?** **Decision:** no — skip only on constructs that can inject
   unenumerable keys. **Rationale:** a `when()` value adds no key, and real editor payloads use
   it constantly; blanket-skipping would forfeit most of the recall the rule exists for.
7. **Constants vs strings.** **Decision:** resolve `Model::COLUMN` by reflection on both sides.
   **Rationale:** `canonical()` (`:172-183`) deliberately keeps constants symbolic, so a
   constants-model against string-keyed resources would never match; mixed usage is the norm.
8. **Ship enabled?** **Decision:** yes, `enabled: true`. **Rationale:** purely advisory, and at
   1.0 it fires only on exact mirrors; a lane nobody has seen fire never gets switched on.
9. **Caller depth for association.** **Decision:** 2, not the analyzer's default 6.
   **Rationale:** locality is the whole reason wiring beats names; a hub model at depth 6 drags
   in unrelated features' resources for the mirror gate to reject.
10. **Fire on non-addition-only edits?** **Decision:** yes, any added name.
    **Rationale:** whether the same edit also renamed something is a risk question; a mixed PR
    is where a forgotten resource key is most likely.
11. **How is it validated, given replay reads the working tree?** **Decision:** synthetic
    fixtures score identification; `expect_finding` ships too but its positive use is limited.
    **Rationale:** `BenchmarkCommand` never checks the commit out, so a replayed bug commit
    sees post-fix resources; fixing that skew is a larger, separate plan.
12. **Fixtures: new model or extend `Post`?** **Decision:** extend `Post` and `ReviewResource`.
    **Rationale:** keeps the fixture project small; the cost is updating existing suites that
    assert `Post`'s members or edges, accepted knowingly.

## Open questions

None. Every decision above was settled in the 2026-07-23 interview.

## Assumptions ledger

Sign-off is meant to be possible by skimming this section alone.

- **Load-bearing** — `callersOf()` at depth 2 actually reaches the resource-building caller for
  the canonical shape (`ReviewController@show`). Verified in principle from
  `ReferenceEdgeTracerTest.php:91-92,113` (model-directed `loads-relation` edges exist) but
  **not** measured end-to-end; if Phase 3's wired-mirror test cannot resolve a candidate, stop
  and report rather than widening the depth silently.
- **Load-bearing** — reflection resolution of model/resource constants is safe on the analyzer
  side. `EagerLoadStringChecker` already loads app classes with degrade-to-skip; if loading a
  resource class turns out to have side effects, stop.
- **Load-bearing** — extending fixture `Post` will require updating existing graph/analyzer
  assertions. If the churn spreads beyond mechanical count/edge updates, stop and reconsider a
  dedicated fixture model.
- Config key names (`payload_parity.enabled` / `mirror_threshold` / `ignore`) and the exact
  finding wording are the plan author's invention — change freely, they are not load-bearing.
- Threshold validated as a `[0,1]` float; out of range throws. Not user-confirmed in detail.
- Resource *collections* need no special case — they carry no per-field `toArray()` and fall
  out through the normal skip path.
- No cap on candidate resources and no truncation of findings; a wide change can produce a
  long list by design.
- The name fallback runs only when the graph yields nothing, so the directory scan cost is not
  paid on wired apps.
- `--no-payload-parity` gets no MCP counterpart — verified: `DetectChangesTool` exposes only
  `base` and has no `--no-cache` equivalent either.
- README and `plans/README` prose beyond this plan's status row are out of scope per the
  maintainer's "plan file only" call; the config-table row still ought to land before release.

## Findings

- **Implemented and shipped** on branch `advisor/048-payload-parity-detection` (9 commits off
  `acb128b`), reviewed via `/final-verification-review` → `evaluate` → `code-review` +
  `codex-review`. Full suite 783 tests / 1831 assertions, PHPStan 0, Pint clean, Rector 0.
- **Codex round 1 (fixed, `c026788`)** — the name fallback matched only an exact FQCN *segment*,
  so the conventional `App\Http\Resources\PostResource` / `PostCollection` (class name, not a
  segment, carries the model name) was silently skipped. Broadened to also match an exact
  `{Model}Resource`/`{Model}Collection` class-name suffix, with a regression test proving `Post`
  never matches a different model's `PostRevisionResource`.
- **Codex round 2 (fixed, `3af7aac`)** — `fieldSet()` mis-read a combined multi-property
  statement (`protected $fillable = [...], $casts = [...];` in one statement): both names
  resolved to the same `Property` node and the name-agnostic `arrayOf()` always returned the
  first item's array, dropping the casts field. Added a name-aware `arrayForNamedProperty()`
  used only by the new code; `arrayOf()` / `isAdditionOnlyEdit()` untouched.
- **Codex round 3 (dismissed, known limitation)** — field-name *constants* are resolved by
  runtime reflection (`AppFiles::stringConstantValue()`), so a long-lived MCP process holding a
  stale class, or a historical benchmark replay, can see a constant value that diverges from the
  analyzed source. Dismissed as the intended design (Q7) matching the `EagerLoadStringChecker`
  reflection precedent: correct on the primary `detect-changes` CLI path, failing safe
  (degrade-to-skip → a missed finding, never a wrong one), and the same replay skew already
  documented under "Known limits". A source-based re-resolution (reimplementing inheritance /
  trait / cross-class constant lookup that reflection gives for free) is a large, risky change
  unjustified for a secondary scenario. Left as a follow-up if real MCP/replay evidence appears.
- **Non-blocking follow-up** — `classFqcn` + the `self`/`static`-aware constant resolver are
  duplicated between `EloquentConfig` and `PayloadParityChecker` (~16 lines). Consolidating into
  `AppFiles` was left out of scope (a file the plan did not touch); both copies are tested.
