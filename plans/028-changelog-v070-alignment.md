# Plan 028: Align the v0.7.0 CHANGELOG entry with the release notes

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 822a3c8..HEAD -- CHANGELOG.md`
> If CHANGELOG.md changed since this plan was written, compare the
> "Current state" excerpts against the live file before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: commit `822a3c8`, 2026-07-19

## Why this matters

The v0.7.0 CHANGELOG entry jumps straight from the version heading to
`### Added`, dropping the one-sentence lead the release notes carry (and that
the v0.6.0 entry has), so Packagist/GitHub CHANGELOG readers get a less
legible entry than release readers. The entry also carries a different
`verified-sha` (`3fff0c5…`) than the local release-notes file (`1b7c00d…`) —
the CHANGELOG was CI-copied from the release body as published, while the
notes file was updated afterwards. That mismatch means the published release
body may predate the final notes content; it needs a human decision, not a
silent rewrite.

**Repo rule that governs this plan** (from the project instructions):
"CHANGELOG is CI-managed … Don't hand-edit `CHANGELOG.md` as part of a
release. Post-release typo fixes are committed directly." This is a
post-release editorial fix — the allowed category — but the *published GitHub
release body* is outward-facing and stays the maintainer's to change.

## Current state

- `CHANGELOG.md:8-11`:

  ```markdown
  ## v0.7.0 - 2026-07-19

  <!-- verified-sha: 3fff0c5627094e4e0c6eb1834bb8cf10e602020d -->
  ### Added
  ```

- `internal/release-notes-0.7.0.md:1-7` (note: `internal/` is gitignored —
  read it locally; it exists on this machine):

  ```markdown
  <!-- verified-sha: 1b7c00d3f94e63793bb4b44a4f4ac4639e16fc65 -->

  # v0.7.0

  The report crosses the stack boundary: changed frontend files report the backend endpoints they touch, and changed backend code reports the Inertia pages it renders.

  ## Added
  ```

- The v0.6.0 entry keeps its lead line (see `CHANGELOG.md` around line 31) —
  the style to match.
- `git log` shows `3fff0c5` ("Apply Rector refactors and list the frontend
  bridge under coverage") precedes `1b7c00d` ("Restructure the
  frontend-changes README section").

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Compare bodies | `diff <(tail -n +3 internal/release-notes-0.7.0.md) <(sed -n '/## v0.7.0/,/## v0.6.0/p' CHANGELOG.md)` | informational — establish what differs beyond the lead line |
| Published body | `gh release view 0.7.0 --json body -q .body \| head -20` | informational |

## Scope

**In scope** (the only file you should modify):
- `CHANGELOG.md` (the v0.7.0 section only)

**Out of scope** (do NOT touch):
- `internal/release-notes-0.7.0.md` — the source of truth for what was
  released; never retro-edit it.
- The published GitHub release body — outward-facing; if it needs updating,
  that is the maintainer's call (report the diff, step 2).
- Any other CHANGELOG section.

## Git workflow

- Branch: `advisor/028-changelog-v070-alignment` (or commit directly to main
  if the operator's convention for typo-fix commits allows — ask nothing;
  branch is the safe default).
- Commit style: imperative subject, e.g. `Carry the v0.7.0 lead line into the CHANGELOG`.
- If the repository has commit signing enabled, never fall back to an unsigned commit.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Establish the exact delta

Run both commands from the table. Record: (a) whether the published release
body already contains the lead sentence, (b) any other content difference
between the notes file and the CHANGELOG section beyond the lead line and the
sha comment.

**Verify**: you can state the delta precisely (lead line only, or more).

### Step 2: Edit the CHANGELOG section

Insert the lead sentence between the sha comment and `### Added`, matching
v0.6.0's layout:

```markdown
## v0.7.0 - 2026-07-19

<!-- verified-sha: 3fff0c5627094e4e0c6eb1834bb8cf10e602020d -->
The report crosses the stack boundary: changed frontend files report the backend endpoints they touch, and changed backend code reports the Inertia pages it renders.

### Added
```

Leave the `verified-sha` value **unchanged** — it records what the release
was verified against at publish time; rewriting it would falsify the record.
If step 1 found further content drift (beyond the lead line), apply only
what makes the CHANGELOG match the *published release body*, and list any
notes-file-vs-published-body differences in your final report for the
maintainer to reconcile on the GitHub release.

**Verify**: `sed -n '/## v0.7.0/,/### Added/p' CHANGELOG.md` shows heading →
sha comment → lead sentence → `### Added`.

## Test plan

- None (docs-only). The verification is the sed check plus a clean
  `git diff` showing only the intended insertion.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] The v0.7.0 section contains the lead sentence before `### Added`
- [ ] The `verified-sha` line is byte-identical to before (`git diff` shows no change on it)
- [ ] `git diff --stat` touches only `CHANGELOG.md`
- [ ] The final report states whether the published GitHub release body matches the notes file, and lists any residual diff
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- `gh release view 0.7.0` shows a body that differs from BOTH the notes file
  and the CHANGELOG in substance (not just the lead line) — three diverging
  artifacts need the maintainer, not an editor.
- The CHANGELOG's v0.7.0 section was already fixed (drift) — nothing to do;
  mark the plan REJECTED with that note.

## Maintenance notes

- Process observation for the maintainer (not this executor's to fix): the
  sha mismatch happened because the notes file was re-verified after the
  release body was published. The `pre-release` skill's gate could catch
  this by comparing the notes-file sha against the tag target at publish
  time.
