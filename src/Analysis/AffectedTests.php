<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use InvalidArgumentException;
use RuntimeException;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\DispatchTarget;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * Inverts the test-reference mapping into a test selection: which test files exercise the surface
 * a diff can reach? Selection runs on two axes — tests referencing any reached entry point, and
 * tests importing any changed or reached class (a unit test never touches an entry point) — and
 * fails safe: whenever the analysis cannot vouch for completeness (an UNRESOLVED file, a coarse
 * low-confidence seed, an unfollowable dispatch, an uncheckable entry point, references that live
 * only in non-runnable support files), the verdict is "not determinable — run the full suite",
 * never a silently smaller set. Over-selection is the acceptable error; under-selection is the one
 * this tool exists to prevent.
 */
final class AffectedTests
{
    /**
     * Dispatch sites named in a reason before the rest collapses into a count. Matches the rendered
     * breadth cap the formatters already use, so a capped list reads the same wherever it appears.
     */
    private const int SITE_CAP = 15;

    /**
     * The full selection assembly for the current branch diff — one shared implementation
     * of the fail-safe contract for the CLI and MCP: untracked-file short-circuit,
     * base/diff-resolution failures as undetermined-with-reason, the empty-diff fast
     * path, then {@see select()} over a fresh {@see ImpactAnalyzer::detectChanges()} run.
     * `untrackedFiles` rides along for the CLI's stderr note and is stripped from every
     * stdout/structured document by the callers. Unexpected `Throwable`s escape.
     *
     * @return array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, untrackedFiles: list<string>}
     */
    public static function selectForCurrentDiff(GraphCache $graphs, ?string $requestedBase, bool $fresh = false, ?string $requestedHead = null): array
    {
        $untracked = [];

        try {
            $base = RichterConfig::baseRef($requestedBase);
            // Inside the try: an unresolvable ref is an expected failure, and it has to come out as
            // an undetermined selection (exit 2, run the full suite) rather than escape to the
            // generic backstop, which would exit 1 and break the contract this command is for.
            $head = RichterConfig::headRef($requestedHead);

            // Only the working tree can be widened by an untracked file. An explicit head names a
            // COMMITTED tree, which a file that was never `git add`-ed cannot be part of, so
            // applying the guard there would force the full suite for exactly the dirty checkout
            // `--head` exists to analyse around.
            $untracked = $head === 'HEAD' ? ChangedSymbols::untrackedRelevantFiles() : [];

            if ($untracked !== []) {
                // An untracked (never `git add`-ed) file is invisible to every diff form — the
                // one gap the analysis can never close, so the selection is undetermined,
                // never silently narrowed.
                return self::undeterminedForCurrentDiff($base, [sprintf(
                    '%d untracked file(s) can\'t be analysed — `git add` them or run the full suite: %s',
                    count($untracked),
                    implode(', ', $untracked),
                )], $untracked);
            }

            ['changed' => $changed, 'outOfScope' => $outOfScope] = ChangedSymbols::resolveWithScope($base, $head);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            // A diff that can't be taken means the selection can't be determined — fail
            // toward the full suite.
            return self::undeterminedForCurrentDiff(is_string($requestedBase) ? $requestedBase : '', [$exception->getMessage()], $untracked);
        }

        // A test file the diff itself touched is affected by definition — no graph reasoning needed,
        // and none available: `tests/` is outside every lane the analysis reads. A DELETED one is
        // filtered out: the diff still names it, and handing a path that no longer exists to a test
        // runner fails the run this selection is meant to shorten.
        $changedTests = array_values(array_filter(
            TestReferenceIndex::runnableOnly($outOfScope),
            // Under `tests/` and still on disk. `$outOfScope` is every changed file no lane read, so
            // a `tools/SmokeTest.php` reaches here too and is no argument for the suite runner; a
            // DELETED test is a path that would fail the run this selection exists to shorten.
            static fn (string $file): bool => str_starts_with($file, 'tests/') && is_file(base_path($file)),
        ));

        if ($changed === []) {
            return ['base' => $base, 'determinable' => true, 'reasons' => [], 'tests' => $changedTests, 'frontendTests' => [], 'unreferencedEntryPoints' => 0, 'unresolvedDispatchSites' => [], 'untrackedFiles' => $untracked];
        }

        $graph = $graphs->graph(fresh: $fresh);
        // Parity lanes off: they only produce findings, which the selection never reads —
        // and the consumer lane would otherwise pay a whole frontend-tree scan on a CI
        // hot path for output this command discards.
        $selection = self::select(
            new ImpactAnalyzer($graph)->detectChanges($changed, payloadParityEnabled: false),
            $changed,
            TestReferenceIndex::fromTests(base_path('tests'), base_path()),
            $graph->unresolvedDispatchSites(),
            $graph,
            self::configuredFrontendTestIndex(),
            $graph->hasUnparseableFiles(),
            $changedTests,
        );

        return ['base' => $base] + $selection + ['untrackedFiles' => $untracked];
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $untracked
     * @return array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, untrackedFiles: list<string>}
     */
    private static function undeterminedForCurrentDiff(string $base, array $reasons, array $untracked): array
    {
        return ['base' => $base, 'determinable' => false, 'reasons' => $reasons, 'tests' => [], 'frontendTests' => [], 'unreferencedEntryPoints' => 0, 'unresolvedDispatchSites' => [], 'untrackedFiles' => $untracked];
    }

