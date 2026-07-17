# Plan 004: Restore the laravel-brain config keys after a graph build instead of leaving them overridden

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: This plan was written against a working tree
> with uncommitted changes on top of commit `50a0efa`, so a commit-range diff
> is not a reliable drift signal. Instead, compare every "Current state"
> excerpt below against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

`CodeGraphBuilder::build()` overrides four `laravel-brain.*` config keys in the host application's global config repository and never restores them. In a short-lived Artisan process that is invisible; in the long-lived MCP server process (Richter registers one) the host app's Brain config stays permanently overridden after the first build — any other code reading `laravel-brain.*` in that process sees Richter's widened glob paths instead of the host's values. A snapshot-and-restore in a `finally` removes the leak without changing what the analyzer sees during the build.

## Current state

- `src/Graph/CodeGraphBuilder.php:41-53` — the mutation, verbatim:

```php
public function build(?string $projectRoot = null, ?callable $onProgress = null): CodeGraph
{
    $projectRoot ??= base_path();

    config()->set('laravel-brain.route_paths', self::ROUTE_PATHS);
    config()->set('laravel-brain.channel_paths', self::ROUTE_PATHS);
    config()->set('laravel-brain.commands.console_route_paths', self::ROUTE_PATHS);
    config()->set('laravel-brain.commands.class_paths', self::COMMAND_CLASS_PATHS);

    $analysis = new ProjectAnalyzer()->analyze(
        $projectRoot,
        $onProgress ?? static fn (string $event, array $data): null => null,
    );
```

  The rest of `build()` (node normalisation, tracer passes) runs *after* `analyze()` and does not read Brain config; only `analyze()` consumes the four keys.

- `src/Graph/GraphCache.php:93-120` — `brainConfigInput()` excludes exactly these four keys from the cache fingerprint. Its docblock reads: *"CodeGraphBuilder::build() force-overrides the four path keys in global config, so their host values never reach the analyzer — and hashing them would flip the fingerprint after the first build in a process, turning every subsequent MCP call into a rebuild."* After this plan, the first half stays true (the builder still overrides during the build); the second half ("would flip the fingerprint after the first build") becomes stale, because the values are restored after each build. The exclusion itself remains correct — host values still never reach the analyzer — so only the comment needs updating, not the logic.
- `tests/Feature/CodeGraphBuilderTest.php` — builds the graph against the fixture project (`TestCase::fixtureProjectPath()`, helper `graph()` at line 31). The natural home for the regression test.
- Repo conventions: `<?php declare(strict_types=1);`, comments state constraints, PHPUnit 12 `#[Test]`, snake_case test names.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `vendor/bin/phpunit --filter CodeGraphBuilderTest` | all pass |
| Cache tests | `vendor/bin/phpunit --filter GraphCacheTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Graph/CodeGraphBuilder.php`
- `src/Graph/GraphCache.php` (docblock sentence only — no logic change)
- `tests/Feature/CodeGraphBuilderTest.php`

**Out of scope** (do NOT touch, even though they look related):

- `GraphCache::brainConfigInput()`'s *logic* — the four-key exclusion stays correct after the restore (see Current state); changing it would make the fingerprint hash values the analyzer never sees.
- Centralising the Brain config-key names into constants shared with `GraphCache` — a nice-to-have that belongs to the Brain-coupling finding (audit finding 13), not this fix.

## Git workflow

- Branch: `advisor/004-brain-config-restore` off `main`.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add a failing regression test

In `tests/Feature/CodeGraphBuilderTest.php`, add:

```php
#[Test]
public function a_build_restores_the_host_apps_brain_config(): void
{
    config()->set('laravel-brain.route_paths', ['host/sentinel/*.php']);

    new CodeGraphBuilder()->build(self::fixtureProjectPath());

    $this->assertSame(['host/sentinel/*.php'], config('laravel-brain.route_paths'));
}
```

(Match the file's existing instantiation style for the builder — see its `graph()` helper — and reuse the fixture path helper.)

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → exactly this test fails, asserting the sentinel was replaced by Richter's glob list. Any other failure is a STOP condition.

### Step 2: Snapshot and restore around the analyze call

In `CodeGraphBuilder::build()`:

1. Before the four `config()->set(...)` lines, snapshot the current values of the four keys (e.g. an array of key → `config($key)`).
2. Wrap everything from the first `config()->set()` through the `$analysis = ... ->analyze(...)` call in `try { ... } finally { /* restore each snapshotted key via config()->set($key, $original) */ }`. Only `analyze()` reads the keys, so the restore can happen immediately after it — the tracer passes below need no override.
3. Add a one-line comment stating the constraint: the override must not outlive the build, because the process may be a long-lived MCP server sharing config with the host app.

**Verify**: `vendor/bin/phpunit --filter CodeGraphBuilderTest` → all pass, including the new test.

### Step 3: Update the stale GraphCache docblock sentence

In `src/Graph/GraphCache.php`, rewrite the `brainConfigInput()` docblock so it no longer claims the override persists ("would flip the fingerprint after the first build in a process"). The keys stay excluded because the builder force-overrides them *during* every build, so their host values never influence the produced graph. Keep the docblock's existing tone.

**Verify**: `vendor/bin/phpunit --filter GraphCacheTest` → all pass (fingerprint behavior unchanged).

### Step 4: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 5: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "004" plans/README.md` → row shows DONE.

## Test plan

- One new feature test: a sentinel `laravel-brain.route_paths` value survives a `build()` (the regression this plan fixes).
- Existing `GraphCacheTest::the_fingerprint_survives_a_build_in_the_same_process` (line 85) is the guard for the fingerprint interaction — it must stay green untouched.
- Verification: `composer test` → green.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter CodeGraphBuilderTest` exits 0, including the new restore test
- [ ] `vendor/bin/phpunit --filter GraphCacheTest` exits 0 with no test modified
- [ ] `composer test` exits 0, 0 failures
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in the three in-scope files plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `build()` excerpt no longer matches (in particular if a restore already exists — the finding may have been fixed independently; report that).
- `GraphCacheTest::the_fingerprint_survives_a_build_in_the_same_process` fails after the change — the restore has interacted with the fingerprint in a way this plan claims it cannot; do not patch the fingerprint to compensate.
- Restoring the keys makes any Brain-driven test fail — that would mean something *after* `analyze()` reads Brain config, falsifying this plan's core assumption.

## Maintenance notes

- Audit finding 13 (Brain coupling on undocumented internals) proposes centralising these config-key names and adding a Brain contract test; if that lands, the snapshot/restore here should use those shared constants.
- Reviewer scrutiny: the restore must be in `finally` so an `analyze()` throw doesn't leak the override.
