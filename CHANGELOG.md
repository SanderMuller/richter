# Changelog

All notable changes to `sandermuller/richter` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