    /**
     * Only when the bridge (or an explicit test path) is configured — an unconfigured
     * project must not pay a directory scan per run.
     */
    private static function configuredFrontendTestIndex(): ?FrontendTestIndex
    {
        if (RichterConfig::frontendRoots() === [] && RichterConfig::frontendTestPaths() === []) {
            return null;
        }

        return FrontendTestIndex::fromConfiguredPaths(base_path());
    }

    /**
     * @param  array{coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, registryEntryPoints?: list<string>, lowConfidence: bool, callers?: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies?: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, ...}  $result  an {@see ImpactAnalyzer::detectChanges()} result
     * @param  list<ChangedFileSymbols>  $changed
     * @param  CodeGraph|null  $graph  when given, a `schedule::` entry point resolves through its
     *   scheduled command (the schedule node id itself is an opaque hash) instead of blocking
     *   determination outright
     * @param  FrontendTestIndex|null  $frontendTests  when given, frontend specs referencing a
     *   reached route are suggested under `frontendTests` — advisory for the JS runner, never an
     *   input to determinability (a route no spec references is not a blocker)
     * @param  list<array{file: string, line: int, dispatcher: string}>  $unresolvedDispatchSites  the
     *   dispatch statements whose target could not be followed ({@see CodeGraph::unresolvedDispatchSites()}).
     *   Taken as the sites rather than a flag so the reason can name them: "a dispatch somewhere could
     *   not be followed" leaves a reader with nothing to act on, which is what kept this verdict
     *   permanent for a project that has one.
     * @param  list<string>  $changedTests  runnable test files the diff itself touched. They are
     *   affected without any graph reasoning, and no lane here can find them — `tests/` is outside
     *   every tree the analysis reads. Folded into the selection so an undetermined verdict still
     *   names something correct instead of nothing.
     * @return array{determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>}
     */
    public static function select(array $result, array $changed, TestReferenceIndex $tests, array $unresolvedDispatchSites, ?CodeGraph $graph = null, ?FrontendTestIndex $frontendTests = null, bool $hasUnparseableFiles = false, array $changedTests = []): array
    {
        $reasons = [];

        if (in_array('unresolved', $result['coverage'], strict: true)) {
            $reasons[] = 'changed file(s) could not be placed in the graph (UNRESOLVED)';
        }

        if ($result['lowConfidence']) {
            $reasons[] = 'a changed member could not be pinned to a graph node (low confidence)';
        }

        // A file the parser could not read (S1) contributes zero edges, so it could hide a caller of
        // ANY change — could-be-anything taint, unscopeable. It blocks determination globally.
        if ($hasUnparseableFiles) {
            $reasons[] = 'one or more app files could not be parsed — the graph is incomplete';
        }

        // An unfollowable dispatch (S2) hides a `dispatcher → target::handle` edge. It can only make
        // an invisible dispatcher a missing caller of the change when a possible dispatch TARGET is
        // in the change's upward-caller closure (or is the changed class). A change with no dispatch
        // target upstream cannot be reached through the hidden edge, so an unresolved dispatch
        // elsewhere is irrelevant to it — the scoping never under-selects (see changeReachesDispatchable).
        $blockingSites = $unresolvedDispatchSites !== [] && self::changeReachesDispatchable($result, $changed)
            ? $unresolvedDispatchSites
            : [];

        if ($blockingSites !== []) {
            $reasons[] = 'the graph contains job dispatches that could not be followed: ' . self::renderSites($blockingSites);
        }

        $selected = $changedTests;
        $frontendSelected = [];
        $unreferenced = 0;

        // Selection walks the registry fan-out surfaces too. They are context in the report, since
        // they cannot tell one class of a registry from another — but each is a route that really
        // may dispatch the change, and a selection that skips them under-selects, which is the one
        // direction this command must never fail in.
        foreach ([...$result['entryPoints'], ...$result['registryEntryPoints'] ?? []] as $entryPoint) {
            $frontendSelected = [...$frontendSelected, ...$frontendTests?->testsReferencing($entryPoint) ?? []];
            $referencing = self::testsReferencingEntryPoint($entryPoint, $tests, $graph);

            if ($referencing === null) {
                $reasons[] = "entry point \"{$entryPoint}\" could not be checked against the test suite";

                continue;
            }

            if ($referencing === []) {
                ++$unreferenced;

                continue;
            }

            $runnable = TestReferenceIndex::runnableOnly($referencing);

            if ($runnable === []) {
                // A route/command reference inside a support trait or helper is a real coverage
                // signal, but the tests use()-ing that helper cannot be mapped — a smaller set
                // would silently drop them.
                $reasons[] = "tests referencing \"{$entryPoint}\" live only in non-test support files — cannot map them to runnable tests";

                continue;
            }

            $selected = [...$selected, ...$runnable];
        }

        // The import axis is deliberately broad — every changed class, every class the change
        // reaches in either direction, and a rename's vanished old FQCN — because a unit test of an
        // intermediate caller never references an entry point. Imports are a weak signal though, so
        // unlike the entry-point axis, non-runnable files (fixtures import app classes too) simply
        // filter out without blocking determination.
        foreach (self::classesToMatch($result, $changed) as $class) {
            $selected = [...$selected, ...TestReferenceIndex::runnableOnly($tests->testsImporting($class))];
        }

        $selected = array_values(array_unique($selected));
        sort($selected);
        $frontendSelected = array_values(array_unique($frontendSelected));
        sort($frontendSelected);

        return [
            'determinable' => $reasons === [],
            'reasons' => $reasons,
            // The selection is still reported when not determinable — useful context — but a
            // consumer must treat it as incomplete and run the full suite.
            'tests' => $selected,
            'frontendTests' => $frontendSelected,
            'unreferencedEntryPoints' => $unreferenced,
            // The sites that blocked THIS selection, uncapped while the reason above stays capped:
            // the reason is prose for a reader, where 36 lines help nobody, and this is the
            // machine's copy, which must not lose the ones past the cap.
            //
            // Blockers, not an inventory. Sites the change cannot be reached through leave the
            // selection determinable and belong in no reason, so listing them here would report
            // work nobody has to do. `richter://graph/stats` carries the project-wide list.
            'unresolvedDispatchSites' => $blockingSites,
        ];
    }

