<?php declare(strict_types=1);

return [
    /*
     * Git ref `richter:detect-changes` diffs the current branch against when no --base option is passed.
     */
    'default_base' => 'origin/main',

    /*
     * The root namespace of the classes under app/ — the prefix richter maps a changed file path to
     * and gates every "is this an app class?" check on. Null derives it from the PSR-4 entry in
     * composer.json that maps to app/ ('App\\' on a conventional Laravel app, and the fallback when
     * composer.json names no single unambiguous root). Set it explicitly when app/ is mapped by two
     * or more PSR-4 roots, e.g. 'Acme\\'.
     */
    'root_namespace' => null,

    /*
     * Editor for the clickable file:line links in the `--html` report. Reuses debugbar's/Ignition's
     * env chain and defaults to phpstorm exactly as debugbar does, so an existing setup needs no new
     * variable. Supported: phpstorm, idea, vscode, vscode-insiders, vscode-remote, vscodium, sublime,
     * textmate, emacs, macvim, atom, nova, netbeans, xdebug. Set to null to keep the file references
     * plain text — worth doing for a shared CI artifact, since a link embeds an absolute local path
     * that only opens on the machine that generated the report.
     */
    'editor' => env('CODE_EDITOR') ?: env('DEBUGBAR_EDITOR') ?: env('IGNITION_EDITOR', 'phpstorm'),

    /*
     * Project-specific global helper functions that dispatch a job, beyond Laravel's own
     * dispatch()/dispatch_sync(). Each is expected to take the job instance as its first argument.
     */
    'dispatch_helpers' => [],

    /*
     * Project wrappers around Pennant, as `Enum\Class::method`, e.g.
     * `App\Enums\FeatureToggle::isActive`. A `EnumCase->method()` call then annotates the
     * change as flag-gated, alongside the built-in `Feature` facade / `@feature` support.
     */
    'feature_gate_methods' => [],

    /*
     * Directories under app/ whose classes Richter traces beyond Laravel Brain's route-anchored
     * graph: it walks their methods and draws the edges those bodies imply, so a change in one is
     * placeable instead of UNRESOLVED. Missing directories are skipped, so unused defaults are
     * harmless.
     *
     * Tracing only. It does not promote anything: which reached classes count as an entry surface
     * of their own, and which namespaces floor the risk level, are both fixed vocabularies in the
     * analyzer that no config key extends. Listing a directory here makes its classes reachable
     * and countable, never entry points.
     *
     * A directory that belongs here but is absent gets a stderr note: the commands report an
     * app/ directory holding classes of which none reach the graph at all.
     */
    'entry_point_roots' => ['Jobs', 'Listeners', 'Console/Commands', 'Filament', 'Helpers', 'Http/Middleware', 'Livewire', 'Observers'],

    /*
     * Read the bodies of statically-called methods.
     *
     * A class reached only through a static call is placed in the graph, but nothing reads its
     * method bodies, so whatever it constructs or calls stays invisible. This walk reads the methods
     * those static calls name. Turn it off to trade that reach for build time — measured at ~4.5s on
     * a 4,000-file application.
     *
     * Set it to 'class' to read every traceable method of those classes, not only the ones a static
     * call names — the rest of such a class is otherwise never read. Measured at ~8.0s against the
     * ~4.5s above on the same 4,000-file application.
     */
    'second_hop' => true,

    /*
     * Frontend roots (relative to the project root, e.g. 'resources/js') whose changed
     * .ts/.tsx/.js/.jsx/.vue files are scanned for backend endpoint references — Wayfinder
     * imports and Ziggy route() calls. Off when empty. The routes a changed frontend file
     * references are reported as touched entry points (with their gates and security
     * annotation) and feed richter:affected-tests, but never the risk level: a frontend
     * change does not alter backend behaviour. `generated_paths` entries (relative to each
     * root) match a directory, an exact file, or a `*`-glob (crosses `/`) — Wayfinder's
     * generated trees and Ziggy's generated route map are excluded by default as
     * regeneration churn, not semantic frontend change. `.d.ts` declaration files are
     * always excluded, regardless of this list.
     */
    'frontend' => [
        'roots' => [],
        'generated_paths' => ['actions', 'routes', 'wayfinder', 'ziggy.js'],
        // Where Inertia page components live — a changed backend member rendering a page is
        // noted under Findings with the resolved page file (works without `roots`).
        'pages_path' => 'resources/js/Pages',
        // Directories scanned for frontend spec files (*.test.*/*.spec.*/*.cy.*) whose endpoint
        // references feed richter:affected-tests' advisory frontendTests list. Empty means
        // "the frontend roots".
        'test_paths' => [],
        // Extra JS/TS callees, beyond the built-in HTTP/route helpers, whose string arguments
        // are treated as backend endpoints. Match the callee's leading identifier, e.g.
        // 'myHttpClient'.
        'http_callees' => [],
    ],

    /*
     * On-disk cache for the built code graph, keyed by a content fingerprint of everything the
     * build reads (app/, routes/, resources/views, the richter and laravel-brain config, package
     * versions). Any input change rebuilds automatically; `--no-cache` bypasses it for one run.
     * `directory` null means storage/framework/cache/richter.
     */
    'cache' => [
        'enabled' => true,
        'directory' => null,
    ],

    /*
     * Build Brain's route-anchored analysis and richter's own source-tracer branch concurrently
     * (the tracer branch runs in a child `artisan` process) instead of sequentially. The two
     * branches are data-independent and the merged graph is byte-identical either way, so this only
     * shortens the cold build (~40% on a multi-core machine). Any child-process failure falls back
     * to the serial build, and `--profile` forces serial so the phase split stays measurable.
     */
    'parallel' => true,

    /*
     * Replayable accuracy fixtures for `richter:benchmark`: historical fix commits the change-impact
     * report is re-run against. Bug fixtures (expect_signal: true) must resolve and reach an entry
     * point; controls (expect_signal: false) cap the risk a harmless change may report via max_risk
     * ('low', 'medium' or 'high'). expect_finding (optional) additionally asserts that the report
     * SAYS the given substring somewhere it could carry it — a finding, or a hazard's evidence or
     * member. Both, because the destination moves: the payload-parity checks were findings before
     * they became tier-2 hazards, and a fixture pins what the report says, not which section says
     * it.
     *
     * max_hazard_tier (optional, 0-3, default 3) caps the worst hazard the change may produce, where
     * 0 allows none. It is not the same guard as max_risk: the tier x reach matrix maps several
     * different situations onto MEDIUM, so a case whose honest level is MEDIUM stays green while a
     * spurious hazard appears underneath it. Cap the tier to catch that.
     *
     * max_risk is checked on every fixture, not only on controls; it just does nothing on a bug
     * fixture left at the default 'high', which is why `benchmark:add` writes that value there. Only
     * a control is scaffolded with a cap below it, taken from what the replay actually reported.
     */
    'benchmark_cases' => [
        // [
        //     'key' => 'TICKET-123',
        //     'fix_commit' => 'abc1234',
        //     'bug_class' => 'background-job change (data not copied on duplication)',
        //     'expect_signal' => true,
        //     'max_risk' => 'high',
        //     'max_hazard_tier' => 2,
        //     'expect_finding' => 'layout',
        // ],
    ],

    /*
     * DEPRECATED and no longer read. The risk level is decided by the hazard a change carries and by
     * whether anything would catch a regression in what it reaches — not by how many nodes it
     * touches. Those counts are still reported, under `Impact`, where they describe the change
     * instead of grading it.
     *
     * The key is accepted and ignored for one release so an upgrade does not fail on it. Remove it.
     */
    'risk_thresholds' => [],

    /*
     * Advisory-only payload-parity checks, both directions: a model field ($fillable/$casts/
     * casts()) added but never added to a resource that mirrors its other fields, and a resource
     * toArray() key REMOVED while a frontend consumer of the routes it reaches still reads it —
     * the two shapes behind a payload field silently going missing.
     *
     * These are HAZARDS, not findings: a payload key a consumer still reads is a break, so it is
     * reported beside the other tier-2 hazards and it does move the risk level. `enabled` and
     * `ignore` keep working exactly as before; only the destination changed.
     */
    'payload_parity' => [
        'enabled' => true,
        // Fraction of a candidate resource's pre-existing fields that must be mirrored for it to
        // count as a mirror of the model. Deliberately exact (1.0) — this is a no-guess advisory
        // check, not a heuristic score.
        'mirror_threshold' => 1.0,
        // Suppress specific model fields ('App\Models\Post::internal_flag'), resource keys
        // ('App\Http\Resources\PostResource::published_at'), form-request fields
        // ('App\Http\Requests\StorePostRequest::subtitle'), or fields validated inline, named
        // against the member holding the call ('App\Http\Controllers\PostController::store::subtitle').
        // A whole resource, request or member suppresses all of its fields
        // ('App\Http\Resources\Api\InternalResource' — both directions).
        'ignore' => [],
    ],

    /*
     * Sibling-read parity: a changed method that reads a nullable column raw where the code that was
     * already beside it — a sibling in the same directory, or the model's own accessor — resolves the
     * same value through a fallback. Findings only: never `risk`, `--fail-on` or `affected-tests`. The
     * finding names both observed reads and claims nothing more; the sibling may be the wrong one.
     *
     * Only a nullable SCALAR column is compared. That was measured, not chosen: read literally, a
     * generated `@property ...|null` block marks relations, cast objects, primary keys and timestamps
     * nullable too, and those were two thirds of the findings and none of the defect class.
     */
    'sibling_read_parity' => [
        'enabled' => true,
        // Silence one pair ('App\Models\Post::excerpt') or every property of a type
        // ('App\Models\Post'). Both forms are exact — no wildcards.
        'ignore' => [],
    ],

    /*
     * Which files this project calls HUBS, for the keep set `detect-changes` annotates and
     * `richter:task-slice` reports: a shared model, a service provider, a client every feature talks
     * to. An entry surface reached only through one of these is fan-out rather than a surface the work
     * in front of you owns, and the reports fold it under that cause.
     *
     * BOTH EMPTY MEANS OFF. Every surface is kept and nothing is folded. Richter ships no default and
     * infers none: two measured applications produced no rule for hub-ness, so a shipped list would be
     * a guess presented as a finding. Describe your own hubs or get the full list.
     *
     * A surface whose OWN file is in the diff is kept even when that file sits under a prefix here —
     * you edited that class, you did not merely touch the hub behind it.
     *
     * Never an input to `risk`, `--fail-on`, the gate or `affected-tests` selection. This list is
     * project policy, not evidence about the code.
     */
    'task_slice' => [
        'hub_paths' => [],
        'hub_path_prefixes' => [],
    ],

    /*
     * Change hazards: the tiered properties of a diff that say it may break something — an
     * authorization guard removed, `$hidden` narrowed, a mass-assignment surface widened, a
     * validation constraint dropped, a queued payload changed, a public member removed, a column a
     * migration drops. Every predicate is exact: a lane that cannot read both sides of a
     * comparison in full reports nothing rather than guessing. So a reported hazard is one richter
     * read in full, and an empty hazard list is not proof there is none.
     *
     * The tiers themselves are not configurable. Suppress a hazard with `ignore` below, or skip
     * the lanes with `--no-hazards`; a hazard that reports always carries its own tier.
     */
    'hazards' => [
        'enabled' => true,
        // Suppress one hazard, named as payload_parity.ignore is: the member it sits on
        // ('App\Http\Controllers\PostController::update'), a model's config member
        // ('App\Models\Post::$fillable'), or an inline-validated field
        // ('App\Http\Controllers\PostController::store::subtitle'). A migration hazard is named by
        // table and column ('posts.subtitle'), and the table on its own ('posts') quietens every
        // hazard on it — the way to silence a framework table, a queue table or a pivot in one entry.
        'ignore' => [],
    ],
];
