# Plan 005: Route all name resolution through the AppFiles helpers instead of five private copies

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

The canonical "resolved FQCN of a php-parser Name node" logic lives in `AppFiles::resolveName()`, yet the same three lines are re-implemented privately in three tracers (five sites), and the exact `NameResolver` traverser construction is duplicated in two more places. These copies must stay byte-identical: a future change to name-resolution behavior (e.g. the `replaceNodes` option) that misses one copy produces subtly wrong edges in exactly one tracer — the kind of drift nobody notices until an impact report is quietly wrong. This is a pure consolidation refactor; behavior must not change.

## Current state

The canonical helpers — `src/Support/AppFiles.php:65-86`:

```php
public static function parseResolved(string $source): ?array
{
    $ast = self::parse($source);

    if ($ast === null) {
        return null;
    }

    // NameResolver attaches a `resolvedName` FQCN to every Name node (imports/aliases applied);
    // replaceNodes=false keeps originals so names read by written form.
    new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]))->traverse($ast);

    return $ast;
}

/** The NameResolver-attached FQCN of a name node (imports/aliases applied), root-slash trimmed. */
public static function resolveName(Name $name): string
{
    $resolved = $name->getAttribute('resolvedName');

    return ltrim($resolved instanceof Name ? $resolved->toString() : $name->toString(), '\\');
}
```

The duplicate sites to eliminate:

1. `src/Tracers/DispatchEdgeTracer.php:234-239` — private `resolveName(Name $name)`: identical body to `AppFiles::resolveName`. Called at `:93` and `:157` and `:229`.
2. `src/Tracers/PolicyEdgeTracer.php:104-106` — inline in `policiesReferencedIn()`: `$resolved = $name->getAttribute('resolvedName'); $fqcn = ltrim($resolved instanceof Name ? $resolved->toString() : $name->toString(), '\\');`.
3. `src/Tracers/EntryPointTracer.php:220-229` — `resolveListenerName(mixed $node)`: the `ClassConstFetch` branch re-implements the same extraction on `$node->class`.
4. `src/Tracers/EntryPointTracer.php:287-292` — private `resolveTypeName(Name $name)`: identical body.
5. `src/Tracers/EntryPointTracer.php:156-174` — `eventListenerEdges()` parses via Brain's `PhpFileParser` and then runs its own inline `NameResolver` traverser (`:172-174`) with the same options as `parseResolved`.
6. `src/Tracers/EagerLoadStringChecker.php:72-78` — `findingsFor()` calls `AppFiles::parse()` then runs its own inline `NameResolver` traverser (`:78`) with the same options — i.e. it re-implements `parseResolved` in two calls.

Note: `AppFiles` is already imported by all three tracer files.

