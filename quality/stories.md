# User stories (durable)

Persona-tagged stories for richter's surfaces. Tags reference
[`personas.md`](personas.md). Each story states the contract: *what should be
true*. Expected behaviour describes the current, documented behaviour; a
mismatch between a story and the code is a finding, not a doc fix.

---

## RICH-001 — Plan a feature from a symbol's blast radius

**Surface:** `php artisan richter:impact <symbol>`

**Personas:** C2, C3 · W1 · H-leaf/H-hub

**Story:** As a developer planning a feature, I want the callers, dependencies,
and reached entry surfaces of the classes I intend to touch, so that I scope
the work and its risks before writing code.

**Expected behaviour:** Prints callers (what breaks) and dependencies (what it
reaches) breadth-first with depth and edge type; names the entry surfaces the
caller walk reaches with test-reference and security annotations; every hop
carries `file:line` where known.

**Edge cases:** substring matches; a symbol that matches nothing returns
nearest-node leads, not an empty report; `(none)` when no surface is reached.

## RICH-002 — Agent scopes a task over MCP

**Surface:** MCP tool `impact`

**Personas:** C1 · W1/W3 · **H-hub**

**Story:** As a coding agent asked to change a shared class, I want the blast
radius as structured content, so that I plan the change and its verification
without leaving the session.

**Expected behaviour:** Returns prose (capped lists) plus structured content in
the same shape as CLI `--json`; repeated calls reuse the in-memory graph; the
maps (`entryPointPaths`, `entryPointSecurity`, …) key by entry-point node.

**Edge cases:** a hub symbol fans out to hundreds of callers and entry points —
the structured payload today is uncapped, so this story is the stress case for
the agent's context budget; empty maps serialize as `[]`; `impact` has no
per-file attribution, so its rows keep plain-name order (unlike
`detect-changes`, whose `entryPoints` is in reading order).

## RICH-003 — Mid-build slice: what does this task own

**Surface:** `php artisan richter:task-slice` / MCP tool `task-slice`

**Personas:** C1, C2 · W2 · H-hub

**Story:** As a developer (or agent) mid-feature, I want the surfaces this task
owns, the hazards on the diff, and the unproven surfaces, so that I see what
still has to be added before the PR.

**Expected behaviour:** One JSON document from a single graph walk: `kept`,
`unreferencedKept`, `hazards` worst-first, `findings`, the unchanged
`affectedTests` selection, and `droppedHubCount` as a count, not a list. The
test selection is never narrowed by hub folding — folding can only flip
`affectedTestsDeterminable` to `false`.

**Edge cases:** no hub paths configured → nothing folds; diff owns no surface →
`runImpact` names the classes to query instead of concluding "reaches nothing";
run failure is still one JSON document.

## RICH-004 — Agent diffs mid-feature against the task parent

**Surface:** MCP tool `detect-changes` with `base` and `head`

**Personas:** C1 · W2

**Story:** As an agent working on a stacked branch, I want to analyse the range
my task owns — not the whole branch — so that the report speaks about my
change only.

**Expected behaviour:** `head` defaults to `HEAD` (includes the uncommitted
working tree); naming a commit analyses that committed state. `entryPoints`
is in reading order — attributed most-specifically-explained first — and
`entryPointAttribution` carries `{via, ownReach}` so the agent filters rows by
how much they belong to this diff instead of guessing.

**Edge cases:** a broken or missing base ref is an error, never an empty "no
impact" report; naming a historical commit as `head` (the CLI's `--head`)
also replays history without reverting `config/richter.php`, so a replayed
diff runs under the project's own hazard and parity settings.

## RICH-005 — Refactor input on a hub symbol

**Surface:** `richter:impact --explain`

**Personas:** C2, C3 · W3 · **H-hub**

**Story:** As a developer about to refactor a widely-used class, I want the
entry surfaces behind its callers and the chain from each surface down to the
symbol, so that I know which behaviours my refactor can regress and which have
no test watching them.

