# Plan 046: Harden and honestly document the HTML report's escaping invariant

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat afcefa1..HEAD -- src/Analysis/Html.php src/Analysis/HtmlFormatter.php src/Analysis/BlastDiagram.php src/Analysis/EditorLink.php tests/Unit/HtmlFormatterTest.php tests/Unit/EditorLinkTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW (a test addition, one consistency edit that is inert for current
  values, and comment corrections — no behavior change to shipped output)
- **Depends on**: none
- **Category**: tests / tech-debt / docs
- **Planned at**: commit `afcefa1`, 2026-07-22

## Why this matters

The `--html` report is **safe today** — a security audit traced every
interpolated value and found no XSS or injection hole. This plan closes three
gaps that make that safety *fragile to future edits*, not broken now:

1. **The one context the audit flagged as semantically different — the editor
   link `href` (a URL, not HTML body) — has zero adversarial test coverage.**
   Its safety rests entirely on `rawurlencode` in `EditorLink::url()`. A refactor
   that weakened that would keep the whole suite green.
2. **`BlastDiagram` writes several SVG attributes without the central
   `Html::e()` helper**, contradicting the class's own stated invariant ("no
   structurally-safe exception list — run everything through `e()`"). The values
   are type-constrained enums/numbers so it's safe now, but the silent exception
   is exactly how a future non-constrained value lands unescaped.
3. **The code comments and commit `cc1cabb` claim the href is "covered by the
   same [`Html::e`] rule as every other value."** That conflates HTML-escaping
   with URL-escaping — the exact conflation the audit warns about. The href's
   URL safety comes from `rawurlencode` + a fixed scheme allow-list in
   `EditorLink`, not from `Html::e`. A maintainer trusting the comment could
   remove `rawurlencode` believing the central helper still protects them.

## Current state

- `src/Analysis/Html.php` — the central escape helper. `Html::e()` (line 18) is
  `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. `Html::link()`
  (lines 99-104) wraps inner HTML in `<a href="' . self::e($url) . '">` — note
  it HTML-escapes the URL, which is correct for the *attribute* layer but is not
  what makes the URL itself safe.

- `src/Analysis/EditorLink.php` — builds the href. `url()` (lines 51-61) is the
  load-bearing URL safety:

  ```php
  public function url(string $relativeFile, ?int $line): string
  {
      $absolute = str_replace('\\', '/', $this->basePath) . '/' . ltrim(str_replace('\\', '/', $relativeFile), '/');
      return strtr($this->scheme, [
          '{file}' => str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode($absolute)),
          '{line}' => (string) ($line ?? 1),
      ]);
  }
  ```

  `$this->scheme` comes from a fixed `SCHEMES` const map keyed by editor name
  (`fromConfig()`, lines 39-48) — an unknown editor name returns `null` (plain
  text, no link), so a `javascript:` scheme cannot be injected via config.

- `src/Analysis/BlastDiagram.php` — the SVG renderer. Attributes written
  **without** `Html::e`:
  - line 46: `<circle class="ring" cx="' . $layout['centre'] . '" ... r="' . $ring['r'] . '"/>` (numbers)
  - lines 50-51: `<line x1="' . $edge['x1'] . '" ... y2="' . $edge['y2'] . '" data-from="' . Html::e($edge['source']) . '" ...` (coords raw; the `data-*` ARE escaped)
  - line 67: `<circle class="n-' . $node['kind'] . '" cx="' . $node['x'] . '" cy="' . $node['y'] . '" r="6"` (`kind` is the enum union `'seed'|'impacted'|'association'`; coords are `round()`ed floats)
  - line 73: `data-depth="' . $node['depth'] . '"` (int)

  Every one of these values is type-guaranteed (a `RadialLayout` enum `kind` or a
  numeric coordinate) — safe today, but a silent exception to line 15-16's stated
  rule.

- `src/Analysis/HtmlFormatter.php` — docblock lines 14-16:
  "Every interpolated value is project-derived and untrusted … there is no
  structurally-safe exception list — run everything through `e()`." This is the
  invariant BlastDiagram silently breaks.

- **The existing adversarial test**, `tests/Unit/HtmlFormatterTest.php:249-293`
  (`untrusted_project_data_is_escaped_everywhere_it_is_interpolated`), runs with
  **no editor configured**, so `EditorLink` is null and no value ever reaches an
  `href`. `HtmlFormatter::detectChanges(array $result, array $changed, string $base, ?EditorLink $editor = null)`
  takes the editor as its 4th parameter (confirm the exact signature at
  `HtmlFormatter.php:33` before writing the test).

- **The existing href test**, `tests/Unit/EditorLinkTest.php`, covers a space
  (lines 77-84, `a_space_in_the_path_is_percent_encoded_but_slashes_are_kept`)
  but not `"`, `<`, `&`, `#`, or `?`. It uses a `url(...)` helper — confirm its
  signature at the top of the file.

