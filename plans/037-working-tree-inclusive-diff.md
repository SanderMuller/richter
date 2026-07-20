# Plan 037: Make `HEAD` mode actually see the working tree — no silent false negatives before commit

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2d8a437..HEAD -- src/Changes/ChangedSymbols.php src/Console/DetectChangesCommand.php src/Console/AffectedTestsCommand.php README.md`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1 (a silent false negative in the exact interactive loop the feature accelerates)
- **Effort**: M
- **Risk**: MED (changes what diff `HEAD` mode analyses — a semantics change; the new set
  is the current working-tree net change vs base, not a superset of committed-only [a
  locally-reverted committed change is correctly excluded]; the historical-replay path must
  not change at all)
- **Depends on**: none (pairs naturally with 036 — both make `affected-tests` usable)
- **Category**: correctness + docs (consumer dogfood, Finding 2)
- **Planned at**: commit `2d8a437`, 2026-07-20

## Why this matters

`ChangedSymbols::resolve()` diffs `git diff -U0 <base>...HEAD` (three-dot =
merge-base..HEAD **commit tree**, committed only), but reads each changed file's head
**source from the working tree** (`file_get_contents(base_path($file))`), and its
docstring promises *"`HEAD` reads changed sources from the working tree (uncommitted
edits included)."* The promise is false. Two consequences, both confirmed live by the
reporting consumer:

1. **Silent false negative (dangerous).** A purely *uncommitted* behavioural edit
   produces an empty `<base>...HEAD` diff → `detect-changes` prints "No changed PHP
   files" and `affected-tests` returns 0 tests (exit 0). A developer running
   `affected-tests` **before committing** — the natural interactive loop — is told
   "nothing affected, run nothing" on real changes. This is exactly the under-selection
   the tool exists to prevent, and it hits the primary workflow.
2. **Hunk/source desync.** When uncommitted edits sit on top of committed ones, added/
   removed **line numbers** come from the committed tree while **member spans** come from
   the working-tree file — the two can drift, mis-mapping a hunk to the wrong member.

Both stem from one mismatch: the diff and the sources come from different trees.

## Design (decided — do not re-litigate)

**Align the diff with the sources: in `HEAD` mode, diff the working tree against the
merge-base.** `resolve()` already computes `$mergeBase`. For `head === 'HEAD'`, the diff
becomes `git diff -U0 <mergeBase>` (working tree vs merge-base) — which reads staged +
unstaged changes, exactly matching `headSource` (working tree) and `baseSource`
(merge-base). For any other `head` (historical replay, e.g. benchmark fix commits), keep
`<base>...<head>` unchanged. This makes the analysed set the **current working-tree net
change** relative to base (committed edits still present in the tree, plus uncommitted/
staged ones), and fixes the desync because both hunk lines and head source now come from
one tree. Note: this is *not* a strict superset of the committed-only `<base>...HEAD` set —
a committed change the working tree has locally reverted is (correctly) excluded, since it
is not part of the current state. The contract is "analyse the working tree"; CI gates on
clean checkouts where working-tree ≡ HEAD, so the two coincide there.

**Why the explicit-merge-base two-dot form, not `git diff --merge-base <base>`:**
`$mergeBase` is already resolved in `resolve()`, and `git diff <commit>` avoids depending
on the `--merge-base` diff flag (Git ≥ 2.30) across the CI matrix. Reorder so the
merge-base is computed **before** the diff, then build the diff argv conditionally.

**Docstring becomes true.** Update the `:17` docstring to describe the actual behaviour
("`HEAD` diffs the working tree against the merge-base with `<base>`, so uncommitted and
staged edits are included; any other ref replays that ref's committed tree via `<base>...<ref>`").

**Residual honesty gap — untracked files.** `git diff` never shows untracked (never
`git add`-ed) files, so a brand-new uncommitted file still won't appear. This is a
narrower gap than the fixed one, but to keep the fail-safe posture, emit a **stderr**
warning from the command layer when the working tree has untracked files git diff can't
see — never on stdout, so `--plain`/`--json` contracts stay intact (reuse the
`getOutput()->getErrorStyle()` pattern `--profile` established, `DetectChangesCommand`).
Scope the warning to untracked files under `app/` or the configured frontend/Blade roots
(a `git status --porcelain` filter) so unrelated untracked noise isn't reported.

**Exit-code contract unchanged.** `affected-tests` still exits 0 for determinable-empty
and 2 for undeterminable. Including working-tree edits only changes *what* is in the
diff, never the determinability logic.

## Current state (excerpts — confirm against live code)

- `src/Changes/ChangedSymbols.php`
  - `:17` docstring (the false promise).
  - `:25` `$diff = Process::path(base_path())->run(['git', '-c', 'core.quotepath=off', 'diff', '-U0', '--end-of-options', "{$base}...{$head}"]);`
  - `:31-37` merge-base resolution into `$mergeBase` (currently **after** the diff).
  - `:397-414` `headSource()` — for `'HEAD'` reads the working-tree file; otherwise
    `git show <head>:<file>`. **Leave this as-is** — it is already correct for both modes
    once the diff is aligned.
  - `:385-394` `baseSource()` — `git show <mergeBase>:<file>`. Unchanged.
- `src/Console/DetectChangesCommand.php` — has the stderr `getErrorStyle()` split from
  `--profile`; the untracked warning goes here.
- `src/Console/AffectedTestsCommand.php` — calls `ChangedSymbols::resolve($base)` at
  `:60`. If the untracked warning is shared, factor it so both commands can emit it
  without polluting `--plain`/`--json` stdout.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Focused | `vendor/bin/phpunit --filter 'ChangedSymbols|DetectChanges|AffectedTests'` | OK |
| Benchmark replay guard | `vendor/bin/phpunit --filter 'Benchmark'` | OK (replay path must be untouched) |
| Full suite | `composer test` | `"result":"passed"` |
| Static / style / rector | `composer phpstan` ; `vendor/bin/pint --test` ; `vendor/bin/rector process --dry-run` | exit 0 / exit 0 / 0 changed |

## Scope

**In scope:**
- `src/Changes/ChangedSymbols.php` (diff form + docstring; reorder merge-base first)
- `src/Console/DetectChangesCommand.php`, `src/Console/AffectedTestsCommand.php`
  (untracked-file stderr warning only)
- `README.md` (correct any "committed diff" wording that contradicts working-tree analysis)
- The `ChangedSymbols` test(s) and the two command feature tests (locate the git-fixture
  harness the committed-diff tests already use — the repo builds temp git repos for these)

**Out of scope:**
- `headSource()` / `baseSource()` internals — already correct.
- The historical-replay path (`head !== 'HEAD'`) — must be byte-for-byte unchanged.
- Determinability / exit-code logic in `AffectedTests`.

## Git workflow

- Branch `advisor/037-working-tree-inclusive-diff` from the local main tip; commit per
  logical unit (diff form + docstring; untracked warning; README). No signing. End with:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Do NOT push or open a PR.

## Fixtures & anonymization (MANDATORY)

Neutral domain only (plan 041's canonical vocabulary). The consumer's reproduction
("uncommitted behavioural edit to `IssuesPanel::refresh` → detect-changes says no changes;
committing it surfaces the entry point") maps to an uncommitted edit to
`App\Livewire\StatusPanel::refresh` (or `App\Http\Controllers\PostController::show`) in a
temp git-repo fixture.

## Steps

### Step 1: Pin current behaviour, then the gap (test-first)

In the `ChangedSymbols` test using the git-fixture harness:

1. Regression pin: a **committed** edit to `PostController::show` vs base → the member
   surfaces (today's behaviour; must still pass after the change).
2. Historical replay pin: `resolve($base, $someCommitSha)` still uses `<base>...<sha>` and
   returns the committed members of that range (guards the untouched path).
3. New (RED before the fix): a **purely uncommitted** edit to `PostController::show` →
   the member must surface. This fails today (empty `base...HEAD`).
4. New (RED before the fix): a committed edit plus an uncommitted edit on top in the same
   file → both members map to the correct spans (desync guard).

### Step 2: Align the diff

Reorder `resolve()` to compute `$mergeBase` first, then:
`$range = $head === 'HEAD' ? [$mergeBase] : ["{$base}...{$head}"];` and splice into the
git argv (keep `-c core.quotepath=off`, `-U0`, `--end-of-options`). Update the `:17`
docstring to the true description. Re-run Step 1 tests — 3 and 4 now pass, 1 and 2 still pass.

### Step 3: Untracked-file honesty warning (stderr)

Add a helper that runs `git status --porcelain`, filters to untracked (`??`) paths under
`app/` or the configured frontend/Blade roots, and — when non-empty — writes a one-line
warning to `getErrorStyle()` in both commands. Never to stdout. Test: with an untracked
`app/Models/Report.php` present, `--json` stdout stays a single parseable document and the
warning appears on stderr. (If wiring both commands cleanly is disproportionate, ship it
in `detect-changes` only and note the follow-up — the diff-form fix is the load-bearing
part.)

### Step 4: Full regression

`composer test` → passed; `composer phpstan` → 0; `vendor/bin/pint --test` → 0;
`vendor/bin/rector process --dry-run` → clean; benchmark tests green.

## Done criteria

- [ ] Uncommitted-only edit now surfaces (Step 1 case 3 green); committed + replay pins still green
- [ ] Desync case (Step 1 case 4) green
- [ ] Untracked warning on stderr only; `--json`/`--plain` stdout unchanged
- [ ] Docstring at `:17` matches behaviour; README carries no contradicting "committed diff" claim
- [ ] `composer test` / `phpstan` / `pint --test` / `rector --dry-run` all clean
- [ ] No out-of-scope files modified; `plans/README.md` row updated

## STOP conditions

- Drift vs the "Current state" excerpts.
- The historical-replay path can't be preserved unchanged while adding the `HEAD` branch.
- A benchmark replay test changes result (means the `head !== 'HEAD'` path was altered).
- The untracked warning cannot be emitted without touching `--plain`/`--json` stdout.

## Maintenance notes

- If a future need arises for a "committed only" mode, add it as an explicit flag rather
  than reverting the default — the interactive-loop false-negative is the reason the
  default is working-tree-inclusive.
- The untracked gap is fundamental to `git diff`; the warning is the honest mitigation.
  Do not try to synthesize untracked files into the diff (they have no base to diff against).
