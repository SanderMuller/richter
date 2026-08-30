---
name: richter-setup
description: "Invoke-only — run with `/richter-setup` or when asked to set up/configure richter; never activates on its own. Inspects a Laravel project and proposes config/richter.php (entry-point roots, dispatch helpers, base branch, frontend, Brain security), and optionally scaffolds a CI advisory-comment workflow and registers richter's MCP server in .mcp.json. Proposes and confirms every write; never writes unasked."
disable-model-invocation: true
metadata:
  schema-required: "^1"
---

# Set up richter for this project

richter's change-impact report is only accurate once it knows the app's shape — which subsystems are
entry surfaces, which helpers dispatch jobs, the real base branch, the frontend stack. The defaults
can't know that, so a fresh install is often under-configured (a subsystem reads `UNRESOLVED`, a
dispatch is missed, the report is scoped against the wrong branch). This skill inspects the project and
**proposes** that config — and, only if asked, a CI advisory-comment workflow — one confirmation at a
time.

## When this runs

Invoke-only: `/richter-setup`, or when the user asks to "set up / configure richter." It never
auto-activates.

## Rules (non-negotiable)

- **Propose, then confirm.** Show each proposed edit (a diff or the exact block) and get an explicit
  yes before writing `config/richter.php`. Reading the config must never publish or create it as a
  side effect.
- **CI and MCP registration are opt-in.** Never scaffold or edit a workflow (Step 3) or touch
  `.mcp.json` / `composer.json` (Step 4) unless the user explicitly says yes to that step.
- **Idempotent.** Read existing config first; propose only additions/adjustments, never a blind
  overwrite.
- **Hedge — these are heuristics.** Propose the narrowest change, state the why and the false-positive
  risk, and prefer evidence over guessing.

## Step 1 — Read the current state (read-only)

- Is `config/richter.php` published? If yes, read it — every proposal is relative to it. If no, do
  **not** publish it yet; note that publishing (`php artisan vendor:publish --tag=richter-config`) is
  part of the write confirmed in Step 2.
- Note the app's shape from `composer.json` and the tree: Laravel version, a form-builder
  (`kris/laravel-form-builder`), an Inertia/Wayfinder/Ziggy frontend, `laramint/laravel-brain`, and the
  repo's default branch.

## Step 2 — Propose config/richter.php

Work through these; propose only what applies, each with a one-line why; then one confirmation to write.

- **`default_base`** — the repo's real default branch (`git symbolic-ref --short refs/remotes/origin/HEAD`,
  e.g. `origin/main` or `origin/development`). *Why:* the report diffs against this; a wrong base
  silently mis-scopes every run, and a non-`main` default is common.
