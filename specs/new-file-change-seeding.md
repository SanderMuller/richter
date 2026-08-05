# New-file change seeding

<!-- spec:planned-at 566a95ef3c635bb22abe88bb9aa0da9e3e911a71 2026-08-05 +uncommitted -->

## Overview

A brand-new PHP file under `app/` currently reports `0 graph nodes`, coverage `analyzed`, and risk
`LOW` — even when its class is in the graph, is an entry surface, and reaches existing code. Every
member of a new file classifies `CHANGE_ADDED` (there is no base side to diff against), so
`ChangedFileSymbols::hasOnlyAdditiveOrCosmeticChanges()` returns true and `detectChanges()`
short-circuits the file before it can seed anything. This spec makes a genuinely new file seed its
class node and report like any other real change: reach, entry points, and risk.

The additive rule itself stays for what it was written for — a **member added to an existing class**
has no existing callers and must keep seeding nothing.

## Assumptions

<!-- Filled by the Assumptions Audit; each bullet is a sign-off item. -->

- **Scope is the `app/` PHP lane only.** A new Blade view already seeds its own `view::` node through
  `directSeeds`, and a new frontend file goes through `FrontendChanges`; neither passes through the
  additive gate this spec changes. Confirmed in `src/Changes/ChangedSymbols.php:108-142`.
- **A new file's class seed is PRECISE, not coarse.** Confirmed — no low-confidence flag, and the
  coarse HIGH→MEDIUM cap stays disarmed. See Resolved Questions 1. *Load-bearing.*
- **A new file whose class has no graph node reports coverage `analyzed` plus a finding**, not
  `unresolved`. Confirmed. See Resolved Questions 2. *Load-bearing.*
- **`isNewFile` overrides `cosmeticOnly`.** A new file with no members at all (a marker interface, an
  empty class) has `members === []`, which today sets `cosmeticOnly: true`. Derived from the accepted
  direction ("a genuinely new file is a real change"), not an independent inference.
- **Report annotation is presentational only.** The text/markdown/HTML reports gain a "new file"
  marker; no new key is added to the `--json` payload in this spec, so the JSON contract is untouched.
- **Risk output will change for consumers.** A PR that only adds files can move from `LOW` to
  `MEDIUM`/`HIGH` and trip `--fail-on`. This is the accepted cost of the chosen direction, called out
  in the release notes (Phase 3), not mitigated by a config flag.

---

## 1. Current state

Traced on the working tree at the stamped commit:

| Step | Site | Behaviour today |
|---|---|---|
| Classify | `src/Changes/ChangedSymbols.php:186-260` | `classifyFile(..., isNew: true)` receives the `--- /dev/null` signal from `UnifiedDiffParser`, uses it to skip the unreadable-base fail-closed branch (`:194`) and the payload-parity field capture (`:274`) — then **drops it**. `ChangedFileSymbols` never learns the file is new. |
| Classify | every member | With `$baseSrc === null`, no head member `existedBefore`, so `changeTypeFor()` returns `CHANGE_ADDED` for all of them (`:341`). `hasClassLevelChange()` is skipped for a new file by design (`:247`), so no coarse seed either. |
| Seed | `src/Analysis/ImpactAnalyzer.php:127` | `hasOnlyAdditiveOrCosmeticChanges()` is true → `summary = 0`, `coverage = 'analyzed'`, `continue`. The file never reaches the member seeding (`:142`), the coarse seed (`:158`), or the entry-class floor (`:165`). |
| Entry points | `src/Analysis/ImpactAnalyzer.php:356` | `withSelfListedEntryClasses()` skips additive files, so a new job/listener/command does not self-list as its own entry surface. |
| Coverage | `src/Analysis/ImpactAnalyzer.php:424` | `withUnresolvedJobFlips()` skips additive files. |

Reproduced with the real classifier (new console command, all lines added, `isNew: true`):

```
members: [["property","signature","added"],["method","handle","added"]]
hasOnlyAdditiveOrCosmetic: true   resolvableMembers: 0   needsCoarseSeed: false
```

while `richter:impact` on the same class in the same checkout returns a populated result — the node
exists, only the change-side lookup misses it. The graph is built from the working tree, so a new
class's nodes and edges are present whenever anything references it (that is why `impact` works).

