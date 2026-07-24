# Consumer handoff: payload-parity detection (2026-07-23)

> **What this is**: a consumer-side handoff, pinned at `7e5369c` (`v0.12.0-2`).
> Findings and a proposed direction — **not** a plan. The maintainer converts
> this into a numbered plan (with line-verified "Current state" excerpts) if the
> direction is accepted. One finding only; this was a version-bump dogfood, not
> an audit.
>
> Provenance: consumer app upgraded 0.8.0 → 0.12.0, ran `detect-changes`,
> `benchmark` (7/7 green) and adopted `feature_gate_methods` (plan 039's key).
> No regressions found in the bump itself.

## H1 — A model field that never reaches its API Resource is invisible

**Priority (consumer's view)**: P1 for recall. **Effort**: M–L. **Category**: detection gap.

### Evidence

The consumer's `benchmark_cases` hold five real-bug fixtures. **Two are the same
bug class** — `HPB-5250` ("resource field omitted — setting missing in editor
payload") and `HPB-5151` ("resource field omitted — scoring strategy missing in
editor payload"). No other class repeats. That is the consumer's single most
reproduced defect shape, and it is 40% of their curated corpus.

Shape: a per-record setting is added to the model (column + `$fillable` + cast)
and is writable through the settings form, but is never added to the Resource
that builds the client payload. The write succeeds. The client never receives
the key, renders the default, and the setting appears to silently revert. No
exception, no failing test unless someone wrote one asserting that exact key.

### Why richter cannot see it today

- `ReferenceEdgeTracer::NAMESPACE_TYPES` (`ReferenceEdgeTracer.php:50-56`) maps
  `App\Http\Resources\` to a `resource` edge, **class-level only** — deliberately
  (`:44-47`). Its own docblock (`:20-23`) already names this as "the exact blind
  spot behind a payload field silently going missing". The gap is known and was
  consciously deferred; this handoff is a consumer datapoint that it is the
  highest-frequency one in practice.
- Nothing parses a `toArray()` return-array's keys. `JsonResource` does not
  appear anywhere in `src/` (only in a fixture).
- Nothing parses FormRequest `rules()`.
- `EloquentConfig` (`Changes/EloquentConfig.php:34`) recognises addition-only
  `$fillable`/`casts()` edits and classifies them additive/LOW — which is
  **correct for risk** and should not change.

### The benchmark does not currently prove the two fixtures are caught

Worth flagging plainly: those two cases pass at `risk: high, entry points: 12`,
but the replay runs the **fix** commit — which edits the Resource, a node with
many entry points. Replaying the **bug-introducing** side would be a model-only
additive change: LOW, no finding, nothing said. The fixtures currently score
blast radius, not detection. `expect_signal` cannot distinguish the two.

## Proposed direction (maintainer's call — not settled)

### Lane: findings, never risk

Emit an advisory `findings` string. The iron rule stays intact: no `risk`, no
`--fail-on`, no `affected-tests`. This *reinforces* `EloquentConfig`'s
additive/LOW rather than fighting it — the risk classification is right, the
change just deserves a note.

### Mechanism sketch

A fourth checker on the established de-facto contract: `final readonly class`,
`use ChecksChangedLineRanges`, `findingsFor(string $source, ?array $lineRanges = null): array`,
added as a spread at `ChangedSymbols.php:255-257` with params at `:171`/`:246`.

Cross-file reading is precedented — `EagerLoadStringChecker` takes a
`$modelsPath` in its constructor (`:60`) and reads `App\Models\` off-diff. The
same trick reads `App\Http\Resources\`.

1. **Trigger**: the diff adds a name to `$fillable`/`casts()` on a model.
   `EloquentConfig` already detects this shape.
2. **Locate**: resources matching that model (namespace or existing `resource`
   edge).
3. **Test**: parse each resource's `toArray()` literal string keys. If a resource
   already exposes ≥ threshold of the model's `$fillable` set — i.e. it is a
   *mirror* of the model — and the new name is absent → finding.

### Hard scoping decisions (recommended, with reasons)

**Diff-time only. Do not read `database/` or migrations.** `GraphCache::inputFiles()`
(`:255-278`) hashes only `app/`, `routes/`, `resources/views`, `bootstrap/app.php`;
the fingerprint (`:80-104`) carries just `entry_point_roots` and `dispatch_helpers`.
Staying diff-time means **no `inputFiles()` change and no `FORMAT_VERSION` bump**
(`:31`). Treat drift toward build-time as a STOP condition — reading migrations
would buy marginal accuracy for a cache-invalidation blast.

**No-guess parsing, mirroring 039.** Only literal string keys in a plain
`return [...]`. A `toArray()` using `$this->when()`, `mergeWhen()`, spreads,
`array_merge`, `parent::toArray()` or `$this->only()` is **skipped entirely**,
not partially parsed. Under-report over false-positive — a findings string that
is wrong once teaches the reader to ignore the lane forever.

**A mirror threshold is mandatory, not polish.** Real apps carry several
resources per model with deliberately different shapes (a narrow public/embed
resource next to a broad editor one). Without a ratio gate the rule fires
constantly on the narrow ones and is unusable. Suggest configurable, default
~0.7.

### Config

`payload_parity.{enabled, mirror_threshold, ignore}` through the existing
`RichterConfig` conventions — `stringList()` (`:145`) for the ignore list, the
scalar null/''→default, wrong-type→throw pattern (`:85-97`) for the rest.
Whether it ships enabled is the maintainer's call; 039's precedent is
ship-with-an-empty-allowlist rather than off.

### Inverse rule (later, separate)

A field validated/persisted but absent from *every* resource — a write-only
setting — is the same defect seen from the other side and arguably higher value.
It needs FormRequest `rules()` parsing, which does not exist at all today. Keep
it out of the first pass.

## Validating it — the fixtures already exist

Extend `BenchmarkCase` with an `expect_finding` (substring or regex). Cheap:
`evaluate()` (`BenchmarkCase.php:72-98`) already receives the whole
`detectChanges()` result including `findings`, so it is `fromArray()` (`:24`) +
`evaluate()` + the scaffolder stanza at `BenchmarkAddCommand.php:130-145`.

Then the sharp test: replay the **bug-introducing** side of the two consumer fix
commits (or a synthetic equivalent) and assert the finding **names the missing
field**; the post-fix state must be clean. That scores *identification* rather
than elevation, and is a strictly better fixture shape than `expect_signal` for
any future checker.

Add a benign control — a model with a deliberately narrow resource that must
**not** fire — to pin the threshold.

## Fixtures & anonymization (MANDATORY)

Neutral domain only, per plan 041. `Post` and
`App\Http\Resources\Api\v2\Post\ReviewResource` already exist in
`tests/Fixtures/project/` and fit this rule directly. Never the consumer's
product nouns.

## Open questions for the maintainer

1. **Is a thresholded heuristic acceptable here at all?** Every existing checker
   is no-guess exact matching; 039 explicitly says "do not guess". A mirror ratio
   is inherently fuzzy. This is a genuine philosophical break, and it should be
   decided before anyone writes code — not discovered in review.
2. **Findings are bare `list<string>`** with no id or severity. If parity
   findings want per-field suppression, does that argue for promoting findings to
   objects *first*, as its own prerequisite plan?
3. **`$guarded` models get zero coverage** — `EloquentConfig.php:26` excludes
   `$guarded` deliberately. Keying off `$fillable` means apps using `$guarded`
   see nothing. Acceptable, or a blocker for the rule's usefulness?

## Not claimed

Line references come from a structured survey of `src/`, not a line-by-line
read. Confirm every "Current state" excerpt at plan time. No effort estimate is
offered beyond M–L; the threshold heuristic is the risky part and the most
likely reason the rule ends up unusable.
