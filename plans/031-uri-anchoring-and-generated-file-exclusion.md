# Plan 031: Anchor literal-URI candidates to call arguments and extend generated-path exclusion to files, globs, Ziggy output, and `.d.ts`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 0c2e5d1..HEAD -- src/Tracers/FrontendReferenceScanner.php src/Changes/FrontendChanges.php src/Support/RichterConfig.php config/richter.php README.md tests/Unit/FrontendReferenceScannerTest.php tests/Unit/FrontendChangesTest.php`
> This diff is **expected to be non-empty**: plan 019 lands before this plan
> and touches two of these files. Reconcile against the "Expected drift from
> plan 019" section below — only drift *beyond* what that section lists is a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (deliberate recall narrowing on the literal-URI surface; the
  existing suite is verified compatible, but the change revisits a recorded
  design decision)
- **Depends on**: plans/019-frontend-honesty-gaps.md (must land first — same
  files)
- **Category**: bug
- **Planned at**: commit `0c2e5d1`, 2026-07-19

## Why this matters

A heavy consumer of the v0.7.0 frontend bridge (hihaho, a large Ziggy-dominant
Laravel app) reported that the literal-URI matcher accepts **any** string
literal beginning with `/` anywhere in a scanned file — not just literals
passed to an HTTP call. A frontend constants file, nav-link config, fixture,
or generated route map whose strings happen to match real route templates
floods the seeds with false endpoint references, and through
`richter:affected-tests` pulls in unrelated backend tests. hihaho escaped this
only by luck: Ziggy's generated `ziggy.js` stores URIs *without* a leading
slash, so the `\/`-anchored pattern matched nothing.

The same report showed the exclusion config cannot compensate:
`frontend.generated_paths` matches only `{root}/{entry}/` directory prefixes,
so a generated **file** directly under a root (`resources/js/ziggy.js`,
`resources/js/ziggy.d.ts`) cannot be excluded at all, Ziggy's conventional
artifacts are not in the defaults even though Ziggy `route()` calls are a
first-class reference source, and `.d.ts` declaration files scan as `.ts`
(`pathinfo()` reports extension `ts`) despite containing no executable calls.

This plan fixes both halves: (1) a literal (quoted or backtick) becomes a URI
candidate only in **call-argument position**, and (2) `generated_paths`
entries additionally match exact files and `*`-globs, `ziggy.js` joins the
defaults, and `.d.ts` files are rejected outright.

**This revisits a recorded design decision.** The bridge's research doc
(`internal/spike-ts-backend-bridge.md` — a local, gitignored file the executor
may not have; the relevant line, quoted verbatim):

> Literal-URI over-matching (surface 5) is accepted: route-map validation
> filters the noise, and over = more touched endpoints reported = the safe
> direction.

That acceptance predates dogfood evidence. The route-map filter only discards
literals matching *no* route — it is powerless against data files whose
strings match *real* routes (a nav config, a leading-slash route map), which
is exactly the flood the consumer demonstrated. Over-reporting remains the
safe direction for any *single* uncertain reference, but unbounded
over-reporting has a real cost: false affected-tests selection. The durable
record of the revised decision is the `uriCandidates()` docblock this plan
rewrites, plus this plan file.

## Current state

All excerpts verified at commit `0c2e5d1` (pre-019 line numbers — see
"Expected drift from plan 019").

