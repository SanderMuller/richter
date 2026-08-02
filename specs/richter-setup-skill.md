<!-- spec:planned-at d863f5e 2026-08-02 -->

# An invoke-only `richter-setup` skill that configures a project for richter (config + opt-in CI)

## Overview

richter's report accuracy on a real app depends on **app-specific declarations** the defaults can't
know — which subsystems are entry surfaces (`entry_point_roots`), which custom helpers dispatch jobs
(`dispatch_helpers`), the repo's actual base branch (`default_base`), the frontend roots — plus a CI
workflow to post the advisory comment on PRs. That knowhow is currently only a README reference table,
and **no GitHub Action ships** with the package, so a new consumer either reads the whole config
surface and maps it to their architecture by hand, or (more often) runs richter under-configured and
hits the exact issues this project kept debugging: a subsystem reading `UNRESOLVED`, a dispatch through
an unrecognised helper missed, a report scoped against the wrong base branch.

Ship an **invoke-only skill** — `richter-setup` — that an agent runs to do this setup *with* the user:
inspect the project, **propose** `config/richter.php` edits (never silently written), and — only if the
user opts in — scaffold the CI advisory-comment workflow. Mirror the same guidance as **two
copy-paste prompt blocks in the README** (one config, one CI) so agents that don't sync the skill still
get it.

This is consistent with richter's "stay generic, don't app-tune the algorithm" stance: richter's
analysis is unchanged; the skill only helps the consumer *declare their app's shape* correctly.

## Shipping mechanism (researched — `vendor/` + boost-core)

- **Source:** `resources/boost/skills/richter-setup/SKILL.md` (directory form). `resources/` is **not**
  `export-ignore`d, so it ships in the Composer dist and boost-core's `VendorScanner` finds it
  (`vendor/sandermuller/boost-core/src/Discovery/VendorScanner.php` — convention path
  `resources/boost/skills`). **No `boost.php`, no `composer.json` change, no manifest** is required.
- **Invoke-only:** `disable-model-invocation: true` in the frontmatter (a native Claude Code Agent
  Skills field boost-core passes through verbatim) **plus** a `description` that states "invoke-only /
  command-only, never auto-activates". Templates: the vendored `clean-specs` and `migration-squash`
  skills.
- **Frontmatter:** `name: richter-setup` (must equal the directory name), a single-sentence
  `description`, optional `argument-hint`. **Leave untagged** (no `metadata.boost-tags`) so it ships to
  every consumer — adding a tag later is a consumer-breaking change.
- **Consumer reach (F3):** a **boost-core** consumer must add `sandermuller/richter` to
  `withAllowedVendors([...])` in their `boost.php`, then `boost sync`. A **laravel/boost** consumer
  discovers richter as a third-party AI package, but an *existing* install may need
  `boost:update` / package (re)selection for it to appear — not guaranteed-automatic. The README
  documents both paths, without over-claiming "automatic".

## What the skill does (workflow it guides the agent through)

1. **Read current state — READ-ONLY, no writes yet (F1).** Detect whether `config/richter.php` is
   published and read it if so. If it is NOT published, do **not** publish/create it as a side effect;
   surface that fact, state the exact publish/write plan, and fold it into the step-2 confirmation —
   the file is created only *with* the config the user approves, never before consent.
