<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use InvalidArgumentException;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * The shortest directed path between two symbols, in call direction. Lives beside
 * {@see ImpactAnalyzer} rather than inside it (the analyzer delegates from
 * {@see ImpactAnalyzer::trace()}) so the analyzer's class complexity budget stays intact —
 * the same split {@see PublicWriteAuthCrossCheck} uses.
 */
final readonly class SymbolTracer
{
    public function __construct(private CodeGraph $graph) {}

    /**
     * Strictly directional: no reverse fallback; swapping the arguments queries the
     * reverse. On a miss, `furthestReached` is the deepest caller reached from `$to`
     * within the depth limit — how far upstream connectivity extends, never a pointer
     * toward `$from` (the walk has no directionality toward the target, so the deepest
     * hop can lie on an unrelated caller branch).
     *
     * @return array{from: string, to: string, resolvedFrom: list<string>, resolvedTo: list<string>, found: bool, path: list<array{node: string, via: string, file?: string, line?: int}>, furthestReached?: array{node: string, depth: int, file?: string, line?: int}}
     *
     * @throws InvalidArgumentException when either symbol matches no graph node —
     *   deliberately stricter than {@see ImpactAnalyzer::impact()}: an empty trace would
     *   read as "no path", the one misleading answer an error avoids
     */
    public function trace(string $from, string $to, int $maxDepth = 6): array
    {
        $fromNodes = $this->resolveOrFail($from);
        $toNodes = $this->resolveOrFail($to);

        // Upstream from the TO side: a FROM-side node the walk reaches yields the
        // from-first, to-last chain in call direction ({@see CodeGraph::callerPathsTo()}).
        $shortest = $this->shortestChain($this->graph->callerPathsTo($toNodes, $fromNodes, $maxDepth), $fromNodes);

        $result = [
            'from' => $from,
            'to' => $to,
            'resolvedFrom' => $fromNodes,
            'resolvedTo' => $toNodes,
            'found' => $shortest !== null,
            'path' => $shortest === null ? [] : $this->withLocations($shortest),
        ];

        if ($shortest === null && ($deepest = $this->deepestCallerOf($toNodes, $maxDepth)) !== null) {
            $result['furthestReached'] = $deepest;
        }

        return $result;
    }

    /**
     * On a miss the message carries the same lead {@see ImpactFormatter::impact()} renders — a
     * trace failing on a typo is the surface where a nearest-node hint pays off most, because
     * both arguments have to resolve before any path can be reported.
     *
     * @return list<string>
     */
    private function resolveOrFail(string $symbol): array
    {
        $nodes = $this->graph->nodesContaining(ltrim($symbol, '\\'));

        if ($nodes === []) {
            throw new InvalidArgumentException(
                "No graph nodes matched \"{$symbol}\"."
                . ImpactFormatter::missDiagnostic($this->graph->nearestNodes($symbol), $this->graph->nodeCount())
            );
        }

        return $nodes;
    }

    /**
     * @param  array<string, list<array{node: string, via: string}>>  $paths  keyed by from-side target node
     * @param  list<string>  $fromNodes
     * @return list<array{node: string, via: string}>|null
     */
    private function shortestChain(array $paths, array $fromNodes): ?array
    {
        $shortest = null;

        foreach ($fromNodes as $node) {
            $path = $paths[$node] ?? null;

            if ($path !== null && ($shortest === null || count($path) < count($shortest))) {
                $shortest = $path;
            }
        }

        return $shortest;
    }

    /**
     * @param  list<string>  $toNodes
     * @return array{node: string, depth: int, file?: string, line?: int}|null
     */
    private function deepestCallerOf(array $toNodes, int $maxDepth): ?array
    {
        $callers = $this->graph->callersOf($toNodes, $maxDepth);

        if ($callers === []) {
            return null;
        }

        // BFS hops arrive depth-ordered, so the last element is a deepest hop.
        $deepest = $callers[array_key_last($callers)];
        $hop = ['node' => $deepest['node'], 'depth' => $deepest['depth']];
        $location = $this->graph->locationOf($hop['node']);

        return $location === null ? $hop : $hop + $location;
    }

    /**
     * @param  list<array{node: string, via: string}>  $path
     * @return list<array{node: string, via: string, file?: string, line?: int}>
     */
    private function withLocations(array $path): array
    {
        return array_map(function (array $hop): array {
            $location = $this->graph->locationOf($hop['node']);

            return $location === null ? $hop : $hop + $location;
        }, $path);
    }
}