**Expected behaviour:** `--explain` adds the shortest call chain per reached
surface; surfaces connected only by association edges are listed separately as
context, not as callers; prose lists are capped with an "and N more" tail while
the `--json` arrays stay complete.

**Edge cases:** association-only surfaces (model relation, registry fan-out)
must not read as callers; a config-registry fan-out names no single class, so
its surfaces share one collapsed cause.

## RICH-006 — Explain a connection

**Surface:** `php artisan richter:trace <from> <to>` / MCP tool `trace`

**Personas:** C1, C2, C3 · W3/W7

**Story:** As a developer or agent, I want the shortest call-direction path
between two symbols, so that I can verify a suspected coupling or learn how a
request reaches a class.

**Expected behaviour:** One chain, each arrow labelled with its edge type. No
path is a result (exit 0) with the deepest caller reached; an unresolvable
symbol is an error with nearest-node leads. `--depth` raises the search bound.

**Edge cases:** a depth-limited miss says the walk ran out of depth, not that
no path exists; the search runs strictly in call direction.

## RICH-007 — Pre-PR self-check

**Surface:** `php artisan richter:detect-changes` (advisory, exit 0)

**Personas:** C2, C3 · W4

**Story:** As a developer about to open a PR, I want the entry points my branch
reaches, the ones no test references, and the hazards the diff carries, so
that I fix the gaps before a reviewer finds them.

**Expected behaviour:** Advisory by default; hazards named with tiers; every
reached entry point tagged `[test-referenced]`,
`[⚠ no test references this]`, or — when the referencing tests carry no
behavioural assertion the scan recognises —
`[test-referenced. No behavioural assertion found]`; unresolved changed files
marked UNRESOLVED rather than silently dropped.

**Edge cases:** a low or empty result is a signal, not a guarantee; an
unparseable changed file degrades honestly; a guard that moved is not a guard
that was removed — every removal predicate fires only when the removed thing
is not added elsewhere in the same diff, and the ability is compared, not the
call shape; a guard gutted in place (a policy method or `authorize()` body
becoming exactly `return true;`) is a tier-3 hazard even though the member
survives; an empty report is often correct —
a member-less edit (comment, `use` reorder) seeds nothing, and a new method
nothing calls yet can break nothing; an untracked never-added file appears in
no diff and is flagged on stderr; a diff of only out-of-scope files reports
that plainly with the skipped count on stderr.

## RICH-008 — Reviewer orients from the posted report

**Surface:** `richter:detect-changes --markdown` posted as a PR comment

**Personas:** C3 (the reviewer), C4 (the poster) · W5/W6

**Story:** As a reviewer, I want the blast radius and hazards as a PR comment,
so that review starts from what the change reaches instead of from a cold
diff.

**Expected behaviour:** The markdown report carries the same vocabulary as the
CLI and JSON output; worst findings first; long lists collapse into
`<details>` instead of truncating; a risk badge leads.

**Edge cases:** the report must be posted byte for byte — a pipeline that
filters blank lines breaks every `<details>` section, and the blank lines are
part of the output; the markdown writer sits beneath Laravel's console writer,
so an output-rewriting package cannot mangle it; a hub-heavy diff must stay
postable.

## RICH-009 — CI gate

**Surface:** `richter:detect-changes --fail-on=… --fail-on-hazard=… --fail-on-unresolved`

**Personas:** C4 · W6

**Story:** As a pipeline, I want to fail the build only on the specific
failure class the project opted into, so that the gate blocks a removed guard
without blocking every change.

**Expected behaviour:** Opt-in flags, each a separate policy; an un-assessable
diff fails rather than passing as "no impact"; `--json` adds a `gate` object.
Verdicts are comparable only within a pinned version.

**Edge cases:** levels drift upward as coverage improves — a gate on `medium`
in a poorly-placed app blocks nearly everything; `--fail-on-unresolved` fires
on the app's own graph coverage; a cosmetic or additive-only diff is ladder
step 0 ("nothing to assess", `low`), kept apart from "could not place what
this reaches" (`medium`) precisely so a whitespace commit never trips
`--fail-on=medium`.

