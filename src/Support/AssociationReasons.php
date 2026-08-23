<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * Why each association surface is listed: the association edge types a report needs in order to tell a
 * link that names ONE model from one that names every class a registry lists.
 *
 * Split out of {@see ImpactAnalyzer} for the reason {@see AssociationSurfaces} is separate from the
 * formatters — that class sits at its cognitive-complexity ceiling, and "why is this surface here" is
 * one question with two awkward parts, both of which want explaining in one place.
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

        // Two path maps, because one path is only evidence about itself. `callerPathsTo()` answers with
        // ONE shortest route, so a surface reachable BOTH through a registry fan-out and through a
        // named relation would report the fan-out whenever that route is shorter — and fold on evidence
        // about a path it does not depend on. The second map excludes the fan-out edge, so an entry in
        // it IS the proof that the surface does not require one, and its types are the ones worth
        // reporting. No excluded types in the first map: it is looking FOR the association edges the
        // entry-point chains deliberately refuse.
        $anyPath = $this->graph->callerPathsTo($seeds, $targets, $maxDepth);
        $withoutFanout = $this->graph->callerPathsTo($seeds, $targets, $maxDepth, ['config-registry-fanout']);

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

            if (! in_array($hop['node'], $members[$class] ?? [], true)) {
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
