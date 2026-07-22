<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\DispatchTarget;

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
     * @param  array{coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, lowConfidence: bool, callers?: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies?: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, ...}  $result  an {@see ImpactAnalyzer::detectChanges()} result
     * @param  list<ChangedFileSymbols>  $changed
     * @param  CodeGraph|null  $graph  when given, a `schedule::` entry point resolves through its
     *   scheduled command (the schedule node id itself is an opaque hash) instead of blocking
     *   determination outright
     * @param  FrontendTestIndex|null  $frontendTests  when given, frontend specs referencing a
     *   reached route are suggested under `frontendTests` — advisory for the JS runner, never an
     *   input to determinability (a route no spec references is not a blocker)
     * @return array{determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int}
     */
    public static function select(array $result, array $changed, TestReferenceIndex $tests, bool $hasUnresolvedDispatches, bool $hasUnparseableFiles = false, ?CodeGraph $graph = null, ?FrontendTestIndex $frontendTests = null): array
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
        if ($hasUnresolvedDispatches && self::changeReachesDispatchable($result, $changed)) {
            $reasons[] = 'the graph contains job dispatches that could not be followed';
        }

        $selected = [];
        $frontendSelected = [];
        $unreferenced = 0;

        foreach ($result['entryPoints'] as $entryPoint) {
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

            $runnable = self::runnableOnly($referencing);

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
            $selected = [...$selected, ...self::runnableOnly($tests->testsImporting($class))];
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
                if (preg_match('/^App\\\\[\w\\\\]+$/', $seed) === 1) {
                    $classes[$seed] = true;
                }
            }
        }

        foreach ([...$result['callers'] ?? [], ...$result['dependencies'] ?? []] as $hop) {
            $class = explode('::', $hop['node'], 2)[0];

            if (preg_match('/^App\\\\[\w\\\\]+$/', $class) === 1) {
                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }

    /**
     * Only conventionally-named test files are runnable arguments to a test runner — a helper,
     * trait, or fixture under tests/ would make `php artisan test $(…)` execute nothing for that
     * path.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private static function runnableOnly(array $files): array
    {
        return array_values(array_filter(
            $files,
            static fn (string $file): bool => str_ends_with($file, 'Test.php'),
        ));
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
