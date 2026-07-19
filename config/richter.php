<?php declare(strict_types=1);

return [
    /*
     * Git ref `richter:detect-changes` diffs the current branch against when no --base option is passed.
     */
    'default_base' => 'origin/main',

    /*
     * Project-specific global helper functions that dispatch a job, beyond Laravel's own
     * dispatch()/dispatch_sync(). Each is expected to take the job instance as its first argument.
     */
    'dispatch_helpers' => [],

    /*
     * Directories under app/ whose classes are entry points Laravel Brain's route-anchored
     * graph misses. Missing directories are skipped, so unused defaults are harmless.
     * Affects graph tracing only — the risk-floor namespace heuristics (Jobs, Listeners, …)
     * in the analyzer are fixed.
     */
    'entry_point_roots' => ['Jobs', 'Listeners', 'Console/Commands', 'Filament', 'Helpers', 'Http/Middleware', 'Livewire', 'Observers'],

    /*
     * Frontend roots (relative to the project root, e.g. 'resources/js') whose changed
     * .ts/.tsx/.js/.jsx/.vue files are scanned for backend endpoint references — Wayfinder
     * imports and Ziggy route() calls. Off when empty. The routes a changed frontend file
     * references are reported as touched entry points (with their gates and security
     * annotation) and feed richter:affected-tests, but never the risk level: a frontend
     * change does not alter backend behaviour. `generated_paths` (relative to each root)
     * are Wayfinder's generated trees — regeneration churn, not semantic frontend change.
     */
    'frontend' => [
        'roots' => [],
        'generated_paths' => ['actions', 'routes', 'wayfinder'],
        // Where Inertia page components live — a changed backend member rendering a page is
        // noted under Findings with the resolved page file (works without `roots`).
        'pages_path' => 'resources/js/Pages',
        // Directories scanned for frontend spec files (*.test.*/*.spec.*/*.cy.*) whose endpoint
        // references feed richter:affected-tests' advisory frontendTests list. Empty means
        // "the frontend roots".
        'test_paths' => [],
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
     * Replayable accuracy fixtures for `richter:benchmark`: historical fix commits the change-impact
     * report is re-run against. Bug fixtures (expect_signal: true) must resolve and reach an entry
     * point; controls (expect_signal: false) cap the risk a harmless change may report via max_risk
     * ('low', 'medium' or 'high').
     */
    'benchmark_cases' => [
        // [
        //     'key' => 'TICKET-123',
        //     'fix_commit' => 'abc1234',
        //     'bug_class' => 'background-job change (data not copied on duplication)',
        //     'expect_signal' => true,
        //     'max_risk' => 'high',
        // ],
    ],
];
