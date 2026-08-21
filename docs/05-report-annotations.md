# Report annotations

`richter:detect-changes` renders four lanes beside the reach it reports: security exposure per route, Pennant feature gates, payload-parity breaks, and middleware group membership.

Two of them are annotation only. Pennant gates and middleware group membership feed nothing: not the [risk level](07-risk-levels.md), not a gate, and not the [affected-test selection](11-affected-tests.md).

Membership is not the same thing as the group's guards. Which routes a group runs on is the annotation, and it feeds nothing. A guard the group itself lost is a [tier-3 hazard](07-risk-levels.md#hazards) read from `bootstrap/app.php` or a legacy Kernel, and that does decide the level.

The other two do reach the level, each through one narrow door:

- Payload-parity results are tier-2 [hazards](07-risk-levels.md#hazards). A payload key a consumer still reads is a thing that breaks, so it is graded like any other hazard. It prints under `Hazards`, not `Findings`.
- Security exposure decides a hazard's reach class. A `PUBLIC_WRITE` route with no guard richter can point at, reaching a hazardous member, makes that hazard `public-write`. The annotation itself is still inherited from Brain and still seeds nothing.

## Security annotations

Reached routes inherit [Laravel Brain](https://github.com/laramint/laravel-brain)'s security surface as advisory annotation: the exposure level renders inline (`[public]`, `[guest]`, `[authed]`, `[admin]`) and any statically detected issues render under the route:

```text
  - route::POST::/webhooks/payments  (routes/api.php:12)  [⚠ no test references this]  [public]
      ⚠ PUBLIC_WRITE (high): POST route with no auth middleware
```

It exists for routes only (Brain classifies nothing else), and false positives are suppressed where Brain's own config says so (`laravel-brain.security.trusted_route_names` / `trusted_route_uris`). A Livewire, Filament, Nova or queue entry point never carries one of these tags at all; that absence means the surface was not classified, never "public" or "unauthenticated", and its real exposure comes from mount-time `authorize()` calls, middleware, or route placement the graph doesn't model.

Brain classifies exposure from the route's static middleware surface, so it can flag a `PUBLIC_WRITE` on a route that is in fact gated by a policy-constant check (`Gate::authorize(PostPolicy::UPDATE, …)`) it cannot see. Richter cross-checks such a finding against its own `authorizes` edges: when the route's reach authorizes a policy, it adds a note pointing at that policy. The note is evidence for you to verify rather than a suppression, and Brain's finding stays shown.

Brain also matches auth middleware by name (`auth`, `sanctum`, the literal `Illuminate\Auth\Middleware\Authenticate`). An app that subclasses Laravel's middleware matches none of those names, and `App\Http\Middleware\Authenticate extends …\Auth\Middleware\Authenticate` is the default skeleton shape, so every route behind it reads `[public]`. Richter walks the class ancestry that a name match cannot and notes the applied auth middleware beside the finding, again as evidence rather than a suppression. Middleware that authenticates without extending a framework class is still invisible to both; list it under `laravel-brain.security.auth_middleware` to teach Brain the name. A `MISSING_THROTTLE` is left to stand.

## Feature-flag (Pennant) annotations

Pennant feature gating is annotated the same way. A route guarded by `EnsureFeaturesAreActive` renders its flags inline (`[gated: ai-coach]`, a 🚩 badge in markdown, `entryPointGates` in JSON), and a changed member or Blade view that itself checks a flag (`Feature::active(...)`, `@feature`) notes it under Findings; a flag-gated change has a smaller live blast radius than the raw graph suggests, and the reviewer should know. Route detection reads statically visible middleware (a string alias like `'features:ai-coach'` or an FQCN-string form); the runtime-built `EnsureFeaturesAreActive::using(...)` expression is invisible to static route parsing. Only the `Feature` facade, `@feature`, and any `feature_gate_methods`-configured wrapper method are recognised; a project convention like `FeatureToggle::BETA_DASHBOARD->isActive()` needs an allowlist entry (see [Configuration](16-configuration.md)) before it is noted.

## Payload parity

A model field added to `$fillable`/`$casts`/`casts()` but never added to a resource that otherwise mirrors the model's other fields is reported as a tier-2 hazard (`AppResource.php mirrors App\Models\X but does not expose <field> added to App\Models\X`), the exact shape behind a payload field silently going missing after an otherwise-correct edit. The lane makes no guesses: the default `mirror_threshold` requires an exact match against the candidate's pre-existing fields before it counts as a mirror, candidate resources are matched by graph wiring first and only by name when nothing is wired, and anything the checker can't statically enumerate (a dynamic `toArray()` key, a spread, an unparseable resource) is silently skipped rather than guessed at. On by default; disable it for one run with `--no-payload-parity` or globally via `payload_parity.enabled` (see [Configuration](16-configuration.md)).

The same lane runs in the consumer direction: a `toArray()` key the diff removes from a resource is flagged when a frontend file that consumes one of the routes the resource reaches still reads it.

```text
  ! resources/js/Pages/Posts/Show.vue references GET /posts/{post} and reads 'published_at', which this diff removes from App\Http\Resources\PostResource (renamed to 'publishedAt'?)
```

The rename hint appears only when exactly one key was removed and one added, never from a similarity guess. Consumers are the configured `frontend.roots` JS/TS files plus every Blade view's inline `<script>` blocks; server-side Blade PHP never counts as a read. Matching is access-shaped only (`.key`, `['key']`, destructuring), so a translation key or an unrelated variable can't trigger it, though an object-literal write (`{ published_at: date }` in a request body) can, since the destructuring pattern cannot tell the two apart. Suppress a known false positive per key with an `ignore` entry (`App\Http\Resources\PostResource::published_at`). The key diff itself is stricter than the model→resource side: a conditional (`mergeWhen`) or constant-keyed entry makes the whole side unenumerable, and the lane stays silent rather than guess at a removal. The scan only runs on a diff that actually removed a key, and it shares the `payload_parity.enabled` switch and `--no-payload-parity` flag.

A third lane covers the request side. A field removed from a form request's `rules()` stops being validated and stops appearing in `validated()`, so a frontend that still sends it now sends it into nothing, and nothing anywhere reports an error:

```text
  ! resources/js/Pages/Posts/Create.vue sends 'subtitle' to POST /posts, which this diff removes from App\Http\Requests\StorePostRequest::rules() (renamed to 'sub_title'?)
```

Matching here is send-shaped rather than access-shaped: an object-literal key, a `FormData` `append`/`set` with a literal name, or an assignment onto a payload by dot or bracket. The route's own verb is printed rather than assumed, because a sent field is not always a POST body: a query parameter on a `GET` route is matched the same way. The false positive mirrors the response lane's: a file that both posts to and reads from the endpoint can match on a field it only reads, and the same per-field `ignore` entry (`App\Http\Requests\StorePostRequest::subtitle`) suppresses it.

Validation written inline is covered by the same lane. A form request is the documented
convention, not the only place validation lives: an action that validates a handful of fields
commonly does it in the controller, and those fields are just as removable. Both
`$request->validate([...])` and the `ValidatesRequests` form `$this->validate($request, [...])` are
read, the latter only where the class pulls that trait in itself, since `validate` is an ordinary
method name and a class with its own would otherwise have its argument read as request rules. The
finding is anchored on the method that holds the call rather than the file, so a controller's
other actions are not implicated in a field one of them dropped. The per-field `ignore`
entry takes the same shape against that member (`App\Http\Controllers\PostController::store`).

The `rules()` parse is as strict as the resource one: a method that builds its array up (`$rules = […]; if (…) …; return $rules;`), a spread, or a constant key makes the side unenumerable and the lane stays silent. Inline rules are
held to the same bar: a method that passes rules it cannot read (a variable, a merge) is skipped
entirely rather than reported as having removed every field. A dotted rule key (`items.*.name`) matches nothing: its segments appear separately in a payload, and matching the last one would fire on every unrelated `name` in the file.

## Middleware group membership

Route middleware is resolved by alias and never by group. `->middleware('auth')` reaches the graph as `middleware::auth` and Richter rewrites that onto the FQCN, so an aliased middleware is connected to the routes it guards. `->middleware('api')` reaches it as a bare `middleware::api` node, and the classes inside that group are connected to nothing.

The group is not expanded into edges: mapping a global group onto every route would make each of its members report every route in the app as an entry point. But the middleware still self-lists as an entry point (it lives under `\Http\Middleware\`), so without help the report reads "one entry point: the middleware itself" for a change that runs on every route in the group. The answer is wrongly sized rather than missing, and this note supplies the size:

```text
  ! App\Http\Middleware\EnsureTenant runs in middleware group 'api', which guards 142 routes; group membership is not drawn as edges, so those routes are not in the reach above
```

The count comes from the application's registered route table, because the graph cannot answer it: a `route:: → middleware::<group>` edge exists only where a route file applies the group in its own `->middleware('web')` call, and a provider that loops over route files and groups them there, the shape Laravel's own `RouteServiceProvider` ships, draws no such edge for any of them. The graph's subset can be an order of magnitude short of the real figure. Routes are what is counted, so a controller-level attachment of the same group does not inflate it, and a run pointed at a checkout other than the running application falls back to the graph's subset, which under-counts for exactly that reason. Membership comes from Laravel Brain's middleware analyzer, which reads `$middlewareGroups` from a legacy `app/Http/Kernel.php` or the `->web(append: [...])` / `->api(append: [...])` calls in `bootstrap/app.php`. The file the app carries decides which is read, not its framework version. A member written as an alias resolves through the same alias map, and parameters are cut first (`tenant:strict` is one alias with an argument). Nesting is followed: a group may list another group by name and Laravel expands it, so a member of the inner group is also attributed to every group that includes it, and its routes count too. A name that is both a group and an alias is skipped rather than resolved one way (the wrong choice would point the note at the wrong routes), and cycles terminate on a seen-set.

The note is left out whenever the size cannot be vouched for: a group no route references, a middleware in no group, an unreadable Kernel, or an upgraded app that kept an empty `app/Http/Kernel.php` stub beside its bootstrap groups; that stub wins the lookup and yields no groups, which costs the note and never produces a wrong one.

Letting a group's routes count toward reach would raise the risk level of every middleware edit in every app at once, which needs its own evidence rather than arriving as a side effect of an annotation. A guard removed from the group is a different question, answered separately and by comparison rather than by counting: see [route files and middleware groups](07-risk-levels.md#hazards).
