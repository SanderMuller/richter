# Plan 016: Add richter:benchmark:add — scaffold a benchmark fixture from a fix commit

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. The reviewer who dispatched you maintains the
> plan index (`plans/README.md`) — do not edit it.
>
> **Drift check (run first)**: This plan was written against commit
> `d4a856d`. Run `git diff d4a856d --stat -- src/Console src/Analysis/BenchmarkCase.php
> src/RichterServiceProvider.php tests/Feature/CommandsTest.php` — if
> anything changed in those paths since, compare every "Current state"
> excerpt below against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW (a new, read-only command; no existing behavior changes)
- **Depends on**: none
- **Category**: DX (audit direction item D4)
- **Planned at**: main @ `d4a856d`, 2026-07-18 — baseline suite 343 tests

## Why this matters

Adding a `richter.benchmark_cases` fixture today is manual archaeology:
find the fix commit, invent a key, describe the bug class, guess whether
the replay resolves at all, and pick a `max_risk` for a control — then run
the whole benchmark to discover the fixture never exercised anything. The
scaffolder collapses that to one command: validate the commit, **dry-run
the replay through the exact machinery `richter:benchmark` uses**, show
what the case would score today, and print a ready-to-paste config stanza.
It is read-only by design — it never edits the user's config file
(programmatically rewriting a consumer's PHP config would mangle their
formatting and comments; printing a stanza matches Richter's advisory
ethos).

## Current state

- `src/Console/BenchmarkCommand.php` — the exemplar this command mirrors
  structurally. Its `runCase()` (`:74-111`) shows the exact replay recipe:

```php
if (! Process::path(base_path())->run(['git', 'cat-file', '-e', '--end-of-options', "{$case->fixCommit}^{commit}"])->successful()) {
    // … skip: commit not available
}

$changed = ChangedSymbols::resolve("{$case->fixCommit}^", $case->fixCommit); // throws RuntimeException on a broken diff

$result = $analyzer->detectChanges($changed);
$failures = $case->evaluate($result);

$unresolved = count(array_filter($result['coverage'], static fn (string $coverage): bool => $coverage === 'unresolved'));
$this->line('  entry points: ' . count($result['entryPoints'])
    . ", impacted: {$result['impacted']}, risk: {$result['risk']->value}, unresolved files: {$unresolved}");
```

  Its `handle(GraphCache $graphs)` builds the analyzer as
  `new ImpactAnalyzer($graphs->graph(fresh: (bool) $this->option('no-cache')))`.
- `src/Analysis/BenchmarkCase.php` — the value object. Constructor:
  `new BenchmarkCase(key:, fixCommit:, bugClass:, expectSignal:, maxRisk: RiskLevel)`.
  `evaluate(array $result): list<string>` returns failure reasons (empty =
  pass). Config field names consumed by `fromArray`: `key`, `fix_commit`,
  `bug_class`, `expect_signal`, `max_risk` (string `'low'|'medium'|'high'`).
- `src/Analysis/RiskLevel.php` — `Low = 'low'`, `Medium = 'medium'`,
  `High = 'high'`.
- `config/richter.php:40-48` — the commented stanza documenting the target
  paste format:

```php
// [
//     'key' => 'TICKET-123',
//     'fix_commit' => 'abc1234',
//     'bug_class' => 'background-job change (data not copied on duplication)',
//     'expect_signal' => true,
//     'max_risk' => 'high',
// ],
```

- `src/Support/RichterConfig.php:107-119` — the option-injection guard
  rationale: a ref may not start with `-` (`refOrFail`, private). The new
  command's commit argument needs the same guard inline (plus
  `--end-of-options` on every git argv, as `BenchmarkCommand` does).
- `src/RichterServiceProvider.php:20-23` — command registration:

```php
$package
    ->name('richter')
    ->hasConfigFile()
    ->hasCommands(ImpactCommand::class, DetectChangesCommand::class, BenchmarkCommand::class);
```

