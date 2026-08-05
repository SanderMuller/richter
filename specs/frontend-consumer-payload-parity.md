# Frontend-Consumer Payload Parity

<!-- spec:planned-at b272efb918d5cbba193e6ceadd0e65f7548cf301 2026-08-05 +uncommitted -->

## Overview

A key removed from an API resource's `toArray()` today produces no signal about the
frontend files that still read it — the exact shape behind a payload field silently going
missing on the *consumer* side, the mirror image of the model→resource gap the existing
payload-parity lane closes. This spec adds a diff-scoped advisory finding: when a diff
removes a resource key, the frontend files consuming the routes that resource reaches are
scanned for access-shaped reads of that key, and each hit becomes a Finding. Advisory
only — never `risk`, a `--fail-on` gate, or `affected-tests`; no JSON/MCP shape change
(findings are strings in the existing `findings` list); no `GraphCache::FORMAT_VERSION`
bump (diff-classification and analyzer layers only).

## Assumptions

<!-- Audit ledger — one bullet per AI-introduced inference or user-confirmed decision.
Sign-off-ready by skimming this section alone. -->

- **Access-shaped matching only** (user-confirmed, Resolved Question 1): a consuming file
  counts as still reading a removed key only via property-access patterns — `.key`,
  `['key']` / `["key"]`, and destructuring-position `key` — never bare token occurrences.
  Misses a dynamically-composed access; the advisory framing tolerates that recall loss.
- **Consumer index covers JS/TS roots plus Blade inline scripts** (user-confirmed,
  Resolved Question 2): configured `frontend.roots` files (same extensions and
  `generated_paths`/`.d.ts` exclusions as the frontend bridge) plus
  `resources/views/**/*.blade.php` `<script>` slices — so the lane works for SPA, hybrid,
  and pure-Blade apps. Consequence: the gate is `payload_parity.enabled` alone;
  `frontend.roots` is NOT required.
- **Inertia render-props parity is deferred to its own spec** (user-confirmed, Resolved
  Question 3).
- **Rename-aware phrasing** (user-confirmed, Resolved Question 4): exactly one removed +
  one added key in the same resource → the finding names the added key as a possible
  rename; multiple co-added keys → a generic "this diff also adds …" note. Deterministic,
  never a similarity guess.
- **No new config toggle**: the lane rides the existing `payload_parity.enabled` switch
  and the `--no-payload-parity` flag (the same `$payloadParityEnabled` parameter that
  gates the model→resource checker, src/Analysis/ImpactAnalyzer.php:229-241). One knob
  for one findings family. AI-chosen default.
- **`payload_parity.ignore` gains a resource-key form**: existing entries are
  `App\Models\X::field` or a resource FQCN; `App\Http\Resources\XResource::key` now also
  suppresses this lane for that key, and a bare resource FQCN suppresses the whole
  resource in both lanes. Natural extension, AI-chosen.
