# Changelog

All notable changes to `sandermuller/richter` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.44.0 - 2026-08-21

A guard written as an application middleware class drew nothing. The project already declares which class is its `auth` guard, in a file richter was reading for something else.

### Changed

- **A removed guard carries the CWE for the guard it names**, rather than one CWE for all of them. `auth` and `password.confirm` are CWE-306, `can` and `verified` are CWE-862, `signed` is CWE-345, and `throttle` is CWE-770. A lost rate limit reporting as Missing Authentication for Critical Function was the stretched mapping the hazard table's own rule warns about. Anything reading the `cwe` field will see different values for `throttle:`, `signed` and `verified` removals; the tier and the level are unchanged. This applies to the controller-constructor lane as well, which had reported CWE-306 for every middleware since long before the route-file work.

### Added

- **An application's own guard middleware resolves through the alias map the project registers.** `$middlewareAliases` on a legacy Kernel, or `$middleware->alias([...])` in `bootstrap/app.php`. Only seven framework classes were mapped before, so a route written `Route::middleware(Authenticate::class)` against the stock `App\Http\Middleware\Authenticate` scaffolding reported nothing at all when its guard was removed, and every route inside such a group went with it.
  
  A project that writes `'auth' => Authenticate::class` has said which class is its `auth` guard, so the class form and the string form are the same guard and losing either is the same removal. That is declared intent, not an inference from an `extends` clause, which would reach the same answer on weaker evidence.
  
  The refusals hold. A class two guard aliases both name is skipped rather than resolved one way, the same refusal the group reader already makes for a name that is both a group and an alias. A class the project registers under no guard alias draws nothing, and an unmapped class draws nothing.
  
  The map is read from the working tree, so it answers for both sides of the diff, and it is re-read per run: a long-lived process that analysed one checkout does not answer for the next with the first one's map.
  

### Compatibility

No graph-cache bump: `FORMAT_VERSION` stays at 26. No level moves. What changes is that a guard removal previously invisible is now reported, and that some `cwe` values are more exact.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.43.0...v0.44.0

## v0.43.0 - 2026-08-21

A guard leaves a route in two directions, and richter watched neither. It can leave the route, and it can leave the middleware group the route runs in. Both are read now, and a hazard on a member of a class that is itself an entry surface stops grading as unreachable.

### Changed

- **A level moves.** A hazard on a member of a class that is ITSELF an entry surface graded `no-known-path`, and a tier-1 hazard on it `low` — the only LOW cell in the matrix. Reach is computed from a member's callers, and a Livewire page, a Filament resource or a Nova resource has none in application code, because the framework calls it. The class's own nodes now ride along as depth-0 hops before entry-point classification, the same rule the removal lane already took for callers. A tier-1 hazard on such a class reports `medium` instead of `low`, and a tier-2 one reports `no-guard-found` instead of `no-known-path` at the same level. This is the false-LOW direction the model exists to avoid, so the correction is the point rather than a side effect. A plain service class still answers `no-known-path`.

### Added

- **`routes/*.php` is compared route by route for the guard middleware a route lost.** Tier 3, `auth` lane, CWE-306 — or CWE-862 where the guard is a `can:` check. A route file declares no class, so the hazard lanes never reached it: they run behind a class-like gate.
  
  Each route's EFFECTIVE guard set is resolved on both sides — the middleware written on the route, plus every enclosing `Route::middleware(...)->group()` and `Route::group(['middleware' => ...], ...)` wrapper, minus its own `->withoutMiddleware()` — and those are diffed. A file-wide token comparison would report every route as newly unguarded the moment they were wrapped in a guarded group, which is the commonest edit these files see. The chain is read in call order, so `->middleware('auth')->withoutMiddleware('auth')` does not read as guarded; the registration is found anywhere in the chain, so `Route::middleware('auth')->get(...)` is read like the chained form; and a route renamed in the same edit that drops its guard is paired by its action rather than read as deleted.
  
  A route the head no longer declares raises nothing. Deleting a route leaves nobody able to reach it unguarded. The hazard resolves through the route's action where the file names one, so the entry points reaching it answer for it; a closure route falls back to its own node id.
  
- **`bootstrap/app.php` and a legacy `app/Http/Kernel.php` are compared per middleware group.** Removing `auth` from the `web` group unguards every route in that group at once. Tier 3, named for the group (`middleware group 'web'`), silenced with `hazards.ignore` under `middleware-group:web`.
  
  The comparison is shape-aware where the arrivals are not. A literal comparison would call swapping `'auth'` for `Authenticate::class` an authentication removal, so only two shapes are read — the Kernel's `$middlewareGroups` array, and the `withMiddleware` calls (`web(append:)`, `api(remove:)`, `appendToGroup`, `prependToGroup`, `removeFromGroup`, `replaceInGroup`) — and an unrecognised shape produces a finding instead of a hazard. A guard newly named under `remove:` counts only when the base group actually ran it, because `remove:` subtracts from a default set richter does not model.
  
- **A guard moved between a route, a controller constructor and a middleware group is not a removal.** All three surfaces now speak one vocabulary, so the whole-diff moved-not-removed guard matches across them. That vocabulary also gained the parameterised forms (`auth:sanctum`, `can:update,post`) and the framework guard classes, which the controller-constructor lane had been missing. A guard lost by one route while another route in the same file gains one is still reported: `middleware:auth` names no particular surface, so treating that as one guard moving would silence every guard removal in any diff that also adds a guarded route.
  

### Fixed

- **Documentation claims re-traced against the source.** The weak-assertion tag prints with an em dash, not a comma. JSON `verification` reads `null` when a state could not be checked, where `false` means unreferenced. `richter:impact` entry-point hops carry no `depth`. The empty-diff message names the base ref. Middleware group membership comes from Brain's analyzer, and the file the app carries decides which shape is read, not its framework version. The `richter://config` resource exposes only the cache-enabled flag. The hazard table gained the deleted-class lane, and a paragraph now states the no-double-report rules and the gutted-guard (`return true;`) hazard.

### Compatibility

No graph-cache bump: `FORMAT_VERSION` stays at 26. The two performance changes in this release — one descent instead of three in the relation-traversal tracer, and resolving each distinct node id once when declaring member edges — leave the graph's shape untouched.

Levels move only in the direction described above. A route file that carries no hazard still reads as nothing to assess, exactly as it did when it was reported as out of scope.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.42.0...v0.43.0

## v0.42.0 - 2026-08-21

The reach class a hazard carries had a name that asserted more than it knew. `gated` was a fallthrough — everything that was not proven `public-write` landed in it, including surfaces where no guard exists and some where none could exist. It is now a finding, and what falls out of it has a name of its own.

### Breaking

- **`reach` gains a fourth value, `no-guard-found`.** A consumer matching on the three previous values will not recognise it. Nothing that was `public-write` or `no-known-path` moves; what changes is that the old `gated` splits in two.

### Changed

- **`gated` has to be earned now, and by every reaching entry point.** It requires evidence: an exposure Brain read off the middleware surface (`authed`, `admin`, `internal`), or a guard the cross-check correlated to the route. Previously it keyed only on the cross-check, whose two maps are populated *only* for routes Brain flagged `PUBLIC_WRITE` — it exists to contradict Brain, so it never runs anywhere else. A `command::` node, a Livewire surface Brain never classified, and a genuinely authenticated route all graded `gated` together, under a name claiming a guard was visible.
  
  One reaching route without a visible guard is the way in, so a set where the others are guarded grades `no-guard-found`. Averaging would hide the surface that matters.
  
- **`no-guard-found` is the leftover, and it scores exactly as `gated` does.** An admission has to move the level in neither direction: raising it would report `high` across every codebase whose surfaces Brain cannot classify, punishing a coverage gap as though it were a security one, and lowering it would read absence of evidence as evidence. **No level moves in this release.** What changes is what the report says.
  

### Fixed

- **A scheduled entry point resolves through what it runs.** `TestReferenceIndex` handled `route::` and `command::` nodes and app classes, and let `schedule::` fall through to "could not be checked". A schedule id is `md5(type.target.frequency)` — an opaque hash — so it is resolved through the graph's `schedule-to-command` edge to the command behind it, and a test driving that command now references the scheduled surface. A schedule reaching no command, a scheduled closure among them, stays unanswered rather than claiming no test drives it.
- **A removed member keeps reach through a class-based entry point.** Deriving a removal's reach from its declaring class filtered callers to the `route::`/`command::`/`schedule::` prefixes, which dropped Livewire, Filament and Nova classes — entry surfaces in their own right. A removed member reached only that way graded `no-known-path`, and a tier-1 hazard on it `low`. It now uses the analyzer's own entry-point classification rather than a second, narrower copy of it.

### Compatibility

No graph-cache bump: `FORMAT_VERSION` stays at 26. Levels are unchanged; a `schedule::` surface that previously read "could not be checked" now reports whether a test drives the command behind it, which can move a change from `medium` to `low` where one does.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.41.0...v0.42.0

## v0.41.0 - 2026-08-21

Two rounds of consumer dogfooding found three defects in the 0.40 risk model, and each was caught by a person reading source rather than by anything in the suite. This release closes the reasons for that: the level stops making a claim it never checked, and the benchmark corpus gains the assertion that would have caught the worst of them.

### Breaking

- **`verification` is now a tri-state.** `true` referenced, `false` unreferenced, **`null` not checked** — in `--json` and in the MCP `detect-changes` schema, where it was `bool`. A consumer reading it for truthiness is unaffected: `null` and `false` both mean "not verified", and the level treats them identically. A consumer distinguishing the two now can.

### Fixed

- **The level no longer claims tests it never read.** With no test index, every surface graded `false` and the cause line read "3 of 3 reached surfaces have no test referencing them" — a statement about tests that nothing consulted. That is evidence-shaped where there is an absence of evidence, the same falsely-reassuring move this package refuses everywhere else. It also hid a defect: a caller that forgot to pass the index produced a report indistinguishable from a project with no tests at all, which is exactly how that omission survived a release.
  
  Unchecked is now its own state. The level is unchanged — a surface nothing looked at is not a verified one — but the sentence is honest: *"test references were not checked, so nothing here is verified"*, or, where both apply, *"1 of 3 reached surfaces have no test referencing them, and 1 could not be checked"*.
  
- **`expect_finding` matches a hazard as well as a finding.** The three payload-parity checks were findings until 0.40 made them tier-2 hazards, so a benchmark fixture pinning one by its message became unmatchable and failed with "no finding contains …" — which reads as lost coverage rather than a relocation, and sends the reader hunting a graph regression that never happened. A fixture pins that the report SAYS the thing; which section carries it is Richter's business.
  

### Added

- **`max_hazard_tier` on a benchmark case** — `0` to `3`, default `3`, so an absent one constrains nothing. It caps the worst hazard a change may produce, and `0` allows none, which is what a control usually wants to say.
  
  It is not the same guard as `max_risk`, and the difference is the point. The tier × reach matrix maps a tier-2 at `gated`, a tier-1 at `public-write`, and a hazard-free change with unverified reach all onto `medium`. A case whose honest answer is `medium` therefore stays green while a spurious hazard appears underneath it, and only a tier-3 false positive is caught by a level cap at all — because tier 3 is `high` everywhere. Capping the tier constrains the hazards directly.
  
  `richter:benchmark:add --control` now pins the tier a control produces today, for the same reason it already pins the level.
  

### Internal

