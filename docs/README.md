# Documentation

Measure the magnitude of impact of code changes in a Laravel codebase. For a quick overview and installation, see the [main README](https://github.com/SanderMuller/richter/blob/main/README.md).

## Getting started

- [Why Richter?](01-why-richter.md): what a report tells you, what it refuses to guess at, and how the analysis runs
- [Installation](02-installation.md): requirements, the `laravel/mcp` constraint, publishing the config
- [Getting started](03-getting-started.md): one command on a branch, and the line in the report worth acting on
- [Set up your project](04-project-setup.md): the setup skill, or two prompts you can paste to any agent

## Change impact

- [Detecting change impact](05-detect-changes.md): the main command, which diff is analysed, reading the report, `--explain`
- [Report annotations](06-report-annotations.md): security exposure, Pennant gates, payload parity, middleware group membership
- [Output formats](07-output-formats.md): `--markdown`, `--html`, and the `--json` contract
- [Risk levels](08-risk-levels.md): the hazard tiers, the reach matrix, and the ladder that decides the level
- [Gating in CI](09-ci-gating.md): `--fail-on`, `--fail-on-unresolved`, and a pull-request workflow

## Commands

- [Blast radius of a symbol](10-impact.md): `richter:impact`: callers, dependencies, entry surfaces
- [Shortest path between symbols](11-trace.md): `richter:trace`: call chains and depth
- [Affected-test selection](12-affected-tests.md): `richter:affected-tests`: selection mechanics and the exit-code contract
- [Task slice](20-task-slice.md): `richter:task-slice`: one document for work in progress — the surfaces this task owns, and what to run
- [Where a symbol or file is](21-locate.md): `richter:locate`: the exact node id `impact` and `trace` need, without a walk

## Digging deeper

- [Frontend changes](13-frontend.md): the Wayfinder/Ziggy bridge in full
- [MCP server](14-mcp-server.md): the read-only tools and resources an agent can call
- [Graph cache](15-graph-cache.md): the fingerprinted cache, profiling, scoped rebuilds
- [Coverage beyond Laravel Brain](16-coverage.md): the edges a route-anchored analysis misses, and the known limits

## Reference

- [Configuration reference](17-configuration.md): every key in `config/richter.php`
- [Benchmarking](18-benchmark.md): scoring accuracy against replayable history
- [Troubleshooting](19-troubleshooting.md): a symptom index: empty reports, UNRESOLVED files, saturated risk levels, exit 2
