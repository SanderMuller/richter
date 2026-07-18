<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;

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
     * @return array{determinable: bool, reasons: list<string>, tests: list<string>, unreferencedEntryPoints: int}
     */
    public static function select(array $result, array $changed, TestReferenceIndex $tests, bool $hasUnresolvedDispatches, ?CodeGraph $graph = null): array
    {
        $reasons = [];

        if (in_array('unresolved', $result['coverage'], strict: true)) {
            $reasons[] = 'changed file(s) could not be placed in the graph (UNRESOLVED)';
        }

        if ($result['lowConfidence']) {
            $reasons[] = 'a changed member could not be pinned to a graph node (low confidence)';
        }

        if ($hasUnresolvedDispatches) {
            $reasons[] = 'the graph contains job dispatches that could not be followed';
        }

        $selected = [];
        $unreferenced = 0;

        foreach ($result['entryPoints'] as $entryPoint) {
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

        return [
            'determinable' => $reasons === [],
            'reasons' => $reasons,
            // The selection is still reported when not determinable — useful context — but a
            // consumer must treat it as incomplete and run the full suite.
            'tests' => $selected,
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
}
