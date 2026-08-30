# Frontend changes (Wayfinder / Ziggy)

Opt-in: point `frontend.roots` at your frontend source in `config/richter.php`:

```php
'frontend' => [
    'roots' => ['resources/js'],
],
```

Changed `.ts`/`.tsx`/`.js`/`.jsx`/`.vue` files are then scanned for the backend endpoints they reference, and those routes are reported as touched entry points, with their location, exposure and gate annotations, feeding `richter:affected-tests`, while `risk` and `impacted` stay untouched: a frontend edit does not change backend behaviour, and the report says so explicitly. Detected references:

- **[Wayfinder](https://github.com/laravel/wayfinder) imports**: an action import
  (`@/actions/App/Http/Controllers/PostController`) resolves through the router's action index,
  method-precise, with aliased, default, invokable and `import type` forms included. A route
  import (`@/routes/posts`) resolves through the route names instead.
- **[Ziggy](https://github.com/tighten/ziggy) `route()` calls**: `route('posts.show')` resolves
  through the same route-name index the Wayfinder route imports use, so a Ziggy-only frontend is
  covered without Wayfinder installed. The name has to be a literal: a dynamic argument gets one
  resolution attempt and then fails safe, and an unmatched name is not treated as a reference at
  all (both below).
- **Endpoint strings**, matched against the app's route templates: plain literals
  (`axios.post('/posts')`) and backtick templates whose interpolations wildcard one segment
  (`` fetch(`/posts/${id}`) `` matches `/posts/{post}`). A `/`-leading literal or template only
  counts as the **first argument of an allowlisted HTTP/route callee** (`route`, `fetch`,
  `axios`, `useFetch`, `$http`, `$` for jQuery, `window`, and `page`/`cy` for Playwright/Cypress
  navigation by default, plus `frontend.http_callees`), matched on the callee's leading
  identifier before a `.method` (`axios.get(...)`, `$http.post(...)`, `window.fetch(...)`,
  `page.goto(...)`). A verb-named call pins the HTTP method, whether the verb is the callee
  itself (`post('/x')`) or its `.method` segment (`axios.post('/x')`); anything unrecognisable
  stays method-agnostic and never narrows the match. Inline `<script>` blocks in changed Blade
  views get the same literal scan. Gating on the callee means a constants file, nav-link config,
  i18n helper (`translate('/preferences')`), or any other non-HTTP call is never mistaken for an
  endpoint call, and a project-custom HTTP wrapper needs registering via `frontend.http_callees`
  before its literals seed. A few idioms are a documented, deliberate recall loss: a URL assigned
  to a variable and used later (`const URL = '/x'; fetch(URL)`), an options object's `url`
  property (`axios({ url: '/x' })`), and the `request(method, url)` second-argument idiom (the
  URI's callee can no longer be identified once it isn't the call's first argument).

Generated output is excluded from the scan as regeneration churn: Wayfinder's trees (`actions/`,
`routes/`, `wayfinder/` under each root) and Ziggy's route map (`ziggy.js`). `.d.ts` declaration
files are never scanned. See `frontend.generated_paths` in [Configuration](17-configuration.md).

Frontend spec files (`*.test.*`, `*.spec.*`, `*.cy.*` under the roots, or `frontend.test_paths`)
referencing a touched route surface in `richter:affected-tests` as an advisory `frontendTests`
list for the JS runner, never in `--plain` (which feeds the PHP runner), and never a
determinability input.

The scan is regex-based and says so when it can't see: a dynamic Ziggy `route(`…`)` argument or an
unmatched Wayfinder action import marks the file UNRESOLVED (and `richter:affected-tests` exits
`2`), while an unmatched `route('name')` string simply isn't a reference: `routes/` modules and
`route()` helpers collide with frontend-router idioms, so unmatched names never guess. Before a
dynamic argument taints the file, it gets one resolution attempt against a same-module
`const`/`enum` string constant (`route(ROUTES.player)` resolves when `ROUTES` is a flat `const`
with exactly one `player` member); anything less certain (`let`, multiple declarations, imported
constants, nested bodies) keeps the fail-safe.

The bridge also runs in reverse, without any configuration: a changed backend member that
renders an Inertia page (`Inertia::render('Posts/Show')`, the `inertia()` helper) is noted
under Findings with the resolved page file under `frontend.pages_path`, or with an explicit
"no page file found" when the component doesn't resolve, which usually means a renamed or
deleted page.