- The hazard lanes are exercised against the fixture project's real source, not only against source written for the occasion. That distinction is not academic: the policy-constant idiom that broke the `auth` lane in 0.40.0 was already sitting in that project, one line above the synthetic guard the end-to-end test injected instead. Three tests now use it — removing the guard fires, rewriting it between call shapes does not, and the file compared with itself is silent — and each asserts the shape is still present before relying on it.
- A sixth entry in [Known limits](https://sandermuller.github.io/richter/coverage): a provider that CONFIGURES a route it does not declare is connected to nothing, so a rate limiter registered in a service provider has no edge to the routes it throttles. That used to cost only reach. Since 0.40 it costs a level too, because a changed class reaching nothing is graded on whether a test imports the class itself — and tests that drive a throttled route import the route helpers, not the provider. The fix is coverage, not a cap.

### Compatibility

No graph-cache bump: `FORMAT_VERSION` stays at 26. Levels do not move.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.40.2...v0.41.0

## v0.40.2 - 2026-08-21

Three callers built a test-reference index for the report's renderers and handed none of it to the analysis. Before 0.40 that was harmless — test references were annotation. In 0.40 they decide the risk level wherever a change carries no hazard, so those three graded every surface as unreferenced and floored at `medium`.

### Fixed

- **The MCP `detect-changes` tool contradicted itself.** It built the index on the line after the analysis and passed it to the formatters alone. One report therefore rendered entry-point rows as `referenced` beside a cause line calling those same surfaces unreferenced, and the level came from the second reading. Both now come from the same index.
- **`richter:benchmark` graded every fixture blind.** It passed no index at all, so every replayed case reported at least `medium` whatever its tests say. That matters beyond the printed level: `max_risk` is asserted against it, so a corpus could stay green while measuring a level computed without the input the level depends on.
- **`richter:benchmark:add` captured a control's cap from that same blind level**, writing an inflated `max_risk` into the scaffolded stanza.

A historical replay is graded against the CURRENT `tests/` directory. That is the trade `richter:benchmark` already makes for the graph, which is likewise built from the current checkout, and it is the same reason `--head` grades against today's tests: pointing at an old commit deliberately leaves the working tree alone.

### Upgrading

If you keep a [benchmark corpus](https://sandermuller.github.io/richter/benchmark), re-check it after upgrading. Cases that reported `medium` only because the level could not see your tests will now report what they earn, and a control whose `max_risk` was captured by `richter:benchmark:add` on 0.40.0 or 0.40.1 may be capped higher than the change deserves.

The 0.40.1 notes suggested setting `max_risk` on signal fixtures so the corpus checks the level and not only the reach. That advice holds, but do it on this version — a cap chosen on 0.40.1 was chosen against a level computed without the test index.

### Compatibility

No API, config or output-shape changes, and no graph-cache bump: `FORMAT_VERSION` stays at 26. Levels can move DOWN on the surfaces above, wherever a test reference existed that the level was not being shown.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.40.1...v0.40.2

## v0.40.1 - 2026-08-21

A consumer audit of 0.40.0 found the `auth` lane reporting the one thing the release notes single out as the worst kind of mistake: a false "authorization guard removed", at tier 3, HIGH. The cause is one line, and it disables the defence entirely for any codebase that names its abilities with policy constants.

### Fixed

- **A policy constant counts as an ability.** The moved-not-removed guard compares the ABILITY a check names, so that rewriting `Gate::denies('publish')` as `$user->cannot('publish')` keeps the same token and does not read as a removal. Only a string literal formed that token. A class constant is not a literal, so it fell back to the call's own name — `call:can` against `call:cannot` — which can never match.
  
  A project following Laravel's own convention writes `can(PostPolicy::UPDATE, $post)` everywhere and has no string abilities at all, so the defence was switched off for every check it has. Inverting `cannot(Policy::DELETE)` to `can(Policy::DELETE)` — the same ability, on the same policy, in the same callback — reported a removed guard at tier 3.
  
  A constant now tokenises on its resolved class and its own name. That needs no value resolution: two references to one constant agree wherever they are written, which is all the comparison asks for.
  
- **The same fallback hid a real removal.** Two DIFFERENT constant abilities on one call shape also both tokenised as `call:can`, so exchanging one for another cancelled out and reported nothing at all. Distinct tokens make the swap visible.
  
- **A removed policy method is named both ways.** It emitted its own name only, which a caller writing `can(PostPolicy::DELETE)` could never match — the same gap one layer down. It now also emits every constant in its class whose literal value is that method name, read from the policy's own source. That is a value comparison, not a guess at `DELETE` standing for `delete`.
  

### Also in this release

Work that landed alongside the fix above, extending the dispatch-resolution lane 0.39 introduced.

- **A batch of mapped jobs resolves when the collection is bound to a local.** `Bus::batch($items->map(fn ($i) => new SendInvoice($i))->all())` already proved its own contents at the dispatch site. The chain no longer has to be written there: `$jobs = $items->map(...);` followed by `Bus::batch($jobs->all())` resolves too, because the code between the map and the dispatch has to name the collection. The bar is the locally-built job's, one guard stricter — the method must write that name exactly once, at the top level, before the dispatch, and mention it nowhere else, since a collection is an object and `$jobs->push($other)` would change what the batch holds without writing the name. Anything looser keeps the site unresolved.
- Consequently, dispatch sites that read as unfollowable on 0.40.0 can now resolve, which is what lets `richter:affected-tests` reach a determinable verdict on a diff that one such site was holding back.

### Compatibility

- **The graph cache rebuilds once.** `FORMAT_VERSION` moves 25 → 26. The batch lane draws edges a 25 entry does not carry, so an entry written by an earlier version would under-report them.
- The risk-model work in this release adds no edges of its own; hazards are read from the diff's two sides, not from the graph.

### Internal

- `Hazard::$removedToken` is now `$removedTokens`, a list. One removal can be named more than one way, and the guard has to recognise any of them. The field is internal to the hazard record; the flattened `hazards[]` entries in `--json` and over MCP are unchanged.
- Benchmark guidance gained a note worth acting on: `max_risk` is checked on every case, but it defaults to `high`, so a signal fixture that never sets it can never trip the cap — and a level that is wrong for the right reach passes green. Setting it on signal fixtures turns the corpus into a check on the LEVEL rather than only on reach. The 0.40.0 upgrade note is also corrected: the controls needing a re-grade from `low` to `medium` are the ones Richter cannot place, not additive-only ones, which stay at ladder step 0.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.40.0...v0.40.1

## v0.40.0 - 2026-08-21

The risk level scored three things: impacted nodes, entry points reached, and whether the diff touched an entry-point class. All three measure how BIG a change is. A change that removes an authorization check and a change that renames a method on a popular class scored the same, because the model had no notion of what KIND of change it was looking at. The counts also moved on their own — every release that taught Richter to follow more edges raised them for an unchanged diff, so a threshold pinned to them drifted.

This release replaces that model. A level is decided by the HAZARD a change carries — an exact, tiered property of the diff — and, where it carries none, by whether anything would catch a regression in what it reaches. The breadth numbers stay in the report, under `Impact`, where they describe the change instead of grading it.

Every level now prints the reason for itself. A bare `MEDIUM` is not a result this report will render.

### Breaking

- **`risk` changes meaning, so `--fail-on` gates a different thing.** Read [Risk levels](https://sandermuller.github.io/richter/risk-levels) before a gated build meets this release. Four named behaviours flip: an additive `$fillable` entry moves from `low` to `medium` (it is now a hazard); a change reaching only surfaces no test references moves to `medium`; a change reaching nothing richter can place moves to `medium`; and a broad change whose every surface is test-referenced can move DOWN to `low`. That last one matters most — a team using `--fail-on=high` as a breadth alarm loses that alarm.
- **`risk_thresholds` is retired.** Nothing reads it. The key is accepted and ignored for one release so an upgrade does not fail on it; remove it. There is nothing to calibrate any more, which was the point.
- **Three keys leave `--json` and the MCP `detect-changes` schema**: `scoredEntryPoints`, `scoredImpacted` and `coarseCapApplied`. They existed to name the counts the level was scored on, and nothing is scored on counts now. `lowConfidence` stays — it describes the seeding, not the scoring.
- **A benchmark control capped at `max_risk: low` needs re-grading to `medium`.** A benign change Richter cannot place reports `medium` by design. That is an intended over-report, not the unintended kind a control exists to catch.

### Added

- **Change hazards.** Seven lanes, tiered 1 to 3, each naming something that may break rather than something Richter could not see: an authorization guard or authentication middleware removed, `$hidden` narrowed, a mass-assignment surface widened, a `$casts` value changed on a surviving key, a validation constraint dropped, a queued job's constructor changed, a public or protected member removed, and a surviving member's signature changed. They print under `Hazards`, above `Findings`, with the CWE where a clean mapping exists.
- **Every predicate is exact.** A lane that cannot read both sides of a comparison in full reports nothing rather than guessing: an unparseable side, a spread in a `$fillable`, a rule list holding a `Rule::unique(…)` object, a validation array passed as a variable. A false "authorization removed" is worse than the number it replaces, because over-reporting is what trains a reader to ignore a report.
- **A guard that MOVED is not a guard that was removed.** Authorization migrates — a controller's `authorize()` becomes a form request's, a policy method becomes a `Gate::authorize()` call. Every removal predicate is evaluated against what the whole diff ADDED, counted per member so that neither an untouched duplicate elsewhere in the file nor a genuine move between two methods is read the wrong way.
- **A reach class per hazard.** `public-write`, `gated`, or `no-known-path`, combined with the tier to give the level. Tier 3 is `high` at every reach class: the graph's inability to name a caller is Richter's limit, not evidence of safety, and `no-known-path` means unmeasured, never proven-internal.
- **`--fail-on-hazard=<1|2|3>`** blocks on hazards alone, whatever the level. Blocking a removed guard and blocking a missing test are different policies, so each has its own flag. `--no-hazards` skips the lanes.
- **`riskCause`, `hazards` and `verification`** in `--json` and over MCP. `verification` names exactly what the level graded and whether a test references each entry — which is narrower than the printed entry-point list, on purpose.

### Fixed

- **A class-driven surface no longer reads as untested.** The test-reference matcher recognised a route two ways: by route name and by literal URI. A Livewire component or Filament page driven by `livewire(SomePage::class)` is named by neither, so a surface a test genuinely exercises graded `unreferenced`. A route now resolves through its handler edge to the class that serves it — two hops where a Filament resource route needs them — and an import of that class counts. On one audited application, 16 test files drove surfaces that way.
- **Only a runnable test file counts as a reference to the level.** Richter indexes every PHP file under `tests/`, fixtures and base cases included. A fixture holding `route('posts.edit')` or a literal URI would otherwise verify a surface no test exercises, which is a falsely reassuring `low` — the one direction this model must not fail in. The per-row annotation keeps its broader reading.
- **A state that could not be checked counts as unverified.** A route miss while the router was unavailable means the check never ran. Reading it as "not unreferenced" would open the `low` path on a surface nothing checked.
- **Payload parity is a hazard, not a finding.** A payload key a consumer still reads, a model field that never reached the resource mirroring its siblings, a form-request field that stopped being validated: each is a thing that breaks. The three checks keep their predicates and their `payload_parity` config keys unchanged; only the destination moved, and with it their effect on the level.
- **"Nothing to assess" and "could not place this" are separate answers.** An empty, cosmetic-only or additive-only diff reports `low` because there is no question to answer. A real change to a class the graph never charted reports `medium`, because failing to place a change is a placement failure. Collapsing the two would have reported `medium` for a whitespace commit.

### Compatibility

- **The graph cache is unaffected.** Hazards are read from the diff's two sides, not from the graph, so `FORMAT_VERSION` does not move for this work.
- **A frontend-only change still cannot move the level.** Its routes are touched surfaces, never walk seeds, so such a diff has nothing to assess and reports `low` — the promise the config has always made, now kept by construction.
- **Coverage and the level stay separate gates.** An UNRESOLVED file never floors the level; `--fail-on-unresolved` covers it, and the unresolved file still prints its own finding.

### Internal

- Brain's per-route security surface and the test-reference annotation were both documented as never feeding the risk level. Both now do — the first through a hazard's reach class, the second through the verification ladder — and every place that claimed otherwise has been corrected. Pennant gates and middleware group membership genuinely still feed nothing.
- Matching an imported facade on its bare AST token silently never fires. Two lanes were caught doing it, and the resolved-name helper is now used wherever a lane names a class.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.39.0...v0.40.0

## v0.39.0 - 2026-08-20

An instance method call drew no edge in any earlier version. Brain resolves calls along its route-anchored lanes only, and Richter's own tracers covered static calls, constants, relations, facades and dispatches — so for every class no route reaches, `$this->doTheWork()` was invisible. That gap, and three narrower ones beside it, came out of a consumer audit of 0.38 on a 4,000-file application.

Counts move in both directions again. A class that calls its own methods gains callers; an application driven by a dynamic config registry loses entry points it never earned. Re-check `risk_thresholds` against a benchmark corpus before reading a level shift as a regression.

### Added

- **Calls a class makes on itself.** `$this->doTheWork()` links the two members. The receiver is the enclosing class by definition, so this needs no type inference — a call on a property, a parameter or a local is a receiver-typing problem and is still not drawn. The target may be inherited rather than declared, in which case the inherited-method lane connects it to the ancestor whose body runs. A method no app class in the chain declares is a framework method (`$this->hasMany(…)`) and draws nothing: on the audited application, 9,262 such calls resolved to 4,695 real edges and 4,567 framework calls that draw none.
- **Why each context entry is listed.** The "Runs this code without calling it" section named classes and nothing else, so telling a trait user from an override implementor meant reading the source. Each entry now carries the edge types that put it there, in every format and as `traitAndOverrideReachVia` in `--json`.
- **A test file the diff itself touched is selected.** It is affected by definition, and no lane could find it — `tests/` sits outside every tree the analysis reads.

### Fixed

- **An inherited method no longer drags in its siblings.** 0.38 stopped a structural path from fanning out through `override` and left `inherits` reaching the same cousins from the other side: every class that inherits an ancestor member rather than overriding it. Both edges are refused out of a structural-only path now. A seed-adjacent hop stays legal, so a changed member still reaches the ancestor whose body runs and a changed ancestor still reaches every descendant.
- **A batch built by mapping is followed.** `Bus::batch($items->map(fn () => new SomeJob(…))->all())` read as a dispatch whose target could not be followed, which taints `richter:affected-tests` for a whole project — while the graph already carried the edge from the `new` inside the closure. `map` returns one item per item in, so the callable's return type is the item type whatever the source held. Every step is checked: the calls between the map and the dispatch must preserve the item type (`toArray()` does not — it converts an `Arrayable` job into an array), the callable must be written out at the call site, and its single return must be a `new` of a dispatch target. Anything else keeps the doubt it had.
- **A dynamic registry lookup is context, not a caller.** A `config()` read whose key the build can enumerate names one class, and a surface behind it calls the change. A key it cannot enumerate falls back to every class the config file names, and the surfaces behind that are identical for each of them — so on an engine with hundreds of registered classes, a change to any one reported the same admin pages. Those surfaces move to the association list, which already says context rather than reach. Affected-test selection still walks them: the dispatch is real, and skipping it would under-select.
- **An undetermined test selection names what it found.** The verdict blocks the selection from being trusted; it does not make the tests it did find disappear. They print under a heading that calls them a floor. `--plain` stdout stays empty and the exit code is unchanged, so a shell fallback still runs the full suite.

### Compatibility

No breaking changes; every public signature is unchanged.

- **The graph cache rebuilds once.** `FORMAT_VERSION` moves 23 → 25. Instance-call edges are additive, so a 0.38 entry under-reports them; the registry split changes an edge's type, so a 0.38 entry would also classify those surfaces the old way.
- **Entry-point lists shrink on registry-driven applications, and caller counts rise where classes call their own methods.** Both are the intended corrections. See [Risk levels](https://sandermuller.github.io/richter/risk-levels) before adjusting thresholds.
- **New edge types** appear in `richter:trace` chains and in graph output: `instance-call`, and `config-registry-fanout` beside the existing `config-registry`.

### Internal

- The docs site now publishes a plain-text surface: `llms-full.txt` behind one URL, and each page as `<slug>.md` beside its HTML.
- Two limits are documented where the code makes the choice: the mapped-batch proof reads method names rather than the receiver's type, so a collection-shaped class with its own `map()` could defeat it; and a trait that calls a method its consuming class supplies draws no edge, since the honest target is one node per consumer.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.38.0...v0.39.0

## v0.38.0 - 2026-08-20

Two halves of the same promise: reach what the code really runs, and report nothing it does not. Relation traversals now start from any root the source types, so four everyday shapes that drew nothing resolve. In the other direction, a change to one class in a wide interface hierarchy stops reporting every sibling implementor as reached.

Counts move both ways in this release. A relation-heavy application gains reach; an application with wide interface or template-method hierarchies loses the sibling fan-out it never earned. Re-check `risk_thresholds` against a benchmark corpus before reading a level shift as a regression.

### Added

- **Relation chains start from any root the source types.** `Post::find(1)->comments`, `new Post`, a local bound from a previous hop, and a `@var` docblock all resolve now, where only `$this` and a typed property or parameter did before. A static that returns one model (`find`, `first`, `create`, `firstWhere`, `findSole`, `updateOrCreate` and the rest of that family) types the chain it starts; `query()` and `where()` return a builder, so a chain through them still stops.
- **Statements are read in control-flow terms, not source order.** A local carries a type only after the line that bound it. A binding under an `if`, a loop, a `try`, a `match` or a ternary clears the variable instead of typing it, because a sibling branch may bind another model and the source cannot say which one ran. A closure keeps its bindings to itself in both directions. `find`, `findOrFail` and `findOrNew` resolve only for a scalar literal id, since Laravel hands an array id to `findMany()` and returns a collection.
- **`@var` docblocks are keyed by the assignment they annotate.** They were collected method-wide before, so a later annotation typed an earlier read and two annotations for one variable collapsed into the last. Names in a docblock resolve through the file's imports, including `use function` aliases.

### Fixed

- **A wide hierarchy no longer drags in every sibling.** The walk reached an interface method through `implements` then `declares` and left it through the `override` edges, so a change to one implementor listed every other one under "Runs this code without calling it" and counted them as impacted. On a large application a two-file change listed 473 such cousins and counted 1203 impacted nodes against 631 real ones. An `override` hop out of a node whose whole path from the change was structural is now refused. The rule reads the path, not the last edge: a call chain that happens to end on a `declares` hop is real dispatch and still fans out, which is what Class-Hierarchy Analysis is for. A changed override still climbs to its abstract call site, and a changed abstract still reaches its overrides.
- **A change that pins to no member no longer walks the class sideways.** A removed method leaves nothing for the graph to seed against, so the class node stands in. Upstream it answers the question that fallback exists for, who uses this class. Downstream it left the change entirely, through `implements` and `uses-trait` into everything the class is merely related to. The dependency walk now starts from the changed class's own members; the caller walk keeps the class node.

### Compatibility

No breaking changes. `CodeGraph::reachedViaTypes()` gained an optional trailing parameter, and every other public signature is unchanged.

Two things to expect on the first run after upgrading:

- **The graph cache rebuilds once.** `FORMAT_VERSION` moves 22 → 23. An entry written by 0.37 under-reports every relation chain the new roots resolve, so it is discarded rather than served.
- **Counts move in both directions.** Relation-heavy code gains reach; code with hub interfaces or template-method base classes loses the sibling fan-out. See [Risk levels](https://sandermuller.github.io/richter/risk-levels) before adjusting thresholds.

### Internal

- Type reading moved to `Support\DeclaredTypes`: reading a type and walking a relation chain are separate jobs, and only the second needs the relation index.
- The BFS carries one traversal rule for every walk, so entry points, chains, the drawn region and the impacted count cannot disagree about what the change reaches.
- [Coverage](https://sandermuller.github.io/richter/coverage) lists the known limits, now including the one this release's walk rule leaves: where a node is reached first through type structure and later through a call, the chain printed for it keeps the first route. Reach, counts and risk are unaffected.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.37.0...v0.38.0

## v0.37.0 - 2026-08-19

Relations are now followed, not only declared. A body that walks `$this->post->author`, a nested eager-load path, and the model a relation returns all draw edges, so a change to a model reaches the code that reads it instead of stopping at the model class. Two other blind spots close with them: a facade whose accessor names a container key, and a class whose statically-called methods were the only ones ever read. The documentation moves to a site of its own.

Impacted counts rise on relation-heavy applications — that is the point of the release, and it is also a reason to re-check `risk_thresholds` before treating a level shift as a regression.

### Added

- **Relation traversals.** `$this->post->author` links the relation methods it walks. A relation names its target in the `hasMany(Comment::class)` argument, so no hop needs type inference — only the start of the chain does: `$this`, a typed property, or a typed parameter. An untyped property, a `mixed`, a union type, or a query-builder result ends the chain before it starts, and a chain also ends after a to-many hop, since `$post->comments` is a collection and what follows belongs to the collection rather than to `Comment`.
- **Whole eager-load paths.** `with('comments.author')` links `Post::comments` **and** `Comment::author`. Only the first segment was linked before.
- **Per-model eager-load validation.** A segment is checked against the model that segment actually belongs to, so a name that is a relation on some *other* model is now reported against the model it was written on. Where the receiver cannot be resolved, the previous check against all model methods still applies, and a relation the scan cannot read (a string class argument, a macro) is never reported.
- **Relation methods link to the models they return** (`relation-to-model`). Brain's model-to-model edge is class to class, which stopped one hop short of the member that reads the relation: changing the model a traversal arrived at reported nothing. On this package's own fixture project the change moves a leaf model from 2 callers and no entry points to 18 and 3.
- **Container-key facade accessors.** A facade whose `getFacadeAccessor()` returns `'reports'` rather than `Reports::class` resolves through the bindings registered under `app/Providers`. A key nothing binds, or one two providers bind to different classes, still draws nothing.
- **`second_hop` takes `'class'`.** The walk then reads every traceable method of a statically-called class, not only the methods those calls name — measured at ~8.0s against the default's ~4.5s on a 4,000-file application. `true` and `false` behave exactly as before, and `'methods'` is rejected so each scope keeps one spelling.
- **Self-referential dispatches resolve to their declaring class.** `self::dispatch()` and `static::dispatch()` no longer produce a node named after the literal keyword. `parent::`, a file declaring more than one class-like, and a trait are refused rather than guessed at, and `new self()` draws nothing while `new static()` keeps its edge.
- **A documentation site**, published at [sandermuller.github.io/richter](https://sandermuller.github.io/richter/), including a troubleshooting symptom index and an `llms.txt` for agents.

### Compatibility

No breaking changes. Every addition is additive to the graph, and the `--json` contract is unchanged.

Two things to expect on the first run after upgrading:

- **The graph cache rebuilds once.** `FORMAT_VERSION` moves 20 → 22. An older entry under-reports the reach this release adds, so it is discarded rather than served.
- **Impacted counts and risk levels can rise** wherever code reads relations, since a relation the graph previously ignored now carries reach. Calibrate against a benchmark corpus before changing `risk_thresholds`; a level that shifts right after an upgrade is a coverage change first. See [Risk levels](https://sandermuller.github.io/richter/risk-levels).

New edge types appear in `richter:trace` chains and in graph output: `loads-relation` for a relation a body walks or an eager-load path names, and `relation-to-model` for the model a relation returns.

### Internal

- The provider scan that feeds container bindings is shared with the facade lane, so `app/Providers` is walked once rather than twice.
- The MCP server inherits its `$tools` type from the framework instead of narrowing it.
- Rector's `EncapsedStringsToSprintfRector` is no longer skipped.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.36.0...v0.37.0

## v0.36.0 - 2026-08-14

A class you construct is a class you depend on, and the graph now says so.

### Added

#### `new SomeClass(...)` links the building method to that class's constructor

A value object, DTO or element class with no route of its own had no caller in the graph at all — not because its caller was exotic, but because no lane drew the edge. A diff landing on such a class reported `0 entry points, 0 impacted, LOW`: the same thing a change that affects nothing reports.

Sampling one production codebase put roughly **one app class in six** with no graph node. The rate is not the problem; the interaction is. A missing node only matters when a diff lands on it, and then it takes the whole report to zero rather than degrading it.

**The edge targets the constructor, not the class.** That is what keeps it affordable. A class-level link would make every method of a widely-constructed class reach every place that builds one — the over-reporting shape that trains reviewers to skip the check. Depending on a constructor is the narrower and truer claim: changing it changes what every construction site gets, and changing some other method of that class does not.

Measured on a 1,613-file corpus, the graph grows 1.5% in nodes and 2.3% in edges.

An unqualified name that resolves against the file's own namespace without existing — `new DateTimeImmutable()` missing its import reads as `App\Services\DateTimeImmutable` — draws nothing rather than inventing a node. That rule was already carrying the static-call lane and is now shared between them.

### Documentation

**Some unfollowable dispatch sites cannot be cleared from the application side.** A named constructor on the job's own class stays listed however the call site is rewritten, because nothing proves the method returns an instance of its own class. Since one site is enough to make every `richter:affected-tests` run report `not determinable`, a project whose remaining sites are all of that kind gains nothing by restructuring the others. `docs/affected-tests.md` now says so — worth knowing before that work is planned.

### Upgrading

**Your cached graph rebuilds once on first run.** `GraphCache::FORMAT_VERSION` moves 19 → 20. The new edges are purely additive, so a graph written by 0.35.0 lacks that reach and under-reports until it is rebuilt.

No configuration changes.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.35.0...v0.36.0

## v0.35.0 - 2026-08-14

A dispatch whose job the same method just constructed is not an unfollowable dispatch, and this release stops counting it as one.

### Fixed

#### A job the dispatching method built itself no longer blocks test selection

```php
$job = new SendInvoice($order);
dispatch($job);












```
That was recorded as a dispatch whose target could not be followed — and the taint is global, so one of them makes every `richter:affected-tests` run report `not determinable` and fall back to the full suite. The graph has carried the edge for this shape all along: the instantiation is in the same method, right above the dispatch. The site pointed at a place to restructure where nothing was hidden and nothing needed restructuring.

The bar for "provably this job" is deliberately high, because the cost of getting it wrong is a selection that omits a real test. The method has to write that variable **exactly once**, at the top level of the method, before the dispatch. Everything else keeps the site:

| Shape | Why it still counts |
|---|---|
| `if (…) { $job = new SendInvoice(); }` | Assigned only on one path — the variable may still hold what it held before |
| `$job = new SendInvoice(); $job = $factory->make();` | Reassigned |
| `$alias = &$job; $alias = …` | Rebound through a reference |
| `foreach ($queue as $job)` | Rebound by the loop |
| `function (…) use (&$job)` | Capturable by reference |
| `public function handle($job)` | A parameter of the same name |

The one residual is a callee taking the variable by reference and replacing it between the construction and the dispatch, which needs that callee's signature to see.

#### `dispatch(SomeJob::for($x))` draws its edge

A named constructor names its class in the receiver, so a change to that job now reaches the dispatching member. The site stays listed: nothing in this pass proves the method returns an instance of its own class, and a wrong "resolved" would drop a real target from a selection.

#### Reach that does not count is now reported

A change to a hub trait used to report `0 entry points, 0 impacted, LOW` — the same thing a change that
affects nothing reports — while `richter:impact` on that very member listed every using class.

`uses-trait` and `override` are excluded from the impacted count deliberately: fifty using classes must
not decide a risk level. That has not changed. What changed is that excluding them from the count is no
longer taken as a reason to exclude them from the report. They appear on their own line — in the text,
markdown and HTML reports, and as `traitAndOverrideReach` in `--json` and the MCP tool — out of the
count and out of risk, exactly as `relatedModels` has worked since 0.26.0.

A class that also arrives by a real call stays where it was: in the impacted number, not in this list.

#### A level computed over nothing says so

When not one changed file could be placed in the graph, the report now says that beside the level.
`UNRESOLVED` already appeared next to each file, but the bottom line read `Risk: LOW`, and the bottom
line is what gets read.

The level itself is unchanged. Risk is a function of the graph by contract, and a report that withheld
one would break every `--fail-on` in CI.

### Performance

**The graph build is about a quarter faster.** Every command pays that build on a cache miss, and a
diff is a miss by definition, so this is the wall-clock cost of a normal run.

Measured over a 1,613-file synthetic corpus: 697 → 530 ms, with the built graph byte-identical. The
two changes that carried it were duplicated work rather than slow work — a php-parser instance was
being constructed for every file under `app/`, and the per-file node buckets were collected by
descending each tree a second time after name resolution had just walked it. The rest is work that
was being done per name and only needed doing once, plus three tracers that now ask a cheap question
of the raw source before walking a file at all.

Nothing about the graph changes: same nodes, same edges, same order, asserted rather than assumed.

### Upgrading

**Your cached graph rebuilds once on first run.** `GraphCache::FORMAT_VERSION` moves 18 → 19, and this one is stale in both directions: a graph written by 0.34.1 keeps sites this version does not record, and lacks the edges a named constructor now draws.

`traitAndOverrideReach` is a new key in the `--json` payload and the MCP `detect-changes` output. It is
additive — every existing key keeps its position and meaning — but anything asserting on the exact key
set will see it.

No configuration changes.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.34.1...v0.35.0

## v0.34.1 - 2026-08-14

Two follow-ups to 0.34.0's refusal reasons, both from using them.

### Fixed

#### `--profile` printed nothing when there was nothing to build

A diff with no PHP under `app/` returns before the graph is ever built, so the flag produced no table at all — which reads as a broken flag rather than as an empty diff. It now says what happened:

```
Build profile: nothing was built — the diff holds nothing the graph is built from.













```
On stderr, like the table it stands in for, and before the payload in `--json` mode so stdout stays one document. Building anyway was the alternative, and it would make a no-op run pay for an analysis nothing asked for.

#### `no-change` did not say what it was comparing against

The reason means every hashed input matches the cached graph, and the cached graph is whatever the last build stored — not any git ref. That is easy to walk into from a tree that genuinely changed: re-running an experiment that re-applies the same edit reproduces content an earlier run already built, so `git diff` shows a change while the cache holds its graph already. The refusal is correct, but the reason read as though it could not be.

The detail line now names the comparison, and the README covers both shapes as one rule: to measure a scoped build, make the edit new, and make it after the run that warmed the cache.

### Documentation

An event named by a class constant resolves in **any** declaration form, including a typed one and several names in one `const` statement:

```php
public const string
    SUBTITLE_CHANGED = 'subtitle-changed',
    SUBTITLE_DELETED = 'subtitle-deleted';













```
That always worked. 0.34.0's note described the narrow case, and a reader with this style would reasonably conclude their code was not covered.

### Upgrading

Nothing to do. No configuration changes, no cache rebuild — `GraphCache::FORMAT_VERSION` stays at 18.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.34.0...v0.34.1

## v0.34.0 - 2026-08-14

0.33.0 taught `--profile` to name the analysis path a build took. It turned out that naming the path answered only half the question anyone asks of an incremental rebuild.

### Added

#### `--profile` says why a scoped rebuild did not run

The label reads `full` for eleven different reasons, and they call for opposite responses. Nothing cached yet is an ordinary first run that resolves itself. A stored graph this version cannot revive repeats on every run, forever. A precondition that genuinely fails is a fact about the project. From outside the process all three looked identical, so "the scoped rebuild never engages" was not a diagnosable report.

`--profile` now prints the reason under the timing table, with a sentence naming the specific input:

```
Build profile (forced rebuild):
  brain-analyze (full)       1.21s  31.4%
  …
  total                      3.85s
  no scoped rebuild: non-app-change
    config/services.php differs from the cached graph and sits outside app/














```
Where a changed file is absent from the stored graph's provenance, the reason also prints a provenance path sharing its basename. Two forms differing only by prefix — a resolved path against an unresolved one, a symlinked project root — otherwise look exactly like a file the graph has never seen, and the two have nothing in common as problems.

One reason is worth knowing in advance: **warming the cache and then profiling the same tree reports `no-change`**, whatever the project looks like. `--profile` refuses the cache hit so there is a build to time, but the stored record still matches the tree byte for byte, and a scope of zero files would re-emit the previous graph unchanged. To see a scoped build, the edit has to come after the run that warmed the cache.

#### An event named by a class constant is no longer an unfollowable dispatch

0.33.0 stopped counting `$this->dispatch('some-event')` — a Livewire browser event, never a job. The same event named through a constant still counted, and the taint is global: one unfollowable dispatch makes every `richter:affected-tests` run report `not determinable`.

`$this->dispatch(self::SOME_EVENT)` is now read the same way, when the dispatching class itself declares that constant as a string. Nothing beyond that: a constant inherited from a parent, one read off another class, or a `static::` reference whose value a subclass can replace all stay listed. Each would need what this pass cannot see, and guessing risks dropping a genuinely unfollowable dispatch — the one error direction that costs a project real test coverage.

Constants are matched against the class that declares them rather than by name across the file, so a file declaring several classes resolves each one's constants for its own methods.

### Upgrading

**Your cached graph rebuilds once on first run.** `GraphCache::FORMAT_VERSION` moves 17 → 18. Resolving a constant-named event shrinks the recorded site list for unchanged files, and nothing the cache fingerprint hashes changed — so without the bump a graph written by 0.33.0 would keep sites this version does not record, and the blocked test selection would survive the upgrade.

**Two internal signatures changed.** `CodeGraphBuilder::buildDetailed()` takes a `ScopedRebuildDecision` as its fourth argument instead of a list of paths, and `GraphCache::mergeBase()` returns a `MergeBase` instead of an array or `null`. Both exist for richter's own graph cache, both arrived in 0.32.0, and both are now marked `@internal`. Code going through the commands, the MCP server, or `CodeGraphBuilder::build()` is unaffected. Note that `^0.33` does not resolve this version, so no project receives it without asking.

No configuration changes.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.33.0...v0.34.0

## v0.33.0 - 2026-08-14

0.32.0 taught `richter:affected-tests` to name the dispatches it could not follow. The first thing that revealed was how many of them were not dispatches at all.

### Fixed

#### Two shapes were blocking test selection for no reason

The taint from an unfollowable dispatch is global: one of them makes *every* run report `not determinable`, so the full suite runs. Two of the shapes counted as unfollowable were never hidden in the first place.

**A Livewire component event.** `$this->dispatch('close-modal')` is Livewire's browser-event dispatch, a different method that happens to share a name with the queue one. `DispatchesJobs::dispatch()` takes a job *object*, so a string literal can never be one. These were counted, and unlike a genuinely dynamic dispatch there was nothing a project could do about it: the release's own advice is to restructure the dispatch into a followable form, and a browser event has no followable form because it was never a job.

**An inline closure.** `dispatch(function () { … })` queues the closure itself, and its body sits in the same source the tracers already read, so the work it does already appears as edges out of the dispatching member. Nothing is hidden and there is no target to name. The same now holds for a closure inside a `Bus::chain([...])`.

Reported together as roughly two-thirds of the blocking sites on one production codebase — that is, two-thirds of the cases where the remedy the previous release proposed did not apply.

#### `--profile` could not show the path it was extended to report

0.32.0 added the analysis path (`full`, `scoped`, `scoped-rejected`) to the `brain-analyze` line so you could tell whether the partial rebuild engaged. It could not: `--profile` forced a fully fresh build, which discards the merge base a scoped run needs, so the path was `full` by construction.

It now refuses the cache *hit* — a hit leaves nothing to time — while still reusing the stored merge base, so both the timings and the label describe the build the project actually gets. `--no-cache` alongside `--profile` still measures a cold one.

### Added

#### `--head` analyses a committed tree

`richter:detect-changes --head=<ref>` and `richter:affected-tests --head=<ref>` analyse that ref's committed tree instead of the working tree, for a run in a dirty checkout whose uncommitted work is not the subject. `--head=HEAD` resolves to the commit, so it means the last commit rather than the working tree.

Untracked files are ignored in this mode rather than blocking the selection, since a file never `git add`-ed cannot be part of a committed tree.

#### `unresolvedDispatchSites` in the `affected-tests` payload

The reason string names the sites, but it caps at 15, and that capped sentence was the only place the CLI reported them — a project with more than fifteen could not reach the rest from `--json` at all.

`--json` and the MCP tool now carry the full list as `{file, line, dispatcher}`, uncapped, while the reason stays capped for readability. It lists what blocked *this* selection and is empty when nothing did; for every unfollowable dispatch in the project regardless of the diff, read the `richter://graph/stats` resource.

### Upgrading

**Your cached graph rebuilds once on first run.** `GraphCache::FORMAT_VERSION` moves 16 → 17. The two dispatch fixes above shrink the recorded site list for unchanged files, and nothing the cache fingerprint hashes changed — so without the bump a cache written by 0.32.0 would keep sites this version does not record, and the blocked selection this release fixes would survive the upgrade.

No configuration changes. `AffectedTests::select()` gained a parameter, which affects only code calling it directly rather than going through the commands or the MCP server.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.32.0...v0.33.0

## v0.32.0 - 2026-08-13

Two features that had been waiting on each other's cache-format change, shipped together so the rebuild happens once: an unfollowable dispatch now tells you where it is, and a warm cache can rebuild only the part of the graph that changed.

### Added

#### An unfollowable dispatch names the line it sits on

A dispatch whose target cannot be seen statically — a variable, a factory call, a closure — hides an edge, so `richter:affected-tests` reports the selection as not determinable and you run the full suite. That was the right verdict and an unusable one: the reason said a dispatch somewhere could not be followed, and left you with nowhere to go.

It now names every site:

```
the graph contains job dispatches that could not be followed:
app/Jobs/Fanout.php:88 (App\Jobs\Fanout::handle), app/Services/Importer.php:12 (App\Services\Importer::run)
















```
The dispatching member and the line were already known at the point the old code incremented a counter, and were simply discarded. The site is the dispatch statement, so two opaque items of one `Bus::chain([...])` are one place to look rather than two; long lists cap at 15. The MCP graph-stats resource carries the same list as `unresolvedDispatchSites`.

**There is deliberately no way to acknowledge a site and have it stop blocking.** Suppressing it would assert that the hidden edge is harmless, and a wrong assertion runs fewer tests than it should — the one direction this command's exit-code contract exists to prevent. What the named site makes possible is the actual repair: restructuring the dispatch into a form the tracer can follow. A project that genuinely needs a dynamic dispatch keeps running its full suite, correctly.

#### A warm cache can rebuild only what changed

Laravel Brain 2.4.0 can re-trace the controllers a changed file declares and merge the result into a previous graph. Richter could not use it, for a structural reason: a cache hit builds nothing, and a miss left no previous graph to merge into, so the merge base never existed.

The cache entry now stores Brain's graph and a record of the inputs it was built from. A miss can then ask *which* inputs differ rather than only whether any did. When every difference is a changed file under `app/` — nothing added, nothing deleted, no config or package change, nothing outside `app/` — the rebuild is scoped to those files. Everything else is a full build, as before.

On a 541-file synthetic corpus, `brain-analyze` went **52 ms → 27 ms** for a changed controller, with the merged graph identical to a full build's. Two things are worth knowing before you expect it:

- **It helps a changed file that declares a controller.** A diff touching only services, models or jobs has no controllers to re-trace and is refused — at no extra cost, measured at 52.5 ms against the full path's 52.3 ms.
- **`brain-analyze` is a minority of the build.** About a quarter on that corpus, so roughly −6% end to end there. A real application should see more of it, since this corpus over-weights richter's own per-file work.

`--profile` now names which path ran (`full`, `scoped`, or `scoped-rejected`) on the `brain-analyze` line, because "did it engage?" is the first question anyone asks.

Every ambiguous case is refused. A wrong refusal costs one full build, which is what every run cost before; a wrong acceptance would produce a graph quietly missing edges. `--no-cache` still forces a full analysis.

### Fixed

#### A corrupt cache entry could quietly drop the unfollowable-dispatch warning

A cache file whose stored dispatch data was malformed but whose fingerprint still matched was served with that data silently treated as "none". The unfollowable-dispatch taint disappeared with it, and a test selection that should have reported *not determinable* reported a narrower set instead — the one failure direction `affected-tests` is built to avoid. A malformed list now fails the whole read and the graph is rebuilt, which is what a malformed edge list already did.

The same read path now also rejects a stored Brain graph that decodes to fewer nodes or edges than its own payload claims, rather than accepting a structurally valid but short graph as a merge base.

### Upgrading

**Your cached graph rebuilds once on first run.** `GraphCache::FORMAT_VERSION` moves 15 → 16, covering both features above — that is why they ship together rather than in consecutive releases. No action needed; the rebuild is automatic and happens once.

No configuration changes. Two internal constructor signatures changed (`CodeGraph`, and `AffectedTests::select()`), which affects only code constructing those directly rather than going through the commands or the MCP server.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.31.0...v0.32.0

## v0.31.0 - 2026-08-13

A count that could exceed the list it describes, traced to its cause and fixed. The correction moves risk levels on one narrow shape, so read the upgrading note if you gate CI on `--fail-on`.

### Fixed

#### `scoredEntryPoints` could exceed the printed entry-point list

Reported from production dogfooding across three releases and not reproducible until the report arrived with a reading of the code beside it. The conclusion was right; the cause turned out to be one layer down.

A graph walk never reports its own seeds as reached — a change is not something the change reaches. When a low-confidence `high` is re-scored, the walk narrows to the precisely-seeded half of the diff, and at that moment the *other* half stops being seeds. A co-changed entry surface — a Livewire, Filament or Nova component that also happens to sit upstream of the precisely-changed code — was then counted as a surface the change reached. The printed walk had correctly suppressed it, so the scored count came out above the printed one.

The rescore now narrows which nodes it walks *from* without narrowing which nodes count as changed. Nothing in the diff can read as reach.

Two things made this survive earlier releases. The invariant was argued in a release note before anything checked it, and the test written to check it later used fixtures that could not produce the shape — it passed both before and after the fix. And the memo that caches these walks was keyed on the seed set alone, so the unsuppressed answer computed earlier in the same run could be handed straight back to the rescore. Both halves now have a test that fails when the fix is removed.

### Changed

#### A low-confidence `high` can now be capped where it previously stood

The counts the rescore produces are what decide whether a coarse `high` survives, so removing co-changed surfaces from them can take a confirmed `high` down to `medium` — with `coarseCapApplied` reporting `true` where it reported `false`.

This is the correction working as intended: the surface that propped the level up was part of the change, not something it reached. But it is a level moving, not only a number, and a `--fail-on=high` gate that tripped on such a diff will no longer trip. It affects one narrow shape — a low-confidence `high` whose precise seeds reach a co-changed entry surface — and no other report changes.

#### `coarseCapApplied` says whether the cap fired, never whether the rescore ran

Every low-confidence `high` is re-scored; the flag reports only whether that rescore *downgraded* the level. A `high` the rescore confirms comes back with the flag `false` and still carries the rescore's counts — so `coarseCapApplied: false` never means "the printed counts are the scored ones". That was the flag a consumer checked first and reasonably misread. The reference and the JSON key table now say so directly, and point at `scoredEntryPoints` / `scoredImpacted` as the only answer to that question.

#### The risk table listed six of the eight entry-point classes

A diff that changes an entry-point class holds the level at `medium`, and the README's table named jobs, listeners, commands, observers, middleware and Livewire — omitting Filament and Nova, which the check has always covered. A reader with an admin-panel resource in their diff would not have expected the floor to apply.

### Upgrading

Nothing to do. No `GraphCache::FORMAT_VERSION` change, so no rebuild is triggered and no cached graph is stale, and there are no configuration or API changes.

If you gate CI on `--fail-on=high`, see the level-movement note above: a diff matching that one shape can now report `medium` where it reported `high`. Pin the version if you need a `--fail-on` verdict to stay comparable across the upgrade.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.30.0...v0.31.0

## v0.30.0 - 2026-08-13

A faster graph build, a claim from the last release walked back to what is actually guaranteed, and a new test that catches the class of change none of the existing ones could see. No behaviour change: the graph this version produces is byte-identical to 0.29.0's.

### Changed

#### The build stopped doing the same work twice

Three duplications, found by profiling rather than by reading. On a 1,600-file synthetic corpus the build went **864 ms → 660 ms, −24%**, measured back to back on the same machine state.

- **The member-declaration pass re-parsed every app class file.** The pass that links each class to the members it declares read and parsed every file a second time, straight after the tracer pass had parsed all of them. It now derives those edges from the AST already in hand and carries the map across the process boundary with the rest of the tracer branch's output, falling back to a read only for a file the tracers never visited. That phase went 172 ms → 60 ms.
- **Two tracers walked each method more than once.** `NodeFinder::findInstanceOf()` is a full traversal, and the reference lane ran three per method — names, method calls, static calls — while the dispatch lane ran two, for calls and instantiations. Each now descends once and buckets what it finds.

The parse itself is now the floor: php-parser's own cost is most of what remains, so this is close to the end of what can be won without changing what the build reads.

#### `scoredEntryPoints` is described as what it is, not as an absolute

0.29.0's notes stated that `scoredEntryPoints <= entryPoints` holds *by construction*. That is what the code derives, and it is now pinned by a test rather than argued in a release note — but a consumer has reported a counterexample that does not reproduce here and that the current code has no path to produce.

Rather than restate the absolute, the documentation now says what the number is: a subset of the printed entry-point list, so a report where it exceeds that list is a bug worth sending in. If you have one, the JSON payload and the changed-file list are what would settle it.

### Added

#### A test that pins the whole graph's shape

Every lane already had tests for the edges its own tracer draws. None of them can see the merged result: an edge a lane stops drawing, one it starts drawing, or one whose type changes leaves every per-lane test green so long as that lane's own fixture is unaffected.

The fixture project's complete edge list is now committed and compared on every run. It is what made the three optimisations above safe to take, and it is why this release can claim byte-identical output rather than merely intend it.

It also corrected a misconception while being written. The test initially claimed to pin edge *order*; replacing its assertion with one that actually proved that — rendering one edge set in two orders and requiring the results to differ — failed, because `CodeGraph` sorts every edge set canonically in its constructor. That is deliberate: it is what stops a cache-revived graph from tie-breaking its walks differently to a fresh build, so `--explain` cannot show one chain on a warm cache and an equal-length different one under `--no-cache`. The test guards content, not sequence.

### Upgrading

Nothing to do. No `GraphCache::FORMAT_VERSION` change, so no rebuild is triggered and no cached graph is stale. No configuration changes, no API changes, and no report should differ from 0.29.0 in any way other than arriving sooner.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.29.0...v0.30.0

## v0.29.0 - 2026-08-12

Two ways the report named a surface that does not run the changed code, or failed to name one that does. Both move risk levels, and neither touches the graph. Sourced from a consumer usage audit that put 0.28.0's own new output to work: one of these was found by following up an observation the reporter had filed as "not a defect".

### Fixed

#### The coarse cap no longer rescores against association reach

0.26.0 took association reach out of the entry-point count, on the grounds that a screen sitting beside the changed model is not a caller of it. One path kept walking those edges: `riskInputs()`, which is what the low-confidence cap consults when it decides whether a coarse `high` survives. So the reach that had been removed from the level could still hold a change at `high` through the back door — the same over-reporting that split was for, on the one path it did not cover.

It had a second, visible symptom. `scoredEntryPoints` could come out **above** the printed count, which inverts what the report says that number is: the narrower set the level was measured against. With this fix `scoredEntryPoints <= entryPoints` holds by construction, since the rescore now walks the same excluded set on a subset of the seeds.

The exclusion is fixed rather than a parameter. The memo behind these walks is keyed on depth and the seed set, so two callers asking with different exclusions would alias onto one entry.

If you have tuned `risk_thresholds`, this is worth a look: on a report where the cap binds, the scored counts get smaller, so a change that sat at `high` can now report `medium`.

#### A Nova resource is an entry surface

`\Filament\` was in the entry-surface vocabulary and `\Nova\` was not, though the two are the same shape: an admin panel built from resource classes the framework routes to, with no route in the application's own files. On an application whose admin is Nova, every change to it reported reaching nothing at all.

Added to both vocabularies — the entry-class one, so a changed resource self-lists as its own surface and carries the `medium` floor, and the UI-component one, so a resource *upstream* of a change counts as a surface rather than a plain caller. Same treatment Livewire and Filament already had.

Two deliberate non-changes:

- **Not gated on Nova being installed.** The test is a substring match on the application's own `App\Nova\` namespace, not on the vendor package. Filament is not gated either, and a project that names a namespace after a panel it does not use is not a case worth a runtime check for.
- **`Nova` is not added to the `entry_point_roots` defaults.** Measured against a real application, adding it changes no report — those classes already arrive through ordinary call edges. Tracing was never what was missing; the vocabulary was. This is now written down in the configuration reference, since it is a reasonable thing to try first.

### Also in this release

The setup skill's replay warning names the config. A tracked `config/richter.php` is reverted by the same checkout that reverts `composer.json` and `composer.lock`, so a tuned `risk_thresholds` silently does not apply during a replay and every level it reports comes from package defaults. Fine when comparing versions against each other; misleading when the calibration is the thing being checked.

### Upgrading

No `GraphCache::FORMAT_VERSION` change — both fixes are analyzer-side, so no rebuild is triggered and no cache is stale.

Risk levels can move in both directions: down where the cap now binds on a low-confidence report, and up where a changed Nova resource picks up the `medium` entry-class floor. Nothing else about how a level is computed changed.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.28.0...v0.29.0

## v0.28.0 - 2026-08-12

A recall fix for applications that still route through the legacy string action, the second half of the view lane, and the report finally naming the counts its risk level was decided on. Sourced from a consumer usage audit — one of whose findings turned out to be wrong, and the reproduction that disproved it found the real bug beside it.

### Fixed

#### A legacy string route action no longer strands its controller

`Route::get('/x', 'FooController@bar')` reaches the graph under-qualified. The bare-basename form was already rewritten onto its FQCN. The partially qualified one — what a `->namespace('Foo')` group produces when the provider supplies the root — was not, because it is FQCN-shaped and survives canonicalisation as a node in its own right.

That is worse than an id nobody can open. Both nodes exist. The route reaches the phantom while every code edge hangs off the real class, so the controller reports no entry surface, the security annotations never arrive, and the payload-parity request lane cannot fire for it. Nothing in the report looks broken; the chain is simply cut, and a change to such a controller reads as inert.

The rewrite now narrows on the namespace the id did carry, so a duplicated basename still resolves when exactly one candidate ends that way — which is the common case, since the missing part is a shared root and the surviving part is what tells the candidates apart. An id that already names a controller is left alone outright: a deeper class can nest another's whole path, and the boundary test on its own would move a route off the class it had correctly reached.

An id whose basename matches no controller, or matches several equally, stays verbatim rather than claiming a class.

#### A request-parity finding names the verb it actually saw

The sentence was written for the form-request lane and said the consumer file "posts to" the route. The matcher was never POST-only — a `params:` object on a GET route matches — so a query-parameter finding read "posts to GET /posts", contradicting itself in a report whose value rests on being trusted. The route's own verb is printed now, and none is assumed.

### Added

#### The view lane reads the `$view` property, not only the `view()` call

0.26.0's lane named page components among its targets, and covered them only where they call `view()` themselves. A page component commonly does not: it declares `protected static string $view = 'pages.settings';` and a base class renders it. Reading calls alone covered none of those, so their Blade files kept reporting UNRESOLVED.

The edge is anchored on the class rather than a member — there is no method to name, and inventing one would send a reviewer to a symbol that does not exist. The same no-guess bar as the call form applies: a literal string, and only when the Blade file exists in the project.

#### `scoredEntryPoints` and `scoredImpacted` — the counts the level was decided on

`risk_thresholds` shipped with guidance to calibrate against the counts your own reports print. On some reports those are not the counts the level was measured against, which makes the instruction silently false exactly where a reader follows it — and following it there sets the bar around an order of magnitude too high, collapsing everything to `medium`.

Two things pull the counts apart:

- A low-confidence `high` is re-scored against the precisely-seeded subset alone. `coarseCapApplied` already reported that a substitution happened, without saying what was substituted.
- The entry-point list gains self-listed and frontend surfaces **after** the level is scored. Those are deliberately excluded from `risk` — a frontend change does not alter backend behaviour — but it means the printed count can exceed the scored one on a report that is not low-confidence at all.

Both counts are now on every report and in the JSON and MCP output. The text, markdown and HTML reports name them only where they differ from the printed counts; a line repeating the same two numbers on every run teaches its reader to skip it. The calibration guidance in the README, the configuration reference, the published config file and the setup skill now all point at these.

No verdict moves: the level is computed exactly as before.

### Also in this release

- The config comment explains why `benchmark:add` writes `'max_risk' => 'high'` on a signal fixture — the cap is checked on every fixture and simply does nothing at the default — and documents the ignore form for a field validated inline (`App\Http\Controllers\PostController::store::subtitle`).
- The setup skill's post-checkout warning gains the case that disabling the hook does not cover: replaying a ref reverts `composer.json` and `composer.lock` while `vendor/` keeps the version under test, so a version bump made on the branch is silently undone. Measurements stay valid; the manifest is what to re-check.

### Upgrading

`GraphCache::FORMAT_VERSION` moves 14 → 15, so the first run after upgrading rebuilds the graph. A stale entry lacks both new sets of edges: the route chains that used to end on a phantom controller, and the views a page component declares rather than renders.

No configuration changes are required. If you have tuned `risk_thresholds`, re-read the calibration note — the numbers to tune against are now printed when they differ from the ones beside them.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.27.0...v0.28.0

## v0.27.0 - 2026-08-12

Two coverage gaps closed, and the risk-threshold guidance corrected twice — once for citing evidence it should not have, and once for replacing it with reasoning that did not hold. Sourced from a consumer usage audit, which confirmed 0.26.0's changes measurably and found the advice that needed fixing.

### Added

#### Validation written inline is covered by the payload-parity lane

The request side of payload parity read a form request's `rules()` and nothing else. A form request is the documented convention, not the only place validation lives: an action validating a handful of fields commonly does it in the controller, and dropping a key from that array stops validating the field exactly as dropping it from `rules()` does — silently, with the frontend still sending it into nothing.

Both call forms are read:

- `$request->validate([...])`
- `$this->validate($request, [...])`, but only where the class pulls in `ValidatesRequests` itself. `validate` is an ordinary method name, and a class with its own would otherwise have an unrelated options array read as request fields.

Findings anchor on the fully qualified member holding the call, not the file, so a controller's other actions are not implicated in a field one of them dropped, and two classes in one file cannot overwrite each other. The `ignore` entry takes the same shape against that member (`App\Http\Controllers\PostController::store`).

Enumeration is as strict as the `rules()` parse. A method passing rules it cannot read is skipped entirely — reporting its base fields as removed would assert something unknowable, since the variable may still hold every one of them. An empty array is the opposite case and counts: it enumerates successfully to nothing, which against a base that validated something is the removal of everything, as is deleting the call outright.

### Fixed

#### Static calls no longer hang off a class that has no name

The last lane using an anonymous class as an edge source. Taking the file's primary class instead invented `Class::method` ids for members that need not exist — a caller a reviewer opens and cannot find. 0.26.0 fixed this for the config-registry and view lanes and left this one; the shared helper introduced there makes the same fix small here.

Calls inside such a class are attributed to the method that builds it, which is the real owner. Scope-relative receivers are dropped rather than carried over: `self::`, `static::` and `parent::` inside an anonymous class name that class, so resolving them against the enclosing one would draw a confidently wrong edge — worse than none, because the target exists and the chain reads as real.

#### The risk-threshold calibration advice was wrong, and its first correction was too

0.26.0 shipped `risk_thresholds` with guidance to raise them until a routine change reports `medium`. Read as "move the `medium` bar", which is the reading it invites, that can demote real defects to `low` — the level a reviewer skips.

The advice now rests on how the levels are actually decided rather than on any claim about where defects land. Raising the `high` thresholds leaves the `medium` test untouched, so the most it can do is move a change from `high` to `medium`. Raising `medium` is the only edit that can push something to `low`. Move `high` first for that reason alone.

Whether moving `medium` would cost you a real defect is not something the package can assert on your behalf: impacted counts measure graph reach, not how large a change is, so a one-line fix in a widely called method can outrank a broad but shallow one. If you keep a benchmark corpus, running it before and after is the only check that answers it. Corrected in the README, the configuration reference, the published config file and the setup skill.

### Upgrading

`GraphCache::FORMAT_VERSION` moves 13 → 14, so the first run after upgrading rebuilds the graph. A stale entry carries edges out of the phantom `Class::method` ids and lacks the same calls under their real owner, so both directions are wrong until it does.

No configuration changes are required. If you set `risk_thresholds` after reading the 0.26.0 guidance, re-read the corrected version — raising `medium` is the case worth revisiting.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.26.0...v0.27.0

## v0.26.0 - 2026-08-12

A precision release, driven by a consumer usage audit across two production applications. 0.25.0's config-registry lane over-reported; the entry-point list counted things that were not callers; and on a large codebase the risk level had stopped discriminating. All three are addressed, and one new lane closes a recurring class of UNRESOLVED.

### Fixed

#### The config-registry lane over-reported on ordinary config reads

Shipped in 0.25.0, this lane linked a `config('x.y')` lookup to every app class `config/x.php` names, on the reasoning that cohesive config files made the file-level match harmless. `config/app.php` is the counter-example every Laravel application ships: it names app classes in its `aliases` map, so an ordinary `config('app.timezone')` fanned out into all of them and on through their real edges, multiplying the reported reach of a routine change with no true positive behind any of it.

A fully literal key needs no approximation — it is knowable at build time. It is now looked up in the config file's own returned array and draws only the app classes that key's value actually names.

- File granularity remains where the key genuinely cannot be enumerated: an interpolated key (`config("calculators.{$id}")`, the shape the lane exists for), and a file whose array is built by a loop, spread from a default, or keyed by a constant. That is the safe direction for a lane that adds reach.
- Position decides where a spread is involved: `['a' => X::class, ...$extra]` is uncertain because the spread can overwrite the key, while `[...$extra, 'a' => X::class]` is not.
- A repeated key resolves to the last value, as PHP's own array semantics do.
- A key naming a class inside a call (`env('DRIVER', Basic::class)`) still links — that default is what the application uses unless the environment overrides it.

**If you upgraded to 0.25.0, expect impacted counts to fall back to roughly their 0.24.0 levels.** That is the over-reporting going away, not coverage being lost.

### Changed

#### Entry points are callers again

An entry point in the reached list is supposed to be something that **calls** the changed code. A surface connected only through an Eloquent relation is not, and listing the two together produced the most misleading output this report can give: on one application a model change named six admin resources as reached surfaces while the routes that actually run the changed code reported no path at all.

Entry-point discovery now walks call edges only. Surfaces reached solely by association are reported in their own section — present and labelled, never silently dropped, and out of the count that drives the risk level. The impacted total has always drawn this line ("Related models (association reach — context, not risk)"); the entry-point list draws it now too.

The demoted set is deliberately narrow: `model-relationship` and `model-to-policy`, the two edge types that associate rather than invoke. `override` and `config-registry` are over-approximated **calls** — the dispatch is real, only the target is uncertain — so a surface behind one stays in the main list. Explanation chains use the same exclusions, so `--explain` can never present a relation as the reason a listed caller calls the change.

**This narrows what `entryPoints` means.** Consumers parsing the report should know:

- `entryPoints` (text, markdown, HTML, JSON, both MCP tools) no longer includes association-only surfaces.
- A new `associationEntryPoints` list carries them, in the same position across every format. It is a new key in the `detect-changes` and `impact` JSON payloads and in both MCP output schemas.
- Risk levels may drop for changes whose reached count was inflated by relations.

#### Risk thresholds are configurable

Every non-blank report captured on a large application came back HIGH — twelve of twelve, across four unrelated changes and three versions. `impacted >= 20` is a rounding error where a routine change reaches thousands of nodes, and a level that never varies trains reviewers to skip the line.

`risk_thresholds` in `config/richter.php` sets the counts at which each level steps up. They stay **absolute** rather than becoming a percentile of your graph: a gate whose meaning shifts with the repo's own distribution is not one anyone can reason about in CI. Raise them until a middling change on your repo reports `medium` — the impacted count printed on every run is the calibration data.

Defaults are unchanged, so nothing moves unless you set the key.

### Added

#### Views rendered outside a route

Laravel Brain connects a controller to `view('posts.show')` by walking the body a route led it to. A class no route resolves to — a Livewire component, a Filament page, a mailable, an action — never gets its body walked, so the view it renders has no caller and every diff touching that view read UNRESOLVED.

The render call is written out in the source, so it is read directly. Literal names only, and only when the Blade file exists under `resources/views` — a package-namespaced name (`mail::message`) resolves elsewhere and would mint a node nothing else shares. Brain's own `action-to-view` type is reused, so a controller both lanes see yields one edge rather than two hops in every chain.

#### `richter:trace --depth`

The miss message already said the walk had run out of depth; there was no way to ask the follow-up question, so "no path" and "path deeper than 6" read identically. `--depth` sets the search limit, validated before the graph is built so a mistyped flag does not cost a scan first.

### Internal

- An anonymous class is no longer used as an edge source in the config-registry and view lanes. Naming it after the file's primary class invented a member that may not exist — a caller a reviewer opens and cannot find. Its calls are attributed to the method that builds it, which is both true and openable.
- `config/*.php` gained a graph-build input in 0.25.0; that still holds, so a config change costs one rebuild.
- The `PUBLIC_WRITE` auth cross-check is **not** superseded by Brain 2.4.0. Brain added a class-basename match, which its own source describes as "a name match, not a verified subclass check" — a middleware subclass named anything other than the framework's own basename still draws the false finding that richter's ancestry walk catches.

### Upgrading

`GraphCache::FORMAT_VERSION` moves 12 → 13, so the first run after upgrading rebuilds the graph. The config lane's change removes edges and the view lane adds them, so a stale entry would be wrong in both directions.

No configuration changes are required. If you parse the JSON or MCP payloads, see "Entry points are callers again" above for the one narrowed key and the one new key.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.25.0...v0.26.0

## v0.25.0 - 2026-08-12

Richter follows config-keyed class registries now, and several places where the report claimed more coverage than it had were corrected. Sourced from a consumer usage audit across two production applications.

### Added

#### Config-registry edges

A subsystem dispatched by looking a class up in `config/x.php` was reachable from nothing. No static call connects `config("x.{$id}")` to that file's array of `::class` constants, so every class in the registry had no caller, and a change to one reported zero entry points however central it was. Richter links the lookup to every app class the config file names.

- Both call forms are recognised, including the interpolated key this shape exists for (`config("calculators.{$id}")`): the key is dynamic, the file is not. A fully dynamic argument (`config($key)`) names no file and draws nothing rather than guessing at one.
- The match is at file granularity, deliberately. A registry's keys are frequently built at runtime; its class list never is.
- The fan-out is excluded from the risk count, on the same grounds as `override`. It carries reach and entry-point discovery, which is the whole point, without letting one edit to the resolver saturate the level on breadth alone.
- Only app classes are linked. A vendor class named in a config file is reached from everywhere, and linking one would attach the framework to any method that reads a config value.

#### `config/*.php` is a graph-build input

Nothing the build read lived under `config/` before this lane, so adding a class to a registry would have served the previous graph and left the new class still reporting no callers. Every config change now costs one graph rebuild. That is the deliberate trade: a false miss costs a rebuild, a false hit would be the falsely reassuring stale report this package exists to prevent.

#### Changed files no lane analyses are named

A diff of nothing but a stylesheet, a CI workflow and a lockfile printed `No changed PHP files under app/`. That is accurate about the analyser and reads as "no impact" to whoever changed those files. The count is now named on stderr beside the report:

```text
Note: 2 changed file(s) are outside the analysed scope (not PHP under app/, a Blade view, or a configured frontend root) and were not analysed: resources/sass/app.scss, vapor.yml























```
Stderr, like the untracked-file note, so `--json` and `--markdown` stdout stay exactly the report. Frontend sources the configuration declines to scan are not counted: generated Wayfinder output under `frontend.generated_paths` and `.d.ts` declarations were silenced on purpose, and a note that fires loudest on regeneration churn is one people stop reading.

`ChangedSymbols::resolveWithScope()` and `FrontendChanges::isDeliberatelyIgnored()` are the new public entry points behind it. `ChangedSymbols::resolve()` is unchanged and still returns the changed members alone.

### Changed

#### UNRESOLVED wording, in all three renderers

A changed file could read "in an area not yet graphed" while having a graph node and being listed as an entry point two lines below. That is the job-flip lane, where the file is placed but its dispatchers could not be enumerated. All three renderers now say the reach could not be fully determined, which holds in both lanes. If you match on the report text, these strings moved:

| Renderer | Before | After |
|---|---|---|
| text, per file | `(UNRESOLVED: coverage incomplete for this area)` | `(UNRESOLVED: reach for this file could not be fully determined)` |
| text, summary | `(some changed files are in an area not yet graphed …)` | `(some changed files could not be fully placed …)` |
| markdown, table cell | `⚠️ **UNRESOLVED** (not placed in the graph)` | `⚠️ **UNRESOLVED** (reach not fully determined)` |
| markdown / HTML, note | `… could not be placed in the graph` | `… could not be fully placed` |
| HTML, badge | `UNRESOLVED (not placed in the graph)` | `UNRESOLVED (reach not fully determined)` |

The JSON payload is unchanged: `coverage` still carries `analyzed` or `unresolved`.

#### Risk levels are documented as version-sensitive

The thresholds are absolute, which is what keeps `--fail-on` predictable, and it has a consequence worth knowing before gating CI on it: every release that teaches Richter to follow more edges raises the impacted-node count for the same diff, so a change that sat under a threshold can cross it on an upgrade with nothing in your application having changed. Treat a level shift right after a version bump as a coverage change first. Pin the version in CI if a `--fail-on` verdict has to stay comparable across releases.

### Fixed

#### Middleware group route counts were an order of magnitude low

The note reading `runs in middleware group 'web', which guards N routes` counted purely off graph edges, and a `route:: → middleware::<group>` edge exists only where a route file applies the group in its own `->middleware()` call. A provider that loops over route files and groups them there, the shape Laravel's own `RouteServiceProvider` ships, draws no such edge for any of them. On one application the graph knew 36 of 420. The count now comes from the application's registered route table, and falls back to the graph subset only when the run is pointed at a checkout other than the running application. That whole note exists to stop a reviewer under-sizing a change, so a number that errs downward defeated its purpose.

#### `richter:benchmark:add --control` refuses a change that already reports HIGH

A control caps the risk a harmless change may report. HIGH is the top of the scale, so such a cap asserts nothing and the case passes forever. Whoever runs this command is usually triaging a control that just went red, where pasting the green no-op is both the obvious move and the one that destroys the fixture.

#### `entry_point_roots` was documented as promoting classes

The key was described as making a directory "traced as entry points" and recommended by name for registry-dispatched subsystems. It traces, never promotes: whether a reached class counts as an entry surface of its own is a fixed vocabulary that no config key extends, so listing a directory there makes its classes reachable and countable and never entry points. Corrected in the README, the configuration reference and the shipped config file.

#### The defined-node fallback was documented as covering routes files

It claimed a `routes/api.php` change was placeable through it. Such a file never enters the changed set, so no lane sees it. It is out of scope, and now says so.

### Internal

Setup guidance warns about `post-checkout` hooks that reinstall dependencies. Comparing several refs in one session (replaying branches, walking commits, bisecting) lets such a hook install each ref's own lockfile and swap the Richter version mid-comparison.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.24.0...v0.25.0

## v0.24.0 - 2026-08-11

One new advisory lane, and a README that finally leads with what the package is for.

### Added

- **A middleware change that runs through a group is now sized.** Route middleware is resolved by *alias* and never by group. `->middleware('auth')` reaches the graph as `middleware::auth` and Richter rewrites that onto the FQCN, so an aliased middleware is connected to the routes it guards. `->middleware('api')` reaches it as a bare `middleware::api` node, and the classes inside that group are connected to nothing.
  
  The group is still not expanded into edges — mapping a global group onto every route would make each of its members report every route in the app as an entry point. But the middleware self-lists as an entry point anyway, so the report read "one entry point: the middleware itself" for a change that runs on every route in the group. The answer was wrongly *sized* rather than missing, and this supplies the size:
  
  ```text
    ! App\Http\Middleware\EnsureTenant runs in middleware group 'api', which guards 142 routes; group membership is not drawn as edges, so those routes are not in the reach above
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
  The count comes off the `route:: → middleware::<group>` edges already in the graph, so it counts endpoints only: a controller-level attachment of the same group does not inflate it. Membership is read from `$middlewareGroups` on a Laravel 10 Kernel or the `->web(append: [...])` form in a Laravel 11+ `bootstrap/app.php`. A member written as an alias resolves through the same alias map, parameters are cut first (`tenant:strict` is one alias with an argument), and a group that names another group is expanded transitively, since Laravel runs the inner group's middleware on the outer group's routes too.
  
  Silence is the answer wherever the size cannot be vouched for: a group no route references, a middleware in no group, a name that is both a group and an alias, an unreadable Kernel, or an upgraded app that kept an empty `app/Http/Kernel.php` stub beside its bootstrap groups. Advisory like the rest of the annotation family — it never moves the risk level, and the test suite pins that risk and entry points are identical with the Kernel present and absent. Full detail in [the detect-changes reference](https://github.com/SanderMuller/richter/blob/main/docs/detect-changes.md#middleware-group-membership).
  

### Changed

- **The README leads with what Richter does for you, and the reference depth moved to `docs/`.** It had grown to 636 lines, with the pitch buried under an insider comparison of what Richter adds over Laravel Brain, and the install command sitting below seventy lines of prose. It now opens with the six differentiators and puts `detect-changes` first under Usage. The annotation lanes, payload-parity mechanics, frontend scan details, JSON contract, and the full configuration table move verbatim into six files under `docs/`, which ship in the dist archive and are linked from the README. `/docs` was ignored by the original package scaffold's template `.gitignore`; that entry is gone, since nothing had ever lived there and it would have 404'd every one of the new links.

### Internal

- Plan 051 is closed. Levers A and C — routing Richter's parsing through Brain's shared parse cache, and a per-file tracer cache — were deferred until Brain's performance work released. It has, and the win expired anyway for an unrelated reason: the tracer branch now runs in a child process, where Brain's `analyze()` never ran, so the re-parse those levers remove is cross-process and no in-process cache reaches it. Measured on a 1,340-file synthetic application rather than argued: Brain parses 262 of them (the route-reached fraction, and the ceiling on convertible cache hits), its parser is 6% *faster* cold so the swap is never a regression, and the whole change is worth 2% of build time on the default path. The numbers are kept in the plan file for whoever asks again.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.23.0...v0.24.0

## v0.23.0 - 2026-08-10

Two silent-failure shapes that richter used to miss: a call through an application facade, and a validation field a frontend still sends after the rule was dropped. Both were found by auditing what Laravel Brain covers that richter does not — and both turned out to be places where the overlap looks like coverage and is not.

### Added

- **A call through an application facade now reaches the class behind it.** A facade is an app class like any other, so `Reports::generate()` drew a static-call edge to `App\Facades\Reports::generate` — a member the facade does not declare — and nothing linked it to the class its accessor names. Changing that class reported no callers at all, while every call site sat in a file richter had parsed. The new `facade-resolves-to` edge carries the facade member over to the concrete's member, and the concrete joins the second-hop walk for the same reason a static-call target does: otherwise its node exists and nothing reads its body.
  
  The facade member is bridged, not rewritten away. That is what makes a change to the facade itself — a repointed accessor — reach the callers, and what lets `richter:trace` show that the call goes through a facade.
  
  Scope is deliberate. `getFacadeAccessor()` returning `Concrete::class` is carried over; returning a container key (`return 'reports'`) draws nothing, because resolving it would need a string-keyed binding registry richter does not keep, and the wrong concrete sends a reviewer to the wrong file. An accessor naming a vendor class, and a facade method the concrete does not declare (`__call` magic), likewise draw nothing rather than a phantom node — as does an accessor that can return two different classes, where the concrete is chosen at runtime and naming one of the two would be a guess dressed as a fact.
  
- **A validation field removed from `rules()` is flagged when a consumer still sends it.** The payload-parity family covered the response side twice — a model field never mirrored into its resource, a resource key removed while a consumer still reads it — and the request side not at all. Dropping a rule silently drops the field: it stops being validated and stops appearing in `validated()`, so the value never arrives and nothing reports an error.
  
  ```text
    ! resources/js/Pages/Posts/Create.vue posts to POST /posts and sends 'subtitle', which this diff removes from App\Http\Requests\StorePostRequest::rules() (renamed to 'sub_title'?)
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
  Matching is send-shaped where the response lane is access-shaped: an object-literal key, a `FormData`/`URLSearchParams` `append`/`set` with a literal name, a bracket write, a payload assignment. The object-literal pattern is the one the response lane names as its own false-positive class — a destructure of a response and an object literal being built are the same tokens — which is why the two lanes match separately instead of sharing a predicate. The residual cost is the mirror image, and the per-field `ignore` entry (`App\Http\Requests\StorePostRequest::subtitle`) carries it.
  
  The `rules()` parse is as strict as the resource one: a method that builds its array up, a spread, or a constant key makes the side unenumerable and the lane stays silent rather than name a field that was never removed. A dotted rule key (`items.*.name`) matches nothing on purpose — its segments appear separately in a payload, and matching the last one would fire on every unrelated `name` in the file. Advisory only, like the rest of the family: never `risk`, `--fail-on`, or `affected-tests`. Shares the `payload_parity.enabled` switch and the `--no-payload-parity` flag.
  

### Fixed

- **One semantically invalid `use` alias no longer aborts the whole run.** A file can parse and still be invalid — two `use` statements binding the same alias is the common shape — and name resolution ran with the default throwing error handler. The resulting `PhpParser\Error` came out of `AppFiles::parseResolved()`, which no call site catches: one such file anywhere under `app/` took down the entire graph build, and one inside a diff took down `detect-changes`. Errors are collected now, as Laravel Brain's own parser already does, so the rest of the file's names still resolve. The file is not counted unparseable either — that flag is a global determinability blocker, and treating it as one would make a single invalid alias enough for `affected-tests` to refuse to answer.

### Why these lanes stayed richter's

Both edges exist upstream in some form, and neither could be handed over.

Brain emits a `facade-resolves-to` edge, but only for a `CallChainEdge` — a facade call inside a route-reached body. That is precisely the half richter does not need; the gap lives where no route reaches.

Brain's `ValidationRulesExtractor` reads `rules()`, but both of its entry points are gated on a file path, and this lane needs the *base* side of a diff, which exists only as a git blob. Even with a source-string entry point it would not serve: the lane must be able to conclude that a side cannot be enumerated and stay silent, and a `list<RuleRow>` cannot express that — a keyless item comes back as the field `'*'`, a value rather than a signal.

### Internal

- Graph cache `FORMAT_VERSION` 10 → 11, for the facade edges: they grow the edge set for identical file inputs, so a stale entry served to the new code would under-select. The request lane touches no edge and needs no bump.
- `ArrayReturnKeys` is the key enumeration `ResourceKeyParser` already performed, lifted out with the method name as a parameter — the resource and form-request parsers ask one question of a different method. `ResourceKeyParser` keeps its public API and its behaviour unchanged.
- `FrontendConsumerLane` holds what the two consumer-facing lanes share: the routes upstream of a class, the files consuming them, their scannable content, the ignore forms, the rename hint. One instance serves both checkers, so a diff that trips both lanes still walks the frontend once.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.22.0...v0.23.0

## v0.22.0 - 2026-08-10

Raises the Laravel Brain floor to `^2.4.0` and hands one lane back to it. Brain's release fixes the name-resolution bug behind the last reported reach gap, which is what makes this one worth taking rather than deferring.

### Changed

- **`laramint/laravel-brain` now requires `^2.4.0`.** The floor is load-bearing, not housekeeping: a class built by a same-namespace sibling was invisible to the graph until Brain resolved unqualified names against the file's own namespace, so the regression test covering it fails on 2.3.1 and passes on 2.4.0. In practice that shape is a value object or DTO living beside the factory that builds it — constructed several times and reporting that nothing referenced it.
  
- **Event → listener links are Brain's now.** Richter carried its own `EventServiceProvider::$listen` reader, with `$subscribe` and `#[AsEventListener]` recorded in its docblock as a known gap. Brain reads all three, so the reader is gone. Reach is preserved and slightly sharper — Brain points the edge at the event's constructor, where the event is actually built, so the event class reaches its listener one hop further out. The `Class@method` form the old reader handled survives too.
  
- **`model-to-policy` does not count toward risk.** Brain added the edge in 2.4.0. It says which policy governs a model, which is a governs-relation rather than a call: changing a policy leaves the model working. Counting it would have raised the risk level of every policy edit by the model it governs — a change the version bump would have made silently. It joins `model-relationship`, `declares`, `uses-trait` and `override` as reach that is context, not impact.
  

### What did not change, and why

Brain 2.4.0 also gained Blade view composition, resource references, queue dispatches and observer links — territory richter already covers. Those lanes stay, because the overlap is narrower than it looks: **Brain's analysis is anchored on routes, richter's tracers scan files.** A class no route reaches yields no Brain edges at all. The Blade case shows the trap: on a route-reachable fixture Brain emitted exactly the same `view-to-view` edges as richter's tracer, same ids and same type, and in a project without routes it emitted none while richter still found every component and include.

The `PUBLIC_WRITE` cross-check stays for the same kind of reason. A route behind an app middleware that extends `Illuminate\Auth\Middleware\Authenticate` is still classified public, because the auth match remains name-based with no ancestry walk — so the note contradicting that finding is still the only thing covering it.

### Internal

- Graph cache `FORMAT_VERSION` 9 → 10. The listener edges left the graph and Brain's took their place. The Brain version in the cache fingerprint already invalidates every entry for this particular change; the bump covers the general case, since a richter-only graph change would not.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.21.0...v0.22.0

## v0.21.0 - 2026-08-07

Closes the last reach gap real-world adoption feedback had left open, and it turned out to be one cause behind two symptoms: a `new X` inside a statically-called method drawing no edge, and an inherited method's work never becoming reachable through the subclass.

### Added

- **Statically-called method bodies are now read.** A class reached only through `Foo::bar()` — a static registry, a named constructor, a factory — had its node placed by richter's own static-call edge and nothing more: Laravel Brain's call-chain analysis is anchored on routes and never walks such a class, and the entry-point tracer only walks what `entry_point_roots` names. So everything the class constructed or called was invisible, and `impact` on the constructed class came back empty. Richter now reads the methods those static calls name.
  
  The inherited-method gap closes with it, and for a reason worth stating: reading the body puts the subclass member node into the edge set, which is the only condition under which an `inherits` edge to the parent's work is ever drawn. A caller arriving at a subclass now reaches what the inherited method actually does, not just the parent's node.
  
  **`richter.second_hop`** (default `true`) turns the walk off, trading that reach for build time — measured at ~4.5s on a 4,000-file application. It participates in the cache fingerprint, so a graph built with the walk on is never served to a run configured with it off.
  
  Scope was chosen by measurement. On that same application: walking every app class costs ~78s, adding the class-hierarchy `override` targets ~41s, the static-call target *classes* ~8.0s, and the *called methods* of those classes ~4.5s. Only the last is affordable — and it is also the most precise, since a method nobody calls has no reason to be read.
  

### Documentation

- The 0.20.1 boundary note "a class placed through richter's own edges is not re-walked for what it constructs" is now narrower and stated as such: the called method is read, the rest of such a class still is not. The relation boundary (declarations, not property-chain traversals) is unchanged.

### Internal

- Graph cache `FORMAT_VERSION` 8 → 9. The walk only ever adds edges, so a stale entry would under-select. Existing caches invalidate on first run; no action required.
- The build emits a `second-hop-walk` phase under `--profile`, with the edge count and the number of methods that could not be read, so the cost is measurable on a real application rather than estimated.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.20.1...v0.21.0

## v0.20.1 - 2026-08-07

Fixes an over-report 0.20.0 introduced. The new defined-node lane placed a file by the graph nodes pinned to it — and then walked all of them, which is the wrong reading for a file that merely registers things.

### Fixed

- **A one-line edit to a registry file no longer reports HIGH.** Adding a command to a legacy `app/Console/Kernel.php`'s `$commands` array walked the ten commands registered beside it and every schedule declared in the same file, reached 211 nodes, and rated the change HIGH — enough to fail a `--fail-on=high` gate over an edit that cannot break any of them. The lane now splits the nodes it finds by what they mean for the change. An entry-prefixed node is a surface the file *declares* — a `$commands` entry, a `schedule()` call, a route definition — so it is treated the way a frontend-referenced route already is: listed as touched, and never walked or counted toward risk. Everything else the file defines stays a walk seed, because a `middleware::` node is the changed class rather than something it declares.
  
  This restores a rule the analyzer already had: the risk inputs freeze before self-listed entry classes and frontend routes are appended, precisely so a declaration cannot move the risk level. Coverage, the changed-node count and `richter:affected-tests` are unaffected — a declared surface places the file just as a walk seed does, and the entry-point list test selection reads still carries it. The breadth note that already accompanied a large reach is unchanged.
  
- **A new file that declares surfaces no longer reads as referenced by nothing.** The same guard now covers the new-file finding, which would otherwise tell a brand-new routes file that no traced edge reaches it while it declares a dozen routes.
  

### Documentation

- Two boundaries the coverage list let a reader infer past are now stated. **Relations are traced as declarations, not as traversals** — a method body walking `$this->a->b->c->d` to reach a model is not followed, because resolving it needs the type of every hop. And **a class placed through richter's own edges is not re-walked for what it constructs** — Laravel Brain's call-chain analysis is anchored on routes, so a class reached only through a static call has its method bodies left unread, and a `new SomeDto(...)` inside one draws no edge. Both are gaps in reach, never in honesty: nothing is reported as unaffected on their account.

No graph-format change; existing caches stay valid.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.20.0...v0.20.1

## v0.20.0 - 2026-08-06

One theme: a report that is quieter than the truth, with nothing in the output saying so. Two edge types were missing from the graph, one class of file could not be placed in it at all, and three diagnostics stayed silent where they had something to say. Real-world adoption feedback surfaced all of them; the graph work is the substantive half, the diagnostics are what turn the remaining gaps from puzzling into legible.

### Added

- **Static-call edges.** A class reached only through `Foo::bar()` — the shape a static registry, named constructor, or factory is used through — got no node at all, so `detect-changes` did not merely stay quiet about it: it actively reported that nothing referenced a class two graphed callers did reference. The tracer is class-scoped rather than a flat method bucket, so `self`/`static`/`parent` resolve against the right class and a file declaring several classes stays correct. An unqualified plain name is trusted only when it resolves to a loadable class, so an unimported `Carbon::now()` no longer invents an `App\Services\Carbon`.
- **Inherited-method edges.** A method a class inherits without overriding runs in the parent, so the parent is now connected to the subclass its callers actually go through — the same declaring-class resolution the class-constant lane already applied. Together with the above, a four-link chain (entry point → service → inherited method → static collaborator) that was broken in two places is walkable end to end.
- **A defined-node seeding lane, as the last resort before UNRESOLVED.** Not every entry surface has a class name to look up: a scheduled task is identified by what it runs and how often, and a routes file is not a class at all. Such a file read UNRESOLVED despite defining surfaces the graph already knew — which also made test selection non-determinable for any change touching a legacy `app/Console/Kernel.php`. It now seeds the nodes the graph pins to that exact file, and lists the entry surfaces among them as touched. Gated on every other lane coming up empty, so member-level precision is untouched: a one-method change to a controller still seeds that method, not the class its file also defines. Restricted to nodes that appear in an edge, so "couldn't place this" never quietly becomes "placed, reaches nothing".
- **An entry-point coverage note.** When an `app/` directory holds classes and *none* of them reach the graph, `richter:impact`, `richter:trace` and `richter:detect-changes` say so on stderr and name the `richter.entry_point_roots` entry that would fix it. This is the shape a subsystem takes when it is dispatched through a registry or factory richter cannot follow — and it is invisible to the UNRESOLVED signal, which only ever describes a *changed* file, never a subsystem missing as a consumer of the change. Measured against the graph rather than diffed against the configured roots: that diff would fire on `Models`, `Services`, `Http` and most of a conventional app, and a note that fires everywhere is one its reader learns to skip. Only total absence is reported, and only from five classes up.
- **An auth-middleware cross-check on `PUBLIC_WRITE` findings.** Brain matches auth middleware by name, so an app whose own `Authenticate` subclasses the framework's matches none of the known names — the default skeleton shape — and every route behind it reads `[public]`, with a mutating verb drawing a `high` "requires no authentication". Richter now walks the class ancestry that match cannot and notes the applied middleware beside the finding. Evidence, never a suppression: the finding stays shown, on the same terms as the existing policy-gate cross-check. A route gated only by a member of a *named* middleware group remains out of reach — no lane expands those, deliberately, since mapping a global group onto every class in its stack would flood each of them with every route.

### Fixed

- **`richter:trace` errors are a lead rather than a dead end.** An unresolvable symbol now carries the same nearest-node suggestions `richter:impact` already rendered — or, when nothing in the graph resembles it, how many nodes were scanned. A trace needs *both* arguments to resolve before it can report anything, which makes it the surface where a typo costs the most.
- **A console command's entry-surface label no longer breaks across lines.** The node id embeds the command's whole `$signature`, and the existing display trim split on a literal space — so a multi-line signature kept its newline inside the first token and wrapped the columnar read anyway, in text and in markdown, where it also split the checklist item into two list lines. One shared helper now splits on any whitespace and is used by every renderer.
- A new file that no traced edge reaches states which of the two it is — nothing calls it yet, or the call shape is one richter does not trace — instead of asserting the first.

### Internal

- Graph cache `FORMAT_VERSION` 7 → 8. Both new edge types grow the edge set for identical file inputs, so a stale entry would be served to the new code and under-select. Existing caches invalidate on first run; no action required.
- The parallel tracer branch now carries the class-inheritance map, validated fail-closed like the edge payload — a mis-shaped record falls back to an in-process rebuild rather than drawing edges to methods that do not run.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.19.0...v0.20.0

## v0.19.0 - 2026-08-05

Two themes. First, the analysis a coding agent can *reach* now matches the analysis richter computes: tracing, test selection, and orientation data were CLI-only or nowhere — they are now first-class over MCP, and `richter:impact` reports the entry surfaces a symbol reaches instead of leaving them buried in the callers list. Second, payload parity learned the consumer direction: removing a resource key now warns when a frontend file that consumes the affected routes still reads it.

### Added

- **`richter:trace` and a `trace` MCP tool.** The shortest call-direction path between two symbols — "does FROM reach TO, and through which chain?" — strictly directional (swap the arguments to query the reverse; a reversed answer you might misread is never returned silently). A miss is data, not an error (exit 0) and reports the deepest caller reached from the target within the depth limit — how far upstream connectivity extends — while an unresolvable symbol *is* an error, deliberately stricter than `richter:impact`'s empty result: an empty trace would read as "no path".
- **An `affected-tests` MCP tool.** The same fail-safe selection the CLI computes, over MCP: every non-determinable cause — an untracked relevant file, an unresolvable base, UNRESOLVED coverage — returns `determinable: false` with its reasons, never a tool error, because "run the full suite" must stay the visible, actionable answer. The selection assembly now lives in one shared implementation for both surfaces.
- **Three read-only MCP resources** for orientation without a tool call: `richter://graph/entry-points` (every statically-known entry surface with kind and location), `richter://graph/stats` (node/edge counts plus the honesty flags), and `richter://config` (the effective analysis configuration).
- **Entry surfaces on `richter:impact`.** The report names the routes, commands, schedules, and Livewire/Filament components the callers walk reaches — with the same location, security-exposure, feature-gate, and test-reference annotations `detect-changes` carries, and the same advisory limits (routes-only classification; absence means *not classified*). `--explain` renders the chain from each surface down to the symbol; the JSON and MCP structured content gain the `detect-changes` vocabulary verbatim (`entryPoints`, `entryPointPaths`, `entryPointLocations`, `entryPointSecurity`, `entryPointGates`, `entryPointAuthGates`, `entryPointTestReferences`), so a consumer parses both reports identically. Additive keys; existing fields are unchanged.
- **Consumer-direction payload parity.** A `toArray()` key a diff removes from an API resource is flagged when a frontend file consuming one of the routes the resource reaches still reads it — with a deterministic rename hint when exactly one key was removed and one added. Consumers are the configured `frontend.roots` JS/TS files plus every Blade view's inline `<script>` blocks, so the lane works for pure-Blade apps too; matching is access-shaped only, and the key diff is stricter than the model→resource side (a `mergeWhen` or constant-keyed entry makes the side unenumerable — silence, never a guessed removal). Shares the `payload_parity.enabled` switch and `--no-payload-parity` flag; the `ignore` list gains a per-key form (`App\Http\Resources\XResource::key`).
- **Two agent-skill additions.** `/richter-review` reviews the current branch graph-first (report → entry-point triage → findings → test selection → advisory verdict; it recommends, never gates), and `/richter-setup` gains an opt-in step that registers the MCP server in `.mcp.json` (merge, never overwrite; proposes `composer require --dev laravel/mcp` when absent).

### Changed

- **`richter:affected-tests` no longer runs the payload-parity lanes.** Findings were never an input to the selection, and the consumer lane's frontend-tree scan has no place on a CI hot path whose output discards them. Selection output is byte-identical.
- The `impact` MCP tool's output schema widened with the entry-point keys above, and both new tools advertise full output schemas; `composer.json`'s `laravel/mcp` suggest text now names the four tools and the resources.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.18.0...v0.19.0

## v0.18.0 - 2026-08-05

Three fixes from real-world adoption feedback, all of them cases where the report was quieter than the truth. An application whose classes are not under `App\` had every reachability check miss; a brand-new file read as "no impact" even when its class was in the graph and was itself an entry surface; and a result of zero gave no thread to pull. A silently thin report is the one failure mode this package exists to prevent, so all three are treated as correctness bugs rather than polish.

### Changed

- **A diff that only adds files can now report `medium`/`high` and trip `--fail-on`.** Previously any brand-new file was classified additive and contributed nothing to risk (see Fixed below). If your CI gate is tuned against the old behaviour, expect adds-only branches — a new command, job, listener, or an added class reached from existing code — to report a real risk level for the first time. There is no config flag to opt out: the old result was a false negative, not a quieter setting.

### Added

- **`root_namespace` config key.** Left `null` (the default), richter derives the application root namespace from the PSR-4 entry in your `composer.json` that maps to `app/` — so a conventional app still resolves `App\` and needs no change. Set it explicitly (e.g. `'Acme\\'`) when two or more PSR-4 roots map to `app/`, which the derivation cannot disambiguate.
- **A root-namespace sanity note.** Every command warns on stderr when the root it traced matches no `app/` mapping in `composer.json`, and when `composer.json` maps `app/` under two-plus roots (only one is traced). The `--markdown` report carries the same note inside the document, since stderr never reaches a posted pull-request comment.
- **Nearest-node suggestions on a miss.** `richter:impact` on a symbol that matches nothing now names the closest node ids — ranked by shared identifiers, then by edit distance on the class basename — or reports how many nodes were scanned when nothing in the graph resembles the symbol. A lookup under the wrong root namespace surfaces the real node first.
- **The derived FQCN in the changed-files list.** `richter:detect-changes` echoes the fully-qualified name a changed path resolved to (`app/Services/Inspector.php → App\Services\Inspector`) for any file that reads UNRESOLVED, and for every changed file under `--explain`. That one line separates a coverage gap from a wrong root namespace.
- **A `[new file]` marker** in the text, markdown, and HTML reports, so a whole-class seed reads differently from a member-level one.

### Fixed

- **A non-`App\` root namespace made the analysis miss almost everything.** Path → FQCN, the FQCN → path inverse, every "is this an app class?" gate, the `Policies\` / `Models\` / `Http\Resources\` / `Rules\` / `Actions\` prefixes, and the test-reference index's source scan all compared against the `App\` literal. On an application mapping another PSR-4 root to `app/`, no changed file resolved to a node, the source tracer traced nothing, and the `[test-referenced]` coverage tags could only under-report. All of them now derive from the resolved root. `App\` still wins when an application maps both it and another root to `app/`, so a partially-migrated codebase keeps tracing the half it traced before.
- **A brand-new file reported `0 graph nodes` and `LOW`, even when its class was in the graph.** A new file has no base side to diff against, so every member of it classified as *added* — which made the whole file read as additive, seeding nothing, skipping the entry-point risk floor, and never self-listing as its own entry surface. A new console command whose node `richter:impact` found happily came back as no impact. A genuinely new file now seeds its class node as a precise seed (no low-confidence flag, no capped risk), so its reach, its own entry surface, and its risk level all report. A new file that resolves to no node reads `analyzed` with a finding rather than UNRESOLVED, so `--fail-on-unresolved` does not fail on a class nothing references yet. Adding a member to an *existing* class is unchanged — nothing called it before, so it still seeds nothing.
- **The `--json` impact document could have gained report-only fields.** `JsonPresenter::impact()` passed the analyzer result straight through; it now selects its three keys, so a field added for the human-readable reports can never widen the machine contract (the MCP tool validates its structured content against a declared schema).

### Notes

- The graph cache invalidates itself: the fingerprint now includes the effective root namespace, so the first run after upgrading rebuilds and every later run is served normally. No `--no-cache` needed.
- Installation gained a note: richter requires `laravel/mcp >= 0.8`, which `laravel/boost` only pulls from v2. Composer will not upgrade a package richter does not depend on, so an existing `laravel/boost` v1 install must take that major in the same `composer require`.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.17.5...v0.18.0

## v0.17.5 - 2026-08-02

A setup release: richter now ships a guided way to configure itself for a new project, so a fresh install stops reading `UNRESOLVED` on subsystems the defaults can't see (runtime-dispatched Forms, registry-dispatched calculators) and stops scoping the report against the wrong base branch. No analysis behaviour changed — the engine is byte-identical to 0.17.4.

### Added

- **`richter-setup` skill (invoke-only).** A new boost skill that inspects a Laravel project and *proposes* `config/richter.php` — `default_base`, `entry_point_roots`, `dispatch_helpers`, frontend roots, and the relevant Laravel Brain `security.*` / discovery levers — then, only on explicit opt-in, scaffolds an advisory PR-comment workflow from a shipped template. It never auto-activates (run `/richter-setup` or ask an agent to "set up richter"), reads existing config before proposing, and confirms every write; reading config never publishes it. To make it available: boost-core consumers add `sandermuller/richter` to `withAllowedVendors([...])` and run `boost sync`; laravel/boost discovers it as a third-party AI package.
- **README "Set up richter for your project" section.** Two paste-able prompts for agents without the skill — one to configure `config/richter.php`, one to add the CI advisory comment — kept separate so the CI step stays opt-in.
- **Advisory CI workflow template** (`richter-report.yml`, shipped beside the skill). A non-blocking `pull_request` job with least-privilege permissions (`contents: read`, `pull-requests: write`) that runs `richter:detect-changes` and posts the report as a sticky PR comment via first-party `actions/github-script`. Advisory by contract — nothing in it can fail a PR.

### Notes

This release adds shipped resources and documentation only; there are no code or public-API changes, so existing installs need no action to upgrade.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.17.4...v0.17.5

## v0.17.4 - 2026-08-02

The report now cross-checks a route's `PUBLIC_WRITE` security finding against richter's own authorization edges, flagging a likely false positive when the route is in fact policy-gated.

### Added

- **`PUBLIC_WRITE` cross-check against `authorizes` edges.** richter surfaces Laravel Brain's per-route security findings as advisory annotations. Brain classifies a route's exposure from its static middleware surface, so it can flag `PUBLIC_WRITE` ("requires no authentication — anyone can call this endpoint") on a route that is in fact gated by a policy-constant check (`Gate::authorize(PostPolicy::UPDATE, …)` / `$user->can(PostPolicy::UPDATE, …)`) or a middleware group it cannot see. richter's own graph already records that gate as a `PolicyEdgeTracer` `authorizes` edge. For a route carrying a `PUBLIC_WRITE` issue, richter now checks whether the route's reach authorizes a policy and, on a hit, adds a note naming it — evidence for you to verify, not a verdict. It **contradicts, it never suppresses**: the Brain finding stays shown, so a genuinely public write is never hidden. Throttle and middleware-group auth are still not verified, so a `MISSING_THROTTLE` (and a group-only auth gate) is left to stand.

### Internal

- Analyzer/report only — it reads existing graph edges and adds an `entryPointAuthGates` annotation; it never seeds a walk or influences the risk level, so warm caches stay valid (no `GraphCache` format-version bump).
- Suite: 848 tests / 1,975 assertions, including the reachable-set ∩ `authorizes` intersection (guarded against a BFS-tree edge-drop), the routes-only + `PUBLIC_WRITE`-only trigger, an end-to-end route→controller→policy join on the fixture app, and the contradiction note across the text, Markdown, and HTML formatters (HTML-escaped).

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.17.3...v0.17.4

## v0.17.3 - 2026-08-02

A precision fix: constructing a value object that merely carries a `handle()`/`__invoke()` method is no longer read as dispatching a job, so a change to such a class no longer fans out across every method that builds one.

### Fixed

- **A bare instantiation of a `handle()`-shaped class is no longer treated as a job dispatch.** The dispatch tracer links a `new X(...)` to `X` to catch the shapes static analysis can't otherwise follow — `$job = new X(...); dispatch($job)`, and dispatches through a project-custom helper. But it linked *any* instantiation of a class matching the dispatch predicate, and that predicate matches any class with a `handle()` or `__invoke()` method — the shape of a self-handling bus command, but also of countless plain value objects. So a method that merely built and returned a value object (a calculator returning a result object, say) read as dispatching it, and one widely-constructed object became a phantom hub inflating the reached-entry-point and impacted-node counts across every method that constructed it. Now an intrinsic dispatch target — a `\Jobs\`-namespaced class, a `ShouldQueue` job, a `Dispatchable` command, or one that can't be resolved — is still linked from a bare instantiation unconditionally, so a dispatch through an unrecognised helper stays caught; a class that matches *only* via the `handle()`/`__invoke()` shape is linked from an instantiation only inside a method that actually dispatches.
  
  If you dispatch a plain (non-`Dispatchable`) command through a project-custom helper function, register that helper in `richter.dispatch_helpers` so it is recognised as a dispatch verb — otherwise that shape is no longer linked from a bare `new`.
  

### Internal

- `GraphCache` format version bumped: the change shrinks the edge set for identical file inputs, so warm caches invalidate rather than serving a pre-fix graph carrying the phantom dispatch edges.
- Suite: 839 tests / 1,961 assertions, including a regression that a `handle()`-only class constructed with no dispatch verb draws no edge, and that an intrinsic job through an unrecognised helper still does.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.17.2...v0.17.3

## v0.17.2 - 2026-08-02

A precision fix: changing or adding one constant in a grouped `const A = …, B = …;` declaration no longer treats every co-declared constant as changed, so the blast radius stays scoped to what actually changed.

### Fixed

- **Grouped constant and property declarations are pinned per item.** A multi-item declaration — `const A = …, B = …;` or `public $a, $b;` — gave every item the whole statement's line span. Touching one item, or adding a sibling to the group, then fell inside every item's span, so richter marked every co-declared member changed. Since 0.17.0 seeds constants precisely, adding one constant to a large group (e.g. a company- or identifier-style enum) fanned the blast radius out to every constant's readers — a long, misleading list of reached entry points and impacted nodes. Each item now gets its own line span, so a change pins to the item that actually changed.
  
- **A comment between members is no longer a class-level change.** A comment-only line sitting outside every member — for example, documenting one entry of a grouped declaration — previously registered as a coarse class-level modification and lowered confidence. It is now recognised as the non-behavioural change it is.
  

### Internal

- Diff-classification only — the graph output is unchanged, so warm caches stay valid (no `GraphCache` format-version bump).
- Suite: 837 tests / 1,954 assertions, including regressions that adding one constant to a group is additive, that modifying one flags only that constant, and that a comment between members is not a change.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.17.1...v0.17.2

## v0.17.1 - 2026-08-01

A precision fix: adding a new, documented method no longer makes a diff read as a coarse class-level change and raise a false low-confidence flag.

### Fixed

- **A documented new method no longer lowers confidence.** When a diff added a method together with its `/** … */` docblock, the docblock lines — which sit above the `function` keyword — fell outside the method's span and were read as a class-level modification. That forced a coarse, class-level seed and raised a false "low confidence: a changed member could not be pinned to a graph node" warning on an otherwise precise, method-only diff. A member's span now includes its leading doc comment, exactly as it already did for `#[Attribute]` groups, so a documented new method reads as one additive member and the surrounding method changes seed precisely.

### Internal

- Diff-classification only — the graph output is unchanged, so warm caches stay valid (no `GraphCache` format-version bump).
- Suite: 833 tests / 1,945 assertions, including regressions that a new method added with its docblock stays additive, and that a member span starts at its leading doc comment.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.17.0...v0.17.1

## v0.17.0 - 2026-08-01

More precise change impact: a change to a class constant or enum case now pins to the code that reads it, instead of coarsely flagging the whole class.

### Added

- **Class-constant and enum-case member references.** A changed class constant or enum case could not be pinned to a member node — only methods could — so the impact walk seeded the whole class and reported "low confidence: a coarse class-level estimate". richter now gives constants and enum cases their own graph nodes and draws a `references-constant` edge from every method that reads one, so a change to a constant or case pins to its actual readers and drops the low-confidence flag. Reads resolve to the constant's **declaring** class through the hierarchy, so a constant read via `self::`/`static::` in a subclass still connects to the ancestor that declares it. The edge feeds the blast radius, `richter:affected-tests`, and the risk level (a constant read is a real value dependency). Documented under "Coverage beyond Brain".
  
  Scope, kept honest: a constant read whose owner can't be resolved to a scanned class (a dynamic `$var::CONST`, a vendor constant) reads UNRESOLVED, never "no impact"; a trait constant, a property (`$fillable`/`$casts`), and a class-level modifier still resolve coarsely to the class.
  

### Internal

- `GraphCache` format version bumped: constants and enum cases are new graph nodes/edges, so warm caches invalidate rather than serving a pre-feature graph.
- Suite: 831 tests / 1,942 assertions, including declaring-class resolution (inherited/interface constants), parameter-default and nested-anonymous-class reads, and a reachability check that a constant change pins to its readers without low confidence.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.16.0...v0.17.0

## v0.16.0 - 2026-07-31

Wider graph coverage: the change graph now follows polymorphic dispatch, so a concrete override reached only through an abstract class or interface is no longer invisible. Plus a plainer report.

### Added

- **Class-hierarchy analysis.** A call that resolves to an abstract-class or interface method now also reaches the concrete overrides in every subclass/implementor, drawn as a new `override` edge. This connects handlers chosen at runtime — a driver registered in a config array, a factory, `app()->make($runtimeClass)`, constructor-injected polymorphism — that a static call graph otherwise leaves orphaned, so a change to one of their overrides no longer reads as "unreachable" or "no entry point". Reachability only: the edge feeds the blast radius and `richter:affected-tests`, never the risk level (it is an over-approximated association, not a direct call). It reads only the class hierarchy — no configuration to add. Documented under "Coverage beyond Brain".

### Changed

- **Plainer report copy.** The detect-changes report drops the em-dash asides and double negatives (`(advisory — not a gate)`, `UNRESOLVED — not graphed, never "no impact"`) in favour of plain wording. The `UNRESOLVED` status token itself is unchanged — only the surrounding prose — and the `--json` contract is untouched.

### Internal

- The graph cache format version was bumped: the new `override` edges change the graph for identical file inputs, so warm caches invalidate automatically rather than serving a pre-CHA graph.
- Suite: 812 tests / 1,900 assertions, including class-hierarchy unit coverage, a graph-build reachability test, and a reachability-not-risk pin for the new edge.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.15.1...v0.16.0

## v0.15.1 - 2026-07-31

### Internal

- **HTML report escaping hardened — no output change.** The `--html` report was already safe; this closes the fragilities that would let a future edit reintroduce a hole. Added adversarial coverage for the editor-link `href` at both the unit and integration levels; routed every remaining interpolation in the blast-radius SVG through the central escape helper so the renderer keeps no silent exception to its own "escape everything" rule; and corrected the docblocks to state that the editor `href` is kept URL-safe by `rawurlencode` plus a fixed scheme allow-list, with HTML-escaping only the attribute layer — not a substitute for encoding the URL. The rendered report is byte-for-byte identical.
- **Dropped the abandoned `rector/type-perfect` dev dependency.** Abandoned upstream, it had begun colliding with current PHPStan and broke the static-analysis job on every push. Removed it along with its two rules (`null_over_false`, `narrow_return`); the maintained `tomasvotruba/type-coverage` stays. Dev-only — nothing a consumer installs changes.
- The CHANGELOG decorator now sanitises the release body before prepending it, so internal markers no longer leak into `CHANGELOG.md`.
- Suite: 798 tests / 1,864 assertions.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.15.0...v0.15.1

## v0.15.0 - 2026-07-25

A new advisory payload-parity check plus a set of graph-build performance improvements. No breaking changes — every new behaviour is advisory or output-invariant, and the new config keys default to sensible values.

### Added

- **Payload-parity detection.** A new advisory lane flags a model field added to `$fillable`/`$casts`/`casts()` that is *not* mirrored into a resource which already mirrors the model's other fields — the exact shape behind a payload field silently going missing from an API response. It is advisory only: it never feeds the risk level, `--fail-on`, or `richter:affected-tests`, only the report's findings list. Tunable via the `payload_parity` config (`enabled`, `mirror_threshold`, `ignore`) and suppressible for one run with `--no-payload-parity`.
- **Parallel graph build.** Every command that builds the graph now runs Brain's route-anchored analysis and richter's own source-tracers **concurrently** — the tracers run in a child `artisan` process — instead of sequentially, shortening a cold build on a multi-core machine. The merged graph is identical to the serial build (edge order included); any child-process failure transparently falls back to the serial build, and `--profile` forces serial so the phase split stays measurable. Controlled by the new `parallel` config key (default on).

### Fixed

- **`richter:affected-tests` now fails closed on git-quoted untracked paths.** An untracked file whose pathname git quotes (non-ASCII or special characters) is unquoted before the fail-closed check, so it can no longer slip past and yield a falsely-narrow test selection.

### Internal

- **Faster repeated fingerprinting.** The graph cache's content fingerprint reuses a file's hash across repeated builds within one process (e.g. a long-lived MCP session re-checking a mostly-unchanged tree) when the file's stat signature — inode, size, mtime, and ctime — is unchanged and not racily-recent, skipping the re-read. The fingerprint value stays byte-identical to hashing every file: staleness is still designed out, not heuristically hoped out.
- **Fewer entry-point traces.** The entry-point tracer skips a method whose body contains none of the AST nodes Brain's `MethodTracer` draws an edge from, cutting redundant per-method traces with no change to the graph.
- Suite: 796 tests / 1,858 assertions, including a byte-identical parallel-vs-serial build gate, fingerprint transparency + change-detection tests, and adversarial coverage of the worker payload validation.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.14.0...v0.15.0

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
