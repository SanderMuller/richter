# Plan 015: Emit structured MCP output — the tools return prose plus the documented --json contract

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. The reviewer who dispatched you maintains the
> plan index (`plans/README.md`) — do not edit it.
>
> **Drift check (run first)**: This plan was written against commit
> `d4a856d`. Run `git diff d4a856d --stat -- src/Mcp tests/Feature/McpTest.php
> src/Analysis/JsonPresenter.php` — if anything changed in those paths since,
> compare every "Current state" excerpt below against the live code before
> proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW (additive; error paths and tool names unchanged; the structured shape is the already-shipped `--json` contract)
- **Depends on**: none
- **Category**: feature (audit direction item D3)
- **Planned at**: main @ `d4a856d`, 2026-07-18 — baseline suite 343 tests

## Why this matters

The two MCP tools return prose only, so an agent that wants a field (`risk`,
`entryPoints`, a caller list) has to parse formatter text that is explicitly
capped and human-oriented. Meanwhile the package already ships a complete,
semver-governed machine contract: the `--json` shapes in
`JsonPresenter`. laravel/mcp 0.8 supports MCP structured tool output
natively (`structuredContent` + `outputSchema`). Emitting the existing
JsonPresenter arrays as `structuredContent` — while keeping the prose text
block for human/LLM readers — gives both audiences their format from ONE
contract. No second contract to version: the MCP structured shape and the
CLI `--json` shape are the same arrays by construction.

## Current state

- `src/Mcp/Tools/ImpactTool.php:35-46` — prose-only success path:

```php
public function handle(Request $request): Response
{
    $symbol = $request->get('symbol');

    if (! is_string($symbol) || $symbol === '') {
        return Response::error('The symbol argument must be a non-empty string.');
    }

    $result = new ImpactAnalyzer($this->graphs->graph())->impact($symbol);

    return Response::text(ImpactFormatter::impact($result));
}
```

- `src/Mcp/Tools/DetectChangesTool.php:39-55` — prose-only success paths
  (an error path via `Response::error`, an empty-diff `Response::text`, and
  the analyzed `Response::text`):

```php
public function handle(Request $request): Response
{
    try {
        $base = RichterConfig::baseRef($request->get('base'));
        $changed = ChangedSymbols::resolve($base);
    } catch (InvalidArgumentException|RuntimeException $exception) {
        return Response::error($exception->getMessage());
    }

    if ($changed === []) {
        return Response::text("No changed PHP files under app/ against {$base}.");
    }

    $result = new ImpactAnalyzer($this->graphs->graph())->detectChanges($changed);

    return Response::text(ImpactFormatter::detectChanges($result, TestReferenceIndex::fromTests(base_path('tests'))));
}
```

- `src/Analysis/JsonPresenter.php` — the machine contract to reuse
  **verbatim, do not modify this file**:
  - `JsonPresenter::impact(array $result): array` returns
    `{target: string, callers: list<{depth, node, via}>, dependencies: list<{depth, node, via}>}`.
  - `JsonPresenter::detectChanges(array $result, string $base): array` returns
    `{base, changed, coverage, entryPoints, entryPointPaths, impacted, relatedModels, risk, lowConfidence, coarseCapApplied, findings, unresolved}`.
  - `JsonPresenter::emptyDetectChanges(string $base): array` — same shape,
    zero-valued, for the empty diff.
- laravel/mcp API facts (verified against the vendored 0.8 sources —
  re-verify the line numbers hold in your checkout):
  - `vendor/laravel/mcp/src/Response.php:90-105` —
    `Response::structured(array): ResponseFactory` emits the JSON as the
    text block. **Not what we want** — we want prose as the text block, so
    use the composition it demonstrates on line 104 instead:
    `(new ResponseFactory(Response::text($prose)))->withStructuredContent($array)`.
  - `vendor/laravel/mcp/src/ResponseFactory.php:58` —
    `withStructuredContent(array): static`.
  - `vendor/laravel/mcp/src/Server/Methods/Concerns/InteractsWithResponses.php:30`
    — the server accepts `Response|ResponseFactory|array|string` from a
    tool's `handle`, so widening the return type is supported.
  - `vendor/laravel/mcp/src/Server/Tool.php:32` —
    `public function outputSchema(JsonSchema $schema): array` is an
    overridable hook; per `Tool.php:66-81` the schema is only advertised
    when it declares at least one property.
  - `vendor/laravel/mcp/src/Server/Testing/TestResponse.php:112` —
    `assertStructuredContent(Closure|array)`. Entry point:
    `RichterServer::tool(ToolClass::class, [...args])` (static; routed via
    `Server::__callStatic` → `PendingTestResponse::tool`, see
    `vendor/laravel/mcp/src/Server/Testing/PendingTestResponse.php:39`),
    returning a `TestResponse` with `assertOk` / `assertSee` /
    `assertHasErrors` / `assertStructuredContent`.
