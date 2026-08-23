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
     * @param  array<string, string>  $memberByClass  UI-component class => the member that stood in for
     *   it. A UI surface is reported CLASS-level while the walk reached one of its MEMBERS, so the
     *   class node is a target no path ends on; without the stand-in every Livewire, Filament and Nova
     *   surface answers with no reason at all.
     * @return array<string, list<string>>
     */
    public function for(array $surfaces, array $seeds, int $maxDepth, array $memberByClass): array
    {
        if ($surfaces === []) {
            return [];
        }

        $targets = array_values(array_unique([...$surfaces, ...array_values($memberByClass)]));

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
            $member = $memberByClass[$surface] ?? '';
            $path = $withoutFanout[$surface]
                ?? $withoutFanout[$member]
                ?? $anyPath[$surface]
                ?? $anyPath[$member]
                ?? [];

            $types = array_values(array_unique(array_intersect(
                array_column($path, 'via'),
                ImpactAnalyzer::ASSOCIATION_EDGE_TYPES,
            )));
            sort($types);
            $reasons[$surface] = $types;
        }

        return $reasons;
    }
}
