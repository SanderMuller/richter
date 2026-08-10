![Richter: measure the reach of a code change](richter.png)

# Richter

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/richter.svg?style=flat-square)](https://packagist.org/packages/sandermuller/richter)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/richter/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/richter/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/SanderMuller/richter/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/SanderMuller/richter/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/richter.svg?style=flat-square)](https://packagist.org/packages/sandermuller/richter)
[![License](https://img.shields.io/packagist/l/sandermuller/richter.svg?style=flat-square)](LICENSE)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/sandermuller/richter?style=flat)](https://packagist.org/packages/sandermuller/richter)

Measures the magnitude of impact of code changes in a Laravel codebase. Like the Richter scale, but for your PHP.

Built on [Laravel Brain](https://github.com/laramint/laravel-brain)'s static analysis, Richter constructs a directed code graph of your application (routes, controllers, jobs, listeners, policies, resources, Blade views, Eloquent relations). It reads off that graph:

- **The blast radius of a symbol:** its callers (what breaks if you change it), its dependencies (what it reaches), and the entry surfaces its callers lead back to.
- **The path between two symbols:** the shortest call-direction chain from one to the other.
- **What the current branch diff touches:** the HTTP/CLI entry points and flows the changed files reach, plus a coarse risk level and a set of source-level findings.

You can use those results three ways:

- **CLI:** a self-review aid before you push.
- **MCP:** Richter registers a local `richter` server exposing every analysis read-only — `impact`, `trace`, `detect-changes`, `affected-tests`, plus orientation resources — so a coding agent can work with the graph mid-review without shelling out.
- **CI and PR review:** run `richter:detect-changes` against the pull request's base ref and post the report for the reviewer, human or agent.

Richter is advisory by default: `richter:detect-changes` exits 0, and a low or empty result is a signal, not a guarantee of no impact. Opt into a CI gate with `--fail-on` / `--fail-on-unresolved` when you want a non-zero exit (see [Gating in CI](#gating-in-ci)).

## What it's for

Richter shows what a change reaches, before you or your reviewer have to guess.

- **Catch what you missed, before review.** Run `richter:detect-changes` on your branch and read the entry points and flows the diff reaches. Anything you didn't expect it to touch is worth a look before you open the PR.
- **Turn reach into a test-coverage prompt.** Every reached entry point is tagged `[test-referenced]` or `[⚠ no test references this]`, and a referenced entry point whose referencing tests contain no behavioural assertion the scan recognises is tagged `[test-referenced — no behavioural assertion found]` — a heuristic prompt, not a coverage verdict. An entry point whose behaviour you changed with nothing referencing it is a place to add a test; the tag flags a missing reference, not proof the code is untested.
- **Hand the reviewer your blast radius.** Drop the report into the pull request description, or let a coding agent read it over MCP, so review starts from what the change reaches instead of a cold diff.
- **Size a refactor first.** Before you rename or rework a symbol, `richter:impact "App\Models\User"` lists its callers (what breaks if you change it), its dependencies (what it reaches), and the entry surfaces behind those callers.
- **Answer "how does this even reach that?"** `richter:trace From To` prints the shortest call chain between two symbols — or, when there is none, how far upstream connectivity actually extends.

The analysis never executes your application's routes, jobs, or commands. It is static analysis over a code graph, fast enough to run on every branch. It does, however, autoload classes from the analyzed checkout (to resolve constants, relation names, and queue interfaces), and autoloading runs a file's top-level code. Treat a checkout you would not `composer install` on as one you should not analyze either.

## Coverage beyond Brain

Richter adds two things over Laravel Brain alone: the tooling above (CLI, MCP, and CI/PR review) and wider graph coverage. On coverage, it traces the edges a route-anchored analysis misses.

Brain traces some of these too — view composition, resource references, queue dispatches, observers, facade resolution — but the overlap is narrower than it looks. Brain's analysis starts at routes; richter's tracers read files. For a class no route reaches, Brain draws no edges at all. Where the two agree, it is because that code happened to be route-reachable.

- queue dispatches, including unresolvable ones;
- container bindings and interface implementations;
- polymorphic overrides — a call on an abstract-class or interface method also reaches the concrete overrides in its subclasses/implementors, so a handler chosen at runtime (a config-registry driver, a factory, `app()->make($runtimeClass)`) is not left orphaned;
- static calls — `Foo::bar()`, the shape a static registry, named constructor or factory is reached through, which a `new`-oriented trace leaves with no node at all;
- inherited methods — a method a class inherits without overriding runs in the parent, so the parent is connected to the subclass its callers actually go through (the same declaring-class resolution the constant lane does);
- calls through an application facade — a facade is an app class like any other, so `Reports::generate()` otherwise stops at a member the facade does not declare, leaving the class its accessor names reachable from nothing;
- class-constant and enum-case reads — a change to a constant or enum case pins to the methods that read it (resolved to the declaring class, so an inherited constant still connects), instead of coarsely flagging the whole class;
- policy references (`$user->can(PostPolicy::UPDATE, …)` and `@can(...)` in Blade);
- API resource composition;
- custom validation rules;
- trait usage;
- eager-load relation strings;
- view-to-view includes;
- frontend endpoint references — Wayfinder imports, Ziggy calls, endpoint literals in changed TS/JS/Vue files and Blade inline scripts (opt-in, see [Frontend changes](#frontend-changes-wayfinder--ziggy)).

Three limits on that list, all easy to infer past.

Relations are traced as declarations, not traversals. Richter links `Post` to `Comment` because the relation is declared on the model, but it does not follow a method body walking `$this->a->b->c->d` to arrive at one; resolving that needs the type of every hop.

The second limit used to be larger. A class reached only through a static call had every method body left unread, because Laravel Brain's call-chain analysis is anchored on routes, so a `new SomeDto(...)` inside such a class drew no edge at all. Richter now reads the methods those static calls name, which is enough to connect what they construct and to connect an inherited method's work through the subclass. What stays unread is the rest of the class: a method nobody calls statically. Set `richter.second_hop` to `false` to trade that reach back for build time (~4.5s on a 4,000-file app).

The third is the facade whose `getFacadeAccessor()` returns a container key rather than a class. `return ReportGenerator::class` names its concrete and is carried over; `return 'reports'` names a binding richter does not keep, and resolving it to the wrong class would send a reviewer to the wrong file, so it draws nothing.

All three are gaps in reach, never in honesty: nothing is reported as unaffected on their account.

## Installation

```bash
composer require --dev sandermuller/richter
```

Requires PHP 8.4+ and Laravel 12 or 13.

`laravel/mcp` is optional (it lights up the [MCP server](#mcp-server)), but when present it must
fall in the supported `^0.8||^0.9` range — Richter declares a conflict with anything outside it.
`laravel/boost` only pulls a compatible
`laravel/mcp` from v2, and Composer won't upgrade a package Richter doesn't depend on, so an
existing `laravel/boost` v1 install has to take that major in the same command — otherwise the
install fails on the `laravel/mcp` conflict:

```bash
composer require --dev sandermuller/richter laravel/boost:* -W
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=richter-config
```

## Set up Richter for your project

Richter is accurate only once it knows your app's shape — which subsystems are entry surfaces, which
helpers dispatch jobs, your real base branch, your frontend stack. You can set that up two ways.

**With an agent (recommended).** Richter ships two invoke-only skills. `/richter-setup` (or ask your
agent to "set up Richter") inspects the project, proposes `config/richter.php`, and — only if you say
yes — scaffolds a CI comment workflow and registers the MCP server in `.mcp.json`. It shows you every
edit before writing it. `/richter-review` reviews the current branch graph-first: it runs the report,
triages the reached entry points (unexpected reach, missing test references, security and gate
annotations), walks the findings, and closes with an advisory verdict — it recommends, never gates. To
make the skills available: with **boost-core**, add `sandermuller/richter` to `withAllowedVendors([...])` in your
`boost.php`, then `vendor/bin/boost sync`; with **laravel/boost**, they're discovered as a third-party AI
package (an existing install may need `boost:update` / package selection).

**Or paste these prompts to any agent** (two, so CI stays opt-in):

_Configure:_

> Set up Richter for this Laravel project. Inspect the code and **propose** edits to `config/richter.php` — show me each change and get my OK, write nothing unasked. Cover: `default_base` (my repo's real default branch), `entry_point_roots` (any `app/` subsystem reached via runtime/vendor dispatch — form-builder Forms, registry-dispatched calculators — that `richter:detect-changes` reports `UNRESOLVED`; pick the narrowest dir), `dispatch_helpers` (custom job-dispatch wrapper functions), frontend roots if there's an Inertia/Wayfinder/Ziggy frontend, and `editor: null` if this is mainly for CI. Also flag any Laravel Brain config (`security.auth_middleware`/`throttle_middleware`, route/command/listener discovery) that would fix mis-classified routes at the source.

_Add the CI advisory comment:_

> Add a GitHub Actions workflow that posts the Richter report as an advisory PR comment. First check whether Richter is already wired into an existing workflow and integrate there instead of adding a duplicate. Make the whole job non-blocking, least-privilege (`permissions: contents: read, pull-requests: write`), triggered on `pull_request` (not `pull_request_target`), checkout with `fetch-depth: 0`, run `php artisan richter:detect-changes --base=<PR base sha> --markdown` and post it as a sticky comment. Show me the file before creating it.

## Usage

### Blast radius of a symbol

```bash
php artisan richter:impact "App\Services\PostPublisher"
php artisan richter:impact PostPublisher                     # substrings work too
php artisan richter:impact "App\Services\PostPublisher" --explain    # chain from each reached entry surface
php artisan richter:impact "App\Services\PostPublisher" --json       # machine-readable, for scripting
php artisan richter:impact "App\Services\PostPublisher" --markdown   # PR-ready markdown
```

Prints the symbol's callers (what breaks if you change it) and its dependencies (what it reaches), breadth-first. Each hop shows its depth (`d1`, `d2`, …) and the edge it was reached through, so a caller chain reads back to the entry point one hop at a time:

```text
Callers (what breaks if you change "App\Services\PostPublisher"):
  d1  App\Http\Controllers\PostController::publish  (via action-to-service)  — app/Http/Controllers/PostController.php
  d2  App\Http\Controllers\PostController  (via controller-to-action)  — app/Http/Controllers/PostController.php
  d3  route::POST::/posts/{post}/publish  (via route-to-controller)  — routes/web.php:24

Dependencies (what "App\Services\PostPublisher" reaches):
  d1  App\Events\PostPublished  (via action-to-event)  — app/Events/PostPublished.php
```

Every hop carries its defining file (and line, when known), project-relative — no grepping to find what a report names.

Between the callers and dependencies, the report names the **entry surfaces** the callers walk
reaches — routes, commands, schedules, and Livewire/Filament component classes — with the same
annotations `detect-changes` carries: defining location, `[test-referenced]` /
`[⚠ no test references this]` tags, security exposure (`[public]`, `[authed]`, … — advisory,
routes only, absence means *not classified*) and Pennant gates. `--explain` adds the shortest call
chain from each surface down to the symbol. The tags are orientation, not verdicts — `impact`
reports no risk figure at all, and the section reads `(none)` when the walk reaches no surface.
The `tests/` scan behind the test tags only runs when a surface was actually reached.

A symbol that matches nothing is a lead rather than a dead end: the report names the nearest graph nodes (ranked by shared identifiers, so a lookup under the wrong root namespace surfaces the real node), or — when nothing in the graph resembles it — how many nodes were scanned.

```text
No graph nodes matched "App\Services\TokenInspector". It may be spelled differently, sit under another root namespace, or be reached only through a call shape richter does not trace.
Nearest graph nodes: Acme\Services\TokenInspector, Acme\Services\TokenInspector::inspect
```

With `--json`, stdout is a single document (`{target, callers, dependencies, entryPoints,
entryPointPaths, entryPointLocations, entryPointSecurity, entryPointGates, entryPointAuthGates,
entryPointTestReferences}` — the entry-point keys share `detect-changes`' vocabulary and shapes,
so a consumer parses both reports identically; each hop is `{depth, node, via, file?, line?}`),
or `{"error": "…"}` on failure.

### Shortest path between two symbols

```bash
php artisan richter:trace "App\Http\Controllers\PostController" "App\Services\PostPublisher"
php artisan richter:trace PostController PostPublisher        # substrings work too
php artisan richter:trace PostController PostPublisher --json       # machine-readable
php artisan richter:trace PostController PostPublisher --markdown   # PR-pasteable chain
```

Answers "does FROM reach TO, and through which chain?" — strictly in call direction; swap the
arguments to query the reverse. A found path prints as one chain, each arrow labelled with the
edge type connecting its two hops:

```text
Path from "PostController" to "App\Services\PostPublisher" (call direction, 1 hop(s)):
  ↳ App\Http\Controllers\PostController::publish →(action-to-service) App\Services\PostPublisher
```

No path is a result, not an error (exit 0). The report then names the deepest caller reached
from the TO side within the depth limit, which tells you how far upstream connectivity extends
(it is not a pointer toward FROM), or says plainly that the target has no callers. An
unresolvable symbol *is* an error. That is stricter than `richter:impact`, which returns an
empty result for an unknown symbol, but an empty trace would read as "no path" — a wrong answer,
not an empty one. The error carries the same lead `impact` renders — the nearest graph nodes, or
the number of nodes scanned when nothing resembles the symbol — since a trace needs *both*
arguments to resolve before it can report anything.

With `--json`, stdout is `{from, to, resolvedFrom, resolvedTo, found, path}` — plus
`furthestReached` (`{node, depth, file?, line?}`) on a miss whose target has callers — or
`{"error": "…"}` on failure.

### Advisory change impact of the current diff

```bash
php artisan richter:detect-changes                        # diffs against richter.default_base
php artisan richter:detect-changes --base=origin/develop
php artisan richter:detect-changes --explain              # show how each entry point reaches the change
php artisan richter:detect-changes --json                 # machine-readable, for scripting or CI
php artisan richter:detect-changes --markdown             # PR-ready markdown, for descriptions and comments
php artisan richter:detect-changes --html=impact.html     # self-contained visual report (add --open to launch it)
```

Against the default `HEAD`, the diff is the working tree compared to the merge-base with `--base` — staged and unstaged edits are included, not just what's committed, so running this before you commit still sees your changes. (Passing an explicit non-`HEAD` ref instead replays that ref's committed tree.) The one gap `git diff` can't close is a brand-new file that was never `git add`-ed: it shows in no diff form, so a stderr-only note flags any such untracked file under `app/`, `resources/views/`, or a configured frontend root — never on stdout, so `--json`/`--markdown` output stays exactly the report.

Resolves which class members the branch changed (member-level, not file-level: a one-method change seeds that method, not the whole class), walks the graph, and reports:

- the entry points the change can reach — routes, commands, jobs, listeners, middleware, and Livewire/Filament component classes (a Blade-mounted component or Filament resource/page/widget is a user-facing surface even without a `route::` node) — each tagged `[test-referenced]` or `[⚠ no test references this]`;
- findings in the changed source itself, such as an eager-load or relation string that names no relation on any model. A missing comma between two relation constants is the classic case: `Post::OWNER . User::PROFILE` concatenates to `ownerprofile`, a name Eloquent silently never resolves;
- a coarse risk level (`low` / `medium` / `high`);
- honest degradation: a change that cannot be placed in the graph reads **UNRESOLVED**, never as a falsely reassuring "no impact", and an unfollowable dispatch makes a queue job read "unknown", not "none". A file that resolved to no graph node also echoes the FQCN its path derived to (`app/Services/Inspector.php → App\Services\Inspector`), which is what separates a coverage gap from a wrong root namespace; `--explain` echoes it for every changed file.

  Before a file falls through to UNRESOLVED, richter tries one last lane: the nodes the graph says *that file defines*. Not every entry surface has a class name to look up. A scheduled task is identified by what it runs and how often, and a routes file is not a class at all, so a change to a legacy `app/Console/Kernel.php` or to `routes/api.php` would otherwise be unplaceable despite defining surfaces the graph already knows.

  Those surfaces list as touched, but they are never walked and they never move the risk level. A file that *declares* a surface has not called into it: adding one line to a `$commands` array cannot break the ten commands registered beside it, and rating the edit by everything those ten reach would be breadth dressed up as consequence. The lane runs only when every other lane came up empty, so member-level precision elsewhere is unaffected: a one-method change to a controller still seeds that method, not the class its file also defines.

A member *added* to an existing class seeds nothing — nothing called it before, so it can break nothing. A brand-new **file** is different: the class itself is new, so it seeds on its class node and reports its reach, its own entry surface (a new command, job or listener), and a risk level accordingly — marked `[new file]` in the report. A diff that only adds files can therefore report `medium`/`high` and trip `--fail-on`.

```text
Changed files:
  app/Models/Post.php (4 graph nodes)
  app/Services/CategoryImporter.php (0 graph nodes)  (UNRESOLVED: coverage incomplete for this area)

Entry points reached: 2 (some changed files are in an area not yet graphed — see UNRESOLVED above)
  - command::categories:sync  (app/Console/Commands/SyncCategories.php)  [test-referenced]
  - route::PATCH::/api/posts/{post}  (routes/api.php:41)  [⚠ no test references this]  [authed]

Related models (association reach — context, not risk): 1
  - App\Models\Category

Findings (in the changed source itself):
  ! app/Models/Post.php: eager-load string 'ownerprofile': segment 'ownerprofile' is not a method on any model — check the relation name (a broken constant concatenation reads exactly like this)

Impacted nodes: 7
Risk: MEDIUM (advisory)
```

With `--explain`, each reached entry point carries the shortest call chain down to the changed code. That is the difference between knowing a change reaches `PATCH /api/posts/{post}` and seeing exactly which controller and service carry it there:

```text
Entry points reached: 1
  - route::PATCH::/api/posts/{post}  [⚠ no test references this]
      ↳ route::PATCH::/api/posts/{post} →(route-to-controller) App\Http\Controllers\PostController::update →(action-to-service) App\Services\PostPublisher::publish
```

A self-listed entry class (a changed job or listener that *is* the entry surface rather than being reached from the change) deliberately carries no chain.

Reached routes also inherit [Laravel Brain](https://github.com/laramint/laravel-brain)'s security surface as advisory annotation: the exposure level renders inline (`[public]`, `[guest]`, `[authed]`, `[admin]`) and any statically detected issues render under the route:

```text
  - route::POST::/webhooks/payments  (routes/api.php:12)  [⚠ no test references this]  [public]
      ⚠ PUBLIC_WRITE (high): POST route with no auth middleware
```

This is annotation only — it never feeds the risk level or a `--fail-on` gate, it exists for routes only (Brain classifies nothing else), and false positives are suppressed where Brain's own config says so (`laravel-brain.security.trusted_route_names` / `trusted_route_uris`). A Livewire, Filament, or queue entry point never carries one of these tags at all — that absence means *not classified*, never "public" or "unauthenticated"; its real exposure comes from mount-time `authorize()` calls, middleware, or route placement the graph doesn't model.

Brain classifies exposure from the route's static middleware surface, so it can flag a `PUBLIC_WRITE` on a route that is in fact gated by a policy-constant check (`Gate::authorize(PostPolicy::UPDATE, …)`) it cannot see. Richter cross-checks such a finding against its own `authorizes` edges: when the route's reach authorizes a policy, it adds a note pointing at that policy. The note is evidence for you to verify rather than a suppression, and Brain's finding stays shown.

Brain also matches auth middleware by NAME (`auth`, `sanctum`, the literal `Illuminate\Auth\Middleware\Authenticate`). An app that subclasses Laravel's middleware matches none of those names, and `App\Http\Middleware\Authenticate extends …\Auth\Middleware\Authenticate` is the default skeleton shape, so every route behind it reads `[public]`. Richter walks the class ancestry that a name match cannot and notes the applied auth middleware beside the finding, on the same evidence-not-verdict terms. Middleware that authenticates without extending a framework class is still invisible to both; list it under `laravel-brain.security.auth_middleware` to teach Brain the name. A `MISSING_THROTTLE` is left to stand.

Pennant feature gating is annotated the same way. A route guarded by `EnsureFeaturesAreActive`
renders its flags inline (`[gated: ai-coach]`, a 🚩 badge in markdown, `entryPointGates` in JSON),
and a changed member or Blade view that itself checks a flag (`Feature::active(...)`, `@feature`)
notes it under Findings — a flag-gated change has a smaller live blast radius than the raw graph
suggests, and the reviewer should know. Route detection reads statically visible middleware
(a string alias like `'features:ai-coach'` or an FQCN-string form); the runtime-built
`EnsureFeaturesAreActive::using(...)` expression is invisible to static route parsing. Only the
`Feature` facade, `@feature`, and any `feature_gate_methods`-configured wrapper method are
recognised — a project convention like `FeatureToggle::BETA_DASHBOARD->isActive()` needs an
allowlist entry (see [Configuration](#configuration)) before it is noted.

A model field added to `$fillable`/`$casts`/`casts()` but never added to a resource that otherwise
mirrors the model's other fields is noted under Findings too (`AppResource.php mirrors App\Models\X
but does not expose <field> added to App\Models\X`) — the exact shape behind a payload field
silently going missing after an otherwise-correct edit. This is advisory only: it never feeds
`risk`, a `--fail-on` gate, or `affected-tests`. Deliberately no-guess — the default
`mirror_threshold` requires an exact match against the candidate's pre-existing fields before it
counts as a mirror, candidate resources are matched by graph wiring first and only by name when
nothing is wired, and anything the checker can't statically enumerate (a dynamic `toArray()` key, a
spread, an unparseable resource) is silently skipped rather than guessed at. On by default; disable
it for one run with `--no-payload-parity` or globally via `payload_parity.enabled` (see
[Configuration](#configuration)).

The same lane runs in the consumer direction: a `toArray()` key the diff *removes* from a
resource is flagged when a frontend file that consumes one of the routes the resource reaches
still reads it.

```text
  ! resources/js/Pages/Posts/Show.vue references GET /posts/{post} and reads 'published_at', which this diff removes from App\Http\Resources\PostResource (renamed to 'publishedAt'?)
```

The rename hint appears only when exactly one key was removed and one added, never from a
similarity guess. Consumers are the configured `frontend.roots` JS/TS files plus every Blade
view's inline `<script>` blocks; server-side Blade PHP never counts as a read. Matching is
access-shaped only (`.key`, `['key']`, destructuring), so a translation key or an unrelated
variable can't trigger it — though an object-literal *write* (`{ published_at: date }` in a
request body) can, since the destructuring pattern cannot tell the two apart. Suppress a known
false positive per key with an `ignore` entry (`App\Http\Resources\PostResource::published_at`).
The key diff itself is stricter than the model→resource side: a conditional (`mergeWhen`) or
constant-keyed entry makes the whole side unenumerable, and the lane stays silent rather than
guess at a removal. The scan only runs on a diff that actually removed a key, and it shares the
`payload_parity.enabled` switch and `--no-payload-parity` flag.

With `--markdown`, the report renders as GitHub-flavoured markdown: a risk badge up front, changed files as a table, entry points as a review checklist with their file:line, test tags and exposure badges, and long lists collapsed into `<details>` instead of truncated. The result is ready to paste into (or post onto) a pull request. `--markdown --explain` composes.

With `--html=<path>`, the report is written as ONE self-contained HTML file — every style and script inline, nothing fetched — so it opens offline straight from `file://` and travels as a CI artifact you can link from a pull request. It has five tabs: Overview (a Files / Impacted / Depth / Risk stat row, the reached entry points, and what to focus on), Graph (the blast radius as concentric rings, one per BFS depth), Paths (how each entry point reaches the change), Changes (the member-level diff, naming the member that drove a low-confidence verdict), and Advisory (findings, test references, and the gate). `--open` launches it in the default browser afterwards; a failing opener is a warning, never a failed run.

Every `file:line` in the report is a clickable editor link. `richter.editor` reads the same env chain debugbar and Ignition do (`CODE_EDITOR`, then `DEBUGBAR_EDITOR`, then `IGNITION_EDITOR`) and, like debugbar, defaults to `phpstorm`, so an existing setup needs no new variable. Supported: `phpstorm`, `idea`, `vscode`, `vscode-insiders`, `vscode-remote`, `vscodium`, `sublime`, `textmate`, `emacs`, `macvim`, `atom`, `nova`, `netbeans`, `xdebug`. Set it to `null` to keep the file references plain text — worth doing for a shared CI artifact, since a link embeds an absolute local path that only opens on the machine that generated the report.

`--html` cannot be combined with `--json` or `--markdown`. It replaces the text report on stdout but never touches the gate: `--html --fail-on=medium` still exits non-zero exactly when the gate trips. The diagram is capped at 300 nodes and says so in the report when it caps — the counts above it are never capped. Note that the HTML is a **rendering surface, not a contract**: its markup is free to change in any release. `--json` remains the semver-governed machine output.

With `--json`, stdout is a single JSON document (the full, uncapped report) with these top-level keys, or `{"error": "…"}` if the diff can't be resolved:

| Key | Type | Meaning |
|---|---|---|
| `base` | string | the ref the diff was taken against |
| `changed` | object | `{file: graph-node count}` per changed file |
| `coverage` | object | `{file: "analyzed" \| "unresolved"}` per changed file |
| `entryPoints` | string[] | entry-point nodes the change reaches |
| `entryPointPaths` | object | per reached entry point, the shortest call chain down to the changed code as `{node, via, file?, line?}` hops; a self-listed entry class carries no chain |
| `entryPointLocations` | object | per entry point, its defining `{file, line?}` (project-relative), when known |
| `entryPointSecurity` | object | per reached route, Brain's security surface `{exposure, riskLevel, issues[]}` — advisory annotation, routes only, never an input to `risk` or the gate; a Livewire/Filament/queue entry point has no key here at all, meaning "not classified," never "public" |
| `entryPointGates` | object | per reached route, the Pennant feature flags gating it — advisory annotation, never an input to `risk` or the gate |
| `entryPointTestReferences` | object | per reached entry point, `"referenced"` / `"referenced-no-behavioural-assertion"` / `"unreferenced"`; an entry point whose reference state cannot be determined is omitted from the map — advisory annotation, never an input to `risk`, the gate, or `affected-tests` selection |
| `impacted` | int | count of risk-bearing nodes reached |
| `relatedModels` | string[] | models reached only via association edges (context, not risk) |
| `risk` | string | `"low"` / `"medium"` / `"high"` |
| `lowConfidence` | bool | a changed member couldn't be pinned, so part of the estimate is coarse |
| `coarseCapApplied` | bool | a low-confidence `high` was capped to `medium` |
| `findings` | string[] | source-level findings, as shown above |
| `unresolved` | bool | any changed file is UNRESOLVED |
| `gate` | object | present only under a `--fail-on*` flag (see [Gating in CI](#gating-in-ci)) |

#### Risk levels

Risk is a coarse, advisory signal, deliberately simple so `--fail-on` stays predictable:

| Level | Condition |
|---|---|
| `high` | ≥ 3 entry points reached, **or** ≥ 20 impacted nodes |
| `medium` | ≥ 1 entry point reached, ≥ 5 impacted nodes, **or** the diff changes an entry-point class (job, listener, command, Livewire, observer, middleware) |
| `low` | everything else |

Association edges (model relationships, trait usage, `declares`) are reach and context, not risk. They never count toward the impacted-node total, so touching a hub model or trait can't saturate a change to `high` on breadth alone.

A separate guard covers low confidence. When a changed member can't be pinned to a graph node and only a coarse class-level seed is available, a resulting `high` is capped to `medium` (`coarseCapApplied`). A low-confidence estimate shouldn't drive the top level on its own.

### Gating in CI

`detect-changes` is advisory by default (exit 0). Two opt-in flags turn it into a gate:

- `--fail-on=<low|medium|high>` exits non-zero when the reported risk is at least that level (see [Risk levels](#risk-levels)).
- `--fail-on-unresolved` exits non-zero when any changed file is **UNRESOLVED** (changed code the graph can't place). It works independently of the risk threshold.

Either flag also fails an un-assessable diff (a broken or invalid base ref) rather than letting it pass as "no impact". Add `--json` and stdout carries a `gate` object alongside the report.

A pull-request check that surfaces the blast radius and fails on high-risk or unplaceable changes:

```yaml
name: Impact
on: pull_request

jobs:
  richter:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0   # detect-changes diffs against the base ref, so it must be in history
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate   # detect-changes boots the app to build the graph
      - run: php artisan richter:detect-changes --base=${{ github.event.pull_request.base.sha }} --fail-on=high --fail-on-unresolved
```

No GitHub Action ships with the package. `detect-changes` is a plain Artisan command, so wire it into whatever pipeline you already run.

> **Note:** `detect-changes` runs `php artisan`, so it boots your Laravel application to build the graph. The job needs whatever booting the app normally requires: typically an `.env` (`cp .env.example .env`) and an `APP_KEY` (`php artisan key:generate`), as above. Without them the command fails to boot before it can analyse anything.

The workflow analyzes the pull request's code, and analysis autoloads classes from that checkout (see above). For a public repository, keep the trigger on `pull_request` (never `pull_request_target` with a privileged token) so fork-submitted code runs without access to your secrets.

### Affected-test selection

```bash
php artisan richter:affected-tests                        # human-readable selection
php artisan richter:affected-tests --base=origin/develop
php artisan richter:affected-tests --json                 # {base, determinable, reasons, tests, frontendTests, unreferencedEntryPoints}
php artisan test $(php artisan richter:affected-tests --plain)   # simple form — coarse but safe
```

Diffs the same way `detect-changes` does — against `HEAD`, staged and unstaged edits are included,
so the selection reflects what's actually on disk before a commit exists to diff against. An
untracked (never `git add`-ed) file under `app/`, `resources/views/`, or a frontend root is one
`git diff` cannot see (see above), so here it makes the selection **undeterminable** (exit 2) rather
than emit a narrowed set that silently omits it — the stderr note still fires, and `git add`-ing the
file includes it. The note is stderr-only, never on stdout, so `--plain`/`--json` stay clean.

The simple form only ever errs toward running more: both an undetermined selection and a
determined-but-empty one leave `$(…)` empty, and an argument-less runner executes the full suite.
To also skip the run when the selection is determined and empty, branch on the exit code:

```bash
tests=$(php artisan richter:affected-tests --plain); status=$?
if [ "$status" -eq 0 ] && [ -z "$tests" ]; then echo "No affected tests."
elif [ "$status" -eq 0 ]; then php artisan test $tests
else php artisan test; fi   # exit 2: not determinable — full suite
```

Inverts the test-reference index into a selection: the test files that reference any entry point
the diff reaches, plus the tests that import any changed **or reached** class (a unit test of an
intermediate caller never touches an entry point). A test naming a Livewire component by string
(`Livewire::test('admin.dashboard')`, the `livewire()` helper) counts as referencing
`App\Livewire\Admin\Dashboard` via the default naming convention. A `schedule::` entry resolves
through the command it runs. Only conventionally-named `*Test.php` files are selected — helpers and fixtures
under `tests/` never end up as runner arguments, and an entry point whose only references live in
a support trait blocks determination rather than silently dropping the tests using that trait.
Selection is reference-based recall, not proof of coverage — reached entry points nothing
references contribute nothing, and the report says how many those are.

It fails safe, and the exit code is the contract:

| Exit | Meaning |
|---|---|
| `0` | Selection determined (possibly empty). |
| `2` | **Not determinable — run the full suite.** Any UNRESOLVED file, low-confidence seed, an unparseable app file, an unfollowable dispatch *that a possible dispatch target in the change's reach could hide*, an uncheckable entry point, or an untracked relevant file `git diff` can't see trips this; the reasons are printed (text) or carried in `reasons` (JSON). (A lone unfollowable dispatch no longer blocks a change with no dispatch target upstream.) |
| `1` | Usage or unexpected error. |

In `--plain` mode an undeterminable run prints nothing, so the command-substitution form degrades
to the full suite by construction — as does a determined-but-empty selection, which is why the
exit-code branch above is the precise form.

### Frontend changes (Wayfinder / Ziggy)

Opt-in — point `frontend.roots` at your frontend source in `config/richter.php`:

```php
'frontend' => [
    'roots' => ['resources/js'],
],
```

Changed `.ts`/`.tsx`/`.js`/`.jsx`/`.vue` files are then scanned for the backend endpoints they
reference, and those routes are reported as touched entry points — with their location, exposure
and gate annotations, feeding `richter:affected-tests` — while `risk` and `impacted` stay
untouched: a frontend edit does not change backend behaviour, and the report says so explicitly.
Detected references:

- **[Wayfinder](https://github.com/laravel/wayfinder) imports** —
  `@/actions/App/Http/Controllers/PostController` resolves through the router's action index
  (method-precise; aliased, default, invokable and `import type` forms included), and
  `@/routes/posts` route imports plus Ziggy `route('name')` calls resolve through the route
  names. Wayfinder's generated trees (`actions/`, `routes/`, `wayfinder/` under each root) and
  Ziggy's generated route map (`ziggy.js`) are excluded as regeneration churn, and `.d.ts`
  declaration files are never scanned — see `frontend.generated_paths` above.
- **Endpoint strings**, matched against the app's route templates: plain literals
  (`axios.post('/posts')`) and backtick templates whose interpolations wildcard one segment
  (`` fetch(`/posts/${id}`) `` matches `/posts/{post}`). A `/`-leading literal or template only
  counts as the **first argument of an allowlisted HTTP/route callee** — `route`, `fetch`,
  `axios`, `useFetch`, `$http`, `$` (jQuery), `window`, `page`/`cy` (Playwright/Cypress
  navigation) by default, plus `frontend.http_callees` — matched on the callee's leading
  identifier before a `.method` (`axios.get(...)`, `$http.post(...)`, `window.fetch(...)`,
  `page.goto(...)`). A verb-named call pins the HTTP method, whether the verb is the callee
  itself (`post('/x')`) or its `.method` segment (`axios.post('/x')`); anything unrecognisable
  stays method-agnostic and never narrows the match. Inline `<script>` blocks in changed Blade
  views get the same literal scan. Gating on the callee means a constants file, nav-link config,
  i18n helper (`translate('/preferences')`), or any other non-HTTP call is never mistaken for an
  endpoint call — and a project-custom HTTP wrapper needs registering via `frontend.http_callees`
  before its literals seed. A few idioms are a documented, deliberate recall loss: a URL assigned
  to a variable and used later (`const URL = '/x'; fetch(URL)`), an options object's `url`
  property (`axios({ url: '/x' })`), and the `request(method, url)` second-argument idiom (the
  URI's callee can no longer be identified once it isn't the call's first argument).

Frontend spec files (`*.test.*`, `*.spec.*`, `*.cy.*` under the roots, or `frontend.test_paths`)
referencing a touched route surface in `richter:affected-tests` as an advisory `frontendTests`
list for the JS runner — never in `--plain` (which feeds the PHP runner), and never a
determinability input.

The scan is regex-based and says so when it can't see: a dynamic `route(`…`)` argument or an
unmatched Wayfinder action import marks the file UNRESOLVED (and `richter:affected-tests` exits
`2`), while an unmatched `route('name')` string simply isn't a reference — `routes/` modules and
`route()` helpers collide with frontend-router idioms, so unmatched names never guess. Before a
dynamic argument taints the file, it gets one resolution attempt against a same-module
`const`/`enum` string constant (`route(ROUTES.player)` resolves when `ROUTES` is a flat `const`
with exactly one `player` member); anything less certain — `let`, multiple declarations, imported
constants, nested bodies — keeps the fail-safe.

The bridge also runs in reverse, without any configuration: a changed backend member that
renders an Inertia page (`Inertia::render('Posts/Show')`, the `inertia()` helper) is noted
under Findings with the resolved page file under `frontend.pages_path` — or with an explicit
"no page file found" when the component doesn't resolve, which usually means a renamed or
deleted page.

### Scoring accuracy against replayable history

```bash
php artisan richter:benchmark
php artisan richter:benchmark --case=TICKET-123
php artisan richter:benchmark:add abc1234
php artisan richter:benchmark:add abc1234 --control
```

Replays historical fix commits (configured in `richter.benchmark_cases`) through the report: bug fixtures must resolve and reach an entry point; benign controls cap the risk a harmless change may report. Run it after changing the graph or tracers. A control flipping green→red is a regression in trustworthiness.

`richter:benchmark:add` scaffolds a case from a historical fix commit: it dry-runs the commit through the same replay, reports what it would score today, and prints a paste-ready `benchmark_cases` entry. It never edits the config file.

Each case in `config/richter.php`:

```php
'benchmark_cases' => [
    [
        'key' => 'TICKET-123',                 // label, and the --case selector
        'fix_commit' => 'abc1234',             // commit whose diff is replayed through the report
        'bug_class' => 'background-job change (data not copied on duplication)',
        'expect_signal' => true,               // bug fixture: must resolve and reach an entry point
        'max_risk' => 'high',                  // caps the risk a control (expect_signal: false) may report
    ],
],
```

### Graph cache

Building the code graph is the dominant cost of every command. Richter caches the built graph on disk (default: `storage/framework/cache/richter/graph.json`), keyed by a content fingerprint of everything the build reads: `app/`, `routes/`, `resources/views`, the relevant config, and the package versions. Any input change rebuilds automatically, so a hit can only ever serve the graph the current code produces; there is no TTL to tune and no stale window.

- The cache is on by default; set `richter.cache.enabled` to `false` to disable it.
- `--no-cache` (on every command) bypasses it for one run, the escape hatch for an input the fingerprint doesn't cover.
- A corrupt or mismatched cache file reads as a miss and is rebuilt; it never fails a run.
- `--profile` (on `richter:detect-changes`) forces a fresh build and prints a phase-by-phase timing split to stderr, for judging where build time goes on a given codebase.

### MCP server

When [`laravel/mcp`](https://github.com/laravel/mcp) is installed, Richter registers a local MCP server named `richter` with four read-only tools: `impact` (blast radius plus reached entry surfaces of a symbol), `trace` (shortest call-direction path between two symbols), `detect-changes` (advisory impact of the current branch diff), and `affected-tests` (the test selection the diff warrants). For `affected-tests`, `determinable: false` means run the full suite — every non-determinable cause returns that shape with its reasons, never a tool error. Three read-only resources cover orientation without a tool call:

| Resource | URI | Content |
|---|---|---|
| Entry points | `richter://graph/entry-points` | Every statically-known entry surface — routes, commands, schedules, Livewire/Filament components — with kind and `file:line` where known. |
| Graph stats | `richter://graph/stats` | Node and edge counts by edge type, plus the honesty flags (`hasUnparseableFiles`, `hasUnresolvedDispatches`). |
| Config | `richter://config` | The effective analysis configuration: base ref, root namespace, entry-point roots, dispatch helpers, feature-gate wrappers, payload-parity settings, the frontend bridge, cache and parallel switches. |

A coding agent can then triage changes without shelling out to Artisan. Because the MCP session holds the graph cache in memory, repeated tool calls in one review don't rebuild the graph. Every tool returns MCP structured content in the same shape as the CLI `--json` output, so an agent can branch on fields instead of parsing prose. The supported range is `laravel/mcp` `^0.8||^0.9`; `composer.json` carries a matching `conflict` entry so an unvalidated release fails at resolution time rather than at boot.

Point Claude Code, Cursor, or any MCP client at the Artisan entry point, e.g. in `.mcp.json`:

```json
{
    "mcpServers": {
        "richter": {
            "command": "php",
            "args": ["artisan", "mcp:start", "richter"]
        }
    }
}
```

## Configuration

`config/richter.php`:

| Key | Default | Purpose |
|---|---|---|
| `default_base` | `origin/main` | Git ref `richter:detect-changes` diffs against when `--base` is omitted. |
| `root_namespace` | `null` (derived) | The root namespace of the classes under `app/`. `null` reads it from the PSR-4 entry in your `composer.json` that maps to `app/` — `App\` on a conventional app, and the fallback when no single entry does. Set it explicitly (e.g. `'Acme\\'`) when two or more PSR-4 roots map to `app/`. Every command warns on stderr when the root it used matches no `app/` mapping in `composer.json` (or when two-plus roots map there and only one is traced), and the `--markdown` report carries the same note inside the document, since stderr never reaches a posted comment. |
| `editor` | `phpstorm` (via `CODE_EDITOR` / `DEBUGBAR_EDITOR` / `IGNITION_EDITOR`) | Editor for the clickable `file:line` links in the `--html` report — reuses debugbar's/Ignition's env chain. One of `phpstorm`, `idea`, `vscode`(+`-insiders`/`-remote`/`ium`), `sublime`, `textmate`, `emacs`, `macvim`, `atom`, `nova`, `netbeans`, `xdebug`, or `null` to keep the references plain text. |
| `dispatch_helpers` | `[]` | Project-custom global job-dispatch helper functions (e.g. `dispatch_with_retries`) the dispatch tracer should follow. |
| `feature_gate_methods` | `[]` | `FQCN::method` allowlist of project wrappers around Pennant (e.g. `App\Enums\FeatureToggle::isActive`) — an `EnumCase->method()` call then annotates the change as flag-gated, alongside the built-in `Feature` facade / `@feature` support. |
| `payload_parity` | `{enabled: true, mirror_threshold: 1.0, ignore: []}` | Advisory lane flagging payload-parity breaks in both directions: a model field added but never mirrored into its resource, and a resource `toArray()` key removed while a frontend consumer of its routes still reads it. `mirror_threshold` is the exact-mirror fraction (`1.0` — no-guess by default); `ignore` suppresses a model field (`App\Models\X::field`), a resource key (`App\Http\Resources\XResource::key`), or a whole resource (its FQCN, both directions). Disable for one run with `--no-payload-parity`, or globally by setting `enabled` to `false`. |
| `second_hop` | `true` | Read the bodies of statically-called methods. A class reached only through `Foo::bar()` is placed in the graph but its bodies are never read by Brain's route-anchored analysis, so what it constructs stays invisible — and an inherited method's work never connects through the subclass. Off trades that reach for build time (~4.5s on a 4,000-file app). |
| `entry_point_roots` | `Jobs`, `Listeners`, `Console/Commands`, `Filament`, `Helpers`, `Http/Middleware`, `Livewire`, `Observers` | Directories under `app/` traced as entry points beyond Brain's route-anchored graph (graph tracing only; the analyzer's risk-floor namespace heuristics are fixed). `richter:impact`, `richter:trace` and `richter:detect-changes` note on stderr when an `app/` directory holds classes and *none* of them reach the graph — the shape a subsystem takes when its dispatch is one richter cannot follow and its directory is not listed here. Measured, not diffed against this list: partial presence is normal, so only total absence is reported, and only from five classes up. |
| `frontend.roots` | `[]` (off) | Frontend roots whose changed TS/JS/Vue files are scanned for Wayfinder/Ziggy endpoint references (see [Frontend changes](#frontend-changes-wayfinder--ziggy)). |
| `frontend.generated_paths` | `actions`, `routes`, `wayfinder`, `ziggy.js` | Wayfinder's generated trees and Ziggy's generated route map under each frontend root — excluded from scanning as regeneration churn. Each entry matches a directory, an exact file, or a `*`-glob (crosses `/`). `.d.ts` files are always excluded, regardless of this list. |
| `frontend.pages_path` | `resources/js/Pages` | Where Inertia page components live — a changed member rendering a page is noted under Findings with the resolved file. |
| `frontend.test_paths` | `[]` (the frontend roots) | Directories scanned for frontend spec files whose endpoint references feed `richter:affected-tests`' advisory `frontendTests` list. |
| `frontend.http_callees` | `[]` | Extra JS/TS callees, beyond the built-in `route`/`fetch`/`axios`/`useFetch`/`$http`/`$`/`window`/`page`/`cy`, whose call-argument string literals count as backend endpoints. Matched on the callee's leading identifier, e.g. `myHttpClient` for `myHttpClient.post(...)`. |
| `cache.enabled` | `true` | On-disk graph cache, keyed by a content fingerprint of the build inputs (see [Graph cache](#graph-cache)). |
| `cache.directory` | `null` | Cache location; `null` means `storage/framework/cache/richter`. |
| `parallel` | `true` | Build Brain's analysis and richter's own tracers concurrently (the tracers run in a child `artisan` process) instead of sequentially — shortens a cold build on a multi-core machine. The merged graph is identical either way; any child-process failure falls back to the serial build, and `--profile` forces serial. Set to `false` to always build serially. |
| `benchmark_cases` | `[]` | Replayable accuracy fixtures for `richter:benchmark`. |

Filament coverage is class-level: resources, pages and widgets surface as entry points (and their
computed HTTP routes come in through Laravel Brain when Filament is installed), but individual
table/bulk actions are not modelled as separate entry points.

Richter assumes standard Laravel conventions: `app/Models`, `app/Policies`, `resources/views`, and
`tests/`. The root namespace itself needn't be `App\` — it is derived from `composer.json` (see
`root_namespace` above) — but the sub-namespaces under it are read as conventional.

## Testing

```bash
composer test        # test suite only
composer qa-check    # read-only pre-push gate: Rector + Pint dry-runs, PHPStan, tests — mirrors CI
```

`composer qa` is the auto-fixing variant — it rewrites the working tree (Rector, Pint), so use
`qa-check` when you only want to verify.

## Changelog

See [CHANGELOG](CHANGELOG.md) for what changed per release.

## Security

Found a vulnerability? Don't open an issue — see [SECURITY](SECURITY.md) for where to send it.

## License

MIT. See [LICENSE](LICENSE).