## RICH-010 — CI test selection

**Surface:** `php artisan richter:affected-tests` / MCP tool `affected-tests`

**Personas:** C4, C1 · W6/W2

**Story:** As a pipeline (or agent), I want the test selection the diff
warrants, so that I run less than the full suite without ever silently
skipping a test the change could break.

**Expected behaviour:** Exit-code contract fails toward the full suite; an
undeterminable selection (exit 2 / `determinable: false`) carries its reasons;
`--plain` degrades to the full suite by construction in command substitution.

**Edge cases:** an untracked file under a watched root makes the selection
undeterminable rather than narrowed; non-determinable causes return the shape
with reasons, never a tool error; there is deliberately no way to acknowledge
an unfollowable dispatch site and stop it blocking — the remedy is
restructuring the dispatch into a followable form; some site shapes (a named
constructor on the job's own class) cannot be cleared from the application
side, and one is enough to hold every run at exit 2, so check for those before
planning restructuring work.

## RICH-011 — Script against the full report

**Surface:** `--json` on every command

**Personas:** C4, C1 · W6

**Story:** As a script or downstream tool, I want one complete JSON document
on stdout with stable keys, so that I can store, diff, or post-process the
full analysis.

**Expected behaviour:** The full, uncapped report; on failure stdout is
`{"error": "…"}`; `impact` and `detect-changes` share entry-point vocabulary
so one consumer parses both. Stderr notes never contaminate stdout. `--json`
is the one semver-governed machine contract — the text, markdown, and HTML
formats are rendering surfaces free to reword in any release.

**Edge cases:** empty PHP maps serialize as `[]`; consumers must treat both as
empty; `--json` is not a superset of the prose reports (some rendered values
are not serialised) — a missing value is a payload addition to request, never
a reason to parse a sentence; a hazard's `reach` value stays one of its four
states in `--json` and MCP structured content — the `(via its class)` suffix
belongs to the prose only, so a consumer matching on the four states keeps
working.

## RICH-012 — Orientation without a tool call

**Surface:** MCP resources `richter://graph/entry-points`,
`richter://graph/stats`, `richter://config`

**Personas:** C1 · W7

**Story:** As an agent starting a session, I want the entry-point inventory,
graph honesty flags, and effective configuration as cheap resources, so that I
orient before spending tool calls.

**Expected behaviour:** Read-only resources; stats carry the honesty flags
(`hasUnparseableFiles`, `hasUnresolvedDispatches`) and name each unfollowable
dispatch site.

**Edge cases:** honesty flags must surface in orientation — an agent that
never sees `hasUnresolvedDispatches` over-trusts every later report.

## RICH-013 — Explore an unfamiliar codebase

**Surface:** `richter:impact`, `richter:trace`, HTML report

**Personas:** C3 · W7 · H-hub

**Story:** As a developer new to a codebase, I want to walk outward from a
class or inward from a route, so that I build a mental model from the real
graph instead of from folder names.

**Expected behaviour:** Substring symbol matching lowers the entry barrier;
nearest-node leads turn a miss into a next query; `file:line` on every hop
makes each answer jumpable.

**Edge cases:** a hub query is the first thing a newcomer tries (`impact
User`) — the capped prose must stay legible and point to narrower queries.

## RICH-014 — Set up richter and keep it true

**Surface:** `config/richter.php`, the `/richter-setup` skill, stderr
diagnostics, `--profile`

**Personas:** C5 · W0

**Story:** As an adopter, I want richter to tell me when it cannot see my app
correctly, so that a misconfiguration surfaces as a named diagnosis instead of
as a calm report over invisible changes.

**Expected behaviour:** An UNRESOLVED file echoes the FQCN its path derived to
— the line that separates a wrong `root_namespace` from a coverage gap; an
`app/` directory whose classes never reach the graph is noted on stderr from
five classes up; every command warns when the derived root namespace matches
no `app/` PSR-4 mapping; `--profile` prints the build's phase timings and
names which scoped-rebuild precondition refused. The `/richter-setup` skill
proposes config edits and writes nothing unasked.

**Edge cases:** `entry_point_roots` makes a subsystem placeable, never an
entry surface — the entry-surface vocabulary is fixed and no config key
extends it; all diagnostics stay off stdout; a config change that affects the
build re-fingerprints the cache, so stale-after-tuning cannot happen;
`second_hop` trades build time for reach (`true` reads statically-called
methods, `'class'` reads whole classes, `false` reads neither) and each scope
fingerprints its own cache entry; the documented coverage limits (untyped
relation roots, a provider configuring routes it does not declare, a legacy
Console Kernel's schedule) cost reach, and reach lost to a limit is reported
as ignorance — UNRESOLVED or `medium` — never as safety.

## RICH-015 — A frontend change reaches backend surfaces

**Surface:** `frontend.roots` scanning (Wayfinder imports, Ziggy `route()`
calls, endpoint literals), `frontendTests`

**Personas:** C2, C4 · W2/W6

**Story:** As a developer changing TS/JS/Vue files, I want the backend routes
those files reference reported as touched surfaces and the matching specs
selected, so that a frontend edit is not invisible to impact analysis.

**Expected behaviour:** Changed frontend files under configured roots are
scanned for Wayfinder imports, literal Ziggy route names, and endpoint
strings behind allowlisted HTTP callees; matched routes report as touched
entry points with location, exposure, and gate annotations while `risk` and
`impacted` stay untouched (a frontend edit does not change backend
behaviour); frontend specs referencing a touched route land in the advisory
`frontendTests` list, never in `--plain`. The bridge also runs in reverse: a
changed backend member rendering an Inertia page names the resolved page
file, or says "no page file found".

**Edge cases:** a dynamic Ziggy argument or unmatched Wayfinder import marks
the file UNRESOLVED and flips `affected-tests` to exit 2 — fail-safe, not
silence; an unmatched literal route name is simply not a reference (frontend
routers collide with the helper name, so unmatched names never guess);
generated Wayfinder/Ziggy output and `.d.ts` files are never scanned; a
project-custom HTTP wrapper is invisible until registered in
`frontend.http_callees` (C5's feared failure).

## RICH-016 — Read the change visually

**Surface:** `richter:detect-changes --html=<path>` (`--open`)

**Personas:** C3 · W4/W5/W7

**Story:** As a reviewer or explorer, I want one self-contained HTML file with
the blast radius as a graph and each path walkable, so that I can read a large
change spatially instead of as a list.

**Expected behaviour:** One file, every style and script inline, opens from
`file://` and travels as a CI artifact; five tabs (Overview, Graph, Paths,
Changes, Advisory); every `file:line` is a clickable editor link via the
debugbar/Ignition env chain.

**Edge cases:** the ring diagram caps at 300 nodes and says so in the report —
the counts above it are never capped; editor links embed absolute local
paths, so a shared CI artifact wants `editor: null`; `--html` replaces stdout
but never touches the gate — `--html --fail-on=medium` still exits non-zero
exactly when the gate trips; a failing `--open` is a warning, never a failed
run.

## RICH-017 — Pin accuracy across upgrades

**Surface:** `php artisan richter:benchmark`, `richter:benchmark:add`

**Personas:** C5 · W0

**Story:** As an adopter upgrading richter, I want my project's own historical
fixes replayed through the report, so that a release that loses reach or
invents a hazard turns a case red instead of shifting my verdicts silently.

**Expected behaviour:** Bug fixtures must resolve and reach an entry point;
benign controls cap the risk a harmless change may report; `max_hazard_tier`
catches a false hazard a level cap cannot; `benchmark:add` dry-runs a commit
and prints a paste-ready config entry without editing the file.

**Edge cases:** a control flipping green to red is a trustworthiness
regression, never something to fix by re-capturing; a level-model change
re-baselines controls by design — re-grade the cap instead of reading the red
as a regression; `--control` refuses a commit that already reports HIGH,
because the cap would assert nothing.

## RICH-018 — A payload contract break is a hazard, not a note

**Surface:** the `payload_parity` hazard lanes

**Personas:** C1, C2, C3 · W2/W4

**Story:** As a developer changing a model, resource, or form request, I want
a break in the payload contract between backend and consumer flagged as a
tier-2 hazard, so that a field a consumer still reads or sends cannot vanish
as a silent diff line.

**Expected behaviour:** Three directions: a model field added but never
mirrored into its resource, a resource `toArray()` key removed while a
frontend consumer of its routes still reads it, and a form-request `rules()`
field removed while a consumer still sends it. All three are tier-2 hazards
that move the risk level — the one annotation family graded as hazards.
(Security exposure also reaches the level, but only by classifying an
existing hazard's reach; it raises nothing on its own.)

**Edge cases:** `mirror_threshold` defaults to `1.0` — exact mirrors only, no
guessing; `ignore` suppresses one field, one key, or a whole resource or
request by FQCN; `--no-payload-parity` disables for one run, and the level
moves with it; anything the checker cannot statically enumerate (a dynamic
`toArray()` key, a spread, a rules array built up in the method) makes that
side unenumerable and the lane stays silent rather than guessing; inline
controller validation is read too, anchored on the method holding the call;
a rename hint appears only when exactly one key was removed and one added,
never from a similarity guess.

## RICH-019 — The risk verdict always names its cause

**Surface:** the risk ladder, `risk` / `riskCause`

**Personas:** C1, C2, C3, C4 · W4/W5/W6

**Story:** As any reader of a report, I want every risk level to carry the
sentence that caused it, so that `medium` is an instruction about what to
check instead of a number to argue with.

**Expected behaviour:** One fixed decision ladder — hazard first (tier × reach
matrix), then unplaced reach, then unreferenced surfaces, then `low` — with no
weights and nothing to tune; no format renders a bare level, and `riskCause`
carries the same sentence in `--json` and over MCP; the level and the `Impact`
counts answer different questions and may disagree by design (a one-line
removed guard is `high` at one surface; a broad, fully-referenced refactor is
`low`).

**Edge cases:** "nothing to assess" (step 0) and "could not place what this
reaches" (step 2) look alike and mean opposite things — only the second is a
warning; the two reach admissions (`no-guard-found`, `no-known-path`) never
move the level in either direction, because an admission is not evidence;
hazard tiers never drift across releases, while reach class and verification
state drift upward — pin the version when a verdict must stay comparable; the
weak-assertion sub-tag counts as verified for the level (it only prints on
the row), while `task-slice`'s `unreferencedKept` counts the same surfaces as
unproven — both readings are intentional, one grades and one prompts.

## RICH-020 — Annotations orient, they never grade

**Surface:** security exposure, Pennant gates, middleware group membership,
sibling-read parity

**Personas:** C1, C2, C3 · W2/W5

**Story:** As a reviewer or agent, I want the advisory annotations to carry
orientation — exposure, flags, group size, a suspect raw read — with none of
them grading the change on its own, so that I can weigh evidence the tool
refuses to judge.

**Expected behaviour:** Pennant gates and middleware group membership feed
nothing: not the level, not a gate, not the test selection. Security exposure
reaches the level through one narrow door only — deciding a hazard's reach
class. The cross-check notes (a policy in the route's reach beside a
`PUBLIC_WRITE`, an auth-descended middleware Brain missed) are evidence to
verify, never suppressions — Brain's finding stays shown. Sibling-read parity
is a finding, never a hazard: it names both reads and claims nothing more.

**Edge cases:** only routes are classified — a Livewire, Filament, Nova, or
queue surface never carries an exposure tag, and that absence means "not
classified", never "public"; a middleware group is not expanded into edges, so
the membership note supplies the real route count from the registered route
table instead of inflating reach; the note is left out whenever the count
cannot be vouched for; sibling-read silence is the common case and is not a
claim of correctness.
