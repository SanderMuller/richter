# Plan 017: Bound the laravel/mcp compatibility range and test the mcp-absent path in CI

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- composer.json src/RichterServiceProvider.php .github/workflows/run-tests.yml`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug (dependency boundary)
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

`laravel/mcp` is a 0.x package that Richter references in shipped `src/` code but
declares only in `require-dev` and re-suggests to consumers with **no version
bound**. The only runtime guard is `class_exists(Mcp::class)`, which protects the
*not-installed* case — not an installed-but-incompatible one. This was a
hypothetical risk when the range was `^0.8` and 0.8 was current; **laravel/mcp
0.9.0 has now been released** (`composer outdated --direct` reports
`laravel/mcp 0.8.2 ~ 0.9.0`), so a consumer following the README ("when
laravel/mcp is installed, Richter registers a local MCP server") can resolve
0.9 today and, if its API reshaped, fatal at framework boot — not just when the
MCP surface is used. Separately, no CI cell ever runs without `laravel/mcp`
installed, so the `class_exists` guard's false branch (the common CLI-only
consumer) is never exercised.

## Current state

- `composer.json:35` — `"laravel/mcp": "^0.8"` in `require-dev`.
- `composer.json:121-123`:

  ```json
  "suggest": {
      "laravel/mcp": "Exposes the impact and detect-changes tools over MCP (richter registers a local MCP server when installed)."
  }
  ```

  No version bound in the suggest text; no `conflict` block exists anywhere in
  `composer.json`.
- `src/RichterServiceProvider.php:35-42`:

  ```php
  #[Override]
  public function packageBooted(): void
  {
      // laravel/mcp is a suggested dependency — the MCP surface lights up only when it is installed.
      if (class_exists(Mcp::class)) {
          Mcp::local('richter', RichterServer::class);
      }
  }
  ```

- Shipped files importing laravel/mcp API: `src/RichterServiceProvider.php:5`
  (`Laravel\Mcp\Facades\Mcp`), `src/Mcp/RichterServer.php` (extends the
  package's `Server`), `src/Mcp/Tools/DetectChangesTool.php` and
  `src/Mcp/Tools/ImpactTool.php` (Tool base class, `Request`, `Response`,
  JsonSchema builder).
- `.github/workflows/run-tests.yml:60-63` — all 5 matrix cells install the full
  `require-dev` set (which includes `laravel/mcp`):

  ```yaml
  - name: Install dependencies
    run: |
      composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
      composer update --${{ matrix.stability }} --prefer-dist --no-interaction
  ```

- `tests/Feature/McpTest.php` exercises the MCP-installed path (registration +
  tool behavior). Nothing exercises the absent path.
- Repo conventions: this is a Composer package tested via Orchestra Testbench
  (`vendor/bin/phpunit`, never `php artisan`). Cross-version constraints use
  `||` between majors (see `composer.json:21-25`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Tests | `composer test` | `"result":"passed"`, 0 failures (516 tests at planning time) |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Style (check) | `vendor/bin/pint --test` | exit 0 |
| Composer sanity | `composer validate` | `./composer.json is valid` |
| Dependency state | `composer outdated --direct` | informational |

## Suggested executor toolkit

- Skill `cross-version-laravel-support` — read before touching constraint
  syntax; the repo follows its `||`-range conventions.
- Skill `backend-quality` for the closing checks.

## Scope

**In scope** (the only files you should modify):
- `composer.json`
- `composer.lock` (only as a side effect of the constraint verification in step 1)
- `.github/workflows/run-tests.yml`
- `README.md` (one sentence in the "MCP server" section, step 4)

**Out of scope** (do NOT touch, even though they look related):
- `src/Mcp/**` and `src/RichterServiceProvider.php` — no runtime code change
  is part of this plan. If step 1 shows 0.9 is incompatible, the boundary is
  expressed in `composer.json`, not with runtime version probes.
- The other three workflows (`phpstan.yml`, `pint-check.yml`, `rector-check.yml`).

## Git workflow

- Branch: `advisor/017-mcp-version-boundary`
- Commit style: imperative sentence subjects, e.g. `Bound the laravel/mcp range and cover the mcp-absent path in CI` (match `git log --oneline -10`).
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Establish whether laravel/mcp 0.9 is compatible

Widen the dev constraint and test against 0.9:

1. In `composer.json` change `"laravel/mcp": "^0.8"` to `"laravel/mcp": "^0.8||^0.9"`.
2. Run `composer update laravel/mcp --with-all-dependencies`.
3. Run `composer test`.

**Verify**: `composer show laravel/mcp` → version `0.9.x`, and `composer test`
→ `"result":"passed"`.

If the update or the suite fails on 0.9: revert the constraint to `^0.8`, run
`composer update laravel/mcp` back to 0.8.x, confirm the suite is green again,
and use `<0.8.0 || >=0.9.0` as the conflict range in step 2 instead of
`<0.8.0 || >=0.10.0`. Record which branch you took in the final report.

### Step 2: Publish the boundary as a `conflict` entry

Add to `composer.json` (top level, after `require-dev`):

```json
"conflict": {
    "laravel/mcp": "<0.8.0 || >=0.10.0"
},
```

(Or the `>=0.9.0` variant per step 1's outcome.) This is the machine-readable
boundary: a consumer who installs Richter plus an unvalidated laravel/mcp
release gets a Composer resolution error naming the conflict, instead of a
boot-time fatal.

**Verify**: `composer validate` → valid; `composer update --dry-run` → resolves
without removing laravel/mcp.

### Step 3: Name the supported range in the suggest text

Change the `suggest` entry to include the validated range, e.g.:

```json
"laravel/mcp": "Exposes the impact and detect-changes tools over MCP — supported range ^0.8||^0.9; richter registers a local MCP server when installed."
```

(Adjust to the range validated in step 1.)

**Verify**: `composer validate` → valid.

### Step 4: Note the supported range in the README

In `README.md`, "MCP server" section (starts near line 349: `### MCP server`),
add one sentence naming the supported laravel/mcp range, matching step 3.
Match the section's existing tone; no new heading.

**Verify**: `grep -n "laravel/mcp" README.md` → the section names the range.

### Step 5: Add a no-mcp CI job

In `.github/workflows/run-tests.yml`, add a second job (sibling of `test`)
that removes `laravel/mcp` before running the suite, so the
`class_exists(Mcp::class)` false branch boots in CI:

```yaml
  test-without-mcp:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    name: P8.4 - L12.* - without laravel/mcp
    steps:
      - uses: actions/checkout@v6
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - name: Install dependencies
        run: |
          composer remove laravel/mcp --dev --no-interaction --no-update
          composer update --prefer-stable --prefer-dist --no-interaction
      - name: Execute tests
        run: vendor/bin/phpunit --exclude-group requires-mcp
```

Then mark the MCP-dependent tests: add `#[Group('requires-mcp')]` (import
`PHPUnit\Framework\Attributes\Group`) to the test **class** in
`tests/Feature/McpTest.php`. Check first whether any other test file
references `Laravel\Mcp` (`grep -rln "Laravel\\\\Mcp" tests/`) and group those
too. All remaining tests must pass without the package installed — that is
the point of the job.

Confirm locally that the excluded run is green without laravel/mcp:

```bash
composer remove laravel/mcp --dev --no-interaction
vendor/bin/phpunit --exclude-group requires-mcp
composer require --dev "laravel/mcp:^0.8||^0.9" --no-interaction
```

(The final `require` restores the dev dependency; use the range from step 1.)

**Verify**: the excluded run → 0 failures, 0 errors; afterwards `composer test`
(full suite, mcp restored) → `"result":"passed"`.

## Test plan

- No new PHP test files. The new coverage is the CI job itself plus the
  `requires-mcp` group split, verified locally in step 5.
- Full-suite regression: `composer test` green with laravel/mcp at the step-1
  version.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer validate` exits 0
- [ ] `composer.json` contains a `conflict` entry for `laravel/mcp`
- [ ] The `suggest` text and the README MCP section both name the supported range
- [ ] `.github/workflows/run-tests.yml` contains a job that runs `composer remove laravel/mcp --dev` before the suite
- [ ] `tests/Feature/McpTest.php` carries `#[Group('requires-mcp')]`
- [ ] Local no-mcp run (step 5) exits 0
- [ ] `composer test` exits 0 with laravel/mcp installed
- [ ] `composer phpstan` exits 0
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The excerpts in "Current state" don't match the live code.
- laravel/mcp 0.9 fails the suite **and** reverting to 0.8 does not restore a
  green suite — the environment is broken, not the plan.
- Removing laravel/mcp makes non-MCP tests fail for a reason other than a
  missing `Laravel\Mcp` class (that would mean the package has an undeclared
  hard dependency on it — a bigger finding than this plan; report it).
- You find yourself wanting to add runtime version-probing code in
  `src/RichterServiceProvider.php` — that is explicitly out of scope.

## Maintenance notes

- When laravel/mcp 0.10/1.0 ships, repeat step 1 against it and move the
  conflict ceiling — the conflict block is a moving boundary, not a fixture.
- The `requires-mcp` group is the seam for any future MCP-only test.
- Reviewers: check the conflict range direction carefully — `conflict` blocks
  what is listed, so the entry must name the *invalid* versions.