2. **Inspect + propose `config/richter.php`** — each proposal with a one-line WHY, then a single
   confirm before writing anything:
   - **`default_base`** — detect the repo's real default branch (`git symbolic-ref
     refs/remotes/origin/HEAD`, or the PR base) and set it; a wrong base silently mis-scopes every
     report. (A non-`main` default is common.)
   - **`entry_point_roots`** — find subsystems reached only through runtime/vendor dispatch that would
     read `UNRESOLVED`: form-builder `Form` subclasses (a `buildForm()` invoked by the library),
     registry-/factory-dispatched calculators. **Hedge against over-broadening (F6):** propose the
     **narrowest** dir, prefer *evidence* (files that actually come back `UNRESOLVED` from a
     `richter:detect-changes` run, or a clear framework convention), and **do not** add a broad root
     like `app/Actions` unless most classes there are genuinely entry surfaces — call out the
     false-positive risk (turning internal services into "entry points" inflates reach).
   - **`dispatch_helpers`** — find global functions that wrap `dispatch()`/`Bus::dispatch(...)` (a
     `dispatch_with_retries`-style helper). **Inspect the helper's signature and call sites (F6)** —
     confirm the job is the argument the tracer expects — before recommending the name.
   - **Frontend config** — if an Inertia/Wayfinder/Ziggy frontend is present, propose `frontend.roots`
     and, where they differ from defaults, `frontend.generated_paths`, `frontend.pages_path`,
     `frontend.test_paths`, and `frontend.http_callees` (F4).
   - **Other knobs, when relevant (F4):** `feature_gate_methods` (custom Pennant-wrapper methods),
     `payload_parity` (mirror threshold / ignore / disable), and `editor: null` for a shared CI
     artifact (an editor link embeds an absolute local path).
   - **Brain config, detect-then-propose — not just trusted routes (F5).** When route discovery or
     security classification looks wrong, propose the matching Brain lever rather than only
     suppressing: `security.auth_middleware` / `security.throttle_middleware` (declaring the app's auth
     / throttle middleware so Brain classifies group-gated routes correctly — this can fix
     `PUBLIC_WRITE`/`MISSING_THROTTLE` false positives *at the source*), `auto_discover_routes` /
     `route_paths`, `commands.*`, `listeners.paths`, and `security.trusted_route_names` /
     `trusted_route_uris` for a genuinely known-safe public route. Detect, explain, confirm — never
     mutate Brain config unasked.
3. **CI advisory comment — OPT-IN, asked, never auto-installed.** After config, the skill **asks**
   whether to add a CI workflow that posts the report on PRs. Only on an explicit yes does it proceed,
   and it confirms the file before writing.
   - **Scan ALL workflows first, not one filename (F2):** grep every `.github/workflows/*.yml|*.yaml`
     for an existing `richter:detect-changes` invocation / sticky-comment step / "Richter"/"Impact"
     job. If richter is already wired (in `ci.yml`, `pr.yml`, anywhere), propose *integrating* rather
     than adding a second PR-commenting workflow (which would double-comment); only create
     `richter-report.yml` when none exists.
   - **Template gotchas:** `actions/checkout` with `fetch-depth: 0` (base commit diffable);
     `--base="${{ github.event.pull_request.base.sha }}"`; trigger on `pull_request` (never
     `pull_request_target` with a privileged token); **explicit `permissions: { contents: read,
     pull-requests: write }`**; **the whole job non-blocking (F7)** — every richter step *and* the
     sticky-comment step guarded (`|| true` / `continue-on-error`) so "advisory, never blocks" actually
     holds; and a note that a **fork PR under `pull_request` may lack comment-write permission**
     depending on repo settings.
   - **CI boot requirement (F4):** Brain autoloads/boots the app during analysis, so the workflow needs
     the app bootable in CI (`.env` / `APP_KEY`) — surface this from the README's existing CI guidance.

## README

Add a short "Set up richter for your project" subsection with **two** independent prompt blocks a user
can paste to their agent:

1. **Configure** — inspect the project and propose `config/richter.php` (the step-2 items above).
2. **Add the CI advisory comment** — scaffold `richter-report.yml` (the step-3 workflow).

Keeping them as two prompts honours the opt-in boundary even for users who don't have the synced skill.
Include the one-time boost-core allowlist note (`withAllowedVendors(['sandermuller/richter'])` →
`boost sync`) so the `richter-setup` skill is discoverable; note `laravel/boost` needs nothing.

## Implementation

### Phase 1: the skill — scaffold + config workflow

**ID:** setup-skill-config · **Depends:** — · **Priority:** HIGH

- [x] Create `resources/boost/skills/richter-setup/SKILL.md`. Frontmatter: `name: richter-setup`,
      invoke-only `description`, `disable-model-invocation: true`, untagged. Confirm `resources/` is
      not `export-ignore`d (it ships in the dist).
- [x] Author the "read current state" + "inspect & propose config" workflow (step 2 above), each
      proposal carrying its WHY and a confirm-before-write. Examples are **generic/synthetic** (public
      dist + README): a form-builder `Form`, a registry-dispatched calculator, a `dispatch_with_retries`
      helper — no consumer/domain names.
- [x] Keep it 100–300 lines, directory form, no cargo-cult frontmatter (per `skill-authoring`).

### Phase 2: the opt-in CI advisory-comment step + workflow template

**ID:** setup-skill-ci · **Depends:** setup-skill-config · **Priority:** HIGH

- [x] Add the step-3 CI section: the skill **asks** before doing anything, scaffolds
      `.github/workflows/richter-report.yml` only on consent, confirms the file, and never clobbers an
      existing one (diff + ask).
- [x] Embed the workflow template (fetch-depth 0, `--base=$BASE_SHA`, `pull_request` trigger, `|| true`
      never-blocks, sticky comment). Template is generic — no org/repo specifics.

### Phase 3: README prompt blocks + allowlist note

**ID:** setup-skill-readme · **Depends:** setup-skill-config, setup-skill-ci · **Priority:** MEDIUM

- [x] Add the "Set up richter for your project" subsection with the two prompt blocks (config, CI) and
      the boost-core allowlist note. Cross-link from Installation/Configuration.

### Phase 4: ship + verify

**ID:** setup-skill-verify · **Depends:** setup-skill-config, setup-skill-ci · **Priority:** HIGH

- [x] `vendor/bin/boost sync` locally and confirm `richter-setup` renders into the agent skill dir with
      `disable-model-invocation: true` intact (name == dir, frontmatter valid).
- [x] Validate the embedded workflow YAML parses (a YAML lint / `actionlint` if available).
- [x] Run the standard gauntlet (Rector/Pint/PHPStan/suite) to confirm the docs-only change breaks
      nothing. No PHP code / phpunit test accompanies a skill markdown — verification is sync + YAML
      validity + accuracy of every config key/command against current `main`.

## Edge Cases

| Scenario | Expected behaviour |
|---|---|
| `config/richter.php` not yet published | detect read-only; publish/create it only as part of the confirmed step-2 write, never before consent (F1) |
| Non-git project / no `origin/HEAD` | skip `default_base` detection; note it and ask, never guess |
| No frontend stack detected | no `frontend.roots` proposal |
| No custom dispatch helpers found | no `dispatch_helpers` proposal (defaults stand) |
| richter already wired in some workflow (`ci.yml`/`pr.yml`/…) | scan ALL `.github/workflows/*.yml\|*.yaml`; propose integrating, don't add a second commenting workflow (F2) |
| Whole `app/Actions`-style dir where few classes are entry surfaces | propose the narrowest root (or none) + flag false-positive risk; never inflate internal services (F6) |
| Fork PR under `pull_request` | note comment-write may be unavailable per repo settings; job stays non-blocking regardless (F7) |
| User declines the CI step | write nothing CI-related; config stays as applied |
| boost-core consumer without richter allowlisted | skill never syncs; README allowlist note is the fix |
| `laravel/boost` consumer | skill auto-syncs; no allowlist needed |
| Consumer's default branch is not `main` | `default_base` set to the detected branch (the common mis-scope) |

## Resolved Questions

- **Scope → config + CI-scaffold skill (user decision).** No `richter:doctor` command this round; the
  skill does detection agent-side (composer deps, git, class-pattern greps, optionally
  `richter:detect-changes` on a live diff).
- **CI setup → opt-in, asked, never auto-installed (user decision).** A consented step inside the
  skill, plus a *separate* README prompt — a workflow that posts to PRs is never scaffolded unasked.
- **Invoke-only → `disable-model-invocation: true` + description (researched).** Not auto-activated;
  runs only on `/richter-setup` or an explicit "set up richter" ask.
- **Tags → untagged (researched).** Ships to every consumer; a later tag would be consumer-breaking.
- **Writes → propose-then-confirm for BOTH config and the workflow.** Never silent; both are
  outward-facing files.

## STOP Conditions

- If the skill would write `config/richter.php` **or** the workflow without an explicit confirmation —
  stop. Propose-then-confirm is the safety premise; the CI workflow is never written unasked.
- If shipping the skill turns out to require a `boost.php` or a `composer.json` change (contradicting
  the researched convention-only mechanism) — stop and re-verify the mechanism before proceeding.
- If `resources/` is (or becomes) `export-ignore`d — stop: the skill would not ship in the dist and
  never reach consumers.
- If authoring drifts toward a multi-hundred-line treatise or duplicates the README rather than
  guiding setup — stop and trim (per `skill-authoring` anti-patterns).

## Findings

- **Codex spec review (pre-implementation), 7 findings, all folded:**
  - **F1 (High):** step 1 must be read-only — publishing/creating `config/richter.php` is a write, so it
    happens only inside the confirmed step-2 write, never before consent.
  - **F2:** the "never clobber" check now scans ALL `.github/workflows/*.yml|*.yaml` for an existing
    richter/sticky-comment job (not just `richter-report.yml`), and proposes integrating to avoid a
    double-commenting workflow.
  - **F3:** softened the `laravel/boost` reach claim — third-party pickup may need `boost:update`/package
    selection on an existing install; not guaranteed-automatic.
  - **F4:** broadened the config proposals — `feature_gate_methods`, `payload_parity`,
    `frontend.{generated_paths,pages_path,test_paths,http_callees}`, plus the CI `.env`/`APP_KEY` boot
    requirement.
  - **F5:** Brain config is detect-then-propose beyond trusted routes — notably `security.auth_middleware`
    / `throttle_middleware` (can fix the PUBLIC_WRITE/MISSING_THROTTLE false positives at the source),
    `auto_discover_routes`/`route_paths`, `commands.*`, `listeners.paths`.
  - **F6:** detection must hedge — narrowest `entry_point_roots` dir, evidence-driven, no broad
    `app/Actions` root that inflates internal services; inspect dispatch-helper signatures/call sites.
  - **F7:** the CI job must be non-blocking as a WHOLE (sticky-comment step guarded too), declare
    `permissions: { contents: read, pull-requests: write }`, and note the fork-PR comment limitation.
- **Codex review of the authored skill (post-implementation), 2 findings, both folded:**
  - **C1:** the exploratory `richter:detect-changes` in Step 2 warms the graph cache — a write during
    the read-only phase. Fixed: the skill uses `--no-cache` for that run. (Codex also confirmed every
    proposed config key and the `vendor:publish --tag=richter-config` command match richter's actuals.)
  - **C2:** the workflow template's sticky-comment lookup wasn't paginated (default 30) — a large PR
    could miss the marker and double-comment. Fixed: `github.paginate(listComments, { per_page: 100 })`.
