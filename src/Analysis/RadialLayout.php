<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Positions the reached region as concentric rings for {@see HtmlFormatter}'s blast diagram. The
 * blast radius is already a depth-stratified BFS, so depth is the radius — deterministic,
 * snapshot-testable, and no JavaScript layout library.
 *
 * @phpstan-type PositionedNode array{id: string, x: float, y: float, depth: int, kind: 'seed'|'impacted'|'association', location: array{file: string, line?: int}|null}
 * @phpstan-type PositionedEdge array{x1: float, y1: float, x2: float, y2: float, via: string, source: string, target: string}
 * @phpstan-type Layout array{nodes: list<PositionedNode>, edges: list<PositionedEdge>, rings: list<array{depth: int, r: float}>, centre: float, hiddenCount: int, hiddenEdgeCount: int, impactedCount: int, size: int}
 */
final class RadialLayout
{
    /** Past a few hundred nodes a radial plot is unreadable; {@see HtmlFormatter} states what it hid. */
    public const int MAX_NODES = 300;

    private const int RING_STEP = 90;

    /** Depth 0 is a point, so several seeds would stack invisibly on it — spread them on a small disc. */
    private const int SEED_RADIUS = 30;

    private const int MARGIN = 60;

    /**
     * @param  list<array{source: string, target: string, via: string, depth: int}>  $edges  merged caller + dependency walks
     * @param  array<string, array<string, true>>  $reach  node → set of edge types that reached it
     * @param  list<string>  $seeds  the directly-changed nodes, drawn at the centre
     * @param  array<string, array{file: string, line?: int}>  $entryPointLocations  keyed by entry point; other nodes simply carry no location
     * @return Layout
     */
    public static function compute(array $edges, array $reach, array $seeds, array $entryPointLocations = []): array
    {
        $depths = self::depths($edges, $seeds);
        $kinds = self::kinds($depths, $reach, $seeds);

        $impactedCount = count(array_filter($kinds, static fn (string $kind): bool => $kind === 'impacted'));

        $drawn = self::applyCap($depths);
        $hiddenCount = count($depths) - count($drawn);

        $positions = self::positions($drawn);
        $drawableEdges = self::edges($edges, $positions);

        return [
            'nodes' => self::nodes($positions, $drawn, $kinds, $entryPointLocations),
            'edges' => $drawableEdges,
            'rings' => self::rings($drawn),
            'centre' => self::size($drawn) / 2,
            'hiddenCount' => $hiddenCount,
            'hiddenEdgeCount' => count($edges) - count($drawableEdges),
            'impactedCount' => $impactedCount,
            'size' => self::size($drawn),
        ];
    }

    /**
     * Shortest distance from the change to each node. A merged edge list has lost which end the walk
     * stepped to, but it does not need to: for an edge at depth d one end is the reached node (depth
     * d) and the other is its parent (depth d-1), so the minimum over a node's incident edges lands
     * on the right value for both. Seeds are pinned to 0 regardless.
     *
     * @param  list<array{source: string, target: string, via: string, depth: int}>  $edges
     * @param  list<string>  $seeds
     * @return array<string, int>
     */
    private static function depths(array $edges, array $seeds): array
    {
        $depths = [];

        foreach ($edges as $edge) {
            foreach ([$edge['source'], $edge['target']] as $node) {
                $depths[$node] = min($depths[$node] ?? $edge['depth'], $edge['depth']);
            }
        }

        foreach ($seeds as $seed) {
            $depths[$seed] = 0;
        }

        return $depths;
    }

    /**
     * @param  array<string, int>  $depths
     * @param  array<string, array<string, true>>  $reach
     * @param  list<string>  $seeds
     * @return array<string, 'seed'|'impacted'|'association'>
     */
    private static function kinds(array $depths, array $reach, array $seeds): array
    {
        $isSeed = array_flip($seeds);
        $kinds = [];

        foreach (array_keys($depths) as $node) {
            $kinds[$node] = match (true) {
                isset($isSeed[$node]) => 'seed',
                self::isRiskBearing($reach[$node] ?? []) => 'impacted',
                default => 'association',
            };
        }

        return $kinds;
    }

    /**
     * Reads {@see ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES} rather than copying it, so the diagram
     * and the Impacted tile cannot drift apart.
     *
     * @param  array<string, true>  $viaTypes
     */
    private static function isRiskBearing(array $viaTypes): bool
    {
        return array_diff_key($viaTypes, array_flip(ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES)) !== [];
    }