## Commands you will need

| Purpose   | Command                                            | Expected on success |
|-----------|----------------------------------------------------|---------------------|
| Tests (targeted) | `vendor/bin/phpunit --filter EditorLink`     | all pass            |
| Tests (formatter) | `vendor/bin/phpunit tests/Unit/HtmlFormatterTest.php` | all pass |
| Full suite | `composer test`                                   | all pass            |
| Static analysis | `composer phpstan`                            | exit 0, no errors   |
| Style     | `vendor/bin/pint --test`                           | no style issues     |

## Scope

**In scope** (the only files you should modify):
- `tests/Unit/EditorLinkTest.php` — add adversarial-character href assertions.
- `tests/Unit/HtmlFormatterTest.php` — add an editor-configured hostile-path test.
- `src/Analysis/BlastDiagram.php` — resolve the silent SVG-attribute exception
  (Step 3 — pick ONE of the two options).
- `src/Analysis/HtmlFormatter.php` and/or `src/Analysis/Html.php` — correct the
  inaccurate escaping comment (Step 4).

**Out of scope** (do NOT touch, even though they look related):
- `EditorLink::url()`'s logic — it is correct; this plan tests it and documents
  *why* it's correct, but does not change it.
- The SVG snapshot test (`the_svg_is_a_stable_snapshot`,
  `HtmlFormatterTest.php:295`) — Step 3's option A (routing `kind`/numbers
  through `Html::e`) must NOT change the rendered bytes (escaping an enum word or
  a number is inert), so the snapshot must stay green untouched. If Step 3 would
  change the snapshot, you chose wrong — see STOP conditions.
- The commit message of `cc1cabb` — it is already in history; correct only the
  in-code comments.

## Git workflow

- Branch: `advisor/046-html-report-escaping-hardening`
- Commit style — conventional, matching `git log` (e.g. `cc1cabb`
  "Add a self-contained HTML report to detect-changes"). One commit per step or
  one for the lot is fine.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Adversarial href characters at the unit level

In `tests/Unit/EditorLinkTest.php`, add a test that pushes a path containing
`"`, `<`, `&`, `#`, `?`, and a space through `url()` and asserts the produced
URL contains none of those characters raw (each must be percent-encoded), while
`/` and a drive `:` stay readable (matching the existing space test's
expectations). Use the same `url(...)` helper the file already uses. Example
shape (adjust to the helper's real signature):

```php
#[Test]
public function url_metacharacters_in_the_path_are_percent_encoded(): void
{
    // The href is a URL context, not HTML body — rawurlencode (not Html::e) is what
    // keeps a '"', '&', or '<' in a filename from breaking out of the attribute or the query.
    $url = $this->url('phpstorm', '/app', 'app/Weird"<&>#?.php', 7);

    foreach (['"', '<', '>', '&', '#', '?'] as $meta) {
        $this->assertStringNotContainsString($meta, str_replace('phpstorm://open?file=', '', $url));
    }
    $this->assertStringContainsString('/app/app/', $url); // slashes kept readable
}
```

**Verify**: `vendor/bin/phpunit --filter url_metacharacters_in_the_path_are_percent_encoded`
→ 1 passing.

### Step 2: Hostile path through the real href, editor configured

In `tests/Unit/HtmlFormatterTest.php`, add a test that calls
`HtmlFormatter::detectChanges(...)` **with an `EditorLink` configured** (build it
via `EditorLink::fromConfig('phpstorm', '/base')` — confirm the factory name at
`EditorLink.php:39`) and a changed-file path carrying `"`, `<`, and `&`. Assert:
the output contains an `<a class="ref" href="phpstorm://open?file=` link (proving
the href path was exercised), and that inside that href attribute there is no raw
`"`, `<`, or unescaped `&` breaking the attribute. Model the fixture shape on the
existing `untrusted_project_data_is_escaped_everywhere_it_is_interpolated` test
(lines 249-293) — reuse its `$result`/`$changed` array shapes; the only
additions are the 4th `$editor` argument and href-specific assertions.

Because the `href` value passes through **both** `rawurlencode` (URL layer) and
`Html::e` (attribute layer), the metacharacters appear as their percent- or
HTML-encoded forms, never raw. Assert accordingly (e.g.
`assertStringNotContainsString('file=' . '...raw quote...', $html)` framed so a
raw `"` inside the `href="..."` value would fail).

**Verify**: `vendor/bin/phpunit tests/Unit/HtmlFormatterTest.php` → all pass,
including the new test.

### Step 3: Resolve the silent SVG-attribute exception in BlastDiagram

Pick **exactly one** option and apply it consistently:

- **Option A (preferred — uniformity):** route the currently-raw attribute
  values through `Html::e()` for consistency with the class invariant:
  `class="n-' . Html::e($node['kind']) . '"`, and wrap the numeric coordinate
  interpolations (`cx`, `cy`, `r`, `x1`..`y2`, `data-depth`, ring `cx`/`cy`/`r`)
  in `Html::e((string) $value)`. This is **inert** for current values (escaping
  an enum word or a numeric string changes nothing), so the SVG snapshot test
  must stay byte-identical. If it doesn't, STOP.

- **Option B (document the exemption):** if wrapping numerics reads as noise to
  you, instead add a single explicit comment above the `node()` and `svg()`
  attribute writes stating these values are exempt from `Html::e` *because* they
  are type-guaranteed enums (`RadialLayout::kinds()`) and `round()`ed numerics —
  so the exemption is intentional and reviewable, not accidental. Do NOT leave it
  undocumented.

Prefer Option A. Only choose B if A would touch the snapshot (it should not).

**Verify**:
- `vendor/bin/phpunit tests/Unit/HtmlFormatterTest.php` → all pass, **snapshot
  test unchanged** (Option A must not alter rendered bytes).
- `composer phpstan` → exit 0.

### Step 4: Correct the escaping-rationale comments

Fix the inaccurate claim that the href is "covered by the same `Html::e` rule."
Two touch points:

- `src/Analysis/Html.php` — at `link()` (around line 95-98), note that
  `Html::e($url)` is the **HTML-attribute** layer only; the URL's own safety
  (scheme allow-list + `rawurlencode`) is established in `EditorLink::url()`.
- `src/Analysis/HtmlFormatter.php` — the docblock at lines 14-16 says "run
  everything through `e()`." Keep that (it's true for HTML-body/attribute
  values), but add one clause noting the editor `href` additionally relies on
  `EditorLink`'s `rawurlencode` + scheme allow-list for URL-context safety, so
  `e()` is not the load-bearing layer there.

