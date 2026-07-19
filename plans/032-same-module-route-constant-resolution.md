# Plan 032: Resolve same-module constant `route()` arguments instead of tainting the file — keeping the file-level UNRESOLVED contract intact

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 0c2e5d1..HEAD -- src/Tracers/FrontendReferenceScanner.php tests/Unit/FrontendReferenceScannerTest.php tests/Unit/FrontendChangesTest.php`
> This diff is **expected to be non-empty**: plans 019 and 031 land before
> this plan and touch these files. Reconcile against the "Expected drift from
> plans 019 and 031" section below — only drift *beyond* what that section
> lists is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED (a wrong resolution would be a guess — the forbidden
  direction; the rules below are deliberately conservative)
- **Depends on**: plans/019-frontend-honesty-gaps.md (hard — it restructures
  the same `unresolved` detector); plans/031-uri-anchoring-and-generated-file-exclusion.md
  (soft — same files, execute sequentially)
- **Category**: dx
- **Planned at**: commit `0c2e5d1`, 2026-07-19

## Why this matters

A single dynamic `route(someVariable)` in a changed frontend file flips the
whole file UNRESOLVED, which makes `richter:affected-tests` exit 2 and
degrade to the full suite — even when every sibling reference is a plain
`route('literal')`. The consumer who reported this (hihaho) measured 154
literal calls against 1 dynamic one; for them the cost is low, but for an
indirection-heavy frontend that keeps route names behind a constants object
or enum (`route(ROUTES.player)`), the fail-safe fires on every touched file
and test selection never narrows.

The fix that preserves the honesty contract is to make fewer arguments
*actually* dynamic: when the argument is an identifier or member access whose
referent is a **single, unambiguous string constant in the same module**,
resolve it to that string and treat it exactly like a literal route name.
Anything the resolver cannot pin with certainty keeps the file-level taint.

**Deliberately rejected: scoping the UNRESOLVED taint per-reference** (the
handoff's "at minimum" suggestion). Two session-verified facts kill it:

1. The seeds of resolvable references are *not* discarded today —
   `FrontendChanges::resolve()` collects `directSeeds` and sets the flag
   independently (`src/Changes/FrontendChanges.php:114-116`), so the report
   already shows everything resolvable. Per-reference scoping adds no report
   value.
2. Determinability is a whole-diff verdict: any `'unresolved'` coverage entry
   becomes a blocking reason (`src/Analysis/AffectedTests.php:35-37`). A
   dynamic `route(x)` means the change touches an endpoint the scan **cannot
   name** — returning a "determinable" narrowed test list while such a
   reference exists is exactly "UNRESOLVED silently weakening", the failure
   mode the bridge's design forbids. The only thing per-reference scoping
   could change is the exit-2 outcome — by weakening it.

So this plan implements the constant-resolution half only, and records the
rejection so it is not re-litigated.

## Current state

All excerpts verified at commit `0c2e5d1` (pre-019 line numbers — see
"Expected drift" below; the central excerpt is precisely what 019 rewrites).

- `src/Tracers/FrontendReferenceScanner.php:62-72` — Ziggy name capture and
  the file-level dynamic-argument detector:

  ```php
  preg_match_all('/(?<![\w$])route\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $ziggy);

  return [
      'actions' => $this->uniqueActions($actions),
      'routeNames' => array_values(array_unique([...$routeNames, ...$ziggy[1]])),
      'uris' => array_values($this->uriCandidates($source)),
      // `route(` followed by anything but a string literal or `)` is a dynamic argument —
      // a template literal or variable the scan cannot resolve. Ziggy's argless `route()`
      // fluent form is not dynamic.
      'unresolved' => preg_match('/(?<![\w$])route\s*\(\s*[^\'")\s]/', $source) === 1,
  ];
  ```

- `src/Changes/FrontendChanges.php:81-82` — the taint is ORed across both
  diff sides and produces one finding:

  ```php
  $unresolved = $head['unresolved'] || $base['unresolved'];
  $findings = $unresolved ? ['a dynamic route() argument prevents resolving every referenced endpoint'] : [];
  ```

  and lines 110-112 show why a resolved-but-unknown name is safe — it
  silently drops against the name index, never guesses:

  ```php
  foreach ($routeNames as $name) {
      $seeds = [...$seeds, ...$indexes['byName'][$name] ?? []];
  }
  ```

- Downstream contract this plan must not weaken:
  - `src/Analysis/ImpactAnalyzer.php:246-252` (`frontendLane()`):
    `$unplaced = $file->unresolvedFrontendReferences || ($resolved === [] && $file->directSeeds !== []);`
    → coverage `'unresolved'`.
  - `src/Analysis/AffectedTests.php:35-37`: any `'unresolved'` coverage adds
    the reason `'changed file(s) could not be placed in the graph
    (UNRESOLVED)'` → `determinable: false` →
    `src/Console/AffectedTestsCommand.php:122` exits `UNDETERMINED` (2).

- Existing tests pinning the taint behavior (must keep passing):
  - `tests/Unit/FrontendReferenceScannerTest.php:114-129` — a template
    literal argument (`route(\`videos.${action}\`)`) and a bare variable
    (`route(name)`) both flip `unresolved`.
  - `tests/Unit/FrontendReferenceScannerTest.php:131-138` — `route (name);`
    with whitespace before the parenthesis still flips it.
  - `tests/Unit/FrontendReferenceScannerTest.php:140-149` — Ziggy's argless
    `route().current('videos.*')` is *not* dynamic.
  - `tests/Unit/FrontendChangesTest.php:233-240` — the end-to-end finding
    message for a dynamic argument.

- Design constraints, quoted from the bridge's research doc
  (`internal/spike-ts-backend-bridge.md` — local, gitignored; the executor
  may not have it, so the binding lines are inlined here):

  > Per-file `unresolved` flag when a **detected-but-unresolvable** pattern
  > appears: template literal or variable inside `route(...)` … Plain "no
  > references found" is a determined answer, not unresolved.

  > Any unresolved frontend file → `affected-tests` exits 2 (undetermined),
  > same contract as unresolved dispatches.

  Plus the standing rule "unmatched names never guess"
  (`src/Changes/FrontendChanges.php:18-20` docblock): a *resolved* name that
  matches no registered route must silently drop, exactly as if the author
  had written the literal.

## Expected drift from plans 019 and 031 (land first)

- **019 rewrites the central excerpt.** After 019, the `'unresolved' =>`
  expression (pre-019 `src/Tracers/FrontendReferenceScanner.php:71`) is two
  OR'ed patterns — the original first-character check plus a
  string-literal-followed-by-`+` concatenation check (019 Step 2, target
  shape `preg_match('/(?<![\w$])route\s*\(\s*[\'"][^\'"]*[\'"]\s*\+/', …)`).
  **This plan restructures the first pattern's role into
  enumerate-and-resolve and MUST keep the concatenation pattern OR'ed in as a
  residual taint source** — a concatenated name can never resolve to a single
  constant.
- 019 also adds `(?:\.\w+)?` to the module regexes (pre-019 lines 38/48) and
  adds tests for concatenated arguments — those tests must still pass after
  this plan.
- **031 edits `uriCandidates()`** (call-argument anchoring) and adds tests in
  both test files. No overlap with `scan()`'s route-name/unresolved logic,
  but line numbers shift again.
- All other excerpts (`FrontendChanges.php`, `ImpactAnalyzer.php`,
  `AffectedTests.php`) are untouched by 019/031 except `FrontendChanges.php`
  line numbers shifting from 019's `uriTemplateRegex` fix.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Install | `composer install` | exit 0 |
| Focused tests | `vendor/bin/phpunit --filter 'FrontendReferenceScannerTest\|FrontendChangesTest'` | OK, 0 failures |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `test-writing` — the no-guess negatives are the heart of this plan;
  each needs a comment stating which resolution rule it pins.
- Skill `backend-quality` for closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Tracers/FrontendReferenceScanner.php` (`scan()`'s dynamic-argument
  handling + new private resolver helpers + class docblock)
- `tests/Unit/FrontendReferenceScannerTest.php`
- `tests/Unit/FrontendChangesTest.php` (one end-to-end pair)

**Out of scope** (do NOT touch, even though they look related):
- `src/Changes/FrontendChanges.php` — `resolve()` needs no change: the flag
  arrives computed from the scanner, the finding message stays accurate for
  residual dynamics, and resolved names flow through the existing `byName`
  lookup.
- `src/Analysis/AffectedTests.php`, `src/Analysis/ImpactAnalyzer.php`,
  `src/Console/AffectedTestsCommand.php` — the exit-2 contract is the thing
  being *preserved*, not edited.
- Any per-reference weakening of `unresolved` — rejected above, by design.
- Cross-module (imported) constants, bracket access (`ROUTES['x']`),
  `.concat()` chains — deferred, see Maintenance notes.

## Git workflow

- Branch: `advisor/032-same-module-route-constant-resolution`
- Commit per step pair (test + code), imperative sentence subjects, e.g.
  `Resolve same-module constant route() arguments before tainting the file`.
- If the repository has commit signing enabled, never fall back to an
  unsigned commit. (None was configured at planning time.)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Pin the resolution rules (failing tests first)

In `tests/Unit/FrontendReferenceScannerTest.php` (model after the dynamic-
argument tests at lines 114–138; `#[Test]`, snake_case):

Positive (resolution succeeds, taint stays off):

- `a_route_argument_naming_a_same_module_const_string_resolves`:

  ```ts
  const PLAYER_ROUTE = 'videos.player';
  route(PLAYER_ROUTE);
  ```

  → `routeNames` contains `videos.player`, `unresolved === false`.
- `a_route_argument_via_a_flat_const_object_member_resolves`:

  ```ts
  const ROUTES = { player: 'videos.player', list: "videos.index" } as const;
  route(ROUTES.player, { id: 1 });
  ```

  → `routeNames` contains `videos.player` (options argument tolerated),
  `unresolved === false`.
- `a_route_argument_via_a_string_enum_member_resolves`:

  ```ts
  enum RouteName { Player = 'videos.player' }
  route(RouteName.Player);
  ```

  → `routeNames` contains `videos.player`, `unresolved === false`.

Negative (no-guess rules — each MUST keep the taint):

- `an_undeclared_identifier_still_taints_the_scan`: `route(someName);` with
  no declaration → `unresolved === true` (pins the existing behavior through
  the restructuring).
- `a_let_declared_name_does_not_resolve`: `let name = 'videos.player';
  route(name);` → `unresolved === true` — `let`/`var` are reassignable, so
  the initializer is not the runtime value with certainty; `const` only.
- `a_redeclared_const_is_ambiguous_and_does_not_resolve`: two
  `const P = '…'` declarations with different strings, `route(P);` →
  `unresolved === true` — "exactly one declaration" is the rule.
- `a_nested_object_member_does_not_resolve`:

  ```ts
  const ROUTES = { videos: { player: 'videos.player' } };
  route(ROUTES.player);
  ```

  → `unresolved === true` — only *flat* object/enum bodies are readable
  without a parser; matching a property inside a nested body would be a
  guess (it may belong to a different sub-object than the one accessed).
- `an_imported_constant_does_not_resolve`: `import { ROUTES } from
  './routes-config'; route(ROUTES.player);` → `unresolved === true` —
  same-module only.
- `a_resolved_reference_beside_an_unresolvable_one_keeps_the_file_tainted`
  (**the contract test**):

  ```ts
  const PLAYER_ROUTE = 'videos.player';
  route(PLAYER_ROUTE);
  route(other);
  ```

  → `routeNames` contains `videos.player` AND `unresolved === true`. The
  resolvable reference still contributes; the file-level fail-safe is
  untouched.

**Verify**: `vendor/bin/phpunit --filter FrontendReferenceScannerTest` →
exactly the new tests fail; all pre-existing (incl. plan-019) tests pass.

### Step 2: Restructure `scan()` — enumerate dynamic arguments, resolve, then taint

In `src/Tracers/FrontendReferenceScanner.php`, replace the single boolean
`'unresolved' =>` expression with enumeration + resolution. Target shape
(names are prescriptive so the done-criteria greps work; adapt line layout to
the post-019 file):

```php
[$resolvedNames, $unresolved] = $this->resolveDynamicRouteArguments($source);

return [
    'actions' => $this->uniqueActions($actions),
    'routeNames' => array_values(array_unique([...$routeNames, ...$ziggy[1], ...$resolvedNames])),
    'uris' => array_values($this->uriCandidates($source)),
    'unresolved' => $unresolved,
];
```

```php
/**
 * @return array{0: list<string>, 1: bool} route names recovered from same-module
 *   constants, and whether any dynamic argument stayed unresolvable
 */
private function resolveDynamicRouteArguments(string $source): array
{
    // A string literal followed by `+` is concatenation — never resolvable to one
    // constant. (Pattern introduced by plan 019; keep it OR'ed in verbatim.)
    $unresolved = preg_match('/(?<![\w$])route\s*\(\s*[\'"][^\'"]*[\'"]\s*\+/', $source) === 1;

    // Same first-character discipline as the original detector: anything after
    // `route(` that is not a quote, `)`, or whitespace is a dynamic argument.
    // Capture up to the first `,` or `)` — the name expression, options excluded.
    preg_match_all('/(?<![\w$])route\s*\(\s*([^\'")\s][^),]*)/', $source, $dynamic);

    $resolved = [];

    foreach ($dynamic[1] as $argument) {
        $name = $this->sameModuleConstant(trim($argument), $source);

        if ($name === null) {
            $unresolved = true;

            continue;
        }

        $resolved[] = $name;
    }

    return [$resolved, $unresolved];
}
```