Repo conventions: `<?php declare(strict_types=1);`, `final` classes, comments state constraints. All three tracers have dedicated unit tests (`tests/Unit/DispatchEdgeTracerTest.php`, `tests/Unit/PolicyEdgeTracerTest.php`, `tests/Unit/EagerLoadStringCheckerTest.php`; `EntryPointTracer::listenerTarget` is covered via `tests/Unit/ImpactAnalyzerTest.php:515-516` and the `$listen` path via `tests/Feature/CodeGraphBuilderTest.php` — `a_listen_registered_listener_links_to_its_event`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Tracer tests | `vendor/bin/phpunit --filter 'DispatchEdgeTracerTest|PolicyEdgeTracerTest|EagerLoadStringCheckerTest|CodeGraphBuilderTest'` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (317+ tests) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |
| No stray copies | `grep -rn "getAttribute('resolvedName')" src/` | only `src/Support/AppFiles.php` |
| No stray traversers | `grep -rn "new NameResolver" src/` | only `src/Support/AppFiles.php` |

## Scope

**In scope** (the only files you should modify):

- `src/Tracers/DispatchEdgeTracer.php`
- `src/Tracers/PolicyEdgeTracer.php`
- `src/Tracers/EntryPointTracer.php`
- `src/Tracers/EagerLoadStringChecker.php`

**Out of scope** (do NOT touch, even though they look related):

- `src/Support/AppFiles.php` — the canonical helpers are correct as-is.
- `EntryPointTracer::methodsOf()` and its `PhpFileParser` usage — that is the parse-reuse work of plan 009; here only the *name-resolution* duplication goes.
- Any behavioral change to which edges are emitted.

## Git workflow

- Branch: `advisor/005-dedupe-name-resolution` off `main`.
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: DispatchEdgeTracer

Delete the private `resolveName()` (lines 234-239) and replace its three call sites with `AppFiles::resolveName(...)`.

**Verify**: `vendor/bin/phpunit --filter DispatchEdgeTracerTest` → all pass.

### Step 2: PolicyEdgeTracer

In `policiesReferencedIn()`, replace the two inline lines (105-106) with `$fqcn = AppFiles::resolveName($name);`.

**Verify**: `vendor/bin/phpunit --filter PolicyEdgeTracerTest` → all pass.

### Step 3: EntryPointTracer

1. Delete `resolveTypeName()` (287-292); replace its call site in `interfaceEdgesForResolvedAst()` with `AppFiles::resolveName($implemented)`.
2. In `resolveListenerName()`, replace the `ClassConstFetch` branch body with `return AppFiles::resolveName($node->class);`.
3. In `eventListenerEdges()`, replace the `PhpFileParser` + inline-traverser pair (lines 164-174) with a single `AppFiles::parseResolved((string) file_get_contents($file))` call (keep the existing `is_file` guard and null-AST early return). The `$parser` parameter of `eventListenerEdges()` then becomes unused — remove it and update the call site in `trace()` (line 83).

**Verify**: `vendor/bin/phpunit --filter 'CodeGraphBuilderTest|ImpactAnalyzerTest'` → all pass (the `$listen` event→listener edge test at Feature/CodeGraphBuilderTest is the behavioral guard for point 3).

### Step 4: EagerLoadStringChecker

In `findingsFor()`, replace the `AppFiles::parse($source)` call + inline traverser (lines 72-78) with `$ast = AppFiles::parseResolved($source);` (keep the null early-return). Remove the now-unused `NodeTraverser`/`NameResolver` imports.

**Verify**: `vendor/bin/phpunit --filter EagerLoadStringCheckerTest` → all pass.

### Step 5: Full verification

**Verify**:
- `grep -rn "getAttribute('resolvedName')" src/` → matches only in `src/Support/AppFiles.php`.
- `grep -rn "new NameResolver" src/` → matches only in `src/Support/AppFiles.php`.
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0 (unused-import rules will catch leftover `use` lines).
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

### Step 6: Update the index

Set this plan's row in `plans/README.md` to `DONE`.

**Verify**: `grep -n "005" plans/README.md` → row shows DONE.

## Test plan

Pure refactor — no new tests. The existing tracer unit tests and the builder feature tests are the behavioral specification; every step gates on them. If any assertion needs *changing* to pass, that is a STOP condition, not a test update.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -rn "getAttribute('resolvedName')" src/` matches only `src/Support/AppFiles.php`
- [ ] `grep -rn "new NameResolver" src/` matches only `src/Support/AppFiles.php`
- [ ] `composer test` exits 0, 0 failures, with zero existing assertions modified
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in the four in-scope files plus `plans/README.md`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Any duplicate site's body differs from the canonical helper in more than formatting (a semantic divergence means the copies have already drifted — that is a bug finding, not a refactor step; report which site and how).
- Any existing test assertion would need modification to pass.
- Step 3's `parseResolved` swap changes the `$listen` edge output on the fixture project (Brain's `PhpFileParser` may normalise differently than `AppFiles::parse` — if the Feature test fails, report; do not add compensation logic).

## Maintenance notes

- Plan 008 (single-visitor traversal) and plan 009 (entry-point parse reuse) build on these consolidated helpers; land this first.
- Reviewer scrutiny: `resolveListenerName()` must keep its `String_` fallback branch — only the `ClassConstFetch` branch routes through `AppFiles::resolveName`.