- `src/Tracers/FrontendReferenceScanner.php` — the regex scanner; its
  `uriCandidates()` is the only method this plan touches there. The verb
  wrapper is optional in both patterns, so a bare `/`-leading literal matches
  anywhere:

  ```php
  // src/Tracers/FrontendReferenceScanner.php:90
  preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(\s*)?[\'"](\/[^\'"\s?#]*)[?#]?[^\'"]*[\'"]/i', $source, $literals, PREG_SET_ORDER);
  ```

  ```php
  // src/Tracers/FrontendReferenceScanner.php:97
  preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(\s*)?`(\/[^`]*)`/i', $source, $templates, PREG_SET_ORDER);
  ```

  Its docblock (lines 76–83) documents the current stance: "Plain string
  literals (`axios.get('/api/videos')`) and backtick templates … A verb-named
  call directly around the literal (`.post('/x'`, `put('/x'`) pins the HTTP
  method; any other shape — `fetch()` options, wrappers — stays null so
  uncertainty never narrows."

- `src/Changes/FrontendChanges.php:39-62` — `handles()`. The extension gate
  (line 43) admits `.d.ts` because `pathinfo(PATHINFO_EXTENSION)` yields `ts`,
  and the exclusion (line 58) only ever matches a directory prefix:

  ```php
  // src/Changes/FrontendChanges.php:43
  if ($roots === [] || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, strict: true)) {
      return false;
  }
  // …
  // src/Changes/FrontendChanges.php:58
  return array_all(RichterConfig::frontendGeneratedPaths(), fn (string $generated) => ! str_starts_with($file, $root . '/' . trim($generated, '/') . '/'));
  ```

  The counterpart docblock at `src/Changes/FrontendChanges.php:120-125`
  (Blade inline lane) already takes the position this plan generalizes:
  "markup hrefs, form actions and Blade's `route()` helper are
  navigation/link *generation* … not endpoint calls — scanning them would
  register every linked route as touched."

- `config/richter.php:34` — the shipped default:

  ```php
  'generated_paths' => ['actions', 'routes', 'wayfinder'],
  ```

- `src/Support/RichterConfig.php:46-49` — the code-side fallback duplicates
  that default:

  ```php
  public static function frontendGeneratedPaths(): array
  {
      return self::stringList('richter.frontend.generated_paths') ?? ['actions', 'routes', 'wayfinder'];
  }
  ```

- `README.md:375` — the config table row that must stay accurate:
  `frontend.generated_paths | actions, routes, wayfinder | Wayfinder's
  generated trees under each frontend root — excluded from scanning as
  regeneration churn.` (Related prose at README.md:289.)

- Existing tests that pin behavior this plan must preserve:
  - `tests/Unit/FrontendReferenceScannerTest.php:202-215`
    (`a_verb_named_call_pins_the_http_method_and_wrappers_stay_unpinned`) —
    `load('/videos/7')`, a **non-verb wrapper in call position**, stays a
    candidate with `method: null`. Call-argument anchoring keeps it.
  - `tests/Unit/FrontendChangesTest.php:49-58`
    (`handles_excludes_wayfinder_generated_trees`) — directory-entry
    semantics, including `resources/js/Pages/actions.ts` still scanning.
  - `tests/Unit/FrontendChangesTest.php:193-203`
    (`a_non_endpoint_literal_matches_no_template_and_is_not_a_reference`) —
    asserts empty seeds for `const logo = '/img/logo.svg';`; under anchoring
    the literal is no longer even a candidate, so the assertion still holds.
  - `tests/Unit/FrontendChangesTest.php:165-175`
    (`inline_uri_seeds_map_script_literals_only`) — the Blade inline lane
    uses the same scanner; its surviving literal `fetch('/videos/7', …)` is a
    call argument, so anchoring changes nothing there.

- **Session-verified compatibility fact**: no test in
  `tests/Unit/FrontendReferenceScannerTest.php` or
  `tests/Unit/FrontendChangesTest.php` pins a literal *outside*
  call-argument position as a URI candidate. Every pinned candidate sits
  directly inside `(` or after `,` of a call. The anchoring change is
  therefore additive-negative for the existing suite.

- Design constraints to honor:
  - Frontend annotation never feeds `risk`/`impacted` (README.md:282 region,
    config comment `config/richter.php:26-31`) — unchanged here.
  - "unmatched names never guess" — not implicated; URI literals self-gate
    against the route map as before.
  - UNRESOLVED semantics are untouched: URI literals never flip `unresolved`
    (silent-drop by design), so narrowing this surface cannot weaken the
    fail-safe. It trades **bounded, documented recall loss** (see Step 1's
    negative tests) for the elimination of an unbounded false-positive
    surface.

## Expected drift from plan 019 (lands first)

Plan 019 (`plans/019-frontend-honesty-gaps.md`) modifies two in-scope files.
The excerpts above are from pre-019 code at `0c2e5d1`; reconcile as follows
instead of STOPping:

- `src/Tracers/FrontendReferenceScanner.php`: 019 rewrites the
  `'unresolved' =>` expression in `scan()` (pre-019 line 71) into two OR'ed
  patterns, and appends `(?:\.\w+)?` before the `$` anchor of the two module
  regexes (pre-019 lines 38 and 48). None of that overlaps `uriCandidates()`
  (pre-019 lines 86–112) — expect its **content** to match the excerpts
  verbatim with line numbers shifted down a few lines.
- `src/Changes/FrontendChanges.php`: 019 changes `uriTemplateRegex()`'s
  optional-segment emission `(?:/[^/]+)?` → `(?:/[^/]*)?` (pre-019 lines
  316–321) and may touch `seedsForUris()`. `handles()` (lines 39–62) is
  untouched by 019 — that excerpt should match verbatim.
- `tests/Unit/FrontendReferenceScannerTest.php` and
  `tests/Unit/FrontendChangesTest.php`: 019 adds several tests (concatenated
  `route()` args, optional-param template matching, extension-suffixed
  Wayfinder imports). Do not rely on test counts; all 019 tests must still
  pass after this plan.
- `src/Changes/ChangedSymbols.php` gains a null-head frontend guard from 019
  — out of scope here, no interaction.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Install | `composer install` | exit 0 |
| Focused tests | `vendor/bin/phpunit --filter 'FrontendReferenceScannerTest\|FrontendChangesTest'` | OK, 0 failures |
| Full suite | `composer test` | `"result":"passed"` |
| Static analysis | `composer phpstan` | exit 0 |
| Style (check) | `vendor/bin/pint --test` | exit 0 |

## Suggested executor toolkit

- Skill `test-writing` for the new pinned negatives (each documents a
  deliberate exclusion, so the comment explaining *why* is load-bearing).
- Skill `backend-quality` for closing checks.

## Scope

**In scope** (the only files you should modify):
- `src/Tracers/FrontendReferenceScanner.php` (`uriCandidates()` and its
  docblock only)
- `src/Changes/FrontendChanges.php` (`handles()` and its docblock only)
- `src/Support/RichterConfig.php` (`frontendGeneratedPaths()` default only)
- `config/richter.php` (frontend block: default + comment)
- `README.md` (frontend section + config table row)
- `tests/Unit/FrontendReferenceScannerTest.php`
- `tests/Unit/FrontendChangesTest.php`

**Out of scope** (do NOT touch, even though they look related):
- `scan()`'s route-name and `unresolved` logic in
  `FrontendReferenceScanner.php` — plan 019 owns the detector; plan 032 owns
  constant resolution.
- `src/Analysis/FrontendTestIndex.php` — spec discovery is name-pattern-based
  (`*.test.*` etc.), so `.d.ts`/generated files never enter it; it inherits
  the scanner's anchoring automatically through `routeNodesIn()`.
- `src/Analysis/ImpactAnalyzer.php`, `src/Console/AffectedTestsCommand.php` —
  downstream of the fix.
- `route()`/Wayfinder reference detection — this plan narrows only the
  literal-URI surface.

## Git workflow

- Branch: `advisor/031-uri-anchoring-and-generated-file-exclusion`
- Commit per step pair (test + fix), imperative sentence subjects, e.g.
  `Anchor literal-URI candidates to call-argument position`.
- If the repository has commit signing enabled, never fall back to an
  unsigned commit. (None was configured at planning time.)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Pin the anchored-candidate contract (failing tests first)

In `tests/Unit/FrontendReferenceScannerTest.php` (model after the existing
URI tests at lines 188–264; `#[Test]`, snake_case):

- `a_string_literal_outside_call_argument_position_is_not_a_uri_candidate`:

  ```ts
  const API = '/api/videos';
  const NAV = [{ href: '/videos' }];
  export default { uri: "/videos/9" };
  ```

  → `uris === []`. Comment why: assignments, object-property values, and
  array heads are data/navigation, not endpoint calls — the flood surface the
  hihaho handoff demonstrated.
- `a_literal_in_second_argument_position_is_still_a_candidate`:
  `request('GET', '/videos');` → one candidate `['uri' => '/videos',
  'method' => null]` (the `request(method, url)` wrapper idiom).
- `a_template_literal_outside_call_argument_position_is_not_a_candidate`:
  `` const t = `/videos/${id}`; `` → `uris === []` (the called form
  `` fetch(`/videos/${id}`) `` is already pinned at lines 230–241).
- `an_options_object_url_property_is_a_documented_recall_loss`:
  `axios({ url: '/videos', method: 'post' });` → `uris === []`, with a
  comment that this idiom is deliberately traded away (property position is
  indistinguishable from data).

In `tests/Unit/FrontendChangesTest.php` (model after
`a_non_endpoint_literal_matches_no_template_and_is_not_a_reference`,
lines 193–203):

- `a_data_file_of_route_matching_literals_seeds_nothing`: resolve a source
  like `const LINKS = { videos: '/videos', video: '/videos/9' };` → empty
  `directSeeds`, `unresolvedFrontendReferences === false`. (Today this seeds
  `route::POST::/videos`, `route::GET::/videos`, and
  `route::GET::/videos/{video}` — the false-positive flood in miniature.)

**Verify**: `vendor/bin/phpunit --filter 'FrontendReferenceScannerTest|FrontendChangesTest'`
→ exactly the new tests fail; all pre-existing tests pass.

### Step 2: Anchor `uriCandidates()` to call-argument position

In `src/Tracers/FrontendReferenceScanner.php::uriCandidates()`, change only
the *prefix* of each pattern — the literal core and capture-group numbering
stay identical (verb = group 1, uri = group 2):

```php
preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(|[(,])\s*[\'"](\/[^\'"\s?#]*)[?#]?[^\'"]*[\'"]/i', $source, $literals, PREG_SET_ORDER);
```

```php
preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(|[(,])\s*`(\/[^`]*)`/i', $source, $templates, PREG_SET_ORDER);
```

Semantics: a candidate must sit directly inside a call — first argument
(preceded by `(`, verb-named or not) or a later argument (preceded by `,`).
The verb alternative stays first so a verb-named wrapper still pins the
method; leftmost matching guarantees `axios.post('/x')` enters through the
verb branch, never the bare-`(` branch. `fetch()`/wrapper calls keep
`method: null` — uncertainty never narrows, unchanged.

Rewrite the docblock (currently lines 76–83): state the call-argument anchor,
name the two documented recall losses (a `/`-literal assigned to a variable
and fetched later; a `{url: '/x'}` options-object property), state the known
residual (an array tail — `['/a', '/b']` — still matches its comma-anchored
elements), and record that this supersedes the spike's "literal-URI
over-matching is accepted" decision on consumer evidence (false
affected-tests selection from data/constants/generated files).

**Verify**: `vendor/bin/phpunit --filter 'FrontendReferenceScannerTest|FrontendChangesTest'`
→ 0 failures (Step 1's tests now pass; every pre-existing test still passes,
including `a_verb_named_call_pins_the_http_method_and_wrappers_stay_unpinned`
and `inline_uri_seeds_map_script_literals_only`).

### Step 3: Pin file and glob exclusion in `handles()` (failing tests first)

In `tests/Unit/FrontendChangesTest.php` (model after
`handles_excludes_wayfinder_generated_trees`, lines 49–58):

- `handles_excludes_generated_files_and_globs`:
  - `config()->set('richter.frontend.generated_paths', ['actions', 'ziggy.js', '*.generated.ts'])`
  - `handles('resources/js/ziggy.js')` → `false` (exact file under the root)
  - `handles('resources/js/api.generated.ts')` → `false` (glob)
  - `handles('resources/js/deep/nested/api.generated.ts')` → `false` (the
    glob's `*` crosses `/` — document in the test comment)
  - `handles('resources/js/actions/Foo.ts')` → `false` (directory entries
    keep working)
  - `handles('resources/js/lib/api.ts')` → `true` (non-matching files still
    scan)
- `handles_excludes_ziggy_output_by_default` (no `generated_paths` override —
  exercises the new default from Step 5):
  `handles('resources/js/ziggy.js')` → `false`.

**Verify**: `vendor/bin/phpunit --filter FrontendChangesTest` → exactly the
new tests fail.

### Step 4: Implement file/glob matching in `handles()`

In `src/Changes/FrontendChanges.php:58`, extend the exclusion so each entry
matches as a directory prefix (existing) **or** as an exact relative
file/glob via `Illuminate\Support\Str::is()` (import `Str`; already a
package dependency through `illuminate/support`). Target shape:

```php
return array_all(RichterConfig::frontendGeneratedPaths(), function (string $generated) use ($file, $root): bool {
    $entry = trim($generated, '/');

    // Directory-prefix semantics (the original contract), plus exact files and
    // `*`-globs via Str::is — a generated file directly under a root
    // (ziggy.js) was inexpressible before. Str::is with no wildcard is exact
    // equality; its `*` crosses `/`, so `*.generated.ts` matches at any depth.
    return ! str_starts_with($file, "{$root}/{$entry}/") && ! Str::is("{$root}/{$entry}", $file);
});
```

Update the `handles()` docblock (line 38) to mention files/globs alongside
the generated trees.

**Verify**: `vendor/bin/phpunit --filter FrontendChangesTest` → Step 3's
first test passes (the default-Ziggy test still fails until Step 5);
`handles_excludes_wayfinder_generated_trees` still passes (including the
`resources/js/Pages/actions.ts` still-scans assertion — `Str::is('resources/js/actions', …)`
does not match it).

### Step 5: Reject `.d.ts` and add `ziggy.js` to the defaults

Failing test first, in `tests/Unit/FrontendChangesTest.php`:

- `handles_rejects_declaration_files`:
  `handles('resources/js/ziggy.d.ts')` → `false`;
  `handles('resources/js/types/api.d.ts')` → `false`.

Then:

1. In `src/Changes/FrontendChanges.php:43`, reject declaration files in the
   extension gate — `pathinfo()` reports `ts` for them, but a `.d.ts` carries
   types only, no executable endpoint calls; scanning it is pure
   false-positive surface (route-name string-literal-union types), and a
   determined "no impact" for a type-only change is honest, not
   under-reporting:

   ```php
   if ($roots === [] || str_ends_with(strtolower($file), '.d.ts') || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, strict: true)) {
       return false;
   }
   ```

2. Add `'ziggy.js'` to **both** default lists — `config/richter.php:34` and
   the fallback in `src/Support/RichterConfig.php:48`:

   ```php
   'generated_paths' => ['actions', 'routes', 'wayfinder', 'ziggy.js'],
   ```

   Do not add `ziggy.d.ts` — the `.d.ts` gate covers it at the code level for
   every consumer, including those who published the config before this
   change. Update the config comment (`config/richter.php:26-31`) to say the
   entries match directories, exact files, or `*`-globs, and that Ziggy's
   generated map is excluded by default alongside Wayfinder's trees.

**Verify**: `vendor/bin/phpunit --filter FrontendChangesTest` → 0 failures
(all Step 3 and Step 5 tests pass).

### Step 6: Update the README

Behavior changed in two documented places (README is the consumer contract):

- Config table row at README.md:375: defaults become `actions`, `routes`,
  `wayfinder`, `ziggy.js`; description covers directories, exact files, and
  `*`-globs, plus the automatic `.d.ts` exclusion.
- The frontend section prose (README.md:289 region): state the literal-URI
  rule — a `/`-leading string or backtick template is scanned as an endpoint
  reference **only in call-argument position**, and name the documented
  recall losses (variable-assigned URLs, `{url: …}` options objects). This is
  the adopter-facing half the handoff asked for.

**Verify**: `grep -n "ziggy.js" README.md` → the config table row;
`vendor/bin/pint --test` → exit 0 (README untouched by Pint, but run it with
the code changes staged).

### Step 7: Full regression

**Verify**: `composer test` → `"result":"passed"`; `composer phpstan` →
exit 0; `vendor/bin/pint --test` → exit 0.

## Test plan

- Step 1: four scanner-level negatives/positives pinning the call-argument
  anchor (assignment, object property, array head, second-argument position,
  uncalled template, options-object loss) + one FrontendChanges end-to-end
  data-file test.
- Step 3/5: file exclusion, glob exclusion (incl. depth-crossing `*`),
  directory regression, default-Ziggy exclusion, `.d.ts` rejection.
- Pattern: model after the sibling tests named in each step; `#[Test]`,
  snake_case, one behavior per test, comments carrying the "why".
- Verification: `composer test` → `"result":"passed"` including all plan-019
  tests.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] All new failing tests written first, now passing
- [ ] `composer test` → `"result":"passed"`
- [ ] `composer phpstan` → exit 0
- [ ] `vendor/bin/pint --test` → exit 0
- [ ] `grep -n "Str::is" src/Changes/FrontendChanges.php` → the new exclusion branch
- [ ] `grep -n "d.ts" src/Changes/FrontendChanges.php` → the declaration-file gate
- [ ] `grep -rn "ziggy.js" config/richter.php src/Support/RichterConfig.php README.md` → all three defaults/doc updated
- [ ] `grep -cn "generated_paths" README.md` ≥ 1 and the table row lists the new default
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Drift beyond the "Expected drift from plan 019" list — in particular, if
  `uriCandidates()` or `handles()` no longer match the excerpts after
  accounting for 019.
- Any **pre-existing** scanner or FrontendChanges test fails after Step 2.
  The plan's compatibility claim ("no existing test pins a non-call-argument
  literal") would be wrong — that is a design signal, not a test to update.
- `inline_uri_seeds_map_script_literals_only` fails — the Blade inline lane
  was assumed to inherit the anchor without behavior change.
- The Step 4 shape cannot keep `resources/js/Pages/actions.ts` scanning
  (`handles_excludes_wayfinder_generated_trees`'s last assertion) — the
  exact-match branch would be over-excluding.
- Implementing any step appears to require touching `scan()`'s
  route-name/unresolved logic — that is plan 019/032 territory.

## Maintenance notes

- **Residual false-positive surface, accepted**: comma-anchored array tails —
  in `['/a', '/b']` the second element still matches (preceded by `,`).
  Dropping `,` from the anchor would lose the real `request(method, url)`
  second-argument idiom; the trade went to recall. Revisit only on new
  consumer evidence.
- **Documented recall losses**: `const URL = '/x'; fetch(URL)` and
  `axios({url: '/x'})` no longer produce candidates. If a consumer reports
  under-selection from these idioms, the answer is a targeted follow-up
  (e.g. same-module constant resolution for URLs, mirroring plan 032's
  route-name work) — not reverting the anchor.
- `Str::is`'s `*` crosses `/` (it compiles to `.*`). If path-segment-scoped
  globs are ever wanted, that is a new entry syntax, not a change to this one
  — consumers will have committed configs.
- Reviewer focus: the alternation order in the Step 2 regexes (verb branch
  first), and that group numbering did not shift.
- The spike doc's over-matching acceptance
  (`internal/spike-ts-backend-bridge.md`, local-only) is superseded by this
  plan; the in-repo record is the `uriCandidates()` docblock.
- Plan 032 edits `scan()` in the same scanner file — execute sequentially
  (031 then 032); expect at most trivial adjacent-hunk merges.