- `src/Changes/ChangedSymbols.php:21` —
  `public static function resolve(string $base, string $head = 'HEAD'): array`
  returns `list<ChangedFileSymbols>`; empty list = no changed PHP files
  under app/; throws `RuntimeException` on a broken diff.
- `tests/Feature/CommandsTest.php` — the test recipes to follow:
  - `runArtisan(string $command, array $parameters = [])` helper (`:470-476`).
  - `benchmarkCase()` array helper (`:479-488`).
  - `fakeBenchmarkReplayReachingRoutes()` (`:490-523`) points
    `base_path()` at the fixture project and fakes the git calls in ONE
    `Process::fake([...])` map (`*cat-file*`, `*merge-base*`, `*diff*`,
    `*show*`) so the replay resolves a member inside
    `QuestionController::show()` and reaches two routes → risk `medium`
    (see the control-case tests at `:271-299`).
- Repo conventions: `final` classes, class-level docblocks explaining
  constraints, `match` over if-chains where it reads better, PHPUnit 12
  `#[Test]`, snake_case test names, imperative sentence-case commits.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Command tests | `vendor/bin/phpunit --filter CommandsTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (343 baseline + new) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify/create):

- `src/Console/BenchmarkAddCommand.php` (new)
- `src/RichterServiceProvider.php` (one line: register the command)
- `tests/Feature/CommandsTest.php` (new tests + the fake-helper extension in step 3)
- `README.md` — **only** the `### Scoring accuracy against replayable history`
  section (add the command to the existing code block + one sentence)

**Out of scope** (do NOT touch, even though they look related):

- `src/Console/BenchmarkCommand.php` — read it as the exemplar, change nothing.
- `src/Analysis/BenchmarkCase.php`, `src/Support/RichterConfig.php`,
  `config/richter.php` — the scaffolder consumes their contracts; it must
  not alter them. **Never write to any config file at runtime either.**
- `plans/README.md` — the reviewer maintains the index.

## Git workflow

- Branch: `advisor/016-benchmark-add-scaffolder` off `main` (`d4a856d`).
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: The command

Create `src/Console/BenchmarkAddCommand.php`, a `final` class mirroring
`BenchmarkCommand`'s structure (class docblock explaining what it is for
and the read-only design decision; typed `$signature`/`$description`
properties; `handle(GraphCache $graphs): int`).

Signature:

```php
protected $signature = 'richter:benchmark:add
    {fix-commit : Historical fix commit to replay}
    {--control : Scaffold a harmless-change control (expect_signal false, max_risk capped at the replayed risk)}
    {--key= : Case key to use instead of the derived one}
    {--no-cache : Build the code graph fresh, bypassing the graph cache}';

protected $description = 'Dry-run a fix commit through the change-impact replay and print a ready-to-paste richter.benchmark_cases entry';
```

`handle` flow, in order:

1. **Guard the argument.** `$commit = (string) $this->argument('fix-commit');`
   If it is `''` or starts with `-`, `$this->error(...)` (mirror the
   `RichterConfig::refOrFail` message style: a ref may not start with `-`)
   and return `self::FAILURE`.
2. **Commit exists.** Same `git cat-file -e --end-of-options
   "{$commit}^{commit}"` check as `BenchmarkCommand::runCase` (`:79`), via
   `Process::path(base_path())`. On failure: error
   `Commit {$commit} is not available in this checkout.` → `self::FAILURE`.
3. **Subject for bug_class.** `git log -1 --format=%s --end-of-options
   {$commit}` via the same `Process::path(base_path())`; `trim()` the
   output. If the call fails or trims to `''`, use the placeholder
   `TODO: describe the bug class` (do not fail — the stanza stays useful).
4. **Replay.** `ChangedSymbols::resolve("{$commit}^", $commit)` in a
   try/catch for `RuntimeException` → error the message, `self::FAILURE`.
   If the resolved list is empty: warn
   `Commit {$commit} changes no PHP files under app/ — a fixture built from it would never exercise the report.`
   → `self::FAILURE` (a no-op fixture is worse than none: its green would
   be fake).
