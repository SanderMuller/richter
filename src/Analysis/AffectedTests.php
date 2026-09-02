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
 * tests importing any changed class or any class that REACHES it (a unit test never touches an
 * entry point) — and fails safe: whenever the analysis cannot vouch for completeness (an UNRESOLVED file, a coarse
 * low-confidence seed, an unfollowable dispatch, an uncheckable entry point, references that live
 * only in non-runnable support files), the verdict is "not determinable — run the full suite",
 * never a silently smaller set. Over-selection is the acceptable error; under-selection is the one
 * this tool exists to prevent.
 *
 * Two things narrow or describe the result without touching that verdict. Paths the runner cannot
 * execute ({@see RichterConfig::unrunnableTestPaths()}) are removed from the SELECTION, never from
 * discovery, so a browser-only test stays real coverage everywhere else. And the size fields say how
 * much of the suite the selection is — a fact for the caller to act on, never a reason, because
 * "large" and "untrustworthy" are different claims and `determinable` already carries the second.
 *
 * @phpstan-import-type DetectChangesResult from HtmlFormatter
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
     * @param  DetectChangesResult|null  $analysisUsed  set to the analysis this selection was computed
     *   from, so a caller composing both halves of one report cannot describe two different runs; stays
     *   null when the diff never got that far (an unresolvable ref, an untracked file, nothing changed)
     * @param-out DetectChangesResult|null $analysisUsed
     * @param  SelectionProvenance|null  $provenance  set to why each file is in the selection, or out
     *   of it — what `--explain` answers from. An out-param rather than a document key: every
     *   document field reaches MCP structured content wholesale, so one that rode along would have
     *   to be stripped by each consumer.
     * @param-out SelectionProvenance $provenance
     *
     * @return array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, testsTotal: int, testsShare: float, testsExcluded: int, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, untrackedFiles: list<string>}
     */
    public static function selectForCurrentDiff(GraphCache $graphs, ?string $requestedBase, bool $fresh = false, ?string $requestedHead = null, bool $fullAnalysis = false, ?array &$analysisUsed = null, ?SelectionProvenance &$provenance = null): array
    {
        $provenance = new SelectionProvenance();
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

        // A spec the diff itself changed is affected the way a changed PHP test is, and for the same
        // reason: no lane can find it, because the endpoint-reference axis only ever suggests specs
        // that name a reached route. Collected from the diff's own paths, so a spec outside every
        // configured frontend path is still named.
        $changedFrontendTests = self::changedFrontendSpecs($changed, $outOfScope);

        $unrunnable = RichterConfig::unrunnableTestPaths();
        $suiteRunnable = TestReferenceIndex::runnableFiles(base_path('tests'), base_path());

        if ($changed === []) {
            // The exclusion applies here too: a diff that edits ONLY a Dusk test never reaches
            // select(), and handing that path to `php artisan test` is the failure this key exists
            // to stop.
            $partition = self::partitionUnrunnable($changedTests, $unrunnable);
            $kept = $partition['kept'];

            foreach ([...$changedTests, ...$changedFrontendTests] as $file) {
                $provenance->changedFile($file);
            }

            $provenance->excludedBy($partition['excluded']);
            $provenance->considered(0, 0);

            return ['base' => $base, 'determinable' => true, 'reasons' => [], 'tests' => $kept]
                + self::sizeSignal($kept, $suiteRunnable, $unrunnable, count($changedTests) - count($kept))
                + ['frontendTests' => $changedFrontendTests, 'unreferencedEntryPoints' => 0, 'unresolvedDispatchSites' => [], 'untrackedFiles' => $untracked];
        }

        $graph = $graphs->graph(fresh: $fresh);
        // Parity lanes off: they only produce findings, which the selection never reads —
        // and the consumer lane would otherwise pay a whole frontend-tree scan on a CI
        // hot path for output this command discards. Entry-point attribution is off for the same
        // reason: it walks once per changed file to decide row ORDER, and this command reads the set.
        // `$fullAnalysis` is for a caller that needs the DOCUMENT as well as the selection —
        // `richter:task-slice` composes both — and would otherwise walk the graph a second time to get
        // it. It only turns the two off-by-default lanes back on; the selection reads the entry-point
        // SET, which neither lane moves, so it is identical either way and `TaskSliceTest` pins that.
        // `$analysisUsed` hands the caller exactly the analysis this selection was computed from, so
        // the two halves of one report can never describe different runs.
        $analysisUsed = new ImpactAnalyzer($graph)->detectChanges(
            $changed,
            payloadParityEnabled: $fullAnalysis ? null : false,
            tests: $fullAnalysis ? TestReferenceIndex::fromTests(base_path('tests'), base_path()) : null,
            attributionEnabled: $fullAnalysis,
            // Working-tree analyses only: a named head describes a commit the booted router does not.
            runtimeEvidenceRoot: RichterConfig::runtimeEvidenceRoot($head),
        );

        $selection = self::select(
            $analysisUsed,
            $changed,
            TestReferenceIndex::fromTests(base_path('tests'), base_path()),
            $graph->unresolvedDispatchSites(),
            $graph,
            self::configuredFrontendTestIndex(),
            $graph->hasUnparseableFiles(),
            $changedTests,
            $changedFrontendTests,
            $suiteRunnable,
            $unrunnable,
            $provenance,
        );

        return ['base' => $base] + $selection + ['untrackedFiles' => $untracked];
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $untracked
     * @return array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, testsTotal: int, testsShare: float, testsExcluded: int, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, untrackedFiles: list<string>}
     */
    private static function undeterminedForCurrentDiff(string $base, array $reasons, array $untracked): array
    {
        // The suite size is a property of the checkout, not of the diff, so it is still answerable
        // when the diff is not — and a caller reading `testsTotal` must not get an undefined key
        // just because the base ref would not resolve.
        $unrunnable = RichterConfig::unrunnableTestPaths();

        return ['base' => $base, 'determinable' => false, 'reasons' => $reasons, 'tests' => []]
            + self::sizeSignal([], TestReferenceIndex::runnableFiles(base_path('tests'), base_path()), $unrunnable, 0)
            + ['frontendTests' => [], 'unreferencedEntryPoints' => 0, 'unresolvedDispatchSites' => [], 'untrackedFiles' => $untracked];
    }

    /**
     * The frontend spec files the diff itself changed, taken from the diff's own paths — every
     * changed file, whether a lane analysed it or not, since a project without `frontend.roots`
     * configured drops its specs into the out-of-scope list instead. A DELETED spec is filtered
     * out for the reason a deleted PHP test is: handing a path that no longer exists to a runner
     * fails the run this selection exists to shorten.
     *
     * @param  list<ChangedFileSymbols>  $changed
     * @param  list<string>  $outOfScope
     * @return list<string>
     */
    private static function changedFrontendSpecs(array $changed, array $outOfScope): array
    {
        $paths = [
            ...array_map(static fn (ChangedFileSymbols $file): string => $file->file, $changed),
            ...$outOfScope,
        ];

        $specs = array_values(array_unique(array_filter(
            $paths,
            static fn (string $file): bool => FrontendTestIndex::isSpecFile($file) && is_file(base_path($file)),
        )));

        sort($specs);

        return $specs;
    }

    /**
     * The files left after the paths the runner cannot execute are removed. Globs are matched with
     * `fnmatch()`'s default flags, where `*` crosses `/` — so `tests/Browser/*` covers the whole
     * tree under it, which is the shape a project actually writes.
     *
     * @param  list<string>  $files
     * @param  list<string>  $globs
     * @return list<string>
     */
    private static function withoutUnrunnable(array $files, array $globs): array
    {
        return self::partitionUnrunnable($files, $globs)['kept'];
    }

    /**
     * {@see withoutUnrunnable()}, keeping which glob removed each dropped file — what `--explain`
     * needs to answer the first question a caller asks after configuring one.
     *
     * @param  list<string>  $files
     * @param  list<string>  $globs
     * @return array{kept: list<string>, excluded: array<string, string>}
     */
    private static function partitionUnrunnable(array $files, array $globs): array
    {
        if ($globs === []) {
            return ['kept' => $files, 'excluded' => []];
        }

        $kept = [];
        $excluded = [];

        foreach ($files as $file) {
            $matched = array_find($globs, static fn (string $glob): bool => fnmatch($glob, $file));
            if ($matched === null) {
                $kept[] = $file;

                continue;
            }

            $excluded[$file] = $matched;
        }

        return ['kept' => $kept, 'excluded' => $excluded];
    }

    /**
     * The three advisory size fields. `testsTotal` describes the SUITE, `testsExcluded` describes
     * THIS run — different sets, so adding them together means nothing. Both sides of the share come
     * from the same side of the exclusion filter: a numerator taken before the filter and a
     * denominator taken after it would overstate every selection.
     *
     * Advisory by construction — nothing here touches `reasons`, so nothing here can withdraw a
     * selection. "This selection is large" and "this selection cannot be trusted" are different
     * claims, and `determinable` already carries the second.
     *
     * @param  list<string>  $selected  the selection, already filtered
     * @param  list<string>  $suiteRunnable  every runnable file in the suite, unfiltered
     * @param  list<string>  $globs
     * @return array{testsTotal: int, testsShare: float, testsExcluded: int}
     */
    private static function sizeSignal(array $selected, array $suiteRunnable, array $globs, int $excluded): array
    {
        $total = count(self::withoutUnrunnable($suiteRunnable, $globs));

        return [
            'testsTotal' => $total,
            'testsShare' => $total === 0 ? 0.0 : round(count($selected) / $total, 2),
            'testsExcluded' => $excluded,
        ];
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
     * @param  CodeGraph|null  $graph  handed to the test index, which needs it for two entry-point
     *   shapes it cannot answer from `tests/` alone: a `schedule::` node, whose id is an opaque hash
     *   and only resolves through the command it runs, and a route driven by a CLASS rather than by
     *   its name or URI (a Livewire component, a Filament page). Without it both read unreferenced,
     *   which under-selects — the one direction this command must never fail in.
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
     * @param  list<string>  $changedFrontendTests  frontend spec files the diff itself changed. They
     *   are seeded the way `$changedTests` is, and for the same reason: the frontend axis only
     *   suggests specs referencing a reached route, so a spec for a pure function has no endpoint to
     *   match and would otherwise be invisible while a PHP test in the same position is listed.
     * @param  list<string>  $suiteRunnableFiles  every runnable test file in the suite
     *   ({@see TestReferenceIndex::runnableFiles()}) — the denominator behind `testsShare`. Empty
     *   means the size is unknown, and the share reports 0.0 rather than guessing.
     * @param  list<string>  $unrunnablePaths  globs whose tests the runner cannot execute
     *   ({@see RichterConfig::unrunnableTestPaths()}). Applied HERE, to the selection and to the
     *   suite total, never to the index: an excluded test is still real coverage for the
     *   `[test-referenced]` annotation and for the risk ladder, and excluding it at discovery would
     *   turn a browser-covered route into a reported gap.
     * @param  SelectionProvenance|null  $provenance  filled in as the axes run, never recomputed
     *   afterwards — a second pass deriving "which axis would have matched this" is a second
     *   implementation of the selection rule, and two implementations of one rule drift
     * @param-out SelectionProvenance $provenance
     * @return array{determinable: bool, reasons: list<string>, tests: list<string>, testsTotal: int, testsShare: float, testsExcluded: int, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>}
     */
    public static function select(array $result, array $changed, TestReferenceIndex $tests, array $unresolvedDispatchSites, ?CodeGraph $graph = null, ?FrontendTestIndex $frontendTests = null, bool $hasUnparseableFiles = false, array $changedTests = [], array $changedFrontendTests = [], array $suiteRunnableFiles = [], array $unrunnablePaths = [], ?SelectionProvenance &$provenance = null): array
    {
        $provenance ??= new SelectionProvenance();
        $reasons = [];

        if (in_array('unresolved', $result['coverage'], strict: true)) {
            $reasons[] = 'changed file(s) could not be placed in the graph (UNRESOLVED)';
        }

        if ($result['lowConfidence']) {
            // Named, for the reason the unfollowable-dispatch reason is named: a bare boolean withdraws
            // a whole test selection and leaves the reader nothing to look at. The kind is the part that
            // decides what to do about it — a property or a class-level modifier has no member node by
            // design, so the veto is correct and there is nothing to restructure.
            $reasons[] = 'a changed member could not be pinned to a graph node (low confidence): '
                . self::renderUnpinnable($changed);
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

        // Both shapes the `$graph` param documents read unreferenced without it — under-selection.
        if ($graph instanceof CodeGraph) {
            $tests->useGraph($graph);
        }

        $selected = $changedTests;
        $frontendSelected = $changedFrontendTests;
        $unreferenced = 0;

        foreach ([...$changedTests, ...$changedFrontendTests] as $file) {
            $provenance->changedFile($file);
        }

        // Selection walks the registry fan-out surfaces too. They are context in the report, since
        // they cannot tell one class of a registry from another — but each is a route that really
        // may dispatch the change, and a selection that skips them under-selects, which is the one
        // direction this command must never fail in.
        $entryPointsConsidered = [...$result['entryPoints'], ...$result['registryEntryPoints'] ?? []];

        foreach ($entryPointsConsidered as $entryPoint) {
            $referencingSpecs = $frontendTests?->testsReferencing($entryPoint) ?? [];
            $provenance->referencingEntryPoint($referencingSpecs, $entryPoint);
            $frontendSelected = [...$frontendSelected, ...$referencingSpecs];
            $referencing = $tests->testsReferencing($entryPoint);

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

            $provenance->referencingEntryPoint($runnable, $entryPoint);
            $selected = [...$selected, ...$runnable];
        }

        // The import axis is broad but DIRECTIONAL — every changed class, every class that reaches
        // the change, and a rename's vanished old FQCN — because a unit test of an intermediate
        // caller never references an entry point. {@see classesToMatch()} says why the downstream
        // direction is not here. Imports are a weak signal though, so unlike the entry-point axis,
        // non-runnable files (fixtures import app classes too) simply filter out without blocking
        // determination.
        $classesConsidered = self::classesToMatch($result, $changed);

        foreach ($classesConsidered as $class => $origin) {
            $importing = TestReferenceIndex::runnableOnly($tests->testsImporting($class));
            $provenance->importing($importing, $class, $origin);
            $selected = [...$selected, ...$importing];
        }

        $provenance->considered(count($entryPointsConsidered), count($classesConsidered));

        // Both axes and the seeded set are unioned first, so a file reached two ways is counted once
        // — then the exclusion runs over the whole selection at once, which is where the count of
        // what it removed comes from.
        $selected = array_values(array_unique($selected));
        $partition = self::partitionUnrunnable($selected, $unrunnablePaths);
        $provenance->excludedBy($partition['excluded']);
        $excluded = count($partition['excluded']);
        $selected = $partition['kept'];
        sort($selected);
        $frontendSelected = array_values(array_unique($frontendSelected));
        sort($frontendSelected);

        return [
            'determinable' => $reasons === [],
            'reasons' => $reasons,
            // The selection is still reported when not determinable — useful context — but a
            // consumer must treat it as incomplete and run the full suite.
            'tests' => $selected,
        ] + self::sizeSignal($selected, $suiteRunnableFiles, $unrunnablePaths, $excluded) + [
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
     * The app classes whose importers count as affected, each mapped to why it qualified — `changed`
     * for a class the diff touched, `caller` for one that reaches the change. The origin is what
     * lets `--explain` name the relation instead of only the class.
     *
     * The axis is UPWARD ONLY, and that is a decision rather than an omission. A test importing a
     * class the change CALLS exercises that class: the callee did not change, and the test never
     * touches the code that did. A test importing a class that calls the change is the opposite —
     * it runs the changed code, and it may reference no entry point at all, which is the whole
     * reason this axis exists beside the entry-point one.
     *
     * The downstream half used to be included here. It selected the change's dependency closure to
     * depth six, which measured 97-99% of the whole suite on four real diffs of different shapes.
     * Nothing replaced it: every case it could have protected belongs to the caller half, the
     * changed-class half, or the entry-point axis.
     *
     * @param  array{entryPoints: list<string>, callers?: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, ...}  $result
     * @param  list<ChangedFileSymbols>  $changed
     * @return array<string, 'changed'|'caller'>
     */
    private static function classesToMatch(array $result, array $changed): array
    {
        $classes = [];

        foreach ($changed as $file) {
            if ($file->fqcn !== '') {
                $classes[$file->fqcn] = 'changed';
            }

            foreach ($file->directSeeds as $seed) {
                if (AppNamespace::isAppClass($seed)) {
                    $classes[$seed] = 'changed';
                }
            }
        }

        foreach ($result['callers'] ?? [] as $hop) {
            $class = explode('::', $hop['node'], 2)[0];

            // A changed class that is also its own caller keeps the stronger origin: the diff
            // touched it, which is the more direct thing to tell a reader.
            if (AppNamespace::isAppClass($class) && ! isset($classes[$class])) {
                $classes[$class] = 'caller';
            }
        }

        return $classes;
    }

    /**
     * The members a low-confidence run could not pin, as `file (Class::member, kind)`.
     *
     * A class-level modifier change carries an EMPTY member name — there is no member, the declaration
     * itself changed — so it reads as the class rather than as `Class::`.
     *
     * @param  list<ChangedFileSymbols>  $changed
     */
    private static function renderUnpinnable(array $changed): string
    {
        $named = [];

        foreach ($changed as $file) {
            foreach ($file->unpinnableMembers() as $member) {
                $named[] = $member->name === ''
                    ? sprintf('%s (%s, class declaration)', $file->file, $file->fqcn)
                    : sprintf('%s (%s::%s, %s)', $file->file, $file->fqcn, $member->name, $member->kind);
            }
        }

        // Never empty in practice — `lowConfidence` is set from these very members — but the fallback
        // keeps the sentence whole rather than trailing off after a colon.
        return $named === [] ? 'the member could not be named' : implode('; ', $named);
    }

    /**
     * Whether a possible dispatch target ({@see DispatchTarget}) is the changed class itself or sits
     * in the change's upward-caller closure. This is the ONLY condition under which an unfollowable
     * dispatch (S2) can under-select the change's tests: the hidden `dispatcher → target::handle`
     * edge inserts a missing caller only when such a target reaches the change. The uncertainty
     * direction is safe — DispatchTarget fails toward "yes", so this over-fires rather than under.
     *
     * Why this is not FINER, since a finer version is the obvious thing to ask for — scope the reason to
     * what the diff can reach, so a dispatch in an unrelated subtree stops vetoing selection.
     *
     * It already is, as far as it soundly can be. The hidden edge is `dispatcher → unknownJob::handle`, and
     * it matters exactly when that job is upstream of the change — which is the question this asks.
     * Narrowing it to "the job THIS site could dispatch" needs the target, and not naming the target is
     * what made the dispatch unfollowable in the first place; for a chain of closures there is no class to
     * name at all. In a large application with jobs throughout, the honest answer to the sound question is
     * simply yes.
     *
     * So the way past a site is not a looser rule here but a stricter reader there: a shape that can be
     * followed stops being a site at all. {@see AccumulatedArrayJobs} is the most recent of those, and
     * removing a site outright is always better than excusing one.
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
