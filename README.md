[![Richter: measure the reach of a code change](richter.png)](https://sandermuller.github.io/richter/)

# Richter

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/richter.svg?style=flat-square)](https://packagist.org/packages/sandermuller/richter)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/richter/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/richter/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/SanderMuller/richter/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/SanderMuller/richter/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/richter.svg?style=flat-square)](https://packagist.org/packages/sandermuller/richter)
[![License](https://img.shields.io/packagist/l/sandermuller/richter.svg?style=flat-square)](LICENSE)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/sandermuller/richter?style=flat)](https://packagist.org/packages/sandermuller/richter)

Measures the magnitude of impact of code changes in a Laravel codebase. Like the Richter scale, but for your PHP.

Run `php artisan richter:detect-changes` on a branch and Richter reports the HTTP and CLI entry points the diff can reach, flags the ones no test references, and names the hazards the change carries: a guard removed, a payload key a consumer still reads, a validation constraint dropped. Review then starts from what the change reaches, instead of from a cold diff.

```text
Changed files:
  app/Models/Post.php (4 graph nodes)
  app/Services/CategoryImporter.php (0 graph nodes)  (UNRESOLVED: reach for this file could not be fully determined)

Entry points reached: 2 (some changed files could not be fully placed — see UNRESOLVED above)
  - command::categories:sync  (app/Console/Commands/SyncCategories.php)  [test-referenced]
  - route::PATCH::/api/posts/{post}  (routes/api.php:41)  [⚠ no test references this]  [authed]

Hazards (1):
  ! [tier 2 model CWE-915] App\Models\Post::$fillable — $fillable gained owner_id
      reach: gated (via its class)

Findings (in the changed source itself):
  ! app/Models/Post.php: eager-load string 'ownerprofile': segment 'ownerprofile' is not a method on any model — check the relation name

Risk:   MEDIUM (advisory) — tier 2 `model` hazard on App\Models\Post::$fillable, reach gated
Impact: 2 entry point(s) · 7 impacted node(s)
```

What makes it worth installing:

- **Member-level change impact.** A one-method change seeds that method in the code graph, not the whole class. The graph covers routes, controllers, jobs, listeners, policies, resources, Blade views, and the Eloquent relations your code walks, plus [edges a route-anchored analysis misses](https://sandermuller.github.io/richter/coverage).
- **The rows that matter, first.** Reached entry points are ordered by how specifically the diff explains each one, so a change that touches a widely-referenced class leads with its own routes instead of with whatever sorts first alphabetically. The `--json` payload carries the same attribution.
- **Test-coverage prompts.** Every reached entry point is tagged `[test-referenced]` or `[⚠ no test references this]`, a heuristic prompt rather than a coverage verdict.
- **Blast radius and traces on demand.** `richter:impact` lists a symbol's callers, its dependencies, and the entry surfaces behind them. `richter:trace` answers "how does this even reach that?" with the shortest call chain.
- **Affected-test selection.** `richter:affected-tests` turns the diff's reach into a test selection, with an exit-code contract that fails toward running the full suite whenever the selection cannot be trusted.
- **Built for coding agents.** Richter registers a local MCP server exposing every analysis read-only, so an agent can work with the graph mid-review without shelling out. The `--markdown` report is ready to post as a pull-request comment.

Richter is advisory by default: `richter:detect-changes` exits 0, and a low or empty result is a signal, not a guarantee of no impact. Opt into a CI gate with `--fail-on`, `--fail-on-hazard` or `--fail-on-unresolved`.

The analysis is static, built on [Laravel Brain](https://github.com/laramint/laravel-brain), and fast enough to run on every branch: it never executes your application's routes, jobs, or commands. It does, however, autoload classes from the analyzed checkout, and autoloading runs a file's top-level code. Treat a checkout you would not `composer install` on as one you should not analyze either.

## Installation

```bash
composer require --dev sandermuller/richter
```

Requires PHP 8.4+ and Laravel 12 or 13. `laravel/mcp` is optional and, when present, must fall in the supported `^0.8||^0.9` range; see [Installation](https://sandermuller.github.io/richter/installation) for the `laravel/boost` v1 case.

Richter is accurate only once it knows your app's shape. Ask your agent to "set up Richter", or follow [Set up your project](https://sandermuller.github.io/richter/project-setup).

## Usage

```bash
php artisan richter:detect-changes                     # advisory impact of the current diff
php artisan richter:detect-changes --explain           # show how each entry point reaches the change
php artisan richter:detect-changes --markdown          # PR-ready markdown
php artisan richter:impact "App\Services\PostPublisher"   # blast radius of one symbol
php artisan richter:trace PostController PostPublisher    # shortest call chain between two symbols
php artisan richter:affected-tests                        # the test selection the diff warrants
```

Each of these takes `--json` for machine-readable output. `richter:detect-changes` also takes `--html=<path>` for a self-contained visual report.

## Documentation

Read the full documentation at **[sandermuller.github.io/richter](https://sandermuller.github.io/richter/)**.

**Getting started**
- [Why Richter?](https://sandermuller.github.io/richter/why-richter): what a report tells you, what it refuses to guess at, and how the analysis runs
- [Installation](https://sandermuller.github.io/richter/installation): requirements, the `laravel/mcp` constraint, publishing the config
- [Getting started](https://sandermuller.github.io/richter/getting-started): one command on a branch, and the line in the report worth acting on
- [Set up your project](https://sandermuller.github.io/richter/project-setup): the setup skill, or two prompts you can paste to any agent

**Change impact**
- [Detecting change impact](https://sandermuller.github.io/richter/detect-changes): the main command, which diff is analysed, reading the report, `--explain`
- [Report annotations](https://sandermuller.github.io/richter/report-annotations): security exposure, Pennant gates, payload parity, middleware group membership
- [Output formats](https://sandermuller.github.io/richter/output-formats): `--markdown`, `--html`, and the `--json` contract
- [Risk levels](https://sandermuller.github.io/richter/risk-levels): the hazard tiers, the reach matrix, and the ladder that decides the level
- [Gating in CI](https://sandermuller.github.io/richter/ci-gating): `--fail-on`, `--fail-on-unresolved`, and a pull-request workflow

**Commands**
- [Blast radius of a symbol](https://sandermuller.github.io/richter/impact): `richter:impact`
- [Shortest path between symbols](https://sandermuller.github.io/richter/trace): `richter:trace`
- [Affected-test selection](https://sandermuller.github.io/richter/affected-tests): `richter:affected-tests`
- [Task slice](https://sandermuller.github.io/richter/task-slice): `richter:task-slice`, one document for work in progress

**Digging deeper**
- [Frontend changes](https://sandermuller.github.io/richter/frontend): the Wayfinder/Ziggy bridge in full
- [MCP server](https://sandermuller.github.io/richter/mcp-server): the read-only tools and resources an agent can call
- [Graph cache](https://sandermuller.github.io/richter/graph-cache): the fingerprinted cache, profiling, scoped rebuilds
- [Coverage beyond Laravel Brain](https://sandermuller.github.io/richter/coverage): the edges a route-anchored analysis misses, and the known limits

**Reference**
- [Configuration reference](https://sandermuller.github.io/richter/configuration): every key in `config/richter.php`
- [Benchmarking](https://sandermuller.github.io/richter/benchmark): scoring accuracy against replayable history
- [Troubleshooting](https://sandermuller.github.io/richter/troubleshooting): a symptom index: empty reports, UNRESOLVED files, a level that reads medium everywhere, exit 2

## Testing

```bash
composer test        # test suite only
composer qa-check    # read-only pre-push gate: Rector + Pint dry-runs, PHPStan, tests (mirrors CI)
```

`composer qa` is the auto-fixing variant: it rewrites the working tree (Rector, Pint), so use `qa-check` when you only want to verify.

## Changelog

See [CHANGELOG](CHANGELOG.md) for what changed per release.

## Security

Found a vulnerability? Don't open an issue; see [SECURITY](SECURITY.md) for where to send it.

## License

MIT. See [LICENSE](LICENSE).
