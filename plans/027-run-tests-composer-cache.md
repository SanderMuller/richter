# Plan 027: Cache Composer downloads in the run-tests matrix

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- .github/workflows/run-tests.yml .github/workflows/phpstan.yml`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (if plan 017 landed first, apply the same cache step to its `test-without-mcp` job too)
- **Category**: dx
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

The run-tests workflow is the widest and most frequent CI surface (5 matrix
cells on every `**.php` push/PR) and the only workflow that installs
dependencies with no caching — every cell pays a full dependency download.
The other three workflows all cache (via `ramsey/composer-install@v4` and
`actions/cache@v5`). Caching Composer's **download cache** (not `vendor/`)
is safe across the matrix's differing resolutions: `prefer-lowest`/
`prefer-stable` still resolve independently, only package downloads are
reused.

## Current state

- `.github/workflows/run-tests.yml:51-66` — the uncached install:

  ```yaml
  steps:
    - uses: actions/checkout@v6

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php }}
        coverage: none

    - name: Install dependencies
      run: |
        composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
        composer update --${{ matrix.stability }} --prefer-dist --no-interaction
  ```

  The matrix (lines 36-47) varies `php`, `laravel`, `testbench`, `stability`.
  Note the `composer require --no-update` mutates `composer.json` in the
  runner before `composer update` — `ramsey/composer-install` does not model
  that flow, which is why this plan uses a plain cache step rather than
  switching the action.
- `.github/workflows/phpstan.yml:44-51` shows the repo's existing
  `actions/cache@v5` usage pattern (result-cache keyed with a
  `restore-keys` fallback) — match its style.
- No `composer.lock`-keyed cache is appropriate here: the matrix deliberately
  re-resolves per cell, so key on the inputs that determine the resolution
  (`composer.json` hash + matrix vars).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| YAML sanity | `php -r "require 'vendor/autoload.php'; \Symfony\Component\Yaml\Yaml::parseFile('.github/workflows/run-tests.yml'); echo 'OK';"` | prints `OK` (symfony/yaml ships with the dev deps; if the class is missing, fall back to careful visual review and say so) |
| Suite (unaffected, sanity) | `composer test` | `"result":"passed"` |

## Scope

**In scope** (the only file you should modify):
- `.github/workflows/run-tests.yml`

**Out of scope** (do NOT touch):
- The other three workflows — already cached.
- The matrix definition, PHP/Laravel versions, and the install commands
  themselves — this plan adds caching around them, nothing else.

## Git workflow

- Branch: `advisor/027-run-tests-composer-cache`
- Commit style: imperative subject, e.g. `Cache Composer downloads in the run-tests matrix`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the cache step

In `.github/workflows/run-tests.yml`, between "Setup PHP" and "Install
dependencies", insert:

```yaml
      - name: Get Composer cache directory
        id: composer-cache
        run: echo "dir=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"

      - name: Cache Composer downloads
        uses: actions/cache@v5
        with:
          path: ${{ steps.composer-cache.outputs.dir }}
          key: composer-${{ runner.os }}-P${{ matrix.php }}-L${{ matrix.laravel }}-${{ matrix.stability }}-${{ hashFiles('composer.json') }}
          restore-keys: |
            composer-${{ runner.os }}-P${{ matrix.php }}-L${{ matrix.laravel }}-${{ matrix.stability }}-
            composer-${{ runner.os }}-
  ```

(Indent to match the file's existing two-space step style. `actions/cache@v5`
matches the version the sibling workflows pin.)

**Verify**: the YAML sanity command → `OK`.

### Step 2: Reconcile with plan 017's job, if present

If `.github/workflows/run-tests.yml` contains a `test-without-mcp` job (plan
017), add the same two steps there with `-nomcp` appended to the key.
If the job doesn't exist, skip this step silently.

**Verify**: YAML sanity command → `OK`.

## Test plan

- No PHP tests. Verification is the YAML parse plus, after the operator
  pushes, one green run of the workflow with the cache step reporting a
  save/restore (note in the report that this final confirmation happens
  post-push and is the operator's to observe).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c "actions/cache@v5" .github/workflows/run-tests.yml` ≥ 1
- [ ] Cache key contains all three matrix variables and the composer.json hash
- [ ] YAML sanity command prints `OK`
- [ ] `composer test` exits 0 (untouched, sanity)
- [ ] Only `.github/workflows/run-tests.yml` modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The install step's shape changed since planning (drift) — re-evaluate
  whether `ramsey/composer-install` became viable before adding a raw cache.
- You are tempted to cache `vendor/` — do not; the matrix re-resolves per
  cell and a restored `vendor/` can mask resolution differences
  (`prefer-lowest` exists precisely to catch those).

## Maintenance notes

- If the matrix gains a dimension, extend the cache key with it — a shared
  key across differing resolutions only costs correctness of the cache-hit
  ratio, never of the build, but keeps keys honest anyway.
