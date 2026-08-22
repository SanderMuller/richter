# Configuration reference

Publish the config file with:

```bash
php artisan vendor:publish --tag=richter-config
```

`config/richter.php`:

| Key | Default | Purpose |
|---|---|---|
| `default_base` | `origin/main` | Git ref `richter:detect-changes` diffs against when `--base` is omitted. |
| `root_namespace` | `null` (derived) | The root namespace of the classes under `app/`. `null` reads it from the PSR-4 entry in your `composer.json` that maps to `app/`: `App\` on a conventional app, and the fallback when no single entry does. Set it explicitly (e.g. `'Acme\\'`) when two or more PSR-4 roots map to `app/`. Every command warns on stderr when the root it used matches no `app/` mapping in `composer.json` (or when two-plus roots map there and only one is traced), and the `--markdown` report carries the same note inside the document, since stderr never reaches a posted comment. |
| `editor` | `phpstorm` (via `CODE_EDITOR` / `DEBUGBAR_EDITOR` / `IGNITION_EDITOR`) | Editor for the clickable `file:line` links in the `--html` report; reuses debugbar's/Ignition's env chain. One of `phpstorm`, `idea`, `vscode`(+`-insiders`/`-remote`/`ium`), `sublime`, `textmate`, `emacs`, `macvim`, `atom`, `nova`, `netbeans`, `xdebug`, or `null` to keep the references plain text. |
| `dispatch_helpers` | `[]` | Project-custom global job-dispatch helper functions (e.g. `dispatch_with_retries`) the dispatch tracer should follow. |
| `feature_gate_methods` | `[]` | `FQCN::method` allowlist of project wrappers around Pennant (e.g. `App\Enums\FeatureToggle::isActive`); an `EnumCase->method()` call then annotates the change as flag-gated, alongside the built-in `Feature` facade / `@feature` support. |
| `risk_thresholds` | `[]` | **Deprecated and no longer read.** The level is decided by the hazard a change carries and by whether anything would catch a regression in what it reaches, not by how many nodes it touches. The key is still accepted so that an upgrade does not fail on it, and nothing reads it; remove it. See [Risk levels](07-risk-levels.md). |
| `hazards` | `{enabled: true, ignore: []}` | The [change-hazard lanes](07-risk-levels.md#hazards): an authorization guard removed, `$hidden` narrowed, a mass-assignment surface widened, a validation constraint dropped, a queued payload changed, a public member removed, a class deleted whole, a column a migration drops. Every predicate is exact: a lane that cannot read both sides of a comparison in full reports nothing. `ignore` suppresses one hazard by the member it sits on (`App\Http\Controllers\PostController::update`), a model's config member (`App\Models\Post::$fillable`), an inline-validated field (`App\Http\Controllers\PostController::store::subtitle`), or a migration's table and column (`posts.subtitle`), with the table on its own (`posts`) silencing every hazard on it. Disable for one run with `--no-hazards`, which changes the level and not only the section. The tiers themselves are not configurable. |
| `payload_parity` | `{enabled: true, mirror_threshold: 1.0, ignore: []}` | Tier-2 hazard lanes flagging payload-parity breaks in three directions: a model field added but never mirrored into its resource, a resource `toArray()` key removed while a frontend consumer of its routes still reads it, and a form-request `rules()` field removed while a consumer still sends it. `mirror_threshold` is the exact-mirror fraction (`1.0`, no-guess by default); `ignore` suppresses a model field (`App\Models\X::field`), a resource key (`App\Http\Resources\XResource::key`), a request field (`App\Http\Requests\XRequest::field`), or a whole resource or request (its FQCN, every direction). Disable for one run with `--no-payload-parity`, or globally by setting `enabled` to `false`. See [payload parity](05-report-annotations.md#payload-parity). |
| `second_hop` | `true` | How much of a statically-called class the walk reads. A class reached only through `Foo::bar()` is placed in the graph but its bodies are never read by Brain's route-anchored analysis, so what it constructs stays invisible, and an inherited method's work never connects through the subclass. `true` reads the methods those static calls name (~4.5s on a 4,000-file app); `false` trades that reach for the build time; `'class'` reads every traceable method of those classes, including the ones nothing calls statically (~8.0s on the same app). Those three values are the whole set, and each scope has one spelling: `'methods'` is rejected rather than treated as a synonym for `true`. Switching the value rebuilds the [graph cache](14-graph-cache.md). |
| `entry_point_roots` | `Jobs`, `Listeners`, `Console/Commands`, `Filament`, `Helpers`, `Http/Middleware`, `Livewire`, `Observers` | Directories under `app/` whose classes Richter **traces** beyond Brain's route-anchored graph: it walks their methods and draws the edges those bodies imply, so a change in one is placeable instead of UNRESOLVED. It does not **promote** them. Whether a reached class counts as an entry surface in its own right is a fixed vocabulary (`route::`/`command::`/`schedule::` nodes, plus the `Jobs`, `Listeners`, `Console\Commands`, `Livewire`, `Filament`, `Nova`, `Observers` and `Http\Middleware` namespaces), and no config key adds to it. Listing `Calculators` here makes its classes reachable and countable, never entry points of their own. `richter:impact`, `richter:trace` and `richter:detect-changes` note on stderr when an `app/` directory holds classes and *none* of them reach the graph, the shape a subsystem takes when its dispatch is one richter cannot follow and its directory is not listed here. Measured, not diffed against this list: partial presence is normal, so only total absence is reported, and only from five classes up. |
| `frontend.roots` | `[]` (off) | Frontend roots whose changed TS/JS/Vue files are scanned for Wayfinder/Ziggy endpoint references (see [Frontend changes](12-frontend.md)). |
| `frontend.generated_paths` | `actions`, `routes`, `wayfinder`, `ziggy.js` | Wayfinder's generated trees and Ziggy's generated route map under each frontend root, excluded from scanning as regeneration churn. Each entry matches a directory, an exact file, or a `*`-glob (crosses `/`). `.d.ts` files are always excluded, regardless of this list. |
| `frontend.pages_path` | `resources/js/Pages` | Where Inertia page components live; a changed member rendering a page is noted under Findings with the resolved file. |
| `frontend.test_paths` | `[]` (the frontend roots) | Directories scanned for frontend spec files whose endpoint references feed `richter:affected-tests`' advisory `frontendTests` list. |
| `frontend.http_callees` | `[]` | Extra JS/TS callees, beyond the built-in `route`/`fetch`/`axios`/`useFetch`/`$http`/`$`/`window`/`page`/`cy`, whose call-argument string literals count as backend endpoints. Matched on the callee's leading identifier, e.g. `myHttpClient` for `myHttpClient.post(...)`. |
| `cache.enabled` | `true` | On-disk graph cache, keyed by a content fingerprint of the build inputs (see [Graph cache](14-graph-cache.md)). |
| `cache.directory` | `null` | Cache location; `null` means `storage/framework/cache/richter`. |
| `parallel` | `true` | Build Brain's analysis and richter's own tracers concurrently (the tracers run in a child `artisan` process) instead of sequentially, which shortens a cold build on a multi-core machine. The merged graph is identical either way; any child-process failure falls back to the serial build, and `--profile` forces serial. Set to `false` to always build serially. |
| `benchmark_cases` | `[]` | Replayable accuracy fixtures for `richter:benchmark` (see [Benchmarking](17-benchmark.md)). |

Admin-panel coverage is class-level: Filament resources, pages and widgets and Nova resources surface
as entry points (Filament's computed HTTP routes additionally come in through Laravel Brain when it is
installed), but individual table/bulk actions and Nova fields are not modelled as separate entry
points.

Adding `Nova` to `entry_point_roots` is not needed and buys nothing measurable: those classes are
already reached through ordinary call edges, so tracing them changes no report. What makes a change to
one visible is the namespace being in the entry-surface vocabulary above, which no config key extends.

Richter assumes standard Laravel conventions: `app/Models`, `app/Policies`, `resources/views`, and
`tests/`. The root namespace itself needn't be `App\` (it is derived from `composer.json`, see
`root_namespace` above), but the sub-namespaces under it are read as conventional.