Keep the edits to comments/docblocks only — no code change in this step. Follow
the repo's existing docblock voice (full sentences, explains the *why*).

**Verify**: `composer phpstan` → exit 0 (docblock `{@see}` references must
resolve).

### Step 5: Full verification

**Verify**:
- `composer test` → all pass (report the total; it was 700 before this plan,
  +2 new tests expected).
- `vendor/bin/pint --test` → no style issues.
- `composer phpstan` → exit 0.

## Test plan

- New test in `tests/Unit/EditorLinkTest.php`:
  `url_metacharacters_in_the_path_are_percent_encoded` — `"`/`<`/`>`/`&`/`#`/`?`
  percent-encoded, `/` kept.
- New test in `tests/Unit/HtmlFormatterTest.php`: hostile path through a
  **configured** editor, asserting the `href` was exercised and carries no raw
  metacharacter.
- Structural patterns: `a_space_in_the_path_is_percent_encoded_but_slashes_are_kept`
  (`EditorLinkTest.php:77`) and
  `untrusted_project_data_is_escaped_everywhere_it_is_interpolated`
  (`HtmlFormatterTest.php:249`).
- Verification: `composer test` → all pass, +2 new tests.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer phpstan` exits 0, no errors
- [ ] `vendor/bin/pint --test` reports no style issues
- [ ] `composer test` exits 0; the 2 new tests exist and pass; SVG snapshot test
      still passes unchanged
- [ ] The href now has adversarial coverage at both the unit (`EditorLink`) and
      integration (`HtmlFormatter` with editor) levels
- [ ] No comment in `Html.php`/`HtmlFormatter.php` still claims the href is made
      safe by `Html::e` alone (`grep -rn "same rule\|covered by" src/Analysis/Html.php src/Analysis/HtmlFormatter.php` returns nothing implying URL safety from `e()`)
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row for plan 046 updated to DONE

## STOP conditions

Stop and report back (do not improvise) if:

- Option A in Step 3 changes the SVG snapshot bytes — that means a value you
  wrapped is not actually inert under `Html::e` (a real escaping change), which
  is a signal worth surfacing, not silently snapshot-updating.
- `HtmlFormatter::detectChanges`'s signature does not take an `?EditorLink` as
  documented (drift) — do not guess how to inject the editor.
- The new href test passes even when you deliberately break `rawurlencode` in
  `EditorLink::url()` (as a throwaway check) — that means the test isn't
  actually reaching the URL layer; fix the test before proceeding.
- Any excerpt in "Current state" doesn't match live code.

## Maintenance notes

- The load-bearing URL-context protection is `EditorLink::url()`'s `rawurlencode`
  + the fixed `SCHEMES` allow-list. A reviewer of any future `EditorLink` change
  must treat those two as the security boundary — `Html::e` is a second,
  HTML-attribute layer, not a substitute.
- If a new editor scheme is added to `SCHEMES`, confirm `{file}` sits in a
  position where percent-encoding is sufficient (a query value or path segment),
  not somewhere a scheme/host could be spoofed.
- Deferred: no change to the `--html` output bytes is intended by this plan; it
  is a coverage + documentation hardening pass.