- **Resource files are matched by path prefix**, mirroring the `modelFields()` precedent
  (src/Changes/ChangedSymbols.php:274): `app/Http/Resources/` and `app/Transformers/` —
  never a hard-coded `App\` FQCN prefix, which would regress the non-`App\`
  root-namespace support that landed in the stamped baseline (`AppNamespace`,
  `Fqcn::fromPath()`). The two directories correspond to the namespace fragments
  `ReferenceEdgeTracer` maps to the `resource` edge (:53-54), which covers nested
  resource composition (:21-23).
- **The key-set diff lives in diff classification**, mirroring the model-field pattern:
  `ChangedSymbols` already holds `$baseSrc` and `$headSrc` where it computes
  `modelFields()` (src/Changes/ChangedSymbols.php:258-260), so the resource-key diff is
  computed there and carried as new `ChangedFileSymbols` properties
  (`removedResourceKeys`, `addedResourceKeys`) — no new `git show` machinery. Verified
  seam.
- **No-guess on unparseable shapes, via a strict parse mode**: the classification-time
  key diff uses a stricter variant of the shared parse core — **literal string keys
  only**; an unkeyed array item (`$this->mergeWhen(...)` and friends), a
  class-constant key, a spread, or a dynamic key aborts to `null` ⇒ no key diff ⇒
  silence. The existing checker's laxer behaviour (unkeyed items are *skipped*, not
  aborted — PayloadParityChecker.php:311-317 — and constant keys resolve via the
  autoloaded head codebase, AppFiles.php:89-102) stays byte-identical for its own lane
  purely per STOP condition 2; its known pre-existing limitation — a field exposed only
  inside `mergeWhen` can read as "not exposed" — is out of scope here and unchanged.
  In the removal-sensitive direction those two behaviours would fabricate findings: a
  key moved *into* `mergeWhen` would read as removed, and a base-side constant key
  resolves to its *head* value. Strict mode is a new parameter on the shared core, so
  STOP condition 2 (existing lane byte-identical) holds by construction. Documented
  recall loss: const-keyed and conditional keys are invisible to this lane.
- **The consumer index is lazy**: built only on a run where at least one removed resource
  key survived the ignore list. A run with no removed keys pays nothing new. AI-chosen.
- **Method-aware route matching**: the index stores endpoint references the way the
  frontend bridge resolves them — method-pinned when the callee names a verb,
  method-agnostic otherwise (never narrowing). Convention reuse.

---

## 1. Current State

All anchors verified at the stamp commit.

- **Backend shape enumeration exists**: `PayloadParityChecker::keysFor()/parseKeys()`
  (src/Analysis/PayloadParityChecker.php:240-264) statically resolves a resource's
  `toArray()` keys with a memoized no-guess cache. The parse core
  (`AppFiles::parseResolved($source)` + `keysOfArray()`) already operates on a source
  string; only the `parseKeys()` entry is path-coupled (is_file + file_get_contents).
- **The model-side precedent**: `ChangedSymbols::modelFields($file, $isNew, $headSrc,
  $baseSrc)` (src/Changes/ChangedSymbols.php:258) computes a base-vs-head field diff
  during classification and carries it on `ChangedFileSymbols`
  (`modelFieldSet`/`addedModelFields`, src/Changes/ChangedFileSymbols.php:34-46);
  `ImpactAnalyzer::detectChanges()` consumes it in the parity lane
  (src/Analysis/ImpactAnalyzer.php:229-241).
- **Resource→route reachability exists**: `ReferenceEdgeTracer` draws `resource` edges
  from any referencing class — including nested resource composition — so
  `callersOf(<resource FQCN>)` walks up through parent resources and actions to `route::`
  nodes.
- **Consumer→route indexing exists as a pattern**: `FrontendTestIndex`
  (src/Analysis/FrontendTestIndex.php:29-76) — Finder over configured paths,
  `addSource()` per file through the endpoint scanner, `testsReferencing(node)` inverse
  lookup. This spec generalises the pattern, not the class.
- **Blade inline-script extraction exists**: `FrontendChanges::scriptSlices()`
  (src/Changes/FrontendChanges.php:162-164) — the changed-Blade lane already scans
  `fetch('/api/…')`-style literals in views.
- **Contrast for positioning** (README material, not code): comparable graph-based code
  tools extract response keys from inline `.json({...})` literals — rare in idiomatic
  Laravel, where the Resource class is the response shape. Parsing `toArray()` is the
  deeper seam.

## 2. Resource-Key Diff (classification side)

Mirror the `modelFields()` pattern for changed files whose path falls under the resource
directories (path-prefix matching — see the Assumptions ledger):

- Extract the AST→keys core out of `PayloadParityChecker::parseKeys()` into a shared,
  content-accepting entry point with a **strict-mode parameter** (see the Assumptions
  ledger): default mode reproduces today's behaviour exactly; strict mode aborts to
  `null` on any non-literal-string key or unkeyed item. Existing payload-parity
  behaviour must stay byte-identical — the current test suite is the regression net
  (STOP condition 2).
- In `ChangedSymbols`, when the changed file's path falls under `app/Http/Resources/` or
  `app/Transformers/`: enumerate base and head key sets from `$baseSrc`/`$headSrc` in
  strict mode; `removedResourceKeys` = base − head, `addedResourceKeys` = head − base.
  A new file (`isNewFile`), unreadable base, or a `null` strict parse on either side ⇒
  both sets empty.
- Carry both on `ChangedFileSymbols` as defaulted properties, like
  `modelFieldSet`/`addedModelFields`.

## 3. Consumer Index

New `src/Analysis/FrontendConsumerIndex.php`, following the `FrontendTestIndex` shape:

- **Sources**: files under configured `frontend.roots` matching the bridge's extensions,
  minus `generated_paths` and `.d.ts` — plus every `resources/views/**/*.blade.php`,
  reduced to its `<script>` slices (reuse `scriptSlices()`; make it reusable from
  `FrontendChanges`). A Blade file without a `<script` substring skips before any regex.
- **Indexing**: each source runs through `FrontendChanges::routeNodesIn()` — the seam
  `FrontendTestIndex::addSource()` already uses (src/Analysis/FrontendTestIndex.php:62-64),
  which returns resolved route *nodes*, not raw URIs. Do not drop down to the raw
  `FrontendReferenceScanner`: URI→route-template resolution already lives in
  `routeNodesIn()`.
- **Lookup**: `filesReferencing(string $routeNode): list<string>`.
- **Laziness**: constructed only when the run has surviving removed resource keys.

## 4. Finding Emission (analyzer side)

New `src/Analysis/FrontendConsumerParityChecker.php` — a beside-class, per the
complexity-budget precedent the `PublicWriteAuthCrossCheck` docblock records. Given a
changed resource FQCN and its removed/added key sets:

1. **Affected routes**: `route::` nodes among `callersOf(<resource nodes>)` (routes only —
   UI-component surfaces are not endpoint-consumable and stay out).
2. **Consuming files**: `FrontendConsumerIndex::filesReferencing()` per affected route,
   method-aware.
3. **Access-shaped scan** per consuming file per removed key (Resolved Question 1's
   pattern family). For a Blade consumer the scan runs on the **same `<script>` slices
   the index matched on, never the whole file** — otherwise server-side PHP
   (`$item['published_at']`) and markup would match, a false-positive class the
   accepted profile does not include.
4. **Finding text**, hedged and evidence-first — as rendered by the CLI (the leading
   `! ` is `ImpactFormatter` decoration, src/Analysis/ImpactFormatter.php:87; the
   finding *string* carries no prefix):

   ```text
   ! resources/js/Pages/Posts/Show.vue references PATCH /api/posts/{post} and reads
     'published_at', which this diff removes from App\Http\Resources\PostResource
     (renamed to 'publishedAt'?)
   ```

   The route renders human-readable, not as the raw node id — a
   `route::PATCH::/api/posts/{post}` → `PATCH /api/posts/{post}` transform
   (precedent: src/Analysis/TestReferenceIndex.php:258). The rename suffix appears only
   under Resolved Question 4's deterministic rule; with multiple co-added keys the
   suffix is `(this diff also adds 'a', 'b')`.
5. **Suppression**: `payload_parity.ignore` — resource FQCN (whole resource, both lanes)
   or `ResourceFqcn::key` (this lane, that key).

Wired in `ImpactAnalyzer::detectChanges()` beside the existing parity lane, behind the
same `$payloadParityEnabled` gate. Findings feed the `findings` list only — never `risk`,
the gate, `affected-tests` selection or determinability.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Changed resource file is brand-new (`isNewFile`) | No base side ⇒ empty key sets ⇒ lane silent. (resource-key-diff Tests) |
| Resource file deleted by the diff | A deletion has no head source; it degrades to an empty string (`$headSrc ??= ''`, src/Changes/ChangedSymbols.php:99) ⇒ the strict parse yields `null` ⇒ empty key sets ⇒ lane silent; a deleted resource surfaces through the normal blast radius, not this lane. (resource-key-diff Tests) |
| `toArray()` carries an unkeyed item (`mergeWhen`), const-fetch key, spread, or computed key — on either side | Strict mode aborts to `null` ⇒ no diff ⇒ silence. Specifically: a key *moved into* `mergeWhen` must NOT read as removed, and a base-side constant key must never resolve against the head codebase. (resource-key-diff Tests) |
| Removed key while the resource has no graph node or no callers | No affected routes ⇒ no findings; the coverage gap already reads UNRESOLVED elsewhere. (consumer-parity-findings Tests) |
| Consuming file references several routes, only one affected | The file counts; the finding names the specific affected route it matched — attribution is per-route, not per-file guesswork. (consumer-parity-findings Tests) |
| Same-named property from an unrelated object in a consuming file | Access-shaped match still fires — accepted false-positive profile: advisory phrasing plus the `ResourceFqcn::key` ignore escape hatch. (consumer-parity-findings Tests) |
| `payload_parity.enabled = false`, or `--no-payload-parity` | The whole findings family is off — this lane and the model→resource lane share the switch. (consumer-parity-findings Tests) |
| No `frontend.roots` configured, Blade views present | Views-only index still works (Resolved Question 2); an app with neither roots nor views yields an empty index and no findings. (consumer-index Tests) |
| Generated frontend trees / `.d.ts` files | Excluded exactly as the frontend bridge excludes them. (consumer-index Tests) |
| Blade file without `<script>` | Skipped on a substring check before any regex. (consumer-index Tests) |
| Blade consumer: removed key appears only in server-side PHP or markup | No finding — the access-shaped scan runs on the same `<script>` slices the index matched, never the whole file. (consumer-parity-findings Tests) |
| Router unavailable while indexing | `routeNodesIn()` returns `[]` when route enumeration throws (src/Changes/FrontendChanges.php:184-188) ⇒ empty index ⇒ lane silent — the same accepted degradation `FrontendTestIndex` has. (consumer-index Tests) |
| One removed + one added key | Named rename hint; multiple co-added keys → generic note. (consumer-parity-findings Tests) |
| `richter:affected-tests` on a diff with these findings | Selection and determinability unchanged — findings are never an input. (consumer-parity-findings Tests) |

## Implementation

### Phase 1: Resource-key diff (Priority: HIGH)

**ID:** resource-key-diff · **Depends:** none

- [ ] Extract the content-accepting key-parse core from
      `PayloadParityChecker::parseKeys()` with a strict-mode parameter (default mode =
      today's behaviour; strict = literal string keys only, abort on unkeyed items,
      const-fetch keys, spreads, dynamic keys) — path handling and memo stay in the
      checker; existing payload-parity tests must pass unmodified.
- [ ] Compute `removedResourceKeys`/`addedResourceKeys` in `ChangedSymbols` for changed
      files whose path starts with `app/Http/Resources/` or `app/Transformers/`
      (path-prefix matching per the `modelFields()` precedent, ChangedSymbols.php:274 —
      never an `App\` FQCN prefix, which breaks non-`App\` root namespaces), using strict
      mode on `$baseSrc`/`$headSrc` — base − head and head − base; empty on new file,
      unreadable base, or a `null` strict parse.
- [ ] Add both properties to `ChangedFileSymbols` (defaulted, like the model-field pair).
- [ ] Tests — in `tests/Unit/ChangedSymbolsTest.php` (key-diff behaviour) and
      `tests/Unit/PayloadParityCheckerTest.php` (parser-extraction regression net, green
      unmodified): removed key detected; added-only diff yields empty removed set; new
      resource file silent; deleted resource silent (empty-string head); a key moved
      into `mergeWhen` produces NO removed-key reading (strict abort); a const-fetch key
      aborts strict mode while default mode still resolves it (existing-lane behaviour
      pinned); non-resource paths untouched; a non-`App\` root-namespace resource is
      still matched (path prefix, not FQCN).

### Phase 2: Consumer index (Priority: HIGH)

**ID:** consumer-index · **Depends:** none

- [ ] Make `FrontendChanges::scriptSlices()` reusable (public static or extracted) —
      behaviour-identical for the changed-Blade lane.
- [ ] Extract the bridge's extension list and generated-path/`.d.ts` exclusion logic
      into a reusable seam too — `EXTENSIONS` is a private const and the exclusion
      semantics live inside `handles()` (src/Changes/FrontendChanges.php:25, :48-71);
      duplicating them in the index invites bridge/index drift.
- [ ] Create `src/Analysis/FrontendConsumerIndex.php` — Finder over `frontend.roots`
      (bridge extensions and exclusions) plus `resources/views` Blade script slices;
      `filesReferencing(string $routeNode)` inverse lookup; cheap `<script` pre-check for
      Blade files.
- [ ] Tests — a new `tests/Unit/FrontendConsumerIndexTest.php` (no shared test file with
      resource-key-diff, keeping the phases write-disjoint), using **inline
      `addSource()` sources, not the shared fixture project** (the
      `FrontendTestIndexTest` pattern — count-asserting suites elsewhere must not be
      disturbed by this phase): a JS file and a Blade inline script each resolve to
      route references; generated paths and `.d.ts` excluded; Blade without `<script>`
      skipped; empty configuration yields an empty index.

### Phase 3: Consumer-parity findings (Priority: HIGH)

**ID:** consumer-parity-findings · **Depends:** resource-key-diff, consumer-index

- [ ] Create `src/Analysis/FrontendConsumerParityChecker.php` (beside-class): affected
      `route::` nodes via `callersOf(<resource nodes>)`, consuming files via the lazy
      index, access-shaped key scan, rename-aware finding text, `payload_parity.ignore`
      suppression (FQCN and `ResourceFqcn::key` forms).
- [ ] Wire into `ImpactAnalyzer::detectChanges()` beside the existing parity lane, behind
      the same `$payloadParityEnabled` gate; build the index only when removed keys
      survive the ignore list.
- [ ] Extend the fixture project: a resource composed by a route's controller plus a
      consuming JS file and a Blade inline script reading one of its keys. **Caution:**
      several suites assert counts against this fixture (graph nodes, entry points,
      reached routes) — after adding files, run the full suite and update any
      count-asserting test deliberately, never by loosening the assertion.
- [ ] Tests — Feature (detect-changes E2E on the fixture): removed key read by a consumer
      → finding with route + rename hint; unaffected-route consumer stays silent; a
      removed key appearing only in a Blade file's server-side PHP produces no finding
      (slice-scoped scan); `--no-payload-parity` silences the lane; findings never alter
      `risk`, the gate, or `affected-tests` output; Unit for the rename-phrasing rule
      and ignore forms.

### Phase 4: Documentation (Priority: HIGH)

**ID:** docs · **Depends:** consumer-parity-findings

- [ ] Extend the README payload-parity section: the consumer direction, the finding
      shape, the access-shaped matching rule and its documented false-positive/negative
      profile, the shared `payload_parity.enabled` switch, and the
      `ResourceFqcn::key` ignore form.
- [ ] Update the `payload_parity` entry in the README configuration table and the
      `config/richter.php` docblock for the extended `ignore` semantics.
- [ ] Tests — none executable (docs); check every documented key and flag against the
      shipped code.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **A changed resource's `callersOf()` walk reaches `route::` nodes on the fixture
   project** — the lane's route-attribution premise. If graph coverage doesn't deliver
   it, stop; do not substitute name-based route guessing.
2. **The key-parser extraction preserves existing payload-parity behaviour** — if any
   existing payload-parity test needs modification to pass, the seam is wrong; changing
   the test would erase the regression net.
3. **The baseline is the stamped commit's content** — the spec's anchors were verified at
   `b272efb` (the 0.18.0 candidate). If the classification internals
   (`ChangedSymbols::modelFields`, `ChangedFileSymbols` constructor) have drifted
   materially at implementation time, re-verify before building.

---

## Open Questions

None.

---

## Resolved Questions

1. **Which occurrences count as a consumer read?** **Decision:** Access-shaped patterns
   only (`.key`, `['key']`/`["key"]`, destructuring-position `key`). **Rationale:** Bare
   token matching triggers on translation keys, template strings, and unrelated
   variables; the advisory lane's value dies with noisy findings, and the recall loss on
   dynamically-composed accesses is acceptable for an advisory.
2. **What does the consumer index cover?** **Decision:** Configured `frontend.roots`
   JS/TS files plus `resources/views` Blade inline `<script>` slices, always; the gate is
   `payload_parity.enabled` alone. **Rationale:** The extraction already exists
   (`scriptSlices()`), the index is lazily built so the cost is rare, and Blade inline
   scripts are the *only* consumer surface for a pure-Blade app — requiring
   `frontend.roots` would make the lane a no-op exactly where Alpine/vanilla fetch
   widgets live.
3. **Inertia render-props parity in this spec?** **Decision:** Deferred to its own spec.
   **Rationale:** The core lane should prove finding quality in dogfooding first, the
   Inertia variant shares no phase with this spec, and its page-side prop parsing
   deserves its own research pass.
4. **Rename phrasing?** **Decision:** Rename-aware — a named hint when exactly one
   removed and one added key pair up, a generic co-added note otherwise. **Rationale:**
   Cheap to compute from the same key diff, hands the reviewer the likely fix, and the
   deterministic rule avoids similarity guessing.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
