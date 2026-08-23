<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * Why each association surface is listed: the association edge types a report needs in order to tell a
 * link that names ONE model from one that names every class a registry lists.
 *
 * Split out of {@see ImpactAnalyzer} because that class had no headroom: it measured 80 against
 * phpstan.neon's class cognitive-complexity ceiling of 80 before this lane moved out. The split is
 * forced, not stylistic — though "why is this surface here" is also one question with two awkward
 * parts, and they are easier to explain together.
 *
 * @internal
 */
final readonly class AssociationReasons
{
    public function __construct(private CodeGraph $graph) {}

    /**
     * @param  list<string>  $surfaces  the reported association entry points
     * @param  list<string>  $seeds
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $callers
     * @param  callable(string): (string|null)  $uiComponentClassOf  the analyzer's own UI-class test,
     *   passed in rather than reimplemented — a second copy of that namespace vocabulary would drift.
     * @return array<string, list<string>>
     */
    public function for(array $surfaces, array $seeds, int $maxDepth, array $callers, callable $uiComponentClassOf): array
    {
        if ($surfaces === []) {
            return [];
        }

        $membersByClass = $this->membersByClass($callers, $uiComponentClassOf);
        $targets = $surfaces;

        foreach ($membersByClass as $members) {
            foreach ($members as $member) {
                $targets[] = $member;
            }
        }

        $targets = array_values(array_unique($targets));

        // The second map excludes the fan-out edge, so an entry in it proves the surface does not
        // require one. The first excludes nothing: it is looking FOR the association edges the
        // entry-point chains refuse.
        $anyPath = $this->graph->callerPathsTo($seeds, $targets, $maxDepth);
        // Where the graph holds no fan-out edge at all the two walks coincide, so the second is skipped
        // — output-identical, one BFS saved. A narrower guard ("no fan-out hop on any chosen path") is
        // NOT equivalent: the second walk explores a different frontier and can pick a different
        // fan-out-free chain, which changes a reported reason without changing any fold.
        $withoutFanout = $this->graph->hasEdgeType('config-registry-fanout')
            ? $this->graph->callerPathsTo($seeds, $targets, $maxDepth, ['config-registry-fanout'])
            : $anyPath;

        $reasons = [];

        foreach ($surfaces as $surface) {
            // The surface itself first, then each member that stood in for it — and the fan-out-free
            // map before the unrestricted one, so ANY route without a fan-out beats every route with
            // one.
            $candidates = [$surface, ...($membersByClass[$surface] ?? [])];
            $path = $this->firstPath($withoutFanout, $candidates) ?? $this->firstPath($anyPath, $candidates) ?? [];

            $types = array_values(array_unique(array_intersect(
                array_column($path, 'via'),
                ImpactAnalyzer::ASSOCIATION_EDGE_TYPES,
            )));
            sort($types);
            $reasons[$surface] = $types;
        }

        return $reasons;
    }

    /**
     * EVERY reached member per UI-component class. A UI surface is reported CLASS-level while the walk
     * reached one of its MEMBERS, so the class node is a target no path ends on — and it has to be ALL
     * of them, not the first: one member can have a short route carrying a fan-out while another has a
     * longer route carrying none, and the class does not depend on an edge only one member's shortest
     * path happened to use. {@see ImpactAnalyzer::uiMembersAmong()} keeps the first deliberately, being
     * the donor of a single shortest explain chain, which is a different question.
     *
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $callers
     * @param  callable(string): (string|null)  $uiComponentClassOf
     * @return array<string, list<string>>
     */
    private function membersByClass(array $callers, callable $uiComponentClassOf): array
    {
        $members = [];

        foreach ($callers as $hop) {
            $class = $uiComponentClassOf($hop['node']);

            if ($class === null || $class === $hop['node']) {
                continue;
            }

            if (! in_array($hop['node'], $members[$class] ?? [], strict: true)) {
                $members[$class][] = $hop['node'];
            }
        }

        return $members;
    }

    /**
     * The first of these nodes the map has a path for, or null.
     *
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths
     * @param  list<string>  $candidates
     * @return list<array{node: string, via: string, file?: string, line?: int}>|null
     */
    private function firstPath(array $paths, array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if (isset($paths[$candidate])) {
                return $paths[$candidate];
            }
        }

        return null;
    }
}