- `tests/Feature/McpTest.php` — six tests. Two assert on success-path
  content via direct `handle()` calls and **will break** when `handle`
  returns a `ResponseFactory` (they cast `$response->content()` to string):
  `the_impact_tool_reports_the_blast_radius_of_a_symbol` (`:52-61`) and
  `the_detect_changes_tool_reports_an_empty_diff_cleanly` (`:63-75`).
  The three error-path tests and the registration tests keep working
  untouched (error paths still return `Response`).
- The JsonSchema builder for `outputSchema` is
  `vendor/laravel/framework/src/Illuminate/JsonSchema/JsonSchemaTypeFactory.php`:
  `object(Closure|array $properties = [])`, `array()->items(Type)`,
  `string()`, `integer()`, `boolean()`, `anyOf(Closure|array)`. `object()`
  leaves `additionalProperties` unrestricted unless
  `withoutAdditionalProperties()` is called.
- `README.md:219-234` — the `### MCP server` section describes the two
  tools; it does not yet mention structured output.
- Repo conventions: `final` classes, `#[Override]` on overridden methods,
  docblocks explain constraints, PHPUnit 12 `#[Test]`, snake_case test
  method names.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| MCP tests | `vendor/bin/phpunit --filter McpTest` | all pass |
| Full suite | `composer test` | exit 0, 0 failures (343 baseline + new) |
| Static analysis | `composer phpstan` | exit 0 |
| Code style | `vendor/bin/pint --test` | exit 0 |
| Rector check | `vendor/bin/rector process --dry-run` | exit 0, no proposed changes |

## Scope

**In scope** (the only files you should modify):

- `src/Mcp/Tools/ImpactTool.php`
- `src/Mcp/Tools/DetectChangesTool.php`
- `tests/Feature/McpTest.php`
- `README.md` — **only** the `### MCP server` section (one added sentence)

**Out of scope** (do NOT touch, even though they look related):

- `src/Analysis/JsonPresenter.php` — the shape IS the contract; if the MCP
  surface seems to need a different shape, that is a STOP condition, not an
  edit.
- `src/Analysis/ImpactFormatter.php`, the console commands, `RichterServer`.
- `plans/README.md` — the reviewer maintains the index.

## Git workflow

- Branch: `advisor/015-structured-mcp-output` off `main` (`d4a856d`).
- Commit style: imperative sentence-case (see `git log`).
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: ImpactTool — prose + structuredContent + outputSchema

In `src/Mcp/Tools/ImpactTool.php`:

1. Widen the return type: `public function handle(Request $request): Response|ResponseFactory`.
   Add `use Laravel\Mcp\ResponseFactory;` and
   `use SanderMuller\Richter\Analysis\JsonPresenter;`.
2. Error path unchanged. Success path becomes:

```php
$result = new ImpactAnalyzer($this->graphs->graph())->impact($symbol);

return (new ResponseFactory(Response::text(ImpactFormatter::impact($result))))
    ->withStructuredContent(JsonPresenter::impact($result));
```

3. Declare the output schema (mirroring `JsonPresenter::impact`'s
   documented return shape — same field names, same nesting):

```php
/** @return array<string, mixed> */
#[Override]
public function outputSchema(JsonSchema $schema): array
{
    $edge = $schema->object([
        'depth' => $schema->integer(),
        'node' => $schema->string(),
        'via' => $schema->string(),
    ]);

    return [
        'target' => $schema->string()->description('The symbol as analysed.'),
        'callers' => $schema->array()->items($edge)->description('What breaks if the target changes; depth 1 is a direct caller.'),
        'dependencies' => $schema->array()->items($edge)->description('What the target reaches.'),
    ];
}
```

   If passing the same builder instance (`$edge`) to two `items()` calls
   misbehaves on serialization, build the object literal twice instead —
   verify by dumping the tool's serialized definition in a test (step 3).

