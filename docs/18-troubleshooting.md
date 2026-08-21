# Troubleshooting

A symptom index: each entry names the likely cause and links to the page that explains it in full.

## The report

### The report is empty, and the change was not

Rule this out first. Richter resolves changes to class members, so an edit that changes a file without changing a member seeds nothing and correctly reports nothing. A comment after the closing brace, a reordered `use` block and a formatting pass all fall in that group. So does a member *added* to an existing class: nothing called it before, so it can break nothing.

See [When a report of nothing is correct](04-detect-changes.md#when-a-report-of-nothing-is-correct).

If the diff genuinely changes a member body, check that the file is in scope at all. Only PHP under `app/`, Blade views, and configured frontend roots are analysed. A diff of nothing else prints `No changed PHP files under app/ against <base>.`, with the skipped count on stderr. See [Changed files no lane analyses](04-detect-changes.md#changed-files-no-lane-analyses).

### A changed file reads UNRESOLVED

The graph could not place that file. The report echoes the FQCN the path derived to (`app/Services/Inspector.php → App\Services\Inspector`), and that line is the diagnosis:

- **The FQCN looks wrong.** The root namespace is misread. Set `root_namespace` explicitly in [the configuration reference](16-configuration.md); the default derives it from the PSR-4 entry mapping to `app/`, which is ambiguous when two roots map there.
- **The FQCN looks right.** Nothing in the graph reaches that class. If it belongs to a subsystem dispatched at runtime (a registry, a form builder), add its narrowest directory to `entry_point_roots` so its methods are traced. That makes the subsystem *placeable*; its classes still do not become entry points.
- **Neither.** It may be a coverage gap. [Coverage beyond Laravel Brain](15-coverage.md) lists what is traced and the three known limits.

UNRESOLVED means the reach could not be determined, not that the change has no impact.

### An `app/` directory holds classes but none reach the graph

Every command notes this on stderr, from five classes up. A subsystem takes that shape when its dispatch is one Richter cannot follow and its directory is not in `entry_point_roots`. The fix is the same as above.

### A `self::dispatch()` still resolves to nothing

A self-referential dispatch resolves to its declaring class, but three shapes are refused rather than guessed: `parent::`, a file declaring more than one class-like, and a trait, where `self` is the *consuming* class at runtime. See the queue-dispatch entry in [Coverage beyond Laravel Brain](15-coverage.md).

### A route reads `[public]` when it is authenticated

Exposure comes from Laravel Brain's view of the route's static middleware surface, and it matches auth middleware by **name**. An app that subclasses Laravel's `Authenticate` matches no known name, so every route behind it reads `[public]`. Richter walks the class ancestry and notes the applied middleware beside the finding as evidence; it does not suppress the tag.

The fix at the source is `laravel-brain.security.auth_middleware`. See [Security annotations](05-report-annotations.md#security-annotations).

An absent tag means *not classified*, and only routes are classified at all.

## Risk levels

### Every change reports `medium`

On an application whose subsystems are dispatched through a config-keyed registry, most changes reach no entry point richter can name, and "could not place what this reaches" is `medium` by design. It is not evidence of safety, so it does not read `low`.

The discrimination is in the cause line, not the level. Read it: `could not place` and `no test referencing them` are different problems with different fixes. `--fail-on-hazard` gates the changes that carry an actual hazard, whatever their level.

If the registry is one richter could follow, teaching it the dispatch is the real fix — see [Configuration](16-configuration.md).

### The risk level does not match the `Impact` counts beside it

It is not meant to. `Impact` describes how far the change reaches; the level says what to do about it. A one-line change that removes an authorization guard reports `high` with an `Impact` of one surface, and a broad refactor whose every surface is test-referenced reports `low`.

The `Risk:` line always names its own cause. Read that before the counts. See [Risk levels](07-risk-levels.md).

### The level changed after upgrading, with no code change

Treat a level shift right after a version bump as a coverage change first. Every release that follows more edges raises the impacted count for the same diff, so the counts move upward over time by design. Pin the version in CI if a `--fail-on` verdict has to stay comparable across releases.

## Affected tests

### `affected-tests` always exits 2

Exit 2 means *not determinable: run the full suite*. That is the fail-safe doing its job, and the printed reasons name the cause. The most common one is an unfollowable dispatch: a target no static read can see.

Before planning work to restructure those call sites, check the list for the shapes that **cannot** be cleared from the application side. One is enough to hold every run at exit 2. See [Unfollowable dispatches](11-affected-tests.md#unfollowable-dispatches) and [the exit-code contract](11-affected-tests.md#the-exit-code-contract).

### A brand-new file is ignored

A file never `git add`-ed appears in no diff form. `detect-changes` flags it on stderr, and `affected-tests` treats it as undeterminable so the selection cannot silently omit it. `git add` the file. See [Untracked files](11-affected-tests.md#untracked-files).

## Running it

### The command fails before analysing anything

`richter:detect-changes` runs through `php artisan`, so it boots your application to build the graph. It needs whatever booting normally requires, typically an `.env` and an `APP_KEY`. This bites in CI more than locally; see [Gating in CI](08-ci-gating.md).

### The base ref cannot be resolved in CI

The diff is taken against the merge-base with `--base`, so that ref must exist in the checkout's history. Check out with `fetch-depth: 0`.

### Results look stale after changing config

The cache is keyed by a content fingerprint of everything the build reads, so any covered input rebuilds automatically. There is no TTL and no stale window. `--no-cache` is the escape hatch for an input the fingerprint does not cover. See [Graph cache](14-graph-cache.md).

### `--profile` refuses to run

A cache hit leaves nothing to time, so add `--no-cache` to time a cold build, or make a real change first. A refusal names the precondition that refused it. See [Scoped rebuilds](14-graph-cache.md#scoped-rebuilds).

### Composer refuses to install alongside `laravel/boost`

Richter supports `laravel/mcp` `^0.8||^0.9` and declares a conflict outside it. `laravel/boost` only pulls a compatible `laravel/mcp` from v2, so a v1 install has to take that major in the same command. See [Installation](02-installation.md).

## Still stuck?

Open an issue at [github.com/SanderMuller/richter/issues](https://github.com/SanderMuller/richter/issues). Include the command you ran and the report it printed. When the graph is the suspect, add the `richter://graph/stats` output or a `--profile` run.
