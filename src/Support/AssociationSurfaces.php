<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\ImpactAnalyzer;

/**
 * Splits the association surfaces a report lists into the ones worth a reader's eye and the ones that
 * only say "this class is in a registry".
 *
 * Every entry in that section is context rather than reach, but they are not equally weak, and a flat
 * list spends the reader's attention as though they were. A `model-relationship` or a `model-to-policy`
 * link names ONE model or policy: it says something true of this change and not of every other change
 * to a sibling. A `config-registry-fanout` names no single class — the surfaces behind it are identical
 * for every class the registry lists, which is precisely why the edge is excluded from reach in the
 * first place ({@see ImpactAnalyzer::ASSOCIATION_EDGE_TYPES}). Fifty of those are one fact about a
 * registry, not fifty facts about the diff.
 *
 * So the fan-out group collapses under the single cause it shares, and the discriminating links stay
 * in front of the reader. Nothing is dropped: a caller that collapses a group still prints its count,
 * because a section that silently shortened itself would be the falsely-reassuring report this package
 * refuses to write.
 *
 * A surface with no recorded reason stays with the discriminating group. Absence of a reason is not
 * evidence of a weak one, and hiding what the walk could not classify is the wrong direction to fail.
 *
 * @internal
 */
final class AssociationSurfaces
{
    /**
     * @param  list<string>  $surfaces  the reported association entry points
     * @param  array<string, list<string>>  $via  surface => the association edge types that reached it
     * @return array{0: list<string>, 1: list<string>} the discriminating surfaces, then the ones
     *                                                reached only by a registry fan-out
     */
    public static function partition(array $surfaces, array $via): array
    {
        $discriminating = [];
        $fanoutOnly = [];

        foreach ($surfaces as $surface) {
            $reasons = $via[$surface] ?? [];

            if ($reasons === ['config-registry-fanout']) {
                $fanoutOnly[] = $surface;

                continue;
            }

            $discriminating[] = $surface;
        }

        return [$discriminating, $fanoutOnly];
    }
}