**Verify**: `vendor/bin/phpunit --filter McpTest` — expect the two
content-asserting tests to fail (they still call `handle()` directly);
everything else passes. That failure is fixed in step 3.

### Step 2: DetectChangesTool — both success paths + outputSchema

In `src/Mcp/Tools/DetectChangesTool.php`, same imports and return-type
widening as step 1:

1. Error path unchanged (`Response::error`).
2. Empty diff — keep the prose, add the canonical zero payload:

```php
if ($changed === []) {
    return (new ResponseFactory(Response::text("No changed PHP files under app/ against {$base}.")))
        ->withStructuredContent(JsonPresenter::emptyDetectChanges($base));
}
```

3. Analyzed path:

```php
$result = new ImpactAnalyzer($this->graphs->graph())->detectChanges($changed);

return (new ResponseFactory(Response::text(ImpactFormatter::detectChanges($result, TestReferenceIndex::fromTests(base_path('tests'))))))
    ->withStructuredContent(JsonPresenter::detectChanges($result, $base));
```

4. Output schema mirroring `JsonPresenter::detectChanges`. Three fields
   (`changed`, `coverage`, `entryPointPaths`) are string-keyed maps in PHP;
   when such a map is **empty** it JSON-encodes as `[]` (array), not `{}`
   (object) — exactly as the shipped `--json` contract already behaves. So
   declare those three as `anyOf` object/array to keep the schema honest
   for strict validators:

```php
/** @return array<string, mixed> */
#[Override]
public function outputSchema(JsonSchema $schema): array
{
    return [
        'base' => $schema->string()->description('The git ref the diff was taken against.'),
        'changed' => $schema->anyOf([$schema->object(), $schema->array()])
            ->description('Changed file => resolved seed count. Empty map serializes as [].'),
        'coverage' => $schema->anyOf([$schema->object(), $schema->array()])
            ->description('Changed file => "analyzed" or "unresolved". Empty map serializes as [].'),
        'entryPoints' => $schema->array()->items($schema->string()),
        'entryPointPaths' => $schema->anyOf([$schema->object(), $schema->array()])
            ->description('Entry-point node => call chain down to the changed code. Empty map serializes as [].'),
        'impacted' => $schema->integer()->description('Distinct impacted graph nodes.'),
        'relatedModels' => $schema->array()->items($schema->string()),
        'risk' => $schema->string()->description('low, medium or high.'),
        'lowConfidence' => $schema->boolean(),
        'coarseCapApplied' => $schema->boolean(),
        'findings' => $schema->array()->items($schema->string()),
        'unresolved' => $schema->boolean()->description('True when any changed file could not be placed in the graph.'),
    ];
}
```

   If `anyOf` in the vendored `JsonSchemaTypeFactory` does not accept
   pre-built types like this (check its signature and the `Serializer`),
   fall back to `$schema->object()->description('… Empty map serializes as [].')`
   for those three fields and note the fallback in your report — do not
   invent a different shape.

**Verify**: `composer phpstan` → exit 0 (catches signature/import
mistakes before the tests run).

### Step 3: Migrate and extend the MCP tests

In `tests/Feature/McpTest.php` (add
`use SanderMuller\Richter\Mcp\RichterServer;` and any assertion imports):

1. Rewrite `the_impact_tool_reports_the_blast_radius_of_a_symbol` on the
   server-level test API so it exercises the real serialization path:

```php
RichterServer::tool(ImpactTool::class, ['symbol' => 'User'])
    ->assertOk()
    ->assertSee('User')
    ->assertStructuredContent(function (AssertableJson $json): void {
        $json->where('target', 'User')
            ->has('callers')
            ->has('dependencies');
    });
```

   (`Illuminate\Testing\Fluent\AssertableJson`; keep the existing comment
   about the testbench skeleton graph.)
