# Plan 002: Make the MCP detect-changes tool return a clean error for an option-shaped base ref

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

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `50a0efa` + uncommitted working-tree changes, 2026-07-16

## Why this matters

`DetectChangesTool::handle()` resolves the caller-supplied `base` argument through `RichterConfig::baseRef()` *before* its `try` block, and the `try` only catches `RuntimeException`. `baseRef()` throws `InvalidArgumentException` for an option-shaped ref (anything starting with `-`, e.g. `--upload-pack=…`) — deliberately, as an injection guard. So an MCP client passing such a ref gets an uncaught PHP exception across the MCP boundary instead of a clean `Response::error`. The Artisan command already handles this exact case (with a regression test); the MCP tool — the other half of the public surface — does not. The injection itself is blocked; this is an error-surface bug.

## Current state

- `src/Mcp/Tools/DetectChangesTool.php` — the file with the bug. `handle()` as it exists today (lines 38-55):

```php
public function handle(Request $request): Response
{
    $base = RichterConfig::baseRef($request->get('base'));

    try {
        $changed = ChangedSymbols::resolve($base);
    } catch (RuntimeException $runtimeException) {
        return Response::error($runtimeException->getMessage());
    }

    if ($changed === []) {
        return Response::text("No changed PHP files under app/ against {$base}.");
    }

    $result = new ImpactAnalyzer($this->graphs->graph())->detectChanges($changed);

    return Response::text(ImpactFormatter::detectChanges($result, TestReferenceIndex::fromTests(base_path('tests'))));
}
```

- `src/Support/RichterConfig.php:112-119` — `refOrFail()` throws `InvalidArgumentException` when the ref starts with `-`. Do not modify.
- `src/Console/DetectChangesCommand.php:76-95` — the command's contrasting behavior: it catches `RuntimeException` (warn, advisory exit) and `InvalidArgumentException` (error under a gate, rethrow when advisory — a CLI-specific contract). The MCP tool has no advisory/gate distinction, so a plain `Response::error` for both exception types is the correct MCP surface. Do not modify the command.
- `tests/Feature/McpTest.php` — the MCP test file. Currently 4 tests: server registration, tool names, `ImpactTool` empty-symbol rejection, `DetectChangesTool` broken-ref error. Test style: `#[Test]` attribute, snake_case names, `resolve(DetectChangesTool::class)->handle(new Request([...]))`, assertions on the `Response`.
- `tests/Feature/CommandsTest.php:87` — `detect_changes_rejects_an_option_injection_shaped_base_ref` is the command-side regression test for the same input class; a naming reference, not a file to modify.
- Repo conventions: `<?php declare(strict_types=1);`, `final` classes, PHPUnit 12 `#[Test]`, comments explain constraints not mechanics.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `vendor/bin/phpunit --filter McpTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Mcp/Tools/DetectChangesTool.php`
- `tests/Feature/McpTest.php`

**Out of scope** (do NOT touch, even though they look related):

- `src/Console/DetectChangesCommand.php` — its advisory-rethrow contract for `InvalidArgumentException` is deliberate and tested; do not "align" it with the tool.
- `src/Support/RichterConfig.php` — the throwing guard is correct.
- `src/Mcp/Tools/ImpactTool.php` — takes no ref argument; nothing to fix.

## Git workflow

- Branch: `advisor/002-mcp-detect-changes-catch` off `main`.
- Commit style: imperative sentence-case, e.g. `Harden config validation and pin the MCP tool names` (from `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add a failing regression test

In `tests/Feature/McpTest.php`, add:

```php
#[Test]
public function the_detect_changes_tool_reports_an_option_shaped_ref_as_an_error(): void
{
    $response = resolve(DetectChangesTool::class)->handle(new Request(['base' => '--upload-pack=x']));

    $this->assertTrue($response->isError());
}
```

**Verify**: `vendor/bin/phpunit --filter McpTest` → exactly this test fails, with an uncaught `InvalidArgumentException` (message contains `may not start with "-"`). Any other failure shape is a STOP condition.

### Step 2: Move the ref resolution inside the try and widen the catch

In `src/Mcp/Tools/DetectChangesTool.php`:

1. Add `use InvalidArgumentException;` to the imports (alphabetical position among the non-namespaced imports, matching the file's existing `use RuntimeException;` placement).
2. Move the `$base = RichterConfig::baseRef($request->get('base'));` line to the first statement *inside* the `try`.
3. Widen the catch to `catch (InvalidArgumentException|RuntimeException $exception)` and return `Response::error($exception->getMessage());`.

The result mirrors the shape the command's JSON path uses (`DetectChangesCommand.php:137`: `catch (InvalidArgumentException|RuntimeException $expected)`).

**Verify**: `vendor/bin/phpunit --filter McpTest` → all tests pass, including the new one.

### Step 3: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 4: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "002" plans/README.md` → row shows DONE.

## Test plan

- One new test in `tests/Feature/McpTest.php`: option-shaped base ref → `Response::error`, modeled after the existing `the_detect_changes_tool_reports_a_broken_ref_as_an_error`.
- Verification: `vendor/bin/phpunit --filter McpTest` → 5 tests pass; `composer test` green.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter McpTest` exits 0 with 5 tests
- [ ] `composer test` exits 0, 0 failures
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in the two in-scope files plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- `handle()` no longer matches the "Current state" excerpt (the working tree has drifted).
- The step-1 test fails in a different way than an uncaught `InvalidArgumentException`.
- The fix appears to require changes to `RichterConfig` or the command.
- `Response::error()` does not exist on the installed `laravel/mcp` version (check `vendor/laravel/mcp`) — the API may have shifted; report instead of substituting a different response shape.

## Maintenance notes

- If a future `laravel/mcp` version changes the `Tool`/`Response` API, both tools break together — that dependency risk is audit finding 7 (unconstrained 0.x soft dependency), deliberately not addressed here.
- Reviewer scrutiny: the catch must not swallow `Throwable` — only the two expected operational exception types; an unexpected graph-build error should still propagate loudly rather than read as a polite error.
- Deferred: MCP success-path coverage (both tools' happy-path `Response::text` content) is audit finding 5 territory (fixture-backed feature tests) and is not folded in here.
