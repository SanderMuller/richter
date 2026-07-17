# Richter

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/richter.svg?style=flat-square)](https://packagist.org/packages/sandermuller/richter)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/richter/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/richter/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/SanderMuller/richter/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/SanderMuller/richter/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/richter.svg?style=flat-square)](https://packagist.org/packages/sandermuller/richter)
[![License](https://img.shields.io/packagist/l/sandermuller/richter.svg?style=flat-square)](LICENSE)

Measures the magnitude of impact of code changes in a Laravel codebase. Like the Richter scale, but for your PHP.

Built on [Laravel Brain](https://github.com/laramint/laravel-brain)'s static analysis, Richter constructs a directed code graph of your application (routes, controllers, jobs, listeners, policies, resources, Blade views, Eloquent relations). It reads two things off that graph:

- **The blast radius of a symbol:** its callers (what breaks if you change it) and its dependencies (what it reaches).
- **What the current branch diff touches:** the HTTP/CLI entry points and flows the changed files reach, plus a coarse risk level.

You can use those results three ways:

- **CLI:** a self-review aid before you push.
- **MCP:** Richter registers a local `richter` server exposing both analyses read-only, so a coding agent can check a symbol's blast radius or triage the branch diff mid-review without shelling out.
- **CI and PR review:** run `richter:detect-changes` against the pull request's base ref and post the report for the reviewer, human or agent.

Richter is advisory by default: `richter:detect-changes` exits 0, and a low or empty result is a signal, not a guarantee of no impact. Opt into a CI gate with `--fail-on` / `--fail-on-unresolved` when you want a non-zero exit (see [Gating in CI](#gating-in-ci)).

Its edge over Brain alone is coverage. It adds the graph edges a route-anchored analysis misses: queue dispatches (including unresolvable ones), `$listen`-registered event listeners, container bindings, interface implementations, policy references (`$user->can(PostPolicy::UPDATE, …)` and `@can(...)` in Blade), API resource composition, custom validation rules, trait usage, eager-load relation strings, and view-to-view includes.

## What it's for

Richter shows what a change reaches, before you or your reviewer have to guess.

- **Catch what you missed, before review.** Run `richter:detect-changes` on your branch and read the entry points and flows the diff reaches. Anything you didn't expect it to touch is worth a look before you open the PR.
- **Turn reach into a test-coverage prompt.** Every reached entry point is tagged `[test-referenced]` or `[⚠ no test references this]`. An entry point whose behaviour you changed with nothing referencing it is a place to add a test; the tag flags a missing reference, not proof the code is untested.
- **Hand the reviewer your blast radius.** Drop the report into the pull request description, or let a coding agent read it over MCP, so review starts from what the change reaches instead of a cold diff.
- **Size a refactor first.** Before you rename or rework a symbol, `richter:impact "App\Models\User"` lists its callers (what breaks if you change it) and its dependencies (what it reaches).

The analysis never executes your application's routes, jobs, or commands — it is static analysis over a code graph, fast enough for every branch. It does, however, autoload classes from the analyzed checkout (to resolve constants, relation names, and queue interfaces), and autoloading runs a file's top-level code. Treat a checkout you would not `composer install` on as one you should not analyze either.

## Installation

```bash
composer require --dev sandermuller/richter
```

Requires PHP 8.4+ and Laravel 12+. Optionally publish the config:

```bash
php artisan vendor:publish --tag=richter-config
```

## Usage

### Blast radius of a symbol

```bash
php artisan richter:impact "App\Services\VideoPublisher"
php artisan richter:impact VideoPublisher                     # substrings work too
php artisan richter:impact "App\Services\VideoPublisher" --json       # machine-readable, for scripting
php artisan richter:impact "App\Services\VideoPublisher" --markdown   # PR-ready markdown
```

Prints the symbol's callers (what breaks if you change it) and its dependencies (what it reaches), breadth-first. Each hop shows its depth (`d1`, `d2`, …) and the edge it was reached through — so a caller chain reads back to the entry point one hop at a time:

```text
Callers (what breaks if you change "App\Services\VideoPublisher"):
  d1  App\Http\Controllers\VideoController::publish  (via action-to-service)
  d2  App\Http\Controllers\VideoController  (via controller-to-action)
  d3  route::POST::/videos/{video}/publish  (via route-to-controller)

Dependencies (what "App\Services\VideoPublisher" reaches):
  d1  App\Events\VideoPublished  (via action-to-event)
```

With `--json`, stdout is a single document — `{target, callers, dependencies}`, each hop `{depth, node, via}` — or `{"error": "…"}` on failure.

### Advisory change impact of the current diff

```bash
php artisan richter:detect-changes                        # diffs against richter.default_base
php artisan richter:detect-changes --base=origin/develop
php artisan richter:detect-changes --explain              # show how each entry point reaches the change
php artisan richter:detect-changes --json                 # machine-readable, for scripting or CI
php artisan richter:detect-changes --markdown             # PR-ready markdown, for descriptions and comments
```

Resolves which class members the branch changed (member-level, not file-level: a one-method change seeds that method, not the whole class), walks the graph, and reports:

- the entry points (routes, commands, jobs, listeners, middleware, …) the change can reach, each tagged `[test-referenced]` or `[⚠ no test references this]`;
- findings in the changed source itself — e.g. an eager-load/relation string that names no relation on any model, the shape a missing comma leaves when it concatenates two relation constants (`Video::OWNER . User::PROFILE` → `ownerprofile`) into a name Eloquent silently never resolves;
- a coarse risk level (`low` / `medium` / `high`);
- honest degradation: a change that cannot be placed in the graph reads **UNRESOLVED**, never as a falsely reassuring "no impact", and an unfollowable dispatch makes a queue job read "unknown", not "none".

```text
Changed files:
  app/Models/Video.php (4 graph nodes)
  app/Services/PlaylistImporter.php (0 graph nodes)  (coverage incomplete for this area — UNRESOLVED, not "no impact")

Entry points reached: 2 (some changed files are in an area not yet graphed — see UNRESOLVED above)
  - command::playlists:sync  [test-referenced]
  - route::PATCH::/api/videos/{video}  [⚠ no test references this]

Related models (association reach — context, not risk): 1
  - App\Models\Playlist

Findings (in the changed source itself):
  ! app/Models/Video.php: eager-load string 'ownerprofile': segment 'ownerprofile' is not a method on any model — check the relation name (a broken constant concatenation reads exactly like this)

Impacted nodes: 7
Risk: MEDIUM (advisory — not a gate)
```

With `--explain`, each reached entry point carries the shortest call chain down to the changed code — the difference between "this reaches `PATCH /api/videos/{video}`" and seeing exactly through which controller and service it does:

```text
Entry points reached: 1
  - route::PATCH::/api/videos/{video}  [⚠ no test references this]
      ↳ route::PATCH::/api/videos/{video} →(route-to-controller) App\Http\Controllers\VideoController::update →(action-to-service) App\Services\VideoPublisher::publish
```

A self-listed entry class (a changed job or listener that *is* the entry surface rather than being reached from the change) deliberately carries no chain.

With `--markdown`, the report renders as GitHub-flavoured markdown — risk badge up front, changed files as a table, entry points as a review checklist with their test tags, long lists collapsed into `<details>` instead of truncated — ready to paste into (or post onto) a pull request. `--markdown --explain` composes.

With `--json`, stdout is a single JSON document — the full, uncapped report — with these top-level keys (or `{"error": "…"}` if the diff can't be resolved):

| Key | Type | Meaning |
|---|---|---|
| `base` | string | the ref the diff was taken against |
| `changed` | object | `{file: graph-node count}` per changed file |
| `coverage` | object | `{file: "analyzed" \| "unresolved"}` per changed file |
| `entryPoints` | string[] | entry-point nodes the change reaches |
| `entryPointPaths` | object | per reached entry point, the shortest call chain down to the changed code as `{node, via}` hops; a self-listed entry class carries no chain |
| `impacted` | int | count of risk-bearing nodes reached |
| `relatedModels` | string[] | models reached only via association edges (context, not risk) |
| `risk` | string | `"low"` / `"medium"` / `"high"` |
| `lowConfidence` | bool | a changed member couldn't be pinned, so part of the estimate is coarse |
| `coarseCapApplied` | bool | a low-confidence `high` was capped to `medium` |
| `findings` | string[] | source-level findings, as shown above |
| `unresolved` | bool | any changed file is UNRESOLVED |
| `gate` | object | present only under a `--fail-on*` flag — see [Gating in CI](#gating-in-ci) |

#### Risk levels

Risk is a coarse, advisory signal — deliberately simple, so `--fail-on` stays predictable:

| Level | Condition |
|---|---|
| `high` | ≥ 3 entry points reached, **or** ≥ 20 impacted nodes |
| `medium` | ≥ 1 entry point reached, ≥ 5 impacted nodes, **or** the diff changes an entry-point class (job, listener, command, Livewire, observer, middleware) |
| `low` | everything else |

Association edges — model relationships, trait usage, `declares` — are reach and context, not risk: they never count toward the impacted-node total, so touching a hub model or trait can't saturate a change to `high` on breadth alone. When a changed member can't be pinned to a graph node and only a coarse class-level seed is available, a resulting `high` is capped to `medium` (`coarseCapApplied`) — a low-confidence estimate shouldn't drive the top level on its own.

### Gating in CI

`detect-changes` is advisory by default (exit 0). Two opt-in flags turn it into a gate:

- `--fail-on=<low|medium|high>` exits non-zero when the reported risk is at least that level (see [Risk levels](#risk-levels)).
- `--fail-on-unresolved` exits non-zero when any changed file is **UNRESOLVED** — changed code the graph can't place. Independent of the risk threshold.

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

No GitHub Action ships with the package — `detect-changes` is a plain Artisan command, so wire it into whatever pipeline you already run.

> **Note:** `detect-changes` runs `php artisan`, so it boots your Laravel application to build the graph. The job needs whatever booting the app normally requires — typically an `.env` (`cp .env.example .env`) and an `APP_KEY` (`php artisan key:generate`), as above. Without them the command fails to boot before it can analyse anything.

The workflow analyzes the pull request's code, and analysis autoloads classes from that checkout (see above). For a public repository, keep the trigger on `pull_request` (never `pull_request_target` with a privileged token) so fork-submitted code runs without access to your secrets.

### Scoring accuracy against replayable history

```bash
php artisan richter:benchmark
php artisan richter:benchmark --case=TICKET-123
```

Replays historical fix commits (configured in `richter.benchmark_cases`) through the report: bug fixtures must resolve and reach an entry point; benign controls cap the risk a harmless change may report. Run it after changing the graph or tracers. A control flipping green→red is a regression in trustworthiness.

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

Building the code graph is the dominant cost of every command. Richter caches the built graph on disk (default: `storage/framework/cache/richter/graph.json`), keyed by a content fingerprint of everything the build reads — `app/`, `routes/`, `resources/views`, the relevant config, the package versions. Any input change rebuilds automatically, so a hit can only ever serve the graph the current code produces; there is no TTL to tune and no stale window.

- The cache is on by default; set `richter.cache.enabled` to `false` to disable it.
- `--no-cache` (on all three commands) bypasses it for one run — the escape hatch for an input the fingerprint doesn't cover.
- A corrupt or mismatched cache file reads as a miss and is rebuilt; it never fails a run.

### MCP server

When [`laravel/mcp`](https://github.com/laravel/mcp) is installed, Richter registers a local MCP server named `richter` exposing two read-only tools: `impact` (blast radius of a symbol) and `detect-changes` (advisory impact of the current branch diff). A coding agent can then triage changes without shelling out to Artisan — and because the MCP session holds the graph cache in memory, repeated tool calls in one review don't rebuild the graph.

Point Claude Code, Cursor, or any MCP client at the Artisan entry point — e.g. in `.mcp.json`:

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
| `dispatch_helpers` | `[]` | Project-custom global job-dispatch helper functions (e.g. `dispatch_with_retries`) the dispatch tracer should follow. |
| `entry_point_roots` | `Jobs`, `Listeners`, `Console/Commands`, `Helpers`, `Http/Middleware`, `Livewire`, `Observers` | Directories under `app/` traced as entry points beyond Brain's route-anchored graph (graph tracing only — the analyzer's risk-floor namespace heuristics are fixed). |
| `cache.enabled` | `true` | On-disk graph cache, keyed by a content fingerprint of the build inputs (see [Graph cache](#graph-cache)). |
| `cache.directory` | `null` | Cache location; `null` means `storage/framework/cache/richter`. |
| `benchmark_cases` | `[]` | Replayable accuracy fixtures for `richter:benchmark`. |

Richter assumes standard Laravel conventions: the `App\` root namespace, `app/Models`, `app/Policies`, `resources/views`, and `tests/`.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