## 2. Proposed changes

### 2.1 Carry the new-file signal into the change record

`ChangedFileSymbols` gains a readonly `bool $isNewFile = false` (defaulted, so every existing
construction site and test stays valid), set from `classifyFile()`'s existing `$isNew` at
`src/Changes/ChangedSymbols.php:260`.

`hasOnlyAdditiveOrCosmeticChanges()` returns `false` when `$isNewFile` is true — placed **above** the
`cosmeticOnly` check so a member-less new class is covered too:

```php
public function hasOnlyAdditiveOrCosmeticChanges(): bool
{
    // A genuinely new file is never "additive with no impact": nothing called it before, but it can
    // itself be an entry surface and it can reach existing code. Its members all read CHANGE_ADDED
    // only because there is no base side to diff them against.
    if ($this->isNewFile) {
        return false;
    }
    ...
}
```

The three analyzer call sites need no change — they inherit the new answer.

### 2.2 Seed a new file on its class node

In `detectChanges()`, a new file has no non-additive resolvable member, so member seeding yields
nothing; the class node is the correct granularity (the whole class is new). Add a branch alongside
the coarse branch at `src/Analysis/ImpactAnalyzer.php:158`:

```php
// A new file pins to its class node: every member reads CHANGE_ADDED, so memberSeeds() yields
// nothing, but the class itself is the changed unit. Precise, not coarse — class-level here is the
// exact granularity of the change, not a fallback for a member the graph can't resolve, so it must
// not raise the low-confidence flag or arm the coarse HIGH cap.
if ($file->isNewFile) {
    $fileSeeds = [...$fileSeeds, ...$this->seedsFor($file->fqcn)];
}
```

`seedsFor()` is substring-matched (`CodeGraph::nodesContaining`), so it picks up the class node and
its member nodes in one call — the same mechanism the coarse lane already relies on.

A new file may be **both** new and coarse-seeded? No: `needsCoarseSeed()` requires a non-additive
member, which a new file never has. The two branches are mutually exclusive; no dedupe needed beyond
the existing `array_unique`.

### 2.3 Coverage, entry points, and risk follow from the seed

No further changes needed — the existing rules produce the right answers once the file is no longer
skipped:

- `isEntryPointClass($file->file)` sets `touchesEntryClass`, giving the existing MEDIUM floor for a
  new command/job/listener/middleware.
- `withSelfListedEntryClasses()` lets a new job with no graph caller self-list as its own entry
  surface.
- `impacted`/`entryPoints` count the new file's reach through the normal walk.

The empty-seed case is the one place the existing rule must **not** apply. `coverage[$file] =
$fileSeeds === [] ? 'unresolved' : 'analyzed'` (`src/Analysis/ImpactAnalyzer.php:174`) would mark a
new file whose class nothing references as UNRESOLVED, failing every `--fail-on-unresolved` build that
adds a not-yet-wired class. Per Resolved Questions 2, a **new** file with no seeds reads `analyzed`
and carries a finding instead:

```php
// A new file that resolves to no node is a determined answer, not an unknown: nothing references the
// class yet, and a new class cannot break an existing caller. Every other empty-seed file still reads
// UNRESOLVED — that rule exists for a MODIFICATION richter could not place.
$coverage[$file->file] = $fileSeeds === [] && ! $file->isNewFile ? 'unresolved' : 'analyzed';
```

### 2.4 Report annotation