`sameModuleConstant(string $expression, string $source): ?string` applies the
rules Step 1 pinned — null on *any* uncertainty:

- Bare identifier (`/^[A-Za-z_$][\w$]*$/`): resolve only against `const NAME
  = '…'` / `const NAME: Type = "…"` declarations — `preg_match_all` for
  `/\bconst\s+NAME\s*(?::[^=\n]+)?=\s*([\'"])((?:(?!\1).)+)\1/`; return the
  string only when exactly one match exists. `let`/`var` never resolve.
- Member access (`/^[A-Za-z_$][\w$]*\.[A-Za-z_$][\w$]*$/`): find the
  container as `const`/`enum` with a **flat** brace body —
  `/\b(?:const|enum)\s+OBJ\s*(?::[^={]+)?=?\s*\{([^{}]*)\}/` (exactly one
  match; `[^{}]*` rejects nested bodies by construction; the optional `=`
  covers `enum Name { … }`, the optional `:…` covers a type annotation) —
  then within the body `/\bPROP\s*[:=]\s*([\'"])((?:(?!\1).)+)\1/` (exactly
  one match; `:` for object literals, `=` for enum members). `as const` /
  `satisfies` suffixes need no handling (they sit outside the braces).
- Anything else (backtick first char, nested calls, `+` inside the captured
  expression, bracket access) fails the shape checks → null.