5. **Analyze + report the dry-run.**
   `$result = new ImpactAnalyzer($graphs->graph(fresh: (bool) $this->option('no-cache')))->detectChanges($changed);`
   then print the same summary line format as `BenchmarkCommand::runCase`
   (`:96-98`: entry points, impacted, risk, unresolved files).
6. **Derive the fields.**
   - `key`: the `--key` option when given; else the first match of
     `/\b[A-Z][A-Z0-9]*-\d+\b/` in the subject (JIRA-style ticket); else
     the short SHA via `git rev-parse --short --end-of-options {$commit}`
     (trimmed; if that call fails, `substr($commit, 0, 7)`).
   - `expect_signal`: `! $this->option('control')`.
   - `max_risk`: for a control, `$result['risk']->value` (the tightest cap
     the replay currently satisfies — the whole point of a control); for a
     signal case, `'high'` (the default cap, stated explicitly so the
     stanza is self-documenting).
7. **Verdict.** Build the `BenchmarkCase` (constructor, not `fromArray`)
   and run `$failures = $case->evaluate($result)`. Empty →
   `$this->info('Would currently PASS richter:benchmark.')`. Non-empty →
   print each as `$this->error("Would currently FAIL — {$failure}")`.
8. **Stanza.** Always print it (a currently-failing signal fixture is
   still worth adopting once the analyzer improves — the verdict and exit
   code carry the warning). Match `config/richter.php`'s commented example
   exactly — 4-space-indented entries, single quotes, trailing comma —
   preceded by a line like
   `Add this entry to the benchmark_cases list in config/richter.php:`.
   Escape the bug_class for a single-quoted PHP string:
   `str_replace(['\\', "'"], ['\\\\', "\\'"], $subject)`.
9. **Exit code.** `self::SUCCESS` when the verdict was PASS,
   `self::FAILURE` when it was FAIL — honest exit codes; the stanza prints
   either way.

### Step 2: Register the command

In `src/RichterServiceProvider.php`, add `BenchmarkAddCommand::class` to
the existing `hasCommands(...)` call (keep the import list alphabetical if
it is — check the file).

**Verify**: `composer phpstan` → exit 0.

### Step 3: Tests

In `tests/Feature/CommandsTest.php`:

First, extend the replay helper so one `Process::fake` call carries extra
patterns (repeated `Process::fake` calls are an ordering trap — keep it to
ONE call): change `fakeBenchmarkReplayReachingRoutes()` to
`fakeBenchmarkReplayReachingRoutes(array $extraFakes = [])` and build the
fake map as `array_merge($extraFakes, [...existing four patterns...])`.
The existing four call sites keep working unchanged (default `[]`).

Then add tests (names and assertions are the spec — follow the existing
`expectsOutputToContain` style):

1. `benchmark_add_rejects_an_option_shaped_commit` — run
   `richter:benchmark:add` with `['fix-commit' => '--upload-pack=x']`
   after a bare `Process::fake();` → expects the may-not-start-with-`-`
   message, `assertFailed()`.
2. `benchmark_add_reports_an_unavailable_commit` —
   `Process::fake(['*cat-file*' => Process::result(exitCode: 1)])` →
   expects `is not available`, `assertFailed()`.
3. `benchmark_add_scaffolds_a_signal_case_from_a_replayed_fix` —
   `$this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("PROJ-42 Fix duplicated video questions\n")])`
   then run with `['fix-commit' => 'abc1234']` → expects all of:
   `'key' => 'PROJ-42'`, `'fix_commit' => 'abc1234'`,
   `'bug_class' => 'PROJ-42 Fix duplicated video questions'`,
   `'expect_signal' => true`, `'max_risk' => 'high'`,
   `Would currently PASS`, `assertSuccessful()`.