The reports currently render `path (N graph nodes)` plus an UNRESOLVED note. A new file gets a
`new file` marker so a reader can tell a whole-class seed from a member-level one:
`src/Analysis/ImpactFormatter.php` (text), `src/Analysis/MarkdownFormatter.php`, and a badge in
`src/Analysis/HtmlFormatter.php:340` next to the existing `cosmetic only` badge.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| New file, class present in the graph (the reported case) | Seeds the class node; reach, entry points and risk report normally. Phase `analyzer`, Tests: new command seeds and reaches its entry point. |
| New file with no members (marker interface, empty class) | `cosmeticOnly` is true today; `isNewFile` wins, so it still seeds. Phase `classifier`, Tests: member-less new class is not additive-only. |
| New file whose class has no graph node (nothing references it yet) | Reads `analyzed` (not UNRESOLVED); a finding states that nothing in the graph references it yet. Phase `analyzer`, Tests: empty-seed new file coverage + finding. |
| New entry-point-shaped file (job/command/listener/middleware) | Entry-class MEDIUM floor applies, and it self-lists as its own entry surface when no graph caller resolves. Phase `analyzer`, Tests: new job is MEDIUM and self-lists. |
| Member added to an **existing** file | Unchanged — still additive, still seeds nothing, still LOW. Phase `classifier`, Tests: regression fence on the existing additive tests. |
| 100%-similarity rename (no hunks) | Unchanged: handled before `classifyFile()` at `src/Changes/ChangedSymbols.php:77-83`, seeds the vanished old FQCN directly. `isNewFile` is false. |
| Rename **with** content changes | `isNew` is false (the diff's old side is the old path, not `/dev/null`), so behaviour is unchanged. Phase `classifier`, Tests: renamed-and-edited file is not treated as new. |
| Unreadable base on an existing file | Unchanged — `isNew` false, still fails closed to a coarse class seed (`:194`). Regression fence in the existing tests. |
| Deleted file | `isNew` false; base-side removed members still seed. Unchanged. |
| New model file | `modelFieldSet`/`addedModelFields` stay empty (payload parity deliberately skips a new model, `:274`); the class seed is additional, not conflicting. Phase `analyzer`, Tests: new model seeds without payload-parity data. |
| New file + unfollowable dispatch elsewhere | `withUnresolvedJobFlips()` now considers a new `\Jobs\` file, so its coverage can flip to UNRESOLVED — correct, since it is seeded and its dispatchers are genuinely unknown. Phase `analyzer`, Tests: new job with unresolved dispatches reads UNRESOLVED. |
| New Blade view / new frontend file | Unaffected: separate lanes, already seed their own nodes. No test needed beyond the existing ones. |

## Implementation

### Phase 1: Classifier carries the new-file signal (Priority: HIGH)

**ID:** classifier · **Depends:** none

- [x] Add `public bool $isNewFile = false` to `ChangedFileSymbols` — defaulted last so every existing construction site (and the analyzer tests' helpers) stays valid.
- [x] Pass `isNewFile: $isNew` from `ChangedSymbols::classifyFile()`'s final return (`src/Changes/ChangedSymbols.php:260`) — the signal already reaches the method, it was just dropped.
- [x] Return `false` from `hasOnlyAdditiveOrCosmeticChanges()` when `$isNewFile`, above the `cosmeticOnly` branch, with the "no base side to diff against" rationale in a comment.
- [x] Update `ChangedSymbolsTest::a_newly_added_file_is_additive_not_a_class_level_change` — its real point (no class-level change, no coarse seed) holds; the additive-only assertion inverts. Rename it to say what it now proves.
- [x] Tests (a renamed-and-edited file is already fenced at the parser level in `UnifiedDiffParserTest`) — a new file is not additive-only but still needs no coarse seed; a member-less new class is not additive-only; a member added to an existing file is still additive-only; an unreadable base on an existing file still seeds coarse.

### Phase 2: Analyzer seeds and reports the new file (Priority: HIGH)

**ID:** analyzer · **Depends:** classifier

- [x] Seed `seedsFor($file->fqcn)` for a new file in `detectChanges()`, into `$preciseSeeds` (not `$coarseSeeds`) — per Resolved Questions 1, so no low-confidence flag and no coarse cap.
- [x] Exempt a new file from the empty-seed UNRESOLVED rule (`src/Analysis/ImpactAnalyzer.php:174`) and add a finding naming the file and stating nothing in the graph references it yet — per Resolved Questions 2.
- [x] Verify (don't re-implement) that `touchesEntryClass`, `withSelfListedEntryClasses()` and `withUnresolvedJobFlips()` now fire for a new file, and comment the three sites that inherit the behaviour.
- [x] Tests (the new-model payload-parity fence already exists as `ChangedSymbolsTest::a_brand_new_model_file_carries_no_field_data`) — a new command seeds its class node and reaches `command::…`; a new job reports at least MEDIUM and self-lists as an entry point; a new file reaching an existing service counts it in `impacted`; a new file with no graph node reports the decided coverage plus the finding; an additive member on an existing job stays LOW (regression); the low-confidence flag stays off for a new-file-only change.

### Phase 3: Report annotation and release note (Priority: MEDIUM)

**ID:** report · **Depends:** classifier

- [x] Add a `new file` marker to the changed-files list in `ImpactFormatter` and `MarkdownFormatter`, and a badge in `HtmlFormatter::changedFile()` next to `cosmetic only`.
- [ ] (at release time) Note the behaviour change in `internal/release-notes-<version>.md`: a PR that only adds files can now report MEDIUM/HIGH and trip `--fail-on`, with the reasoning and the fact that no config flag opts out.
- [x] Tests — the formatters render the marker for a new file and omit it otherwise (`FormatterContractTest` covers the shared shape; add per-formatter cases).

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **A new class's nodes are in the graph.** The whole fix rests on the graph being built from the
   working tree, so a new class that anything references already has nodes. If a new file's
   `seedsFor()` comes back empty in the fixture-project feature test even though a fixture class
   references it, the premise is wrong and the fix belongs in the graph build, not the seeding.
2. **`isNew` is only ever true for a genuinely new file.** If `UnifiedDiffParser` also sets it for a
   rename or a mode change, seeding on it would misclassify real modifications — stop rather than
   widening the signal.
3. **A new file never needs a coarse seed.** The design treats the two branches as mutually
   exclusive (`needsCoarseSeed()` requires a non-additive member, which a new file cannot have). If a
   case produces both, the precise/coarse split and the low-confidence flag need re-deciding.

---

## Open Questions

None.

---

## Resolved Questions

1. **Is a new file's class seed precise or coarse?** **Decision:** Precise — it joins `$preciseSeeds`,
   raises no `lowConfidence` flag, and leaves the coarse HIGH→MEDIUM cap disarmed. **Rationale:**
   class-level is the exact granularity of a whole-new class, not a fallback for a member the graph
   could not pin down; the low-confidence flag means "richter could not place this", which would be a
   false statement here, and capping would mask a genuinely large new entry surface.
2. **Coverage for a new file whose class resolves to no graph node.** **Decision:** `analyzed`, plus a
   finding naming the file and stating that nothing in the graph references it yet; every other
   empty-seed file keeps reading `unresolved`. **Rationale:** "nothing references it yet" is a
   determined answer rather than an unknown, and a new class cannot break an existing caller, while
   `unresolved` would fail every `--fail-on-unresolved` build that adds a not-yet-wired class.
   Accepted cost: a reference made only through a shape the tracers miss (runtime dispatch) reads as
   `analyzed`.
3. **Should the report annotation reach the `--json` payload?** **Decision:** No — text, markdown and
   HTML only. **Rationale:** the JSON output is a consumed contract; adding a key is a separate,
   deliberate change and no consumer has asked for one.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **The `isNew` signal was already plumbed to the classifier and simply dropped.** `classifyFile()`
  used it for the fail-closed unreadable-base branch and the payload-parity capture but never passed
  it to `ChangedFileSymbols`, so the whole fix in Phase 1 is three lines plus the gate.
- **`--json` reports the fix without carrying the marker.** The seeding change is visible in the JSON
  payload through `changed` (node counts) and `coverage`; only the `new file` marker is text/markdown/
  HTML-only, per Resolved Questions 3.
- **The reports needed a `newFiles` key on the analyzer result.** `ImpactFormatter` and
  `MarkdownFormatter` receive only the result array (no `ChangedFileSymbols`), so the new-file fact
  travels as `newFiles: list<string>`. `HtmlFormatter` already receives the change records and reads
  `isNewFile` directly. `JsonPresenter` ignores the key, as it ignores the other walk internals.
- **Cognitive-complexity ceiling.** Adding two branches to `detectChanges()` pushed the class over
  PHPStan's cognitive-complexity limit (81 > 80). Folded the `newFiles` bookkeeping into the seeding
  branch rather than extracting a method — one branch, same behaviour, back under the limit.
- **No other test encoded the old contract.** Only `ChangedSymbolsTest::a_newly_added_file_is_…`
  asserted new-file-is-additive; the full suite (889 tests) is green with it inverted, which is the
  evidence that the additive rule for members on existing files was never entangled with it.
