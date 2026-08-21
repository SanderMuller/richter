# Documentation

Measure the magnitude of impact of code changes in a Laravel codebase. For a quick overview and installation, see the [main README](https://github.com/SanderMuller/richter/blob/main/README.md).

## Getting started

- [Why Richter?](01-why-richter.md) — what a report tells you, what it refuses to guess at, and how the analysis runs
- [Installation](02-installation.md) — requirements, the `laravel/mcp` constraint, publishing the config
- [Set up your project](03-project-setup.md) — the setup skill, or two prompts you can paste to any agent

## Change impact

- [Detecting change impact](04-detect-changes.md) — the main command, which diff is analysed, reading the report, `--explain`
- [Report annotations](05-report-annotations.md) — security exposure, Pennant gates, payload parity, middleware group membership
- [Output formats](06-output-formats.md) — `--markdown`, `--html`, and the `--json` contract
- [Risk levels](07-risk-levels.md) — the hazard tiers, the reach matrix, and the ladder that decides the level
- [Gating in CI](08-ci-gating.md) — `--fail-on`, `--fail-on-unresolved`, and a pull-request workflow

## Commands

- [Blast radius of a symbol](09-impact.md) — `richter:impact`: callers, dependencies, entry surfaces
- [Shortest path between symbols](10-trace.md) — `richter:trace`: call chains and depth
- [Affected-test selection](11-affected-tests.md) — `richter:affected-tests`: selection mechanics and the exit-code contract

## Digging deeper

- [Frontend changes](12-frontend.md) — the Wayfinder/Ziggy bridge in full
- [MCP server](13-mcp-server.md) — the read-only tools and resources an agent can call
- [Graph cache](14-graph-cache.md) — the fingerprinted cache, profiling, scoped rebuilds
- [Coverage beyond Laravel Brain](15-coverage.md) — the edges a route-anchored analysis misses, and the known limits

## Reference

- [Configuration reference](16-configuration.md) — every key in `config/richter.php`
- [Benchmarking](17-benchmark.md) — scoring accuracy against replayable history
- [Troubleshooting](18-troubleshooting.md) — a symptom index: empty reports, UNRESOLVED files, saturated risk levels, exit 2