    /**
     * A `schedule::` node is an opaque hash, but the graph knows what it runs — resolve through the
     * scheduled `command::` target(s) when possible. No graph or no command target keeps the
     * original "cannot check" (null) so the fail-safe path still trips.
     *
     * @return list<string>|null
     */
    private static function testsReferencingEntryPoint(string $entryPoint, TestReferenceIndex $tests, ?CodeGraph $graph): ?array
    {
        if (! str_starts_with($entryPoint, 'schedule::') || ! $graph instanceof CodeGraph) {
            return $tests->testsReferencing($entryPoint);
        }

        $commands = array_values(array_filter(
            array_column($graph->dependenciesOf([$entryPoint], 1), 'node'),
            static fn (string $node): bool => str_starts_with($node, 'command::'),
        ));

        if ($commands === []) {
            return null;
        }

        $referencing = [];

        foreach ($commands as $command) {
            $commandTests = $tests->testsReferencing($command);

            if ($commandTests === null) {
                return null;
            }

            $referencing = [...$referencing, ...$commandTests];
        }

        return $referencing;
    }

    /**
     * @param  array{entryPoints: list<string>, callers?: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies?: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, ...}  $result
     * @param  list<ChangedFileSymbols>  $changed
     * @return list<string>
     */
    private static function classesToMatch(array $result, array $changed): array
    {
        $classes = [];

        foreach ($changed as $file) {
            if ($file->fqcn !== '') {
                $classes[$file->fqcn] = true;
            }

            foreach ($file->directSeeds as $seed) {
                if (AppNamespace::isAppClass($seed)) {
                    $classes[$seed] = true;
                }
            }
        }

        foreach ([...$result['callers'] ?? [], ...$result['dependencies'] ?? []] as $hop) {
            $class = explode('::', $hop['node'], 2)[0];

            if (AppNamespace::isAppClass($class)) {
                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }

    /**
     * Whether a possible dispatch target ({@see DispatchTarget}) is the changed class itself or sits
     * in the change's upward-caller closure. This is the ONLY condition under which an unfollowable
     * dispatch (S2) can under-select the change's tests: the hidden `dispatcher → target::handle`
     * edge inserts a missing caller only when such a target reaches the change. The uncertainty
     * direction is safe — DispatchTarget fails toward "yes", so this over-fires rather than under.
     *
     * @param  array{callers?: list<array{node: string, depth: int, via: string, file?: string, line?: int}>, ...}  $result
     * @param  list<ChangedFileSymbols>  $changed
     */
    private static function changeReachesDispatchable(array $result, array $changed): bool
    {
        foreach ($changed as $file) {
            if ($file->fqcn !== '' && DispatchTarget::matches($file->fqcn)) {
                return true;
            }
        }

        foreach ($result['callers'] ?? [] as $hop) {
            $class = self::classOfNode($hop['node']);

            if ($class !== null && DispatchTarget::matches($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sites as one comma-separated `file:line (Dispatcher::method)` run, capped like every other
     * rendered breadth list in the reports ({@see ImpactFormatter}'s `LIST_CAP`) — a project that
     * dispatches dynamically throughout would otherwise bury the other reasons beside this one.
     *
     * @param  list<array{file: string, line: int, dispatcher: string}>  $sites
     */
    private static function renderSites(array $sites): string
    {
        $shown = array_slice($sites, 0, self::SITE_CAP);
        $rendered = implode(', ', array_map(
            static fn (array $site): string => "{$site['file']}:{$site['line']} ({$site['dispatcher']})",
            $shown,
        ));

        $rest = count($sites) - count($shown);

        return $rest > 0 ? "{$rendered}, … and {$rest} more" : $rendered;
    }

    /**
     * The class FQCN a graph node id refers to, or null for a node whose structural prefix is never
     * a bus-dispatch target (a route/view/command/schedule/middleware/model surface, or an ambiguous
     * short controller/action id). A plain `Class::method` id yields its class segment.
     */
    private static function classOfNode(string $node): ?string
    {
        foreach (['route::', 'view::', 'command::', 'schedule::', 'middleware::', 'model::', 'controller::', 'action::'] as $prefix) {
            if (str_starts_with($node, $prefix)) {
                return null;
            }
        }

        return explode('::', $node, 2)[0];
    }
}
