# Changelog

All notable changes to `sandermuller/richter` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.14.0 - 2026-07-24

<!-- verified-sha: f281c2c16644c138f14c8ad1e7aa7fd07733a00b -->
### v0.14.0

Adds a payload-parity findings lane: richter now notices when a model field is added but never reaches the API Resource that renders it — the shape behind a setting that saves fine yet silently reverts on the client. Sourced from production dogfood, where this was the single most-reproduced defect class. Advisory only; nothing about the risk level, the `--fail-on` gate, or `affected-tests` changes.

#### Added

- **Payload-parity detection.** When a diff adds a name to a model's `$fillable`, `$casts`, or `casts()`, richter checks the resources that render that model and reports — under Findings — any that mirror the model's other fields but omit the newly added one. Findings are advisory strings only; they never feed `risk`, `--fail-on`, or `affected-tests`.
  - Candidate resources are matched by graph wiring first (the controllers/actions that touch the model and the resources they return), falling back to conventional names (`App\Http\Resources\PostResource`, `PostCollection`, or the model name as a namespace segment) and `App\Transformers` only when nothing is wired.
  - Deliberately no-guess: the default `mirror_threshold` of `1.0` fires only on an exact mirror, and any resource whose `toArray()` the parser cannot statically enumerate — a spread, `array_merge`, `mergeWhen`, `parent::toArray()`, `only()`, or a dynamic key — is skipped rather than guessed at. Constant-based field names and keys (`Post::TITLE`) are resolved on both sides.
  
- **Configuration:** `payload_parity.{enabled, mirror_threshold, ignore}` in `config/richter.php`. On by default. `ignore` suppresses a specific field (`App\Models\Post::internal_flag`) or a whole resource (its FQCN).
- **`--no-payload-parity`** on `richter:detect-changes` disables the lane for a single run.
- **`expect_finding`** on benchmark cases (and a `--expect-finding` option on `richter:benchmark:add`) asserts that a replayed case surfaces a finding containing a given substring — scoring a checker's *identification*, not just the blast radius it elevates.

#### Compatibility

No breaking changes. The lane is purely additive: the `--json` contract is unchanged (`findings` remains a `list<string>`), and existing reports read identically with the lane disabled. A project that wants it off sets `payload_parity.enabled` to `false` or passes `--no-payload-parity`.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.13.0...v0.14.0

## v0.13.0 - 2026-07-23

<!-- verified-sha: 1fd5e6b52db368dc789063b5bdcb5fd4d3e642d8 -->
Change detection now works when the Laravel app lives in a **subdirectory** of its git repository — a monorepo — not only when the app root and the repo root are the same directory. Every command that reads a diff (`detect-changes`, `impact`, `affected-tests`, `benchmark`) is covered. This release also closes a latent `affected-tests` gap around unreadable base revisions.

### Added

- **Monorepo / nested-app support.** Richter replays git (`git diff`, `git show`, `git status`) from the Laravel project root, which until now had to *be* the git repository root. When the app is nested — e.g. `packages/api/` inside a larger repo — those git paths resolved against the repo root instead of the app, and change detection came back empty. Richter now re-roots them, so a nested app is analysed correctly. At the repo root the behavior is byte-for-byte identical, so the common case is unchanged.

### Fixed

- **A modification whose previous revision can't be read is no longer mistaken for new (additive) code.** When a changed file's base revision could not be read — a `git show` failure, or the mis-rooted path the monorepo case exposed — the file's members were classified as newly added, i.e. no-impact, which could silently narrow `affected-tests` selection. Such a change now fails closed to a coarse, impactful classification. Only a genuinely new file (added against no base — its diff starts from `/dev/null`) stays additive, as before.

### Internal

