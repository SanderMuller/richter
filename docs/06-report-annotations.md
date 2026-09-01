# Report annotations

`richter:detect-changes` renders four lanes beside the reach it reports: security exposure per route, Pennant feature gates, payload-parity breaks, and middleware group membership.

Two of them are annotation only. Pennant gates and middleware group membership feed nothing: not the [risk level](08-risk-levels.md), not a gate, and not the [affected-test selection](12-affected-tests.md).

Membership is not the same thing as the group's guards. Which routes a group runs on is the annotation, and it feeds nothing. A guard the group itself lost is a [tier-3 hazard](08-risk-levels.md#hazards) read from `bootstrap/app.php` or a legacy Kernel, and that does decide the level.

The other two do reach the level, each through one narrow door:

- Payload-parity results are tier-2 [hazards](08-risk-levels.md#hazards). A payload key a consumer still reads is a thing that breaks, so it is graded like any other hazard. It prints under `Hazards`, not `Findings`.
- Security exposure decides a hazard's reach class. A `PUBLIC_WRITE` route with no guard richter can point at, reaching a hazardous member, makes that hazard `public-write`. The annotation itself is still inherited from Brain and still seeds nothing.

## Security annotations

Reached routes inherit [Laravel Brain](https://github.com/laramint/laravel-brain)'s security surface as advisory annotation: the exposure level renders inline (`[public]`, `[guest]`, `[authed]`, `[admin]`) and any statically detected issues render under the route:

```text
  - route::POST::/webhooks/payments  (routes/api.php:12)  [⚠ no test references this]  [public]
      ⚠ PUBLIC_WRITE (medium): POST route with no auth middleware
```

It exists for routes only (Brain classifies nothing else), and false positives are suppressed where Brain's own config says so (`laravel-brain.security.trusted_route_names` / `trusted_route_uris`). A Livewire, Filament, Nova or queue entry point never carries one of these tags at all; that absence means the surface was not classified, never "public" or "unauthenticated", and its real exposure comes from mount-time `authorize()` calls, middleware, or route placement the graph doesn't model.

Brain classifies exposure from the route's static middleware surface, so it can flag a `PUBLIC_WRITE` on a route that is in fact gated by a policy-constant check (`Gate::authorize(PostPolicy::UPDATE, …)`) it cannot see. Richter cross-checks such a finding against its own `authorizes` edges: when the route's reach authorizes a policy, it adds a note pointing at that policy. The note is evidence for you to verify rather than a suppression, and Brain's finding stays shown.

A third cross-check reads the **booted router** — the one surface that sees through named middleware groups. When the analysis runs against the booted working tree (never under a named `--head`, never on a foreign checkout), richter resolves each `[public]`-classified route's fully expanded middleware stack and, where a recognized auth middleware survives it, notes the guard and the group it arrived through beside the finding (`the booted router shows Authenticate (via middleware group 'web') on this route`). Like the other two cross-checks it is evidence, never a suppression — Brain's finding stays shown — and it feeds a hazard's reach class through the same door. Recognition is fail-closed: every registered route sharing the node id must carry the guard, an excluded (`withoutMiddleware`) guard never counts, and any router failure is silence. The `--json` and MCP documents carry it as `entryPointRuntimeGuards`.

Brain reads auth middleware two ways: by name (the aliases `auth`, `sanctum`, `jwt` and the like, plus the class's own basename against `Authenticate` and `ValidateSignature`) and, since Laravel Brain 2.5.0, by walking a class's `extends` chain. That chain terminates on `Illuminate\Auth\Middleware\Authenticate`. A middleware that descends from one of the three other framework auth middlewares (`AuthenticateWithBasicAuth`, `EnsureEmailIsVerified` or `ValidateSignature`) and carries a name of its own matches nothing, because the basename compared is the class's own, so every route behind it reads `[public]`. Richter walks the ancestry of all four and notes the applied auth middleware beside the finding, again as evidence rather than a suppression. A guard that reaches the route through a *named* middleware group (`web`, `api`) is invisible to both, because Brain resolves aliases only and neither side expands a group. Middleware that authenticates without extending a framework class is invisible to both as well; list it under `laravel-brain.security.auth_middleware` to teach Brain the name. A `MISSING_THROTTLE` is left to stand.

## Feature-flag (Pennant) annotations

Pennant feature gating is annotated the same way. A route guarded by `EnsureFeaturesAreActive` renders its flags inline (`[gated: ai-coach]`, a 🚩 badge in markdown, `entryPointGates` in JSON), and a changed member or Blade view that itself checks a flag (`Feature::active(...)`, `@feature`) notes it under Findings; a flag-gated change has a smaller live blast radius than the raw graph suggests, and the reviewer should know. Route detection reads statically visible middleware (a string alias like `'features:ai-coach'` or an FQCN-string form); the runtime-built `EnsureFeaturesAreActive::using(...)` expression is invisible to static route parsing. Only the `Feature` facade, `@feature`, and any `feature_gate_methods`-configured wrapper method are recognised; a project convention like `FeatureToggle::BETA_DASHBOARD->isActive()` needs an allowlist entry (see [Configuration](17-configuration.md)) before it is noted.

## Payload parity

A model field added to `$fillable`/`$casts`/`casts()` but never added to a resource that otherwise mirrors the model's other fields is reported as a tier-2 hazard (`AppResource.php mirrors App\Models\X but does not expose <field> added to App\Models\X`), the exact shape behind a payload field silently going missing after an otherwise-correct edit. The lane makes no guesses: the default `mirror_threshold` requires an exact match against the candidate's pre-existing fields before it counts as a mirror, candidate resources are matched by graph wiring first and only by name when nothing is wired, and anything the checker can't statically enumerate (a dynamic `toArray()` key, a spread, an unparseable resource) is silently skipped rather than guessed at. On by default; disable it for one run with `--no-payload-parity` or globally via `payload_parity.enabled` (see [Configuration](17-configuration.md)).

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

## Sibling-read parity

A changed method that reads a nullable column raw, where the code that was already beside it resolves
the same value through a fallback:

```
app/Actions/CreateTask.php: App\Actions\CreateTask::handle reads Order->external_id (bare);
App\Models\Order::resolvedExternalId reads it (fallback). Nullable per its docblock. Check whether this read
needs the same handling.
```

This is the defect nothing else here can see. The value is simply absent at runtime: nothing throws,
no test fails, and the diff is internally consistent, so reach says nothing and no guard was removed.

**A finding, never a hazard.** It never reaches `risk`, `--fail-on` or `affected-tests`. The finding
names BOTH reads and claims nothing more. The sibling may be the one that is wrong, and a
`=== null` in the changed code is reported as a null-test rather than as "no fallback", because those
are different observations. One soft sibling is enough to report: the finding names one comparison,
never a convention.

What it compares:

- **the changed side**: every read in a method the diff touched, in the head tree, where the
  receiver has a declared application class type. An untyped receiver, a union, a chained call and a
  vendor type record nothing. A write, an `unset()` and a by-reference argument are not reads.
- **the evidence side**: the same property read in a way that supplies or TOLERATES an absent value,
  in the BASE tree. One rule decides which reads count: does the code treat an absent value the same
  as an empty one, or detect `null` specifically while an empty string walks past? `??`, `?:`, `??=`,
  `filled()`, `blank()`, `empty()`, `== null`, and any truthiness test (`! $x`, an `if`, a loop
  condition, `&&`, a `(bool)` cast) tolerate, and count. `=== null`, `is_null()`, `isset()` and a
  nullsafe `?->` detect, and do not — an empty string passes all four, which is the mismatch this lane
  reports. The sources are: the receiver's own declaring class, plus files in
  directories the diff touched. A fallback the same change introduces is not evidence about the code
  that was already there.

Only a nullable SCALAR property is compared, proved from the head version of the declaring class: a
`?string` declaration, or an `@property string|null` line. It is not restricted to Eloquent models. In
practice the two sources split by where the property lives: a model's columns are described by a
generated docblock, while a data object's promoted properties carry real declared types. That restriction was measured: read
literally, a generated docblock marks relations, cast objects, primary keys and timestamps nullable
too, and those were two thirds of the findings and none of the defect class. `id`, `created_at`,
`updated_at` and `deleted_at` are never reported.

The finding names which source proved nullability, and the two are not equal evidence. A `?string`
declaration is an author saying the value can be absent. A generated `@property string|null` describes
the column loosely and can be WIDER than the column itself: a `NOT NULL` column documented `|null` is
common, and a finding resting on one is about a value the database will not let be absent. Read the
source it names before acting on it.

Silence is the common case, and it is not a claim of correctness. Where either side cannot be read in
full, the lane says nothing rather than guessing.

Disable it with `richter.sibling_read_parity.enabled`, or silence one pair with
`'App\Models\Order::external_id'` (or a whole type, `'App\Models\Order'`) in
`richter.sibling_read_parity.ignore`.

## Middleware group membership

Route middleware is resolved by alias and never by group. `->middleware('auth')` reaches the graph as `middleware::auth` and Richter rewrites that onto the FQCN, so an aliased middleware is connected to the routes it guards. `->middleware('api')` reaches it as a bare `middleware::api` node, and the classes inside that group are connected to nothing.

The group is not expanded into edges: mapping a global group onto every route would make each of its members report every route in the app as an entry point. But the middleware still self-lists as an entry point (it lives under `\Http\Middleware\`), so without help the report reads "one entry point: the middleware itself" for a change that runs on every route in the group. The entry point is right and the size is not, so this note supplies the size:

```text
  ! App\Http\Middleware\EnsureTenant runs in middleware group 'api', which guards 142 routes; group membership is not drawn as edges, so those routes are not in the reach above
```

The count comes from the application's registered route table, because the graph cannot answer it: a `route:: → middleware::<group>` edge exists only where a route file applies the group in its own `->middleware('web')` call, and a provider that loops over route files and groups them there, the shape Laravel's own `RouteServiceProvider` ships, draws no such edge for any of them. The graph's subset can be an order of magnitude short of the real figure. Routes are what is counted, so a controller-level attachment of the same group does not inflate it, and a run pointed at a checkout other than the running application falls back to the graph's subset, which under-counts for exactly that reason. Membership comes from Laravel Brain's middleware analyzer, which reads `$middlewareGroups` from a legacy `app/Http/Kernel.php` or the `->web(append: [...])` / `->api(append: [...])` calls in `bootstrap/app.php`. The file the app carries decides which is read, not its framework version. A member written as an alias resolves through the same alias map, and parameters are cut first (`tenant:strict` is one alias with an argument). Nesting is followed: a group may list another group by name and Laravel expands it, so a member of the inner group is also attributed to every group that includes it, and its routes count too. A name that is both a group and an alias is skipped rather than resolved one way (the wrong choice would point the note at the wrong routes), and cycles terminate on a seen-set.

The note is left out whenever the size cannot be vouched for: a group no route references, a middleware in no group, an unreadable Kernel, or an upgraded app that kept an empty `app/Http/Kernel.php` stub beside its bootstrap groups; that stub wins the lookup and yields no groups, which costs the note and never produces a wrong one.

Letting a group's routes count toward reach would raise the risk level of every middleware edit in every app at once, which needs its own evidence rather than arriving as a side effect of an annotation. A guard removed from the group is a different question, answered separately and by comparison rather than by counting: see [route files and middleware groups](08-risk-levels.md#hazards).