Escape the looked-up names with `preg_quote()` when interpolating into the
patterns. Update the class docblock (lines 5–13), which currently says "a
detected-but-unresolvable reference (a dynamic `route()` argument) flips
`unresolved`" — extend it: a dynamic argument first gets one resolution
attempt against same-module `const`/`enum` string constants; only what
survives that flips the flag, and resolution never guesses (exactly-one
discipline, flat bodies, `const` only).

**Verify**: `vendor/bin/phpunit --filter FrontendReferenceScannerTest` →
0 failures (Step 1's tests pass; the pre-existing dynamic/template/argless
tests and 019's concatenation tests all still pass).

### Step 3: End-to-end pair in FrontendChangesTest (failing first)

In `tests/Unit/FrontendChangesTest.php` (its `setUp()` at lines 12–21
registers `videos.show` GET `/videos/{video}`; model after
`ziggy_and_wayfinder_route_names_map_through_the_name_index`, lines 98–108):

- `a_const_resolved_route_name_maps_through_the_name_index`:
  resolve a source `const SHOW = 'videos.show'; route(SHOW);` →
  `directSeeds === ['route::GET::/videos/{video}']`,
  `unresolvedFrontendReferences === false`, `findings === []`.
- `a_residual_dynamic_argument_still_reads_unresolved_with_the_finding`:
  source `const SHOW = 'videos.show'; route(SHOW); route(other);` →
  `directSeeds === ['route::GET::/videos/{video}']`,
  `unresolvedFrontendReferences === true`, findings contain
  `'a dynamic route() argument prevents resolving every referenced endpoint'`.

(A resolved name that matches no registered route needs no new test — it
flows into the `byName` lookup and silently drops, the path already pinned by
`an_unmatched_route_name_silently_is_not_a_reference` at lines 222–231.)

**Verify**: `vendor/bin/phpunit --filter FrontendChangesTest` → 0 failures.

### Step 4: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` →
exit 0; `vendor/bin/pint --test` → exit 0.

## Test plan

- Step 1: three resolution positives (const string, flat const object member
  with options arg, string enum member) + six no-guess negatives (undeclared,
  `let`, redeclared, nested body, imported, resolved-beside-unresolvable
  contract test).
- Step 3: two end-to-end tests through the name index and the finding
  message.
- Regression: the pre-existing dynamic-argument tests
  (`FrontendReferenceScannerTest` lines 114–149) and 019's concatenation
  tests pin that every previously-tainting shape still taints unless it now
  resolves.
- Pattern: model after the sibling tests named in each step.
- Verification: `composer test` → `"result":"passed"`.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] All new failing tests written first, now passing
- [ ] `composer test` → `"result":"passed"`
- [ ] `composer phpstan` → exit 0
- [ ] `vendor/bin/pint --test` → exit 0
- [ ] `grep -n "resolveDynamicRouteArguments\|sameModuleConstant" src/Tracers/FrontendReferenceScanner.php` → both helpers exist
- [ ] `grep -c "route\\\\s\*\\\\(" src/Tracers/FrontendReferenceScanner.php` — the concatenation pattern from plan 019 is still present (inspect: the `'…'\s*\+` pattern survives verbatim)
- [ ] The contract test (`a_resolved_reference_beside_an_unresolvable_one_keeps_the_file_tainted`) exists and passes
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Drift beyond the "Expected drift from plans 019 and 031" list — in
  particular, if plan 019's concatenation pattern is absent from `scan()`
  (019 may not have landed; this plan hard-depends on it).