- Richter's own change-impact accuracy is now self-testable: a test runs a real, unfaked `richter:benchmark` replay against a throwaway git repository, at both the repo-root and nested-app layouts — the machinery that was previously only exercised with faked git.
- Suite grows to 725 tests / 1,736 assertions, including adversarial guards for the re-rooting (a nested untracked file still forces an undetermined result; a sibling package's file is ignored) and for the unreadable-base classification (both the removed-line and addition-only shapes).

### What's Changed

* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/SanderMuller/richter/pull/2
* Bump actions/cache from 5 to 6 by @dependabot[bot] in https://github.com/SanderMuller/richter/pull/1

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/SanderMuller/richter/pull/2

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.12.0...v0.13.0

## v0.12.0 - 2026-07-22

<!-- verified-sha: b9b369df2b53f9c29fe3642d132cfc08da213083 -->
A recall improvement for `richter:affected-tests` and `richter:impact`, from the same real-world adoption feedback that shaped v0.10.0 and v0.11.1. Richter now follows a *dispatched command* all the way to its handler even when that command is not a queued job — closing a blind spot in the change-impact graph.

### Fixed

- **A dispatched command's handler is no longer a hidden caller.** The dispatch tracer drew a `dispatcher → handler` edge only when the dispatched or instantiated target *looked like a queued job* — namespaced under `Jobs\`, or implementing `ShouldQueue`. A resolved `dispatch(new SomeCommand())` whose target is a `Dispatchable` command, or a plain self-handling command (a `handle()`/`__invoke()` class with no queue trait, which Laravel still runs synchronously through the bus), drew **no** edge. A change to such a handler could then drop the dispatching action's test from `affected-tests` selection — but only when the graph contained no *other* unfollowable dispatch — and `richter:impact` under-reported the change's blast radius. Both now recognise the command handler as a real caller.
  
- **A queued job with an unloadable ancestor no longer silently disappears from the graph.** The previous job check swallowed autoload failures and concluded "not a job", so a `ShouldQueue` job whose parent class or trait could not be loaded drew no dispatch edge at all. The shared dispatch-target predicate now resolves that uncertainty toward drawing the edge, so an unclassifiable target is over-approximated (a caller is shown) rather than dropped.
  

### Changed

- **`richter:impact` and `detect-changes` may report more reach — and occasionally a higher risk level — for code that dispatches commands.** This release only ever *adds* edges to the graph, so `affected-tests` can select the same tests or more, never fewer, and impact reports become more complete. If a change dispatches `Dispatchable` or self-handling commands, expect its reported reach to grow to include those handlers. This is the intended, more-honest behavior; it lands as a minor version because the output shifts for a real class of applications.

### Internal

- The dispatch tracer and the `affected-tests` determinability blocker now share one definition of "dispatch target", so edge-drawing and change-scoping recognise exactly the same set of shapes.
- No cache-format bump was needed: the richter package version is part of the graph fingerprint, so upgrading invalidates any cached graph and rebuilds it once, automatically, on first use — the wider edge set appears immediately, no action required.
- Suite grows to 717 tests / 1,649 assertions, including new coverage that a resolved dispatch of a self-handling command and of a `Dispatchable` command each draw the handler edge, and that a genuinely non-dispatchable class still draws none.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.11.1...v0.12.0

## v0.11.1 - 2026-07-22

<!-- verified-sha: cd89bce118650b14d9360ec2847ec6feefa9acff -->
A correctness fix for `richter:affected-tests`, from the same real-world dogfood that shaped v0.10.0. The test selection no longer collapses to "run the full suite" on every change just because the codebase dispatches a job somewhere it can't statically follow — while never selecting fewer tests than a change actually needs.

### Fixed

- **`affected-tests` is usable on real applications again.** An unfollowable job/command dispatch anywhere in the graph previously made *every* change undeterminable ("run the full suite"), because the "unfollowable dispatch" signal was graph-global. It is now **change-scoped**: an unfollowable dispatch only blocks a change that could actually be reached through it — i.e. when a possible dispatch target (a queued job, a `Dispatchable` command, or a plain self-handling `handle()`/`__invoke()` command) sits in the change's caller closure, or is the changed class itself. A change with no dispatch target upstream — a read-only controller path, a model method, a Livewire component — now narrows to the tests it can reach.
  
  This never trades away safety. The signal that a file could not be parsed at all is kept as a separate, **global** block (an unreadable file could hide anything), and the scoped-dispatch rule fails toward blocking on any uncertainty. The one narrow, documented gap — a command wired through `Bus::map` to a separate handler with none of the recognised markers — only affects a codebase with *no* unfollowable dispatch at all, and is a pre-existing analysis limitation, not a regression.
  

### Internal

- The graph's on-disk cache format is bumped, so a consumer's cached graph rebuilds once, automatically, on first use after upgrading — no action required.
- Suite grows to 715 tests / 1,647 assertions, including five adversarial guards that pin every path where the scoped selection must still block (a changed job, a job/command reached by the change, an unclassifiable caller, an unparseable file, and the unlock case where it correctly narrows).

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.11.0...v0.11.1

## v0.11.0 - 2026-07-21

<!-- verified-sha: cc1cabb9eb2ed126dd508f58351f5219cf8c2688 -->
### Added

- **`richter:detect-changes --html=<path>`.** Writes the report as one self-contained HTML file — every style and script inline, nothing fetched — so it opens offline from `file://` and travels as a CI artifact you can link from a pull request. Five tabs: **Overview** (a Files / Impacted / Depth / Risk stat row, the reached entry points, and what to focus on), **Graph** (the blast radius drawn as concentric rings, one per BFS depth from the change, with hover/focus tooltips and connected-edge highlighting), **Paths** (how each entry point reaches the change), **Changes** (the member-level diff, naming the member that drove a low-confidence verdict), and **Advisory** (findings, route security issues, test references, and the gate). `--open` launches it in the default browser afterwards; a failing opener is a warning, never a failed run. The diagram caps at 300 nodes and says so when it does — the counts above it are never capped. It composes with `--fail-on`: `--html` replaces the text report on stdout but never touches the gate or the exit code. The HTML is a rendering surface, not a contract — its markup may change in any release; `--json` remains the semver-governed machine output.
- **Clickable editor links in the report.** Every `file:line` opens your editor at that line. `richter.editor` reuses debugbar's / Ignition's env chain (`CODE_EDITOR`, then `DEBUGBAR_EDITOR`, then `IGNITION_EDITOR`) and, like debugbar, defaults to `phpstorm`, so an existing setup needs no new variable. PhpStorm, the VS Code family (`vscode`, `vscode-insiders`, `vscode-remote`, `vscodium`), Sublime, TextMate, Emacs, MacVim, Atom, Nova, NetBeans, and Xdebug are supported. Set `richter.editor` to `null` to keep the file references plain text — worth doing for a shared CI artifact, where a link would point every reader at an absolute path only present on the machine that generated the report.

### Internal

- The blast-radius diagram is laid out in PHP — depth is the radius — so it is deterministic and snapshot-testable, and the package still ships no JavaScript build step. The graph the report draws is carried alongside the report only: `--json` and the MCP output schema are byte-unchanged.
- Suite grows to 700 tests / 1,613 assertions.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.10.0...v0.11.0

## v0.10.0 - 2026-07-20

<!-- verified-sha: aadac5f8bbe2a116a3cfdc71c5f512b7e40c7023 -->
A precision-and-completeness release sourced from a full-feature dogfood of 0.9.0 against a large production Laravel application. `richter:affected-tests` now reflects your working tree and never silently narrows past a change it cannot see; the frontend bridge only treats genuine HTTP/route calls as route references; and Pennant feature flags are recognised behind the common enum-wrapper convention. All changes are additive or behaviour-refining — there are no breaking changes.

### Added

- **`richter.feature_gate_methods`.** Recognise feature-flag checks written as an enum wrapper (`FeatureToggle::SomeFlag->isActive()`), not only the `Laravel\Pennant\Feature` facade or the `@feature` Blade directive. Register your project's `Enum\Class::method` wrappers and a change behind one is annotated as flag-gated. Annotation only — it never feeds `risk`, the `--fail-on` gate, or `affected-tests` selection.
- **`richter.frontend.http_callees`.** A frontend string literal counts as a backend route reference only when it is the first argument of an HTTP/route callee. The built-in allowlist covers `route`, `fetch`, `axios`, `useFetch`, `$http`, `$` (jQuery), `window`, and `page` / `cy` (Playwright / Cypress spec navigation); register custom HTTP wrappers through config. This removes false route seeds from unrelated calls such as `translate('/settings')` or `console.log('/…')` that previously inflated the touched-route surface.

### Changed

- **`HEAD`-mode diffs now analyse the working tree.** `detect-changes` and `affected-tests` compare the working tree against the merge-base with `--base`, so uncommitted and staged edits are included — running either *before* you commit now sees your actual changes rather than only what is committed. Passing an explicit non-`HEAD` ref still replays that ref's committed tree, so historical and benchmark replays are unchanged, and CI (which checks out clean) is unaffected.

### Fixed

- **`affected-tests` no longer silently under-selects around a file it cannot diff.** An untracked (never `git add`-ed) file under `app/`, `resources/views/`, or a configured frontend root is invisible to `git diff`; the selection now fails closed (exit 2 — "run the full suite") instead of emitting a narrowed set that omits it. `git add` the file to include it. `detect-changes` keeps its advisory stderr note.
- **Hunk/source desync in `HEAD` mode.** With uncommitted edits stacked on committed ones, added/removed line numbers and member spans now come from a single tree, so a hunk can no longer map to the wrong member.

### Documentation

- Exposure classification (`[public]` / `[authed]` / `[admin]`) is route-only. A Livewire, Filament, or queue entry point carrying no exposure tag means "not classified," never "public" or "unauthenticated" — its real exposure comes from mount-time authorization, middleware, or route placement the graph does not model.

### Internal

- Test fixtures were migrated to a neutral, synthetic domain, and a guideline was added to keep fixtures, documentation examples, and specs free of any consumer's product vocabulary. Development scaffolding (`plans/`, `specs/`) is now excluded from the Composer dist archive.
- Suite grows to 608 tests / 1,351 assertions.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.9.0...v0.10.0

## v0.9.0 - 2026-07-20

<!-- verified-sha: 8c35afcd6c22fc82367428d61b43030cbba18399 -->
Two advisory additions from real-world adoption feedback: the test-reference tag now grades whether a referencing test asserts anything, and a `--profile` flag exposes where graph-build time goes.

### Added

- **Assertion-graded test references.** A reached entry point that a test references but whose referencing tests contain no behavioural assertion the scan recognises is now tagged `[test-referenced — no behavioural assertion found]` (text), `🟡 test-referenced, no behavioural assertion found` (markdown), and carries `"referenced-no-behavioural-assertion"` in the new `entryPointTestReferences` JSON/MCP map. The grade is per file and certainty-gated: a file counts as assertion-weak only when every assert-ish call in it is a provable smoke form (`assertOk`, `assertSuccessful`, `assertStatus(200)`, `assertTrue(true)`) or it has none — any behavioural or unrecognised assertion, or a status check that carries meaning (`assertStatus(403)`, `assertForbidden`, an authorization test's own claim), leaves it plain `[test-referenced]`. Uncertainty always collapses to the weaker claim, never to the sub-tag: a false "proves nothing" would wrongly discredit a real test. It is advisory annotation only — never an input to `risk`, a `--fail-on` gate, or `richter:affected-tests` selection.
- **`entryPointTestReferences` in `--json` and MCP structured content.** Per reached entry point, `"referenced"` / `"referenced-no-behavioural-assertion"` / `"unreferenced"`; an entry point whose reference state cannot be determined is omitted from the map.
- **`richter:detect-changes --profile`.** Forces a fresh build and prints a phase-by-phase timing split (Brain analysis, canonicalisation, the consolidated tracer pass, entry-point tracing, Blade tracers, rewrites) to stderr, so `--json` and `--markdown` stdout stay a single clean document. It answers where a build's wall-clock actually goes on a given codebase.

### Internal

- Suite grows from 562 to 580 tests: per-file assertion grading (smoke-form vs behavioural, authorization-status and Pest `expect` edge cases), the profile phase-event sequence, and the `--profile` output-contract coexistence with `--json`.
- Build phase timings ride the existing `onProgress` callback (`richter:phase` events), zero-cost when no listener is attached.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.8.0...v0.9.0

## v0.8.0 - 2026-07-19

<!-- verified-sha: 64e0c766dbe6c7249f9e7b3ce15fb0eade1e3f01 -->
Precision and honesty hardening across the frontend bridge, sourced from real-world adoption feedback, plus a version boundary for the MCP integration and a set of correctness fixes in the diff and seed pipeline.

### Added

- **`laravel/mcp` version boundary.** The supported range is `^0.8||^0.9` (0.9.0 validated against the full suite); `composer.json` now carries a matching `conflict` entry, so an unvalidated future release fails at Composer resolution time instead of fataling at framework boot. The README's MCP section names the range, and CI covers the mcp-absent install so the CLI-only consumer path can't regress silently.
- **Generated-file exclusion for the frontend bridge.** `frontend.generated_paths` entries now match a directory, an exact file, or a `*`-glob (crossing `/`) — a generated file directly under a root was inexpressible before. Ziggy's generated route map (`ziggy.js`) joins the default exclusions next to Wayfinder's trees, and `.d.ts` declaration files are never scanned: they carry types only, and a route-name string-literal-union type is pure false-positive surface.
- **Same-module constant resolution for dynamic `route()` arguments.** A `route(ROUTES.player)` or `route(RouteName.Player)` whose referent is a same-module `const`/`enum` string constant now resolves to that name instead of tainting the file UNRESOLVED. Resolution never guesses: `const`-only (never `let`/`var`), exactly one declaration, flat object/enum bodies only — anything uncertain keeps the file-level fail-safe, and a resolved reference beside an unresolvable one still taints.
- **String-named Livewire components in test selection.** `Livewire::test('admin.dashboard')` and the `livewire('show-posts')` helper now map onto their conventional classes (`App\Livewire\Admin\Dashboard`, `App\Livewire\ShowPosts`) in the test-reference index, so `richter:affected-tests` selects those tests when the component class changes. Convention-based, registry-free; custom component namespaces don't match.
- **Quoted-pathname handling in the diff parser.** Paths git C-quotes under `core.quotePath` (accented or non-Latin filenames, embedded quotes/backslashes) are now decoded, and the diff runs with `core.quotepath=off` — a changed Blade view or frontend file with a non-ASCII name previously dropped out of classification entirely and read as "no impact".

### Changed

- **Literal endpoint strings only count in call-argument position.** A `/`-leading string literal or backtick template now becomes a route candidate only directly inside a call's `(` or after a `,` — previously any such literal anywhere in a scanned file matched, so a constants file, nav-link map, or generated data file whose strings coincide with real route templates flooded the touched-endpoint list and, through `richter:affected-tests`, false-selected unrelated backend tests. Two recall losses are accepted and documented: a literal assigned to a variable and fetched later, and a `{url: '/x'}` options-object property.
- **Blade-view seeds resolve by exact node membership.** A changed view seeds exactly its own node — previously `components.card` also seeded every nested sibling (`components.card.header`, …) through boundary-substring matching, inflating `impacted` and `risk` and potentially tripping a `--fail-on` gate on views that didn't change.

### Fixed

- An unreadable frontend head source (an I/O failure on a file the diff proves exists) reads UNRESOLVED instead of a determined "no references" — the same honesty guard the PHP path already had.
- A concatenated Ziggy name (`route('videos.' + action)`) is recognized as a dynamic argument and flags the file, instead of silently dropping a partial name.
- Optional-parameter route templates match a bare `/` (root `/{locale?}` routes) and trailing-slash literals (`/videos/` against `/videos/{video?}`).
- Extension-suffixed Wayfinder module specifiers (`…/VideoController.ts`, `@/routes/videos.ts`) resolve instead of passing unseen.
- The markdown changed-files table escapes `|` and backticks in file paths — the code-span fence now outruns the longest backtick run in the path, so a legal filename can no longer break the table that lands in a PR description.

### Internal

- Suite grows from 516 to 562 tests (1213 assertions): end-to-end coverage of the frontend/Blade-inline seam against the fixture project, a formatter contract test rendering one rich fixture through all three output surfaces, and characterization of the `benchmark:add` stanza escaping.
- `EntryPointRow` now owns the entry-point facts and ordering both formatters previously duplicated; decoration stays per-format. Both formatters sort on the plain label.
- `riskInputs()` graph walks are memoized per run, so a broad diff no longer repeats identical caller/reach walks per changed entry-class or job file.
- The shared line-range locality rule of the per-source checkers lives in one trait.
- CI: the run-tests matrix caches Composer downloads, and a dedicated job runs the suite without `laravel/mcp` installed (pinned to the Laravel 12 floor) to exercise the optional-dependency guard.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.7.0...v0.8.0

## v0.7.0 - 2026-07-19

<!-- verified-sha: 3fff0c5627094e4e0c6eb1834bb8cf10e602020d -->
The report crosses the stack boundary: changed frontend files report the backend endpoints they touch, and changed backend code reports the Inertia pages it renders.

### Added

- **Frontend endpoint references** (opt-in via `frontend.roots`). Changed `.ts`/`.tsx`/`.js`/`.jsx`/`.vue` files are scanned for the backend endpoints they reference, all mapped through the app's router onto route entry points: [Wayfinder](https://github.com/laravel/wayfinder) action imports (the controller FQCN lives in the import path — deterministic, method-precise, aliasing and `import type` included), Wayfinder route imports and Ziggy `route('name')` calls (name index), and endpoint strings matched against the route templates — plain literals (`axios.post('/videos')`) and backtick templates whose `${…}` interpolations wildcard one segment (`fetch(`/videos/${id}`)` matches `/videos/{video}`). A verb-named call pins the HTTP method; anything unrecognisable stays method-agnostic and never narrows the match. Optional route parameters (`{user?}`) match with and without their segment. The touched routes are listed as entry points with their existing location, exposure and feature-gate annotations — and **never move `risk` or `impacted`**: a frontend edit does not change backend behaviour, and the report says so explicitly on frontend-heavy diffs.
- **Fail-safe semantics carry over.** A dynamic `route(…)` argument or an unmatched Wayfinder action import marks the file UNRESOLVED and makes `richter:affected-tests` exit `2`; an unmatched route name or URI literal simply isn't a reference (`routes/` modules and `route()` helpers collide with frontend-router idioms — unmatched names never guess). Wayfinder's generated trees (`actions/`, `routes/`, `wayfinder/`) are excluded as regeneration churn.
- **Blade inline scripts.** Endpoint literals inside `<script>` blocks of changed Blade views seed touched routes the same way — script slices only, since markup hrefs and form actions are navigation, not endpoint calls.
- **Inertia reverse direction** (no configuration needed). A changed backend member rendering an Inertia page (`Inertia::render('Videos/Show')`, the `inertia()` helper, aliased facades included) is noted under Findings with the page file resolved and existence-checked under `frontend.pages_path` — a miss reads "no page file found", which usually means a renamed or deleted page. Scoped to the changed members, like every source checker.
- **Advisory frontend test selection.** Frontend spec files (`*.test.*`, `*.spec.*`, `*.cy.*` under the frontend roots, or `frontend.test_paths`) referencing a touched route surface in `richter:affected-tests` as a `frontendTests` list — in `--json` and text output for the JS runner, never in `--plain` (which feeds the PHP runner), and never an input to determinability.

New config keys: `frontend.roots` (default `[]`, bridge off), `frontend.generated_paths`, `frontend.pages_path`, `frontend.test_paths`.

### Internal

- Suite grows from 447 to 516 tests (1043 assertions), pinning the scanner idioms (aliased/default/invokable Wayfinder imports, verb pinning, template-literal wildcarding, optional parameters), the annotation-lane risk isolation, the Blade script-slice boundary, and the frontend spec index end-to-end.
- `CodeGraph::hasNode()` provides exact node-id membership for route seeds, where substring matching would let a shorter route id match inside a longer one.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.6.0...v0.7.0

## v0.6.0 - 2026-07-18

<!-- verified-sha: 368b2015fda48b80a8efc4df271fe914d4d11e0c -->
The impact report becomes a review companion: it now says where each entry point lives, how exposed it is, which feature flags gate it — and which tests are worth running for the diff.

### Added

- **`richter:affected-tests`** selects the test files affected by the current diff, uniting two axes: tests referencing any reached entry point (route URI or name, artisan command, schedule entry resolved through its command) and tests importing any changed or reached `App\` class. The contract is fail-safe by design: exit `0` means a determined selection, exit `2` means "cannot determine — run the full suite", and any UNRESOLVED file, low-confidence walk, unresolved dispatch, or uncheckable entry point trips it. `--plain` prints nothing when undetermined, so `php artisan test $(php artisan richter:affected-tests --plain)` degrades to the full suite instead of silently running too little; `--json` carries `determinable`, `reasons`, `tests`, and `unreferencedEntryPoints`. Only runnable `*Test.php` files are ever selected — an entry point referenced solely from non-test support files blocks determination rather than shrinking the set silently.
- **Node locations.** Entry points and `--explain` path hops now carry their defining `file:line` (project-relative): inline in text output, in the markdown review checklist, and as `entryPointLocations` in the JSON/MCP contract. Tracer-only nodes derive their file from the `App\` path convention, existence-checked — never guessed.
- **Security annotation.** Reached routes inherit Laravel Brain's per-route security surface as advisory annotation: exposure renders inline (`[public]`, `[guest]`, `[authed]`, `[admin]`), statically detected issues render as sub-lines, markdown gets badges, and JSON/MCP gain `entryPointSecurity`. Annotation only — it informs the reader and is never an input to `risk` or the CI gate.
- **Pennant feature-flag annotation.** Routes gated by `EnsureFeaturesAreActive` — via middleware alias or FQCN-string form, with aliases read from both a legacy HTTP Kernel and `bootstrap/app.php` — render their flags inline (`[gated: ai-coach]`, a 🚩 badge in markdown, `entryPointGates` in JSON/MCP). When changed code itself checks flags (`Feature::active/inactive/when/unless/…`, the fluent `Feature::for($scope)->…` form, array arguments, backed-enum flags resolved to their value, `@feature` in changed Blade views), the report notes it under Findings. Honest limit: the `EnsureFeaturesAreActive::using(...)` runtime form is invisible to static route parsing and is not detected.
- **Filament and Livewire entry surfaces.** An upstream `App\Filament\` or `App\Livewire\` caller now counts as a class-level entry surface — a Blade-mounted component or Filament resource/page/widget is a user-facing surface even without a `route::` node — and contributes explain chains through its shallowest reached member. `\Filament\` joined the risk-floor namespaces and `Filament` the default `entry_point_roots`. Apps with a *published* config file add `'Filament'` to `entry_point_roots` themselves to get the tracing half. Coverage is class-level: individual table/bulk actions are not modelled as separate entry points.

### Fixed

- `bootstrap/app.php` is now part of the graph-cache fingerprint. It feeds middleware-alias resolution, so editing it invalidates the cache like any other build input; previously a cached graph could survive such an edit.

### Internal

- Graph-cache format version is now 3: the cached graph carries a sparse per-node metadata side-map (locations, security, gates), revalidated shape-by-shape on read so a tampered or drifted entry degrades to the same conservative shapes a fresh build produces.
- A Rector pass modernised the source (docblock FQCNs to imports, locally-called static helpers to instance methods, split guard conditions); no behavioural change.
- Suite grows from 357 to 447 tests (925 assertions), pinning the affected-tests exit-code contract, the metadata cache round-trip, gate detection in alias/FQCN/bootstrap forms, member-scoped flag findings, and the Filament/Livewire entry recognition end-to-end.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.5.0...v0.6.0

## v0.5.0 - 2026-07-18

<!-- verified-sha: 084038831c09c1cfc1dac965f043cdbba9c4b64c -->
### Added

- **Structured MCP output.** Both MCP tools (`impact`, `detect-changes`) now return MCP structured content alongside the prose text block, in exactly the shape of the CLI `--json` contract — one machine contract, two surfaces. Both tools also advertise an `outputSchema`, so agents can branch on fields (`risk`, `entryPoints`, `entryPointPaths`, …) instead of parsing prose. Error paths are unchanged. Note for strict schema validators: the map-shaped fields (`changed`, `coverage`, `entryPointPaths`) serialize as `[]` when empty, exactly as the `--json` contract always has.
- **`richter:benchmark:add <fix-commit>`** scaffolds a `richter.benchmark_cases` fixture from a historical fix commit: it validates the commit, dry-runs it through the exact replay `richter:benchmark` uses, reports what the case would score today, and prints a ready-to-paste config stanza. `--control` derives the `max_risk` cap from the replayed risk; `--key` overrides the derived case key (ticket id found in the commit subject, else the short SHA). Read-only by design — it never edits the config file — and the exit code is honest: non-zero when the scaffolded case would fail `richter:benchmark` today.

### Internal

- `CodeGraph::nodesContaining()` now narrows candidates through a lazily-built token index before running the boundary regex, cutting each seed lookup from a full-graph regex scan to just the nodes sharing an identifier token with the needle. Matching semantics are preserved exactly — the regex remains the final filter — and a wide diff against a large host graph no longer pays O(changed-members × total-nodes) regex executions on top of the cached build.
- Suite grows from 343 to 357 tests: the token-index boundary semantics pinned directly, the structured MCP responses and advertised schemas covered end-to-end, and the scaffolder's guard, replay, derivation and refusal paths all exercised.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.4.0...v0.5.0

## v0.4.0 - 2026-07-18

<!-- verified-sha: 0c11c5ee64fe7f95f8b9f95a9678f60d3107e560 -->
### Fixed

- **Pure renames are now visible.** Moving a class file without editing it produces a 100%-similarity rename in the diff — a section with `rename from`/`rename to` metadata and no hunks. The parser previously ignored those sections entirely, so `richter:detect-changes` reported **no impact** for a change that breaks every caller of the old FQCN. The parser now registers hunk-less rename sections, and the analysis treats them as what they are: a class-level change that seeds **both** sides — the vanished old FQCN directly (its callers, which still reference it, are exactly the blast radius) and the new FQCN as a coarse class-level estimate. A rename whose old name matches nothing in the graph reads UNRESOLVED, never cosmetic. Renames that also edit content were already handled and are unchanged, as are pure *copies* (nothing existing breaks, so they stay additive-by-design).

### Internal

- Six new tests pin the behavior end to end: hunk-less rename registration on both parser flush paths, no double registration for content-carrying renames, pure copies ignored, the resolver's both-FQCN seeding, and an analyzer-level test proving a renamed class's old callers surface as the reported entry points. Suite now at 343 tests.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.3.0...v0.4.0

## v0.3.0 - 2026-07-18

<!-- verified-sha: b139916b8c33e1717efb4909ccf8b48f1a7c6a77 -->
### Added

- **Graph cache.** The code graph is now served from an on-disk cache keyed by a content fingerprint of everything the build reads — `app/`, `routes/`, `resources/views`, the relevant config, and package versions — so repeated runs and MCP sessions stop paying a full rebuild. Staleness is designed out rather than expired out: any changed input changes the fingerprint. Configurable via `richter.cache.enabled` / `richter.cache.directory`; `--no-cache` on all three commands bypasses it for one run.
- **`--markdown` on `richter:impact` and `richter:detect-changes`.** GitHub-flavoured markdown for pull-request descriptions and comments: risk badge up front, changed files as a table, entry points as a review checklist with test tags, and long lists collapsed into `<details>` instead of truncated.
- **`--explain` on `richter:detect-changes`.** Each reached entry point carries the shortest call chain down to the changed code, each hop labelled with its edge type. JSON output always includes the chains as `entryPointPaths`, keyed by entry point — a self-listed entry class deliberately carries no chain, so consumers can tell "reached from the change" apart from "is itself the entry surface".

### Fixed

- **Diff hunk lines starting with `++ ` or `-- ` were misread as file headers.** A removed SQL comment in a heredoc (`-- …`) or an added `++ $i;` statement made the parser drop the change and report a falsely-empty "no impact" — the exact failure the tool exists to prevent. The parser now tracks hunk state, so headers are only recognised in the file preamble.
- **Container-binding edges were silently absent for strict-typed providers.** Service providers opening with `declare(strict_types=1);` produced zero binding edges. Provider scanning is now done natively (and scans every class in a provider file, not only the first), so `bind()`/`singleton()`/`scoped()` calls and `$bindings`/`$singletons` properties resolve regardless of the declare.
- **`--explain` chains are now deterministic across cache and fresh builds.** Edges sort canonically before the graph is built, so a warm cache and `--no-cache` pick the same (equal-length) chain for the same commit.
- **The MCP `detect-changes` tool returns a clean error for an option-shaped base ref** (e.g. `--upload-pack=…`) instead of leaking an uncaught exception, matching the Artisan command's behavior.
- **The eager-load relation checker no longer caches model methods for the process lifetime.** In long-lived processes (MCP server, queue worker), a relation added mid-session could trigger a false "not a method on any model" finding; the scan is now fresh per run while still running at most once per invocation.
- **Graph builds no longer leave `laravel-brain.*` config overridden** in the host application after the build — the four path keys are restored once the analysis completes.
- **The README's safety claim was corrected**: analysis never executes routes, jobs, or commands, but it does autoload classes from the analyzed checkout — with guidance for running against untrusted pull-request branches in CI.

### Internal

- The consolidated per-file AST pass now collects node buckets in a single traversal (previously five full descents per file across the tracers) and retains the entry-point subset of ASTs so the entry-point tracer no longer re-parses those files.
- Name resolution is consolidated onto shared helpers; the previous five private copies are gone.
- The test suite grew from 267 to 337 tests: end-to-end benchmark pass/control scoring, interface-implementation and container-binding edge coverage, MCP success paths, diff-parser edge cases (CRLF, binary, mode-only), and cache round-trip determinism.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.2.0...v0.3.0

## v0.2.0 - 2026-07-16

<!-- verified-sha: 5cec649c4a780e626b583c6c8abdbdab022bedd1 -->
Machine-readable output and an opt-in CI gate for `richter:detect-changes`, plus stricter config validation. Advisory-by-default is unchanged: no flags still means human-readable text and exit 0.

### Added

- **`--json` on `richter:detect-changes` and `richter:impact`.** JSON mode emits a single parseable document on stdout — the full, uncapped report — for scripting and CI. Any failure is expressed as `{"error": "…"}` on stdout rather than a leaked stack trace, so the output is always valid JSON.
- **Opt-in CI gating on `richter:detect-changes`.** `--fail-on=<low|medium|high>` exits non-zero when the reported risk is at least the given level; `--fail-on-unresolved` exits non-zero when any changed file is UNRESOLVED, independent of the risk threshold. Both fail closed: a missing or invalid threshold (`--fail-on`, `--fail-on=`, `--fail-on=bogus`) is a usage error, and an un-assessable diff (a broken or invalid base ref) fails under a gate rather than passing as "no impact". With `--json`, the report carries a `gate` object recording the verdict.
- **README "Gating in CI" section** with a copy-paste GitHub Actions recipe. No Action ships with the package — `detect-changes` is a plain Artisan command.

### Changed

- **Config is validated on read.** A mis-shaped `richter.*` value now throws instead of being silently dropped, and a base ref shaped like an option (leading `-`) is rejected before it can reach a `git` argument. A misconfiguration surfaces loudly rather than degrading into a falsely-empty report.
- **MCP tool names pinned** (`impact`, `detect-changes`) so agent integrations stay stable across releases.

### Notes

Richter remains dev/CI tooling and advisory by default; a low or empty result is a signal, not a guarantee of no impact. Gating is strictly opt-in.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.1.0...v0.2.0

## [Unreleased]
