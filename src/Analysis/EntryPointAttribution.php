<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Closure;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * Which changed file explains each reached entry point, and how far that file reaches on its own.
 *
 * A report lists every surface the whole diff reaches, sorted by label and cut at a cap. Touch one
 * widely-referenced class and that list is the application: on two production codebases the fifteen
 * rows a reader meets first held NONE of the routes the commit added or edited, because a class name
 * sorts before `route::`. The rows that matter are there — they are just not the ones shown.
 *
 * The discriminator is not the path length and not the directory. Both were measured and both fail:
 * an admin-panel resource sits as few hops from a changed model as the feature's own route does, and
 * the feature's routes are declared in a route file the diff never touches. What separates them is
 * how much the EXPLAINING file reaches: a changed controller reaches nine surfaces, a changed hub model
 * reaches ninety. So each entry point is attributed to the changed file with the SMALLEST own reach
 * that reaches it — the most specific explanation the diff offers — and the report orders on that.
 *
 * Measured over five commits across two applications, with member-precise seeds: ranking this way put
 * every feature-owned entry point inside the rendered cap on all five, where the label sort showed none
 * of them on the two hub-touching ones.
 *
 * The identity is the changed FILE PATH, never a class name. A route file, a config file and a file
 * declaring two classes are all valid attributors and none of them has one usable FQCN.
 *
 * An entry point no per-file walk explains — a self-listed entry class, a frontend surface, a node the
 * scoring dropped — gets no entry here. Absence is the honest answer: a null attributor would need a
 * reach number that does not exist, and inventing one would be evidence the walk never produced.
 *
 * @internal
 */
final readonly class EntryPointAttribution
{
    /**
     * @param  Closure(list<array{depth: int, node: string, via: string, file?: string, line?: int}>): list<string>  $entryPointsAmong
     *   the analyzer's own entry-point test, passed in rather than reimplemented — a second copy of
     *   that prefix vocabulary would drift. {@see HazardReach} takes it the same way.
     */
    public function __construct(private CodeGraph $graph, private Closure $entryPointsAmong) {}

    /**
     * @param  array<string, list<string>>  $perFileSeeds  changed file => the seeds it resolved to
     * @param  list<string>  $entryPoints  the run's reported entry points
     * @return array<string, array{via: string, ownReach: int}> keyed by entry-point node, in the order
     *                                                          `$entryPoints` gives; unexplained
     *                                                          entry points are absent
     */
    public function for(array $perFileSeeds, array $entryPoints, int $maxDepth): array
    {
        if ($entryPoints === []) {
            return [];
        }

        $reachedByFile = $this->reachedByFile($perFileSeeds, $maxDepth);
        $attribution = [];

        foreach ($entryPoints as $entryPoint) {
            $best = null;

            foreach ($reachedByFile as $file => $reached) {
                if (! isset($reached[$entryPoint])) {
                    continue;
                }

                $candidate = ['via' => $file, 'ownReach' => count($reached)];

                // Smallest reach wins; the file PATH breaks a tie, so two runs of one diff never
                // disagree about which of two equally specific files explains a surface.
                if ($best === null || [$candidate['ownReach'], $file] < [$best['ownReach'], $best['via']]) {
                    $best = $candidate;
                }
            }

            if ($best !== null) {
                $attribution[$entryPoint] = $best;
            }
        }

        return $attribution;
    }

    /**
     * The reported entry points in reading order — the ONE implementation of that order, called by
     * the prose formats through {@see EntryPointRow::build()} and by the machine payload through
     * {@see JsonPresenter::detectChanges()}.
     *
     * It is one method rather than two sorts on one key because the two surfaces disagreed once
     * already: the formatters sorted, the JSON document copied the walk order, and a 353-surface
     * report's two lists shared a zero-length prefix — an agent reading the machine list from the top
     * met exactly the order 0.61.0 set out to replace. A shared key would not have prevented that;
     * only a shared call does.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, array{via: string, ownReach: int}>  $attribution
     * @return list<string>
     */
    public static function order(array $entryPoints, array $attribution): array
    {
        usort($entryPoints, static fn (string $a, string $b): int => self::sortKey($a, NodeLabel::display($a), $attribution)
            <=> self::sortKey($b, NodeLabel::display($b), $attribution));

        return $entryPoints;
    }

    /**
     * The sort key one entry point gets: attributed rows first, ordered by how specifically the diff
     * explains them, then by label, then by the node id so the order is TOTAL and two runs of one diff
     * render identically.
     *
     * The node id is what makes it total, and it is not redundant with the label: `NodeLabel::display()`
     * truncates a `command::` id at its first whitespace, so two commands differing only in their
     * arguments render one label between them. Without the id they tie, and a stable sort then falls
     * back to whatever order the walk handed in — an order this method's callers must not inherit.
     *
     * An UNATTRIBUTED entry point sorts last rather than first. It is not less important — a
     * self-listed entry class is often the change itself — but no walk explains it, so there is no
     * specificity to compare, and putting it above rows that do have one would claim an ordering the
     * data does not support.
     *
     * @param  array<string, array{via: string, ownReach: int}>  $attribution
     * @return array{0: int, 1: int, 2: string, 3: string}
     */
    public static function sortKey(string $node, string $label, array $attribution): array
    {
        $entry = $attribution[$node] ?? null;

        return [$entry === null ? 1 : 0, $entry['ownReach'] ?? 0, $label, $node];
    }

    /**
     * One upward walk per changed file, association edges excluded — the same exclusion
     * {@see ImpactAnalyzer::detectChanges()} applies to the list it reports, so a surface can never be
     * attributed through a path the report refuses to count.
     *
     * This is NEW work, not a walk the run already performs: `riskInputs()` memoizes two counts rather
     * than the set, and it runs only for changed entry-point-class files and changed job files. Files
     * resolving to an identical seed set share one walk, which is why the memo is keyed on the seeds
     * and not on the path.
     *
     * @param  array<string, list<string>>  $perFileSeeds
     * @return array<string, array<string, true>> file => reached entry points as a set
     */
    private function reachedByFile(array $perFileSeeds, int $maxDepth): array
    {
        $memo = [];
        $reachedByFile = [];

        foreach ($perFileSeeds as $file => $seeds) {
            if ($seeds === []) {
                continue;
            }

            $sorted = $seeds;
            sort($sorted);
            $key = implode("\0", $sorted);

            if (! isset($memo[$key])) {
                $memo[$key] = array_fill_keys(
                    ($this->entryPointsAmong)($this->graph->callersOf($seeds, $maxDepth, ImpactAnalyzer::ASSOCIATION_EDGE_TYPES)),
                    true,
                );
            }

            $reachedByFile[$file] = $memo[$key];
        }

        return $reachedByFile;
    }
}
