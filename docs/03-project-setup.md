# Set up your project

Richter is accurate only once it knows your app's shape: which subsystems are entry surfaces, which helpers dispatch jobs, your real base branch, your frontend stack. You can set that up two ways.

## With an agent (recommended)

Richter ships two invoke-only skills.

`/richter-setup` (or ask your agent to "set up Richter") inspects the project, proposes `config/richter.php`, and, only if you say yes, scaffolds a CI comment workflow and registers the MCP server in `.mcp.json`. It shows you every edit before writing it.

`/richter-review` reviews the current branch graph-first: it runs the report, triages the reached entry points (unexpected reach, missing test references, security and gate annotations), walks the findings, and closes with an advisory verdict. It recommends, never gates.

To make the skills available:

- With **boost-core**: add `sandermuller/richter` to `withAllowedVendors([...])` in your `boost.php`, then run `vendor/bin/boost sync`.
- With **laravel/boost**: they are discovered as a third-party AI package. An existing install may need `boost:update` or a package selection.

## Or paste these prompts to any agent

Two prompts, so CI stays opt-in.

### Configure

> Set up Richter for this Laravel project. Inspect the code and **propose** edits to `config/richter.php`; show me each change and get my OK, write nothing unasked. Cover: `default_base` (my repo's real default branch), `entry_point_roots` (any `app/` subsystem reached via runtime/vendor dispatch, such as form-builder Forms or registry-dispatched calculators, that `richter:detect-changes` reports `UNRESOLVED`; pick the narrowest dir, which makes the subsystem traceable without turning its classes into entry points), `dispatch_helpers` (custom job-dispatch wrapper functions), frontend roots if there's an Inertia/Wayfinder/Ziggy frontend, and `editor: null` if this is mainly for CI. Also flag any Laravel Brain config (`security.auth_middleware`/`throttle_middleware`, route/command/listener discovery) that would fix mis-classified routes at the source.

### Add the CI advisory comment

> Add a GitHub Actions workflow that posts the Richter report as an advisory PR comment. First check whether Richter is already wired into an existing workflow and integrate there instead of adding a duplicate. Make the whole job non-blocking, least-privilege (`permissions: contents: read, pull-requests: write`), triggered on `pull_request` (not `pull_request_target`), checkout with `fetch-depth: 0`, run `php artisan richter:detect-changes --base=<PR base sha> --markdown` and post it as a sticky comment. Show me the file before creating it.
