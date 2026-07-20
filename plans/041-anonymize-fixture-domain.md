# Plan 041: Migrate the test/fixture domain off the consumer's product vocabulary

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git grep -Iil 'Video' -- src/ tests/ | wc -l`
> Record the count before starting; it is the migration surface. If it is far
> from ~55 files, the tree has changed materially since planning — re-derive
> the mapping coverage before proceeding.

## Status

- **Priority**: P2 (the `src/` half ships in the dist archive — a real anonymization
  leak in the installed package; the `tests/` half is VCS-only but public on GitHub)
- **Effort**: L (≈900 occurrences across ~55 files; mechanical but semantics-preserving)
- **Risk**: MED (a mis-scoped rename breaks fixtures or golden assertions; must run the
  full suite after every cluster and keep each rename word-boundary-clean)
- **Depends on**: none; **merge-adjacent to 038/039/040** (they touch test files too — see
  Ordering)
- **Category**: anonymization / compliance (maintainer instruction; evaluate fixture-anonymization ethos)
- **Planned at**: commit `2d8a437`, 2026-07-20
- **Governing standard**: `.ai/guidelines/anonymize-public-examples.md` (added in the tree
  at planning time) — this plan *executes* that guideline against the existing surface.
  Follow the guideline's "Anonymize these" / "Keep these" lists verbatim; the mapping
  table below is the concrete substitution set for the terms already present.

## Why this matters

The existing fixtures use a video-platform vocabulary — `Video` (639× in `tests/`, ~16×
in `src/`), `Playlist`, `Interaction`, `VideoContainer`, `SuperVideo`, `Theme`,
`Question`, `FeatureFlag`, `videos.show`, `/videos/{video}` — that mirrors the reporting
consumer's actual product domain. `src/` ships in the Composer dist archive, so those
references distribute to every downstream consumer; `tests/` is typically `export-ignore`d
from the archive but lives in a public repo. Neither should carry a real downstream app's
domain. This plan replaces the vocabulary with a neutral, clearly-fictional domain — the
same one plans 036–040 already use for their new fixtures, so the whole suite ends up on
one consistent vocabulary.

This is not a behaviour change: every fixture's **shape** (edge types, relation kinds,
route params, assertion structure) is preserved; only the **names** change.

## Canonical mapping (apply exactly; case-preserving)

| Consumer term | Neutral replacement | Notes |
|---|---|---|
| `Video` | `Post` | covers `VideoController`→`PostController`, `VideoContainer`→`PostContainer`, `SuperVideo`→`SuperPost`, `VideoPublisher`→`PostPublisher`, `VideoApi`→`PostApi`, `ProcessVideoJob`→`ProcessPostJob` |
| `video` (lower) | `post` | route params `{video}`→`{post}`, URIs `/videos`→`/posts` |
| `Playlist` | `Category` | single-segment route noun |
| `Interaction` | `Comment` | relation `interactions()`→`comments()`; `interactions`→`comments` |
| `Question` | `Review` | `QuestionController`→`ReviewController` |
| `Theme` | `Tag` | |
| `FeatureFlag` | `FeatureToggle` | aligns with plan 039's fixtures |
| `videos.` (routes) | `posts.` | `videos.show`→`posts.show`, `.player`/`.store`/`.edit` follow |

Leave already-neutral placeholders unchanged: `User`, `Team`, `ImportJob`, `BarJob`,
`OtherJob`, `FooController`, `ErrorController`, `Settings`, single-letter fixtures
(`C`, `X`), `Nonexistent`. If a term not in this table looks consumer-specific, STOP and
ask rather than guessing a replacement.

**Word-boundary discipline.** `Video` is never a substring of an unrelated identifier in
this codebase, so a case-aware whole-token replacement is safe — but verify per file, and
never let `video`→`post` collide with an existing HTTP `post` verb (the replace direction
only *introduces* `post` tokens from `video`; existing `->post(`/`'post'` method tokens are
untouched — confirm no test now reads ambiguously and, if one does, disambiguate the
fixture name locally).

## Ordering

- 038/039/040 introduce **new** fixtures already in the neutral vocabulary, so they do not
  depend on 041. Run 041 **after** they integrate (or in a clean window) so it only
  rewrites pre-existing references, not freshly-added neutral ones. Expect adjacent-hunk
  merges in the shared test files, never semantic conflicts.
- 041 does not depend on 036/037.

## Current state (scope — confirm against live code)

- `git grep -Iil 'Video\|Playlist\|Interaction\|Theme\|FeatureFlag\|Question' -- src/ tests/`
  is the file list. **Exclude `.claude/worktrees/`** (stale agent worktrees, not the tracked tree).
- `src/` occurrences (dist-shipped — do these FIRST): ~16 `Video`, and one each of
  `Playlist`/`VideoContainer`/`SuperVideo`/`Theme`/`FeatureFlag`/`Question`. Most are
  docblock examples / `CodeSample`-style FQCNs. Enumerate with
  `git grep -Iin 'Video\|Playlist\|Interaction\|Theme\|FeatureFlag\|Question' -- src/`.
- `tests/Fixtures/` — fixture PHP files (rename files AND their class names AND every
  reference, keeping PSR-4/paths consistent).
- Inline fixtures — heredoc/string sources and assertion literals across ~55 test files.
- `README.md` — if any example uses the video vocabulary, migrate it too (README ships in the repo).
- `specs/detect-changes-json-and-fail-on-gating.md` — carries domain-term hits; the
  guideline lists `specs/` as a leak vector (provenance too — scrub any consumer/PR/person
  references, not only nouns).
- **Direct consumer-name leak (must-fix):** `tests/Unit/FrontendReferenceScannerTest.php`
  contains the literal string `hihaho`. That is worse than a domain-noun leak — replace it
  with a neutral placeholder (a generic route/host), and grep the whole tree for other
  literal `hihaho` occurrences in shipped/public surfaces.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Full suite | `composer test` | `"result":"passed"` (run after EVERY cluster) |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | exit 0 / 0 / 0 changed |
| Leak check (final gate) | `git grep -Iin 'Video\|Playlist\|Interaction\|VideoContainer\|SuperVideo\|\bTheme\b\|FeatureFlag\|\bQuestion\|hihaho' -- src/ tests/ specs/ README.md ':!.claude'` | **no matches** |

## Scope

**In scope:** every tracked file under `src/`, `tests/`, `specs/`, and `README.md`
carrying a mapped term or a literal consumer name (excluding `.claude/`).

**Out of scope:** `.claude/worktrees/*` (stale), `vendor/`, generated caches. Behaviour of
any production code — this is names only.

## Git workflow

- Branch `advisor/041-anonymize-fixture-domain` from the local main tip.
- Commit in clusters so history stays reviewable and bisectable:
  1. `src/` (dist-shipped) — the highest-value commit on its own.
  2. `tests/Fixtures/` file + class renames.
  3. Inline test-source and assertion renames, in a few thematic commits (frontend,
     analyzer, formatters, …), full suite green after each.
  4. `README.md`.
  No signing. End messages with:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Do NOT push or open a PR.

## Steps

### Step 1: `src/` first (dist-shipped)

Enumerate and rewrite every mapped term in `src/` (docblocks, `CodeSample`/example FQCNs,
any inline strings). Run `composer test` + `phpstan` + `pint --test`. This commit alone
removes the leak from the installed package.

### Step 2: Fixture files under `tests/Fixtures/`

Rename files, class names, namespaces, and all references together. Keep PSR-4 autoload
paths consistent (a renamed fixture class must match its file path). `composer test` green.

### Step 3: Inline test sources + assertions (thematic clusters)

Work file-cluster by file-cluster (frontend scanner/changes; analyzer/detect-changes;
formatters/JSON/MCP; graph/tracers; console). After each cluster: `composer test` green.
Preserve fixture shape exactly — an edge-type/relation/route assertion keeps its structure,
only the noun changes (`Video::interactions` `loads-relation` → `Post::comments`
`loads-relation`).

### Step 4: README + final leak gate

Migrate any README example. Then the leak check command must return **no matches** across
`src/`, `tests/`, `README.md`. Full `composer test` / `phpstan` / `pint --test` /
`rector --dry-run` clean.

## Test plan

No new tests — the existing suite is the safety net. The migration is correct iff the full
suite stays green through every cluster and the final leak-gate grep is empty. If a rename
requires changing an assertion's *structure* (not just a noun), STOP — that means the
rename hit real logic, not a fixture name.

## Done criteria

- [ ] `src/` carries no mapped consumer term (dist archive clean) — its own commit
- [ ] `tests/`, `specs/`, and `README.md` carry no mapped consumer term
- [ ] The literal `hihaho` is gone from `tests/Unit/FrontendReferenceScannerTest.php` and every other shipped/public surface
- [ ] Final leak-gate grep returns no matches (excluding `.claude/`)
- [ ] `composer test` passed; `phpstan`/`pint --test`/`rector --dry-run` clean
- [ ] No production behaviour changed (diff is names/strings/docblocks only)
- [ ] `plans/README.md` row updated

## STOP conditions

- A term outside the mapping table looks consumer-specific — ask before inventing a replacement.
- A rename can only be made green by altering an assertion's structure (it hit logic, not a name).
- The `src/` occurrences turn out to be more than docblock/example references (e.g. a mapped
  term is a real public API symbol) — report before renaming a public symbol (semver surface).

## Maintenance notes

- After this lands, add the mapped consumer terms to any project fixture-anonymization
  `forbidden_terms` guard (the evaluate skill's gate) so a future fixture can't reintroduce
  them — a small config follow-up, not part of this rename.
- New fixtures (including 036–040's) must use only the neutral vocabulary; this plan makes
  that the single convention in the suite.
