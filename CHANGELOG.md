# Changelog

All notable changes to `sandermuller/richter` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.5.0 - 2026-07-18

<!-- verified-sha: 084038831c09c1cfc1dac965f043cdbba9c4b64c -->
### Added

- **Structured MCP output.** Both MCP tools (`impact`, `detect-changes`) now return MCP structured content alongside the prose text block, in exactly the shape of the CLI `--json` contract — one machine contract, two surfaces. Both tools also advertise an `outputSchema`, so agents can branch on fields (`risk`, `entryPoints`, `entryPointPaths`, …) instead of parsing prose. Error paths are unchanged. Note for strict schema validators: the map-shaped fields (`changed`, `coverage`, `entryPointPaths`) serialize as `[]` when empty, exactly as the `--json` contract always has.
- **`richter:benchmark:add <fix-commit>`** scaffolds a `richter.benchmark_cases` fixture from a historical fix commit: it validates the commit, dry-runs it through the exact replay `richter:benchmark` uses, reports what the case would score today, and prints a ready-to-paste config stanza. `--control` derives the `max_risk` cap from the replayed risk; `--key` overrides the derived case key (ticket id found in the commit subject, else the short SHA). Read-only by design — it never edits the config file — and the exit code is honest: non-zero when the scaffolded case would fail `richter:benchmark` today.

### Internal

- `CodeGraph::nodesContaining()` now narrows candidates through a lazily-built token index before running the boundary regex, cutting each seed lookup from a full-graph regex scan to just the nodes sharing an identifier token with the needle. Matching semantics are preserved exactly — the regex remains the final filter — and a wide diff against a large host graph no longer pays O(changed-members × total-nodes) regex executions on top of the cached build.
- Suite grows from 343 to 357 tests: the token-index boundary semantics pinned directly, the structured MCP responses and advertised schemas covered end-to-end, and the scaffolder's guard, replay, derivation and refusal paths all exercised.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.4.0...v0.5.0

## v0.4.0 - 2026-07-18

<!-- verified-sha: 0c11c5ee64fe7f95f8b9f95a9678f60d3107e560 -->
### Fixed

- **Pure renames are now visible.** Moving a class file without editing it produces a 100%-similarity rename in the diff — a section with `rename from`/`rename to` metadata and no hunks. The parser previously ignored those sections entirely, so `richter:detect-changes` reported **no impact** for a change that breaks every caller of the old FQCN. The parser now registers hunk-less rename sections, and the analysis treats them as what they are: a class-level change that seeds **both** sides — the vanished old FQCN directly (its callers, which still reference it, are exactly the blast radius) and the new FQCN as a coarse class-level estimate. A rename whose old name matches nothing in the graph reads UNRESOLVED, never cosmetic. Renames that also edit content were already handled and are unchanged, as are pure *copies* (nothing existing breaks, so they stay additive-by-design).

### Internal

- Six new tests pin the behavior end to end: hunk-less rename registration on both parser flush paths, no double registration for content-carrying renames, pure copies ignored, the resolver's both-FQCN seeding, and an analyzer-level test proving a renamed class's old callers surface as the reported entry points. Suite now at 343 tests.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.3.0...v0.4.0

## v0.3.0 - 2026-07-18

<!-- verified-sha: b139916b8c33e1717efb4909ccf8b48f1a7c6a77 -->
### Added

- **Graph cache.** The code graph is now served from an on-disk cache keyed by a content fingerprint of everything the build reads — `app/`, `routes/`, `resources/views`, the relevant config, and package versions — so repeated runs and MCP sessions stop paying a full rebuild. Staleness is designed out rather than expired out: any changed input changes the fingerprint. Configurable via `richter.cache.enabled` / `richter.cache.directory`; `--no-cache` on all three commands bypasses it for one run.
- **`--markdown` on `richter:impact` and `richter:detect-changes`.** GitHub-flavoured markdown for pull-request descriptions and comments: risk badge up front, changed files as a table, entry points as a review checklist with test tags, and long lists collapsed into `<details>` instead of truncated.
- **`--explain` on `richter:detect-changes`.** Each reached entry point carries the shortest call chain down to the changed code, each hop labelled with its edge type. JSON output always includes the chains as `entryPointPaths`, keyed by entry point — a self-listed entry class deliberately carries no chain, so consumers can tell "reached from the change" apart from "is itself the entry surface".