- **`root_namespace`** — leave it `null` on a conventional app: richter derives the root from the
  PSR-4 entry in `composer.json` that maps to `app/`. Propose it only when **two or more** PSR-4 roots
  map to `app/` (a partially-migrated codebase), where the derivation prefers `App\` and so traces
  only that half — name the root the app's classes actually use. *Evidence:* the commands print a
  stderr note when the root they used matches no `app/` mapping in `composer.json`; that note appearing
  is the signal this key is needed.
- **`entry_point_roots`** — `app/` subdirs whose classes are reached only through runtime/vendor
  dispatch and would otherwise read `UNRESOLVED`: a form-builder Form dir (classes with a `buildForm()`
  the library invokes), a registry-/factory-dispatched calculator dir. *Prefer evidence:* run
  `php artisan richter:detect-changes --no-cache` on a real branch and read BOTH signals it prints.
  Changed files coming back `UNRESOLVED` name candidate dirs; the stderr coverage note names one
  outright ("app/X/ holds N classes and none of them appear in the code graph"). That note is the
  stronger signal and catches what `UNRESOLVED` structurally cannot — a subsystem missing as a
  *consumer* of the change, which no diff-scoped signal sees, because those files did not change.
  (`--no-cache` keeps this exploratory step from writing the graph cache, so Step 1 stays
  read-only.) *Hedge:* propose the **narrowest** dir; do not add a
  broad root like `app/Actions` unless most classes there are genuinely entry surfaces, or internal
  services get inflated into "entry points."
- **`dispatch_helpers`** — any project-global function wrapping `dispatch()`/`Bus::dispatch()` (e.g. a
  `dispatch_with_retries($job)` helper). Inspect the helper's signature and a call site to confirm the
  job is the argument the tracer expects before adding the name.
- **Frontend** — with an Inertia/Wayfinder/Ziggy frontend, set `frontend.roots`; adjust
  `frontend.pages_path`, `frontend.generated_paths`, `frontend.test_paths`, and `frontend.http_callees`
  only where they differ from the defaults.
- **`editor`** — `null` when the primary use is a shared CI artifact (an editor link embeds an absolute
  local path only the generating machine can open).
- **Others, when relevant** — `feature_gate_methods` (custom Pennant-wrapper methods), and the two
  advisory findings lanes, `payload_parity` (mirror threshold / ignore / disable) and
  `sibling_read_parity` (ignore / disable). Both are on by default and need no setup: their ignore
  lists answer a false positive you have actually seen, so leave them empty here.

### Brain config — detect, then propose (don't only suppress)

richter inherits Laravel Brain's route and security classification. When it looks wrong, propose the
matching lever in the app's `laravel-brain` config rather than only silencing:

- **`security.auth_middleware` / `security.throttle_middleware`** — declare the app's auth / throttle
  middleware so Brain classifies group-gated routes correctly. This can fix a false `PUBLIC_WRITE`
  ("no auth") or `MISSING_THROTTLE` **at the source**, when the gate lives in a middleware group Brain
  didn't recognise. Brain matches these by NAME, so it misses any middleware whose name is not
  auth-shaped (`auth`, `sanctum`, `jwt`, `passport`, `verified`, `signed`). richter already covers the
  common case on its own — it walks the class ancestry, so a subclass of
  `Illuminate\Auth\Middleware\Authenticate` is recognised without config and its routes get a note
  contradicting the finding. Propose this key for what ancestry cannot catch: middleware that
  authenticates without extending a framework class (a hand-rolled token or SSO guard).
- **`auto_discover_routes` / `route_paths`, `commands.*`, `listeners.paths`** — when route / command /
  listener discovery misses part of the app.
- **`security.trusted_route_names` / `trusted_route_uris`** — only for a genuinely known-safe public
  route.

## Step 3 — CI advisory comment (opt-in)

**Ask first:** "Add a GitHub Actions workflow that posts the richter report as a PR comment?" Do nothing
here unless the user says yes.

On yes:

1. **Scan every workflow first.** Grep all `.github/workflows/*.yml` / `*.yaml` for an existing
   `richter:detect-changes` run, a sticky-comment step, or a "Richter"/"Impact" job. If richter is
   already wired anywhere (in `ci.yml`, `pr.yml`, …), propose **integrating** there — do not add a
   second commenting workflow, or the PR gets double comments. Create a new file only when none exists.
2. **Scaffold from `templates/richter-report.yml`** (beside this skill), adapting the default branch
   and, if the app needs it in CI, the `.env` / `APP_KEY` steps — Brain boots and autoloads the app
   during analysis, so it must be bootable in CI.
3. **Confirm the file before writing.** Never clobber an existing workflow.

The template is advisory-by-contract: the whole job is non-blocking, it declares least-privilege
`permissions`, and it triggers on `pull_request` (never `pull_request_target` with a privileged token).
Note that a fork PR may lack comment-write permission depending on repo settings — the job stays
non-blocking regardless.

## Step 4 — MCP registration (opt-in)

**Ask first:** "Register richter's MCP server in `.mcp.json`, so coding agents can query impact and
triage the branch diff without shelling out?" Do nothing here unless the user says yes.

On yes:

1. **Check whether `laravel/mcp` is installed** (`composer show laravel/mcp`, or the `require-dev`
   block). richter only suggests it; the server registers itself automatically once the package is
   present. If it is absent, the proposal starts with `composer require --dev laravel/mcp` (richter's
   supported range is `^0.8||^0.9` — an unvalidated release fails at resolution time by design).
2. **Propose the `.mcp.json` entry — merge, never overwrite.** Read any existing `.mcp.json` first: if
   other servers are registered, propose adding the `richter` key beside them; if a `richter` entry
   already exists, say so and change nothing (idempotent). Only when no file exists, propose creating:

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

3. **Confirm before writing**, as with every step. The server exposes read-only analysis only
   (impact, trace, detect-changes, affected-tests, plus orientation resources) — registering it
   changes no application behaviour.

## After setup

- boost-core users: this skill synced because `sandermuller/richter` is in the `boost.php` allowlist —
  keep it there.
- Suggest a first run: `php artisan richter:detect-changes` on the current branch, and check the
  reached entry points and any `UNRESOLVED` files against what was expected — that's the fastest way to
  spot a still-missing `entry_point_roots` entry.
- There is nothing to calibrate: `risk_thresholds` is deprecated and no longer read. The level is
  decided by the hazard a change carries and, where it carries none, by whether a test references what
  it reaches. If a level looks wrong, read its `riskCause` — the fix is usually coverage
  (`entry_point_roots`, `root_namespace`) rather than a knob. Use `hazards.ignore` to silence one
  hazard a project knows is safe. Run the benchmark
  corpus before and after any such change if the project has one; that is the only check that says whether the
  calibration still surfaces real defects.
- Testing any config key against historical diffs has a trap: `richter:detect-changes` has no `--head`,
  so a replay checks out each ref, and a tracked `config/richter.php` reverts to that ref's version
  mid-experiment. Verify the value is live before trusting the numbers.
- If the project has a `post-checkout` hook that reinstalls dependencies (a `composer install` or a
  `composer run <refresh-script>`, common in Laravel repos), say so now. Comparing several refs in one
  session — replaying branches, walking commits, bisecting — checks out each one, and the hook then
  installs *that* ref's lockfile, silently swapping the richter version mid-comparison. Checking out
  with `git -c core.hooksPath=/dev/null checkout <ref>` keeps one version across the whole run.
  Disabling the hook does not make the checkout harmless, though: `composer.json`, `composer.lock` and
  a tracked `config/richter.php` all revert to each replayed ref while `vendor/` keeps the version
  under test. The measurements stay valid — artisan runs off `vendor/` — but a version bump made on
  the branch is silently undone, and so is any tuned `hazards.ignore` or `payload_parity` setting, so
  every replay runs on package defaults. Fine for comparing versions against each other; misleading if
  what you are checking is your own configuration. Re-check the manifest and the config, not just the
  hook.
