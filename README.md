# Richter

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
php artisan richter:impact "App\Models\User"
php artisan richter:impact UserPolicy          # substrings work too
```

Prints the symbol's callers (what breaks if you change it) and its dependencies (what it reaches), breadth-first with depth annotations.

### Advisory change impact of the current diff

```bash
php artisan richter:detect-changes                        # diffs against richter.default_base
php artisan richter:detect-changes --base=origin/develop
php artisan richter:detect-changes --json                 # machine-readable, for scripting or CI
```

Resolves which class members the branch changed (member-level, not file-level: a one-method change seeds that method, not the whole class), walks the graph, and reports:

- the entry points (routes, commands, jobs, listeners, middleware, …) the change can reach, annotated with whether any test references them;
- a coarse risk level (`low` / `medium` / `high`);
- honest degradation: a change that cannot be placed in the graph reads **UNRESOLVED**, never as a falsely reassuring "no impact", and an unfollowable dispatch makes a queue job read "unknown", not "none".

With `--json`, stdout is a single JSON document (the full, uncapped report), or `{"error": "…"}` if the diff can't be resolved.

### Gating in CI

`detect-changes` is advisory by default (exit 0). Two opt-in flags turn it into a gate:

- `--fail-on=<low|medium|high>` exits non-zero when the reported risk is at least that level.
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
      - run: php artisan richter:detect-changes --base=${{ github.event.pull_request.base.sha }} --fail-on=high --fail-on-unresolved
```

No GitHub Action ships with the package — `detect-changes` is a plain Artisan command, so wire it into whatever pipeline you already run.

### Scoring accuracy against replayable history

```bash
php artisan richter:benchmark
php artisan richter:benchmark --case=TICKET-123
```

Replays historical fix commits (configured in `richter.benchmark_cases`) through the report: bug fixtures must resolve and reach an entry point; benign controls cap the risk a harmless change may report. Run it after changing the graph or tracers. A control flipping green→red is a regression in trustworthiness.

### MCP server

When [`laravel/mcp`](https://github.com/laravel/mcp) is installed, Richter registers a local MCP server named `richter` exposing two read-only tools: `impact` (blast radius of a symbol) and `detect-changes` (advisory impact of the current branch diff). Point a coding agent at it and it can triage changes without shelling out to Artisan.

## Configuration

`config/richter.php`:

| Key | Default | Purpose |
|---|---|---|
| `default_base` | `origin/main` | Git ref `richter:detect-changes` diffs against when `--base` is omitted. |
| `dispatch_helpers` | `[]` | Project-custom global job-dispatch helper functions (e.g. `dispatch_with_retries`) the dispatch tracer should follow. |
| `entry_point_roots` | `Jobs`, `Listeners`, `Console/Commands`, `Helpers`, `Http/Middleware`, `Livewire`, `Observers` | Directories under `app/` traced as entry points beyond Brain's route-anchored graph (graph tracing only — the analyzer's risk-floor namespace heuristics are fixed). |
| `benchmark_cases` | `[]` | Replayable accuracy fixtures for `richter:benchmark`. |

Richter assumes standard Laravel conventions: the `App\` root namespace, `app/Models`, `app/Policies`, `resources/views`, and `tests/`.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