- Any no-guess negative from Step 1 can only be made to pass by loosening a
  resolution rule (accepting `let`, accepting nested bodies, accepting
  more-than-one declaration). The rules are the design; loosening them is a
  new decision, not an implementation detail.
- The contract test conflicts with the implementation — i.e. making
  resolution work appears to require clearing the taint for files with
  residual dynamics.
- Any pre-existing unresolved-behavior test (scanner lines 114–149, the
  FrontendChangesTest finding test, 019's additions) fails after Step 2.
- `preg_match_all` enumeration and the resolver disagree on what counts as
  dynamic in a way that lets a dynamic argument produce **neither** a
  resolution **nor** a taint — that would be a silently-weakened UNRESOLVED,
  the one forbidden outcome. Add a regression test for the case found, then
  stop and report.

## Maintenance notes

- **Rejected, do not revisit without new evidence**: per-reference UNRESOLVED
  scoping. Seeds already flow from tainted files
  (`FrontendChanges.php:114-116`) and determinability is whole-diff
  (`AffectedTests.php:35-37`) — scoping could only weaken exit-2.
- **Accepted residual (guess-direction, documented)**: scope shadowing. A
  function parameter or block-scoped variable shadowing a module-level
  `const` of the same name would resolve to the module constant. A regex
  scanner has no scopes; the `const`-only + exactly-once rules make this
  rare for route-name constants (idiomatically SCREAMING_CASE module-level).
  If a consumer ever hits it, the fix is tightening (e.g. requiring
  uppercase-ish names), never loosening.
- **Deferred deliberately**: bracket access (`ROUTES['player']`),
  cross-module imported constants, `.concat()` chains, template-literal
  arguments (`route(\`videos.${x}\`)` still taints via the first-character
  check). Each would need its own no-guess analysis.
- Reviewer focus: the exactly-one discipline in both resolver lookups, the
  flat-body `[^{}]*` construction, and that the 019 concatenation pattern
  survived the restructuring verbatim.
- If plan 031's follow-up idea (constant resolution for URI literals) is ever
  built, `sameModuleConstant()` is the helper to reuse — keep it
  expression-in/string-out generic.
