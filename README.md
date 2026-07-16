# Richter

Measures the magnitude of impact of code changes in a Laravel codebase — like the Richter scale, but for your PHP.

Built on [Laravel Brain](https://github.com/laramint/laravel-brain)'s static analysis, Richter constructs a directed code graph of your application (routes, controllers, jobs, listeners, policies, resources, Blade views, Eloquent relations) and answers two questions over it:

- **What is the blast radius of a symbol?** — its callers (what breaks if you change it) and its dependencies (what it reaches).
- **What does the current branch diff actually touch?** — which HTTP/CLI entry points and flows the changed files reach, plus a coarse advisory risk level.

Richter fills the gaps Brain's route-anchored graph misses: queue dispatches (including unresolvable ones), `$listen`-registered event listeners, container bindings, interface implementations, policy references (`$user->can(PostPolicy::UPDATE, …)` and `@can(...)` in Blade), API resource composition, custom validation rules, trait usage, eager-load relation strings, and view-to-view includes.

It is **dev/CI tooling and advisory only** — a low or empty result is a signal, never a guarantee of no impact.

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
```

Resolves which class members the branch changed (member-level, not file-level — a one-method change seeds that method, not the whole class), walks the graph, and reports:

- the entry points (routes, commands, jobs, listeners, middleware, …) the change can reach, annotated with whether any test references them;
- a coarse risk level (`low` / `medium` / `high`);
- honest degradation: a change that cannot be placed in the graph reads **UNRESOLVED**, never as a falsely reassuring "no impact", and an unfollowable dispatch makes a queue job read "unknown", not "none".

### Scoring accuracy against replayable history

```bash
php artisan richter:benchmark
php artisan richter:benchmark --case=TICKET-123
```

Replays historical fix commits (configured in `richter.benchmark_cases`) through the report: bug fixtures must resolve and reach an entry point; benign controls cap the risk a harmless change may report. Run it after changing the graph or tracers — a control flipping green→red is a regression in trustworthiness.

### MCP server

When [`laravel/mcp`](https://github.com/laravel/mcp) is installed, Richter registers a local MCP server (`richter`) exposing the `impact` and `detect-changes` tools, so coding agents can query blast radius directly.

## Configuration

`config/richter.php`:

| Key | Default | Purpose |
|---|---|---|
| `default_base` | `origin/main` | Git ref `richter:detect-changes` diffs against when `--base` is omitted. |
| `dispatch_helpers` | `[]` | Project-custom global job-dispatch helper functions (e.g. `dispatch_with_retries`) the dispatch tracer should follow. |
| `entry_point_roots` | `Jobs`, `Listeners`, `Console/Commands`, `Helpers`, `Http/Middleware`, `Livewire`, `Observers` | Directories under `app/` traced as entry points beyond Brain's route-anchored graph. |
| `benchmark_cases` | `[]` | Replayable accuracy fixtures for `richter:benchmark`. |

Richter assumes standard Laravel conventions: the `App\` root namespace, `app/Models`, `app/Policies`, `resources/views`, and `tests/`.

## Testing

```bash
composer test
```

## License

MIT — see [LICENSE](LICENSE).