### Fixed

- **Diff hunk lines starting with `++ ` or `-- ` were misread as file headers.** A removed SQL comment in a heredoc (`-- …`) or an added `++ $i;` statement made the parser drop the change and report a falsely-empty "no impact" — the exact failure the tool exists to prevent. The parser now tracks hunk state, so headers are only recognised in the file preamble.
- **Container-binding edges were silently absent for strict-typed providers.** Service providers opening with `declare(strict_types=1);` produced zero binding edges. Provider scanning is now done natively (and scans every class in a provider file, not only the first), so `bind()`/`singleton()`/`scoped()` calls and `$bindings`/`$singletons` properties resolve regardless of the declare.
- **`--explain` chains are now deterministic across cache and fresh builds.** Edges sort canonically before the graph is built, so a warm cache and `--no-cache` pick the same (equal-length) chain for the same commit.
- **The MCP `detect-changes` tool returns a clean error for an option-shaped base ref** (e.g. `--upload-pack=…`) instead of leaking an uncaught exception, matching the Artisan command's behavior.
- **The eager-load relation checker no longer caches model methods for the process lifetime.** In long-lived processes (MCP server, queue worker), a relation added mid-session could trigger a false "not a method on any model" finding; the scan is now fresh per run while still running at most once per invocation.
- **Graph builds no longer leave `laravel-brain.*` config overridden** in the host application after the build — the four path keys are restored once the analysis completes.
- **The README's safety claim was corrected**: analysis never executes routes, jobs, or commands, but it does autoload classes from the analyzed checkout — with guidance for running against untrusted pull-request branches in CI.

### Internal

- The consolidated per-file AST pass now collects node buckets in a single traversal (previously five full descents per file across the tracers) and retains the entry-point subset of ASTs so the entry-point tracer no longer re-parses those files.
- Name resolution is consolidated onto shared helpers; the previous five private copies are gone.
- The test suite grew from 267 to 337 tests: end-to-end benchmark pass/control scoring, interface-implementation and container-binding edge coverage, MCP success paths, diff-parser edge cases (CRLF, binary, mode-only), and cache round-trip determinism.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.2.0...v0.3.0

## v0.2.0 - 2026-07-16

<!-- verified-sha: 5cec649c4a780e626b583c6c8abdbdab022bedd1 -->
Machine-readable output and an opt-in CI gate for `richter:detect-changes`, plus stricter config validation. Advisory-by-default is unchanged: no flags still means human-readable text and exit 0.

### Added

- **`--json` on `richter:detect-changes` and `richter:impact`.** JSON mode emits a single parseable document on stdout — the full, uncapped report — for scripting and CI. Any failure is expressed as `{"error": "…"}` on stdout rather than a leaked stack trace, so the output is always valid JSON.
- **Opt-in CI gating on `richter:detect-changes`.** `--fail-on=<low|medium|high>` exits non-zero when the reported risk is at least the given level; `--fail-on-unresolved` exits non-zero when any changed file is UNRESOLVED, independent of the risk threshold. Both fail closed: a missing or invalid threshold (`--fail-on`, `--fail-on=`, `--fail-on=bogus`) is a usage error, and an un-assessable diff (a broken or invalid base ref) fails under a gate rather than passing as "no impact". With `--json`, the report carries a `gate` object recording the verdict.
- **README "Gating in CI" section** with a copy-paste GitHub Actions recipe. No Action ships with the package — `detect-changes` is a plain Artisan command.

### Changed

- **Config is validated on read.** A mis-shaped `richter.*` value now throws instead of being silently dropped, and a base ref shaped like an option (leading `-`) is rejected before it can reach a `git` argument. A misconfiguration surfaces loudly rather than degrading into a falsely-empty report.
- **MCP tool names pinned** (`impact`, `detect-changes`) so agent integrations stay stable across releases.

### Notes

Richter remains dev/CI tooling and advisory by default; a low or empty result is a signal, not a guarantee of no impact. Gating is strictly opt-in.

**Full Changelog**: https://github.com/SanderMuller/richter/compare/v0.1.0...v0.2.0

## [Unreleased]
