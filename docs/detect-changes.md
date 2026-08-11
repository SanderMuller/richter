# `richter:detect-changes` reference

The [README](../README.md#advisory-change-impact-of-the-current-diff) covers day-to-day usage. This page documents the annotation lanes, the payload-parity checks, and the output formats in full.

## Unplaceable files and the defined-node fallback

Before a file falls through to UNRESOLVED, richter tries one last lane: the nodes the graph says *that file defines*. Not every entry surface has a class name to look up. A scheduled task is identified by what it runs and how often, and a routes file is not a class at all, so a change to a legacy `app/Console/Kernel.php` or to `routes/api.php` would otherwise be unplaceable despite defining surfaces the graph already knows.

Those surfaces list as touched, but they are never walked and they never move the risk level. A file that *declares* a surface has not called into it: adding one line to a `$commands` array cannot break the ten commands registered beside it, and rating the edit by everything those ten reach would be breadth dressed up as consequence. The lane runs only when every other lane came up empty, so member-level precision elsewhere is unaffected: a one-method change to a controller still seeds that method, not the class its file also defines.

## Test-reference tags

Every reached entry point is tagged `[test-referenced]` or `[⚠ no test references this]`, and a referenced entry point whose referencing tests contain no behavioural assertion the scan recognises is tagged `[test-referenced — no behavioural assertion found]`, a heuristic prompt rather than a coverage verdict. An entry point whose behaviour you changed with nothing referencing it is a place to add a test; the tag flags a missing reference, not proof the code is untested. The `tests/` scan behind the tags only runs when an entry surface was actually reached.

## Security annotations

Reached routes inherit [Laravel Brain](https://github.com/laramint/laravel-brain)'s security surface as advisory annotation: the exposure level renders inline (`[public]`, `[guest]`, `[authed]`, `[admin]`) and any statically detected issues render under the route:

```text
  - route::POST::/webhooks/payments  (routes/api.php:12)  [⚠ no test references this]  [public]
      ⚠ PUBLIC_WRITE (high): POST route with no auth middleware
```

This is annotation only: it never feeds the risk level or a `--fail-on` gate, it exists for routes only (Brain classifies nothing else), and false positives are suppressed where Brain's own config says so (`laravel-brain.security.trusted_route_names` / `trusted_route_uris`). A Livewire, Filament, or queue entry point never carries one of these tags at all; that absence means *not classified*, never "public" or "unauthenticated", and its real exposure comes from mount-time `authorize()` calls, middleware, or route placement the graph doesn't model.

Brain classifies exposure from the route's static middleware surface, so it can flag a `PUBLIC_WRITE` on a route that is in fact gated by a policy-constant check (`Gate::authorize(PostPolicy::UPDATE, …)`) it cannot see. Richter cross-checks such a finding against its own `authorizes` edges: when the route's reach authorizes a policy, it adds a note pointing at that policy. The note is evidence for you to verify rather than a suppression, and Brain's finding stays shown.

Brain also matches auth middleware by NAME (`auth`, `sanctum`, the literal `Illuminate\Auth\Middleware\Authenticate`). An app that subclasses Laravel's middleware matches none of those names, and `App\Http\Middleware\Authenticate extends …\Auth\Middleware\Authenticate` is the default skeleton shape, so every route behind it reads `[public]`. Richter walks the class ancestry that a name match cannot and notes the applied auth middleware beside the finding, on the same evidence-not-verdict terms. Middleware that authenticates without extending a framework class is still invisible to both; list it under `laravel-brain.security.auth_middleware` to teach Brain the name. A `MISSING_THROTTLE` is left to stand.

## Feature-flag (Pennant) annotations

Pennant feature gating is annotated the same way. A route guarded by `EnsureFeaturesAreActive` renders its flags inline (`[gated: ai-coach]`, a 🚩 badge in markdown, `entryPointGates` in JSON), and a changed member or Blade view that itself checks a flag (`Feature::active(...)`, `@feature`) notes it under Findings; a flag-gated change has a smaller live blast radius than the raw graph suggests, and the reviewer should know. Route detection reads statically visible middleware (a string alias like `'features:ai-coach'` or an FQCN-string form); the runtime-built `EnsureFeaturesAreActive::using(...)` expression is invisible to static route parsing. Only the `Feature` facade, `@feature`, and any `feature_gate_methods`-configured wrapper method are recognised; a project convention like `FeatureToggle::BETA_DASHBOARD->isActive()` needs an allowlist entry (see [Configuration](configuration.md)) before it is noted.

## Payload parity

A model field added to `$fillable`/`$casts`/`casts()` but never added to a resource that otherwise mirrors the model's other fields is noted under Findings (`AppResource.php mirrors App\Models\X but does not expose <field> added to App\Models\X`), the exact shape behind a payload field silently going missing after an otherwise-correct edit. This is advisory only: it never feeds `risk`, a `--fail-on` gate, or `affected-tests`. Deliberately no-guess: the default `mirror_threshold` requires an exact match against the candidate's pre-existing fields before it counts as a mirror, candidate resources are matched by graph wiring first and only by name when nothing is wired, and anything the checker can't statically enumerate (a dynamic `toArray()` key, a spread, an unparseable resource) is silently skipped rather than guessed at. On by default; disable it for one run with `--no-payload-parity` or globally via `payload_parity.enabled` (see [Configuration](configuration.md)).

The same lane runs in the consumer direction: a `toArray()` key the diff *removes* from a resource is flagged when a frontend file that consumes one of the routes the resource reaches still reads it.

```text
  ! resources/js/Pages/Posts/Show.vue references GET /posts/{post} and reads 'published_at', which this diff removes from App\Http\Resources\PostResource (renamed to 'publishedAt'?)
```

The rename hint appears only when exactly one key was removed and one added, never from a similarity guess. Consumers are the configured `frontend.roots` JS/TS files plus every Blade view's inline `<script>` blocks; server-side Blade PHP never counts as a read. Matching is access-shaped only (`.key`, `['key']`, destructuring), so a translation key or an unrelated variable can't trigger it, though an object-literal *write* (`{ published_at: date }` in a request body) can, since the destructuring pattern cannot tell the two apart. Suppress a known false positive per key with an `ignore` entry (`App\Http\Resources\PostResource::published_at`). The key diff itself is stricter than the model→resource side: a conditional (`mergeWhen`) or constant-keyed entry makes the whole side unenumerable, and the lane stays silent rather than guess at a removal. The scan only runs on a diff that actually removed a key, and it shares the `payload_parity.enabled` switch and `--no-payload-parity` flag.

A third lane covers the request side. A field removed from a form request's `rules()` stops being validated and stops appearing in `validated()`, so a frontend that still sends it now sends it into nothing, and nothing anywhere reports an error:

```text
  ! resources/js/Pages/Posts/Create.vue posts to POST /posts and sends 'subtitle', which this diff removes from App\Http\Requests\StorePostRequest::rules() (renamed to 'sub_title'?)
```

Matching here is send-shaped rather than access-shaped: an object-literal key, a `FormData` `append`/`set` with a literal name, or an assignment onto a payload by dot or bracket. The false positive mirrors the response lane's: a file that both posts to and reads from the endpoint can match on a field it only reads, and the same per-field `ignore` entry (`App\Http\Requests\StorePostRequest::subtitle`) suppresses it.

The `rules()` parse is as strict as the resource one: a method that builds its array up (`$rules = […]; if (…) …; return $rules;`), a spread, or a constant key makes the side unenumerable and the lane stays silent. A dotted rule key (`items.*.name`) matches nothing on purpose: its segments appear separately in a payload, and matching the last one would fire on every unrelated `name` in the file.

## Middleware group membership

Route middleware is resolved by **alias** and never by group. `->middleware('auth')` reaches the graph as `middleware::auth` and Richter rewrites that onto the FQCN, so an aliased middleware is connected to the routes it guards. `->middleware('api')` reaches it as a bare `middleware::api` node, and the classes inside that group are connected to nothing.

The group is deliberately not expanded into edges: mapping a global group onto every route would make each of its members report every route in the app as an entry point. But the middleware still self-lists as an entry point (it lives under `\Http\Middleware\`), so without help the report reads "one entry point: the middleware itself" for a change that runs on every route in the group. The answer is wrongly *sized* rather than missing, and this note supplies the size:

```text
  ! App\Http\Middleware\EnsureTenant runs in middleware group 'api', which guards 142 routes; group membership is not drawn as edges, so those routes are not in the reach above
```

The count comes from the `route:: → middleware::<group>` edges already in the graph, so it counts endpoints only; a controller-level attachment of the same group does not inflate it. Membership is read from `$middlewareGroups` on a Laravel 10 Kernel or the `->web(append: [...])` form in a Laravel 11+ `bootstrap/app.php`. A member written as an alias resolves through the same alias map, and parameters are cut first (`tenant:strict` is one alias with an argument). Nesting is followed: a group may list another group by name and Laravel expands it, so a member of the inner group is also attributed to every group that includes it, and its routes count too. A name that is both a group and an alias is skipped rather than resolved one way (the wrong choice would point the note at the wrong routes), and cycles terminate on a seen-set.

Silence is the answer whenever the size cannot be vouched for: a group no route references, a middleware in no group, an unreadable Kernel, or an upgraded app that kept an empty `app/Http/Kernel.php` stub beside its bootstrap groups; that stub wins the lookup and yields no groups, which costs the note and never produces a wrong one.

Advisory, like everything else on this page. Letting a group's routes count toward reach would raise the risk level of every middleware edit in every app at once, which needs its own evidence rather than arriving as a side effect of an annotation.

## `--markdown` and `--html` output

With `--markdown`, the report renders as GitHub-flavoured markdown: a risk badge up front, changed files as a table, entry points as a review checklist with their file:line, test tags and exposure badges, and long lists collapsed into `<details>` instead of truncated. The result is ready to paste into (or post onto) a pull request. `--markdown --explain` composes.

With `--html=<path>`, the report is written as ONE self-contained HTML file (every style and script inline, nothing fetched), so it opens offline straight from `file://` and travels as a CI artifact you can link from a pull request. It has five tabs: Overview (a Files / Impacted / Depth / Risk stat row, the reached entry points, and what to focus on), Graph (the blast radius as concentric rings, one per BFS depth), Paths (how each entry point reaches the change), Changes (the member-level diff, naming the member that drove a low-confidence verdict), and Advisory (findings, test references, and the gate). `--open` launches it in the default browser afterwards; a failing opener is a warning, never a failed run.

Every `file:line` in the report is a clickable editor link. `richter.editor` reads the same env chain debugbar and Ignition do (`CODE_EDITOR`, then `DEBUGBAR_EDITOR`, then `IGNITION_EDITOR`) and, like debugbar, defaults to `phpstorm`, so an existing setup needs no new variable. Supported: `phpstorm`, `idea`, `vscode`, `vscode-insiders`, `vscode-remote`, `vscodium`, `sublime`, `textmate`, `emacs`, `macvim`, `atom`, `nova`, `netbeans`, `xdebug`. Set it to `null` to keep the file references plain text, worth doing for a shared CI artifact, since a link embeds an absolute local path that only opens on the machine that generated the report.

`--html` cannot be combined with `--json` or `--markdown`. It replaces the text report on stdout but never touches the gate: `--html --fail-on=medium` still exits non-zero exactly when the gate trips. The diagram is capped at 300 nodes and says so in the report when it caps; the counts above it are never capped. Note that the HTML is a **rendering surface, not a contract**: its markup is free to change in any release. `--json` remains the semver-governed machine output.

## `--json` output

With `--json`, stdout is a single JSON document (the full, uncapped report) with these top-level keys, or `{"error": "…"}` if the diff can't be resolved:

| Key | Type | Meaning |
|---|---|---|
| `base` | string | the ref the diff was taken against |
| `changed` | object | `{file: graph-node count}` per changed file |
| `coverage` | object | `{file: "analyzed" \| "unresolved"}` per changed file |
| `entryPoints` | string[] | entry-point nodes the change reaches |
| `entryPointPaths` | object | per reached entry point, the shortest call chain down to the changed code as `{node, via, file?, line?}` hops; a self-listed entry class carries no chain |
| `entryPointLocations` | object | per entry point, its defining `{file, line?}` (project-relative), when known |
| `entryPointSecurity` | object | per reached route, Brain's security surface `{exposure, riskLevel, issues[]}` (advisory annotation, routes only, never an input to `risk` or the gate); a Livewire/Filament/queue entry point has no key here at all, meaning "not classified," never "public" |
| `entryPointGates` | object | per reached route, the Pennant feature flags gating it (advisory annotation, never an input to `risk` or the gate) |
| `entryPointTestReferences` | object | per reached entry point, `"referenced"` / `"referenced-no-behavioural-assertion"` / `"unreferenced"`; an entry point whose reference state cannot be determined is omitted from the map (advisory annotation, never an input to `risk`, the gate, or `affected-tests` selection) |
| `impacted` | int | count of risk-bearing nodes reached |
| `relatedModels` | string[] | models reached only via association edges (context, not risk) |
| `risk` | string | `"low"` / `"medium"` / `"high"` |
| `lowConfidence` | bool | a changed member couldn't be pinned, so part of the estimate is coarse |
| `coarseCapApplied` | bool | a low-confidence `high` was capped to `medium` |
| `findings` | string[] | source-level findings |
| `unresolved` | bool | any changed file is UNRESOLVED |
| `gate` | object | present only under a `--fail-on*` flag (see [Gating in CI](../README.md#gating-in-ci)) |
