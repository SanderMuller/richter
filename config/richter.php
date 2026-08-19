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
     * ('low', 'medium' or 'high'). expect_finding (optional) additionally asserts that one of the
     * report's advisory findings contains the given substring — e.g. a payload-parity note.
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
        //     'expect_finding' => 'layout',
        // ],
    ],

    /*
     * The counts at which the advisory risk level steps up. Absolute, never relative to the graph,
     * so a `--fail-on` verdict means the same thing on every run and across releases — that is the
     * whole reason they are numbers and not percentiles.
     *
     * The defaults were calibrated on small-to-mid applications. On a large one they saturate: a
     * codebase where a routine change reaches thousands of nodes will report `high` for everything,
     * and a level that is always `high` carries no signal and trains reviewers to skip the line.
     * Move the `high` bar before the `medium` one. Raising `high` leaves the `medium` test untouched,
     * so the most it can do is move a change from `high` to `medium`; raising `medium` is the only
     * edit that can push something to `low`, the level a reviewer skips. Whether that costs you a
     * real defect depends on where your bug fixes land, and these counts measure graph REACH rather
     * than how big a change is — a one-line fix in a widely called method can outrank a broad but
     * shallow one, so do not assume defects sit below routine changes. A benchmark corpus run before
     * and after is the only check that answers it.
     * If you keep a benchmark corpus, run it afterwards: it is the check that a calibration has not
     * quietly demoted the defects it was meant to surface.
     *
     * Calibrate against `scoredEntryPoints` / `scoredImpacted`, not the counts printed beside them.
     * They are usually the same number, but the entry-point list gains surfaces after the level is
     * scored (a changed class that is itself an entry surface, a frontend file's routes) and a
     * low-confidence `high` is re-scored on the precisely-seeded subset. Where they come apart the
     * printed count can be an order of magnitude larger, so a threshold set against it sits far above
     * where the level is actually decided. The report names the scored counts whenever they differ.
     *
     * Note these interact with coverage: every release that teaches Richter to follow more edges
     * raises the impacted count for an unchanged diff, so a level that shifts right after an upgrade
     * is a coverage change before it is a code change.
     */
    'risk_thresholds' => [
        'high' => ['entry_points' => 3, 'impacted' => 20],
        'medium' => ['entry_points' => 1, 'impacted' => 5],
    ],

    /*
     * Advisory-only payload-parity checks, both directions: a model field ($fillable/$casts/
     * casts()) added but never added to a resource that mirrors its other fields, and a resource
     * toArray() key REMOVED while a frontend consumer of the routes it reaches still reads it —
     * the two shapes behind a payload field silently going missing. Never feeds risk, --fail-on,
     * or affected-tests; only the findings list.
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
];