2. Rewrite `the_detect_changes_tool_reports_an_empty_diff_cleanly`
   likewise, keeping its `Process::fake`; assert the prose via
   `assertSee('No changed PHP files under app/')` and the payload exactly:
   `->assertStructuredContent(JsonPresenter::emptyDetectChanges('origin/main'))`
   (the testbench config default base is `origin/main`; the exact-array
   form pins every field of the zero contract).
3. Add a structured-content test for the analyzed detect-changes path if a
   cheap deterministic fake exists — otherwise assert at minimum that a
   faked non-empty diff yields a `structuredContent` whose `base` and
   `risk` keys exist. Keep `Process::fake` patterns consistent with the
   existing empty-diff test.
4. Add one test asserting the advertised output schema: resolve each tool
   and assert its serialized definition contains an `outputSchema` with the
   expected property names (see `vendor/laravel/mcp/src/Server/Tool.php:53-81`
   for the array shape — the `toArray`/`toMethodCall` style serializer).
   Property-name presence is enough; do not pin the full schema document.
5. Leave the three error-path tests and the two registration tests as they
   are — if any of them fails, that is a STOP condition (an error path
   changed behavior).

**Verify**: `vendor/bin/phpunit --filter McpTest` → all pass, including
≥2 new/updated `assertStructuredContent` assertions.

### Step 4: README — one sentence

In `README.md`'s `### MCP server` section (around line 221), extend the
paragraph with one sentence stating that both tools also return MCP
structured content in the same shape as the CLI `--json` output, so agents
can branch on fields instead of parsing prose. Do not add a new section or
restructure anything.

**Verify**: `git diff README.md` shows only that section changed.

### Step 5: Full verification

**Verify**:
- `composer test` → exit 0, 0 failures.
- `composer phpstan` → exit 0.
- `vendor/bin/pint --test` → exit 0.
- `vendor/bin/rector process --dry-run` → exit 0, no proposed changes.

## Test plan

- Migrated: symbol blast-radius test and empty-diff test now run through
  `RichterServer::tool(...)` and assert both prose (`assertSee`) and
  `structuredContent`.
- New: analyzed-path structured assertion; output-schema advertisement
  test per tool.
- Untouched: the three error-path tests (missing symbol, broken ref,
  option-shaped ref) and both registration tests must stay green with zero
  assertion edits.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/phpunit --filter McpTest` exits 0 with ≥2 `assertStructuredContent` call sites in the file
- [ ] `composer test` exits 0, 0 failures; no existing error-path/registration assertion modified
- [ ] `composer phpstan` exits 0
- [ ] `vendor/bin/pint --test` exits 0
- [ ] `vendor/bin/rector process --dry-run` exits 0 with no proposed changes
- [ ] `git status --short` shows changes only in the four in-scope files
- [ ] `grep -c "withStructuredContent" src/Mcp/Tools/*.php` → each tool file ≥1
- [ ] `grep -n "JsonPresenter" src/Analysis/JsonPresenter.php` diff-clean: `git diff --stat -- src/Analysis/JsonPresenter.php` is empty

## STOP conditions

Stop and report back (do not improvise) if:

- Any "Current state" excerpt no longer matches the live code.
- The MCP structured shape seems to need to differ from the JsonPresenter
  shape (e.g. a serialization problem with a field) — the single-contract
  premise is the point of this plan; report, don't fork the shape.
- `RichterServer::tool(...)` cannot resolve the tools or the TestResponse
  lacks `assertStructuredContent` (would mean the installed laravel/mcp
  differs from 0.8's API surveyed above).
- Any pre-existing error-path or registration test fails after your change.
- `anyOf` fallback (step 2.4) is needed AND the plain-`object()` fallback
  also fails to serialize — report the serializer error verbatim.

## Maintenance notes

- The structured shape is coupled to `JsonPresenter` on purpose: a future
  field added to the `--json` contract shows up on MCP automatically, and
  the outputSchema must gain the matching property in the same change —
  reviewers should check the schema whenever `JsonPresenter` changes.
- The MCP surface has no gate flags, so the `gate` key the CLI adds under
  `--fail-on` never appears here; if gating ever comes to MCP, extend the
  schema then.
- laravel/mcp is a suggested dependency; these files only load when it is
  installed, so the new `ResponseFactory` import does not tighten the
  package's requirements.
