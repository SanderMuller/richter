<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The hazards as a printed report lists them: one row each, except that one middleware lost by several
 * routes of one file is one row naming them all. A route group gives its middleware to every route
 * inside it, so one edited line is one hazard per route, and a reader answers it once.
 *
 * For the printed reports only. `--json`, the gate and the risk level see every hazard on its own.
 *
 * @internal
 */
final class HazardRows
{
    /**
     * Rows keep the order of the hazard that opens them. Hazards of one row agree on everything a row
     * prints once, the reach class included: an unguarded public route must not hide behind its gated
     * siblings.
     *
     * @param  list<Hazard>  $hazards
     * @return list<array{hazard: Hazard, subject: string, evidence: string, routes: list<string>}>
     *   `routes` is empty for a row of one hazard
     */
    public static function of(array $hazards): array
    {
        $grouped = [];

        foreach ($hazards as $index => $hazard) {
            $key = $hazard->sharedEvidence === null
                ? $index
                : implode("\n", [$hazard->tier, $hazard->lane, $hazard->cwe ?? '', $hazard->sharedEvidence, $hazard->reachLabel()]);

            $grouped[$key][] = $hazard;
        }

        return array_map(self::row(...), array_values($grouped));
    }

    /**
     * @param  non-empty-list<Hazard>  $hazards
     * @return array{hazard: Hazard, subject: string, evidence: string, routes: list<string>}
     */
    private static function row(array $hazards): array
    {
        $first = $hazards[0];

        if (count($hazards) === 1) {
            return ['hazard' => $first, 'subject' => $first->member, 'evidence' => $first->evidence, 'routes' => []];
        }

        // The member rides along because it is what `hazards.ignore` matches on.
        $routes = array_map(static fn (Hazard $hazard): string => "{$hazard->route} ({$hazard->member})", $hazards);

        return ['hazard' => $first, 'subject' => count($hazards) . ' routes', 'evidence' => (string) $first->sharedEvidence, 'routes' => $routes];
    }
}
