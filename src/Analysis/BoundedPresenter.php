<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Bounds a {@see JsonPresenter} document for the MCP structured-content surface. The CLI `--json`
 * document is a complete, semver-governed contract; an MCP response lands in an agent's context
 * window, where a hub symbol's full fan-out (measured at ~1.9 MB) displaces the task the agent is
 * doing. This step caps the breadth arrays at {@see LIST_CAP} preserving their existing order,
 * restricts the per-entry maps to the keys their capped list still shows, and adds `bounded` plus
 * one `<name>Total` per capped array so a capped list is impossible to read as complete.
 *
 * Only the MCP tools call this. The CLI commands serialize {@see JsonPresenter}'s document as-is —
 * a script has a disk, not a context window — so wiring this into a command is a contract break.
 *
 * @internal shared cap home for {@see ImpactFormatter}'s prose lists too; not public package API
 */
final class BoundedPresenter
{
    /**
     * One cap across surfaces: the prose lists, the prose hop sections, and the MCP breadth arrays
     * all cut at the same count, so the prefix an agent reads is the prefix a human sees.
     */
    public const int LIST_CAP = 15;

    /** The `impact` document's breadth arrays, each capped and totalled. */
    private const array IMPACT_LISTS = ['callers', 'dependencies', 'entryPoints', 'associationEntryPoints'];

    /** The `detect-changes` document's breadth arrays. It carries no caller/dependency walk. */
    private const array DETECT_CHANGES_LISTS = ['entryPoints', 'associationEntryPoints', 'relatedModels', 'traitAndOverrideReach'];

    /**
     * Maps keyed by entry-point node, restricted to the entry points still shown. `verification` is
     * deliberately absent: the risk level is derived from it, so it stays complete in every tier.
     */
    private const array ENTRY_POINT_MAPS = [
        'entryPointPaths',
        'entryPointLocations',
        'entryPointSecurity',
        'entryPointGates',
        'entryPointAuthGates',
        'entryPointRuntimeGuards',
        'entryPointTestReferences',
        'entryPointAttribution',
    ];

    /**
     * @param  array<string, mixed>  $document  the full {@see JsonPresenter::impact()} document
     * @param  bool  $full  return the uncapped lists and maps; `bounded`/totals stay present
     * @param  list<string>  $entries  entry-point nodes to keep visible past the cap
     * @return array<string, mixed>
     */
    public static function impact(array $document, bool $full = false, array $entries = []): array
    {
        return self::bound($document, self::IMPACT_LISTS, $full, $entries);
    }

    /**
     * @param  array<string, mixed>  $document  the full {@see JsonPresenter::detectChanges()} (or
     *                                          {@see JsonPresenter::emptyDetectChanges()}) document
     * @param  bool  $full  return the uncapped lists and maps; `bounded`/totals stay present
     * @param  list<string>  $entries  entry-point nodes to keep visible past the cap
     * @return array<string, mixed>
     */
    public static function detectChanges(array $document, bool $full = false, array $entries = []): array
    {
        $bounded = self::bound($document, self::DETECT_CHANGES_LISTS, $full, $entries);

        // The keep set nests its own breadth list. `keptTotal` sits inside the object beside
        // `droppedHub`, so the "dropped surfaces are the difference between kept and entryPoints"
        // reading — which only holds under `full` — has its honest totals in one place.
        $keepSet = $bounded['entryPointKeepSet'] ?? null;

        if (is_array($keepSet) && is_array($keepSet['kept'] ?? null)) {
            $kept = array_values($keepSet['kept']);
            $keepSet['keptTotal'] = count($kept);

            if (! $full && count($kept) > self::LIST_CAP) {
                $keepSet['kept'] = array_slice($kept, 0, self::LIST_CAP);
                $bounded['bounded'] = true;
            }

            $bounded['entryPointKeepSet'] = $keepSet;
        }

        return $bounded;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $listKeys
     * @param  list<string>  $entries
     * @return array<string, mixed>
     */
    private static function bound(array $document, array $listKeys, bool $full, array $entries): array
    {
        $bounded = false;
        $totals = [];
        $shownByKey = [];

        foreach ($listKeys as $key) {
            $list = $document[$key] ?? null;

            if (! is_array($list)) {
                $totals[$key . 'Total'] = 0;

                continue;
            }

            $list = array_values($list);
            $totals[$key . 'Total'] = count($list);
            $shown = $list;

            if (! $full && count($list) > self::LIST_CAP) {
                $shown = array_slice($list, 0, self::LIST_CAP);

                // Expansion intersects the request with the full list and appends each node at most
                // once: a node already in the prefix or repeated in the request never duplicates a
                // row, and an unknown name is silently no row at all — the agent copied names from a
                // previous response, and `bounded` plus the totals already say the list is short.
                if ($key === 'entryPoints' && $entries !== []) {
                    $inFull = array_fill_keys(array_filter($list, is_string(...)), true);
                    $inShown = array_fill_keys(array_filter($shown, is_string(...)), true);

                    foreach ($entries as $entry) {
                        if (isset($inFull[$entry]) && ! isset($inShown[$entry])) {
                            $shown[] = $entry;
                            $inShown[$entry] = true;
                        }
                    }
                }

                $document[$key] = $shown;

                // Computed from the final shown list: an `entries` request naming every omitted
                // node restores the complete list, and `bounded` claims something is hidden only
                // while something actually is.
                if (count($shown) < count($list)) {
                    $bounded = true;
                }
            }

            $shownByKey[$key] = $shown;
        }

        // Each map restricts to the shown keys of its own key domain; a key the full document never
        // held is never fabricated, so intentionally sparse maps (security carries routes only,
        // attribution omits unexplained surfaces) stay exactly as sparse as they were.
        $document = self::restrictMaps($document, self::ENTRY_POINT_MAPS, $shownByKey['entryPoints'] ?? null);
        $document = self::restrictMaps($document, ['associationEntryPointsVia'], $shownByKey['associationEntryPoints'] ?? null);
        $document = self::restrictMaps($document, ['traitAndOverrideReachVia'], $shownByKey['traitAndOverrideReach'] ?? null);

        $document['bounded'] = $bounded;

        return [...$document, ...$totals];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $mapKeys
     * @param  list<mixed>|null  $shown  the capped list whose entries stay visible; null caps nothing
     * @return array<string, mixed>
     */
    private static function restrictMaps(array $document, array $mapKeys, ?array $shown): array
    {
        if ($shown === null) {
            return $document;
        }

        $keep = array_fill_keys(array_filter($shown, is_string(...)), true);

        foreach ($mapKeys as $mapKey) {
            $map = $document[$mapKey] ?? null;

            if (is_array($map)) {
                $document[$mapKey] = array_intersect_key($map, $keep);
            }
        }

        return $document;
    }
}
