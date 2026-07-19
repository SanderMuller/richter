# Plan 023: Pin benchmark:add's config-stanza escaping with tests

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- src/Console/BenchmarkAddCommand.php tests/Feature/CommandsTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW (test-only)
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

`richter:benchmark:add` prints a paste-ready PHP stanza destined for a
consumer's `config/richter.php`. The one code path that makes that PHP
syntactically safe — escaping `'` and `\` in the commit subject / `--key`
value — is unverified: every existing test uses quote-free, ticket-shaped
subjects. An apostrophe in a commit subject is completely ordinary
(`Fix user's dashboard`), and a regression here emits broken PHP straight into
a consumer's config file. The escaping code is currently correct (verified by
reading at planning time); this plan pins it so it stays that way.

## Current state

- `src/Console/BenchmarkAddCommand.php:130-152`:

  ```php
  private function printStanza(string $key, string $commit, string $bugClass, bool $expectSignal, RiskLevel $maxRisk): void
  {
      $escapedKey = $this->escapeForSingleQuotedString($key);
      $escapedCommit = $this->escapeForSingleQuotedString($commit);
      $escapedBugClass = $this->escapeForSingleQuotedString($bugClass);
      …
      $this->line("        'bug_class' => '{$escapedBugClass}',");
      …
  }

  private function escapeForSingleQuotedString(string $value): string
  {
      return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
  }
  ```

- The bug_class value comes from `commitSubject()`
  (`BenchmarkAddCommand.php:101-107`, `git log -1 --format=%s`, Process-faked
  in tests as `'*log*'`), the key from `--key` or the subject
  (`deriveKey()`, `:109-128`).
- Existing test pattern — `tests/Feature/CommandsTest.php:431-443`:

  ```php
  #[Test]
  public function benchmark_add_scaffolds_a_signal_case_from_a_replayed_fix(): void
  {
      $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("PROJ-42 Fix duplicated video questions\n")]);

      $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234'])
          ->expectsOutputToContain("'key' => 'PROJ-42'")
          …
          ->assertSuccessful();
  }
  ```

  The `fakeBenchmarkReplayReachingRoutes()` helper (defined lower in the same
  file — read it before writing) fakes the git plumbing so the replay reaches
  a route.
- Test conventions: `#[Test]`, snake_case names, one behavior per test.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter benchmark_add` | OK |
| Full suite | `composer test` | `"result":"passed"` |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `test-writing`.

## Scope

**In scope** (the only file you should modify):
- `tests/Feature/CommandsTest.php`

**Out of scope** (do NOT touch):
- `src/Console/BenchmarkAddCommand.php` — the escaping is correct today; if a
  test fails against current behavior, that is a STOP, not a code change.

## Git workflow

- Branch: `advisor/023-benchmark-add-escaping-test`
- Commit style: imperative subject, e.g. `Pin benchmark:add stanza escaping for quotes and backslashes`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the escaping tests

In `tests/Feature/CommandsTest.php`, next to the existing `benchmark_add_*`
tests, add:

1. `benchmark_add_escapes_quotes_and_backslashes_in_the_stanza` — fake the
   subject as `Fix user's dash\board rendering` (PHP source:
   `"Fix user's dash\\board rendering\n"`), run with an explicit `--key`
   containing an apostrophe (e.g. `--key => "O'Brien-7"`), and assert:
   - output contains `'key' => 'O\'Brien-7'`
   - output contains `'bug_class' => 'Fix user\'s dash\\board rendering'`
   (Build the expectation strings carefully: in the PHP test source the
   expected substring for the key line is `"'key' => 'O\\'Brien-7'"`.)
2. `benchmark_add_stanza_is_valid_php_for_awkward_subjects` — same fake;
   capture the full output via `$this->withoutMockingConsoleOutput()` +
   `Artisan::call`/`Artisan::output()` (pattern at `CommandsTest.php:110-121`),
   extract the stanza between the first `[` line and the closing `],`, and
   assert it round-trips: `eval('return [' . $stanza . '];')` yields an array
   whose `bug_class` equals the raw subject `Fix user's dash\board rendering`.
   If the repo's PHPStan/Pint setup rejects `eval` in tests
   (`spaze/phpstan-disallowed-calls` is installed — check
   `phpstan.neon.dist` for an `eval` rule first), fall back to asserting the
   exact expected escaped line instead and note it; do not fight the ruleset.

**Verify**: `vendor/bin/phpunit --filter benchmark_add` → all pass, including
the 2 new tests.

### Step 2: Full regression

**Verify**: `composer test` → `"result":"passed"`; `vendor/bin/pint --test` →
exit 0; `composer phpstan` → exit 0.

## Test plan

As per step 1 — two tests: escaped-output assertion, and (ruleset permitting)
a round-trip validity assertion. Model after
`benchmark_add_scaffolds_a_signal_case_from_a_replayed_fix`.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] ≥1 new test feeds a subject/key containing `'` and `\` through benchmark:add and passes
- [ ] `composer test` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] Only `tests/Feature/CommandsTest.php` modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- A new test FAILS against the current `src/` behavior — that would mean the
  escaping has a real bug; report it (the fix then needs its own reviewed
  change, per the repo's test-first bug rule).
- `fakeBenchmarkReplayReachingRoutes` doesn't exist or has a different name
  (drift) — locate the actual helper before improvising fakes.

## Maintenance notes

- If the stanza printer ever moves to `var_export()` (a reasonable future
  simplification), these tests transfer as-is — they assert the contract
  (valid single-quoted PHP), not the implementation.