    /**
     * Shallowest nodes win the cap: closest to the change is most worth seeing.
     *
     * @param  array<string, int>  $depths
     * @return array<string, int>
     */
    private static function applyCap(array $depths): array
    {
        if (count($depths) <= self::MAX_NODES) {
            ksort($depths);

            return $depths;
        }

        $ordered = $depths;
        uksort($ordered, static fn (string $a, string $b): int => [$depths[$a], $a] <=> [$depths[$b], $b]);

        $kept = array_slice($ordered, 0, self::MAX_NODES, preserve_keys: true);
        ksort($kept);

        return $kept;
    }

    /**
     * @param  array<string, int>  $drawn
     * @return array<string, array{x: float, y: float}>
     */
    private static function positions(array $drawn): array
    {
        $rings = [];

        foreach ($drawn as $node => $depth) {
            $rings[$depth][] = $node;
        }

        $centre = self::size($drawn) / 2;
        $positions = [];

        foreach ($rings as $depth => $nodes) {
            sort($nodes);
            $count = count($nodes);

            foreach ($nodes as $index => $node) {
                // Start at 12 o'clock so a one-node ring reads as deliberate rather than arbitrary.
                $angle = ($index / $count) * 2 * M_PI - M_PI / 2;
                $radius = self::radius($depth, $count);

                $positions[$node] = [
                    'x' => round($centre + $radius * cos($angle), 2),
                    'y' => round($centre + $radius * sin($angle), 2),
                ];
            }
        }

        return $positions;
    }

    /**
     * @param  array<string, array{x: float, y: float}>  $positions
     * @param  array<string, int>  $drawn
     * @param  array<string, 'seed'|'impacted'|'association'>  $kinds
     * @param  array<string, array{file: string, line?: int}>  $entryPointLocations  keyed by entry point, so most nodes carry none
     * @return list<PositionedNode>
     */
    private static function nodes(array $positions, array $drawn, array $kinds, array $entryPointLocations): array
    {
        $nodes = [];

        foreach ($drawn as $node => $depth) {
            $nodes[] = [
                'id' => $node,
                'x' => $positions[$node]['x'],
                'y' => $positions[$node]['y'],
                'depth' => $depth,
                'kind' => $kinds[$node] ?? 'impacted',
                'location' => $entryPointLocations[$node] ?? null,
            ];
        }

        return $nodes;
    }

    /**
     * Skip edges with a capped-away endpoint — a line to nowhere reads as a rendering bug.
     *
     * @param  list<array{source: string, target: string, via: string, depth: int}>  $edges
     * @param  array<string, array{x: float, y: float}>  $positions
     * @return list<PositionedEdge>
     */
    private static function edges(array $edges, array $positions): array
    {
        $drawable = [];

        foreach ($edges as $edge) {
            if (! isset($positions[$edge['source']], $positions[$edge['target']])) {
                continue;
            }

            $drawable[] = [
                'x1' => $positions[$edge['source']]['x'],
                'y1' => $positions[$edge['source']]['y'],
                'x2' => $positions[$edge['target']]['x'],
                'y2' => $positions[$edge['target']]['y'],
                'via' => $edge['via'],
                'source' => $edge['source'],
                'target' => $edge['target'],
            ];
        }

        return $drawable;
    }

    /** A lone seed sits dead centre; several share a small disc so none is hidden under another. */
    private static function radius(int $depth, int $ringCount): float
    {
        if ($depth > 0) {
            return (float) ($depth * self::RING_STEP);
        }

        return $ringCount === 1 ? 0.0 : (float) self::SEED_RADIUS;
    }

    /**
     * One guide circle per occupied depth, so the rings the layout is built on are visible rather
     * than implied — without them a reader sees scattered dots and no structure.
     *
     * @param  array<string, int>  $drawn
     * @return list<array{depth: int, r: float}>
     */
    private static function rings(array $drawn): array
    {
        $depths = array_values(array_unique(array_filter($drawn, static fn (int $depth): bool => $depth > 0)));
        sort($depths);

        return array_map(static fn (int $depth): array => [
            'depth' => $depth,
            'r' => (float) ($depth * self::RING_STEP),
        ], $depths);
    }

    /** @param  array<string, int>  $drawn */
    private static function size(array $drawn): int
    {
        $maxDepth = $drawn === [] ? 0 : max($drawn);

        return 2 * ($maxDepth * self::RING_STEP + self::MARGIN);
    }
}