4. `benchmark_add_scaffolds_a_control_case_capped_at_the_replayed_risk` —
   same replay + `'--control' => true` → expects
   `'expect_signal' => false` and `'max_risk' => 'medium'` (the replay
   reaches two routes → risk medium, see the existing control tests),
   `assertSuccessful()`.
5. `benchmark_add_falls_back_to_the_short_sha_key_when_the_subject_has_no_ticket`
   — replay with
   `['*log*' => Process::result("Fix duplicated video questions\n"), '*rev-parse*' => Process::result("abc1234\n")]`
   → expects `'key' => 'abc1234'`.
6. `benchmark_add_fails_when_the_commit_changes_no_app_php` —
   `Process::fake(['*cat-file*' => Process::result(), '*log*' => Process::result("subject\n"), '*merge-base*' => Process::result("base123\n"), '*diff*' => Process::result('')])`
   → expects `would never exercise`, `assertFailed()`.

Pattern-collision note: the faked git argvs here are `cat-file`, `log`,
`rev-parse`, `merge-base`, `diff`, `show` — the globs above match
distinct commands; if a fake unexpectedly answers the wrong call, print
`Process::fake` recorded invocations rather than loosening a pattern.

**Verify**: `vendor/bin/phpunit --filter CommandsTest` → all pass,
including the 6 new tests; the 4 pre-existing replay-helper call sites
unmodified.

### Step 4: README

In the `### Scoring accuracy against replayable history` section
(`README.md`, around line 188): add
`php artisan richter:benchmark:add <fix-commit>` (and the `--control`
variant) to the existing command code block, plus one sentence: the
command dry-runs the commit through the replay, reports what it would
score, and prints a paste-ready `benchmark_cases` entry — it never edits
the config file. No new sections.

**Verify**: `git diff README.md` shows only that section changed.

### Step 5: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

## Test plan

Feature tests only (the command is thin orchestration over already
unit-tested parts): the 6 tests in step 3 cover the argument guard, the
missing-commit path, signal + control scaffolding end-to-end through the
real fixture-project replay, key fallback, and the empty-diff refusal.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter CommandsTest` exits 0 and includes the 6 new `benchmark_add_*` tests
- [ ] `composer test` exits 0, 0 failures, zero existing assertions modified (the replay-helper signature change with a default parameter does not count as an assertion change)
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in the four in-scope files
- [ ] `php -r "echo 'ok';"`-level sanity: `grep -n "benchmark:add" src/Console/BenchmarkAddCommand.php` shows the signature; `grep -n "BenchmarkAddCommand" src/RichterServiceProvider.php` shows the registration
- [ ] `grep -rn "file_put_contents\|Config::write\|config_path" src/Console/BenchmarkAddCommand.php` → no matches (read-only guarantee)

## STOP conditions

Stop and report back (do not improvise) if:

- Any "Current state" excerpt no longer matches the live code.
- The fixture replay in test 3/4 does not produce risk `medium` — the
  fixture graph changed; report instead of adjusting the expected risk.
- `ChangedSymbols::resolve("{$commit}^", $commit)` needs different
  arguments than `BenchmarkCommand` uses — the two commands must replay
  identically, so a divergence means this plan's premise drifted.
- You find yourself wanting to write to `config/richter.php` or any file
  in the consuming project — that is explicitly out of scope by design.
- The `Process::fake` single-call helper extension breaks any of the 4
  existing benchmark tests.

## Maintenance notes

- The stanza format is coupled to `BenchmarkCase::fromArray`'s field names
  — if a field is ever added there, this command's stanza and derivation
  logic must follow in the same change.
- The dry-run verdict reuses `BenchmarkCase::evaluate`, so scoring-rule
  changes propagate automatically; only the field-derivation heuristics
  (ticket regex, control cap) live here.
- The ticket-key regex is deliberately simple (uppercase JIRA style); a
  consumer whose tracker uses another format passes `--key`. Do not grow a
  config option for this without a real request.
