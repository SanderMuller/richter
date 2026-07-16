<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * A directed code graph (nodes connected by typed edges) with upstream/downstream traversal. Built
 * from Laravel Brain's analysis by {@see CodeGraphBuilder} but knows nothing about Brain, so it stays
 * trivially testable. Node ids are opaque strings carried verbatim from the edges.
 */
final class CodeGraph
{
    /** @var array<string, list<array{node: string, via: string}>> */
    private array $downstream = [];

    /** @var array<string, list<array{node: string, via: string}>> */
    private array $upstream = [];

    /** @var array<string, true> */
    private array $nodes = [];

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  bool  $hasUnresolvedDispatches  a dispatch verb was seen whose job target couldn't be
     *   statically resolved — so a queue change reaching no entry point is "unknown", not "none".
     */
    public function __construct(array $edges, private readonly bool $hasUnresolvedDispatches = false)
    {
        foreach ($edges as $edge) {
            $this->downstream[$edge['source']][] = ['node' => $edge['target'], 'via' => $edge['type']];
            $this->upstream[$edge['target']][] = ['node' => $edge['source'], 'via' => $edge['type']];
            $this->nodes[$edge['source']] = true;
            $this->nodes[$edge['target']] = true;
        }
    }

    public function hasUnresolvedDispatches(): bool
    {
        return $this->hasUnresolvedDispatches;
    }

    /**
     * Nodes whose identifier contains the needle at identifier boundaries on both sides — so
     * "Video" matches `model::App\Models\Video` but neither `…\VideoContainer` nor `SuperVideo`.
     *
     * @return list<string>
     */
    public function nodesContaining(string $needle): array
    {
        if ($needle === '') {
            return [];
        }

        $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($needle, '/') . '(?![A-Za-z0-9_])/i';

        return array_values(array_filter(
            array_keys($this->nodes),
            static fn (string $node): bool => preg_match($pattern, $node) === 1,
        ));
    }

    /**
     * Breadth-first walk of everything that depends on the given nodes (callers).
     *
     * @param  list<string>  $from
     * @return list<array{depth: int, node: string, via: string}>
     */
    public function callersOf(array $from, int $maxDepth = 6): array
    {
        return $this->walk($this->upstream, $from, $maxDepth);
    }

    /**
     * Breadth-first walk of everything the given nodes depend on (callees).
     *
     * @param  list<string>  $from
     * @return list<array{depth: int, node: string, via: string}>
     */
    public function dependenciesOf(array $from, int $maxDepth = 6): array
    {
        return $this->walk($this->downstream, $from, $maxDepth);
    }

    /**
     * BFS over both directions, mapping each reached node (seeds excluded) to the SET of edge types
     * any traversed edge used to reach it — recorded on every encounter, not just first visit, so a
     * node reachable by both a relationship and a behavioural edge carries both regardless of BFS order.
     *
     * @param  list<string>  $from
     * @return array<string, array<string, true>>
     */
    public function reachedViaTypes(array $from, int $maxDepth = 6): array
    {
        $via = [];

        foreach ([$this->upstream, $this->downstream] as $adjacency) {
            $this->bfs($adjacency, $from, $maxDepth, static function (array $hop, int $depth, bool $firstVisit) use (&$via): void {
                $via[$hop['node']][$hop['via']] = true;
            });
        }

        foreach ($from as $seed) {
            unset($via[$seed]);
        }

        return $via;
    }

    /**
     * @param  array<string, list<array{node: string, via: string}>>  $adjacency
     * @param  list<string>  $from
     * @return list<array{depth: int, node: string, via: string}>
     */
    private function walk(array $adjacency, array $from, int $maxDepth): array
    {
        $result = [];

        $this->bfs($adjacency, $from, $maxDepth, static function (array $hop, int $depth, bool $firstVisit) use (&$result): void {
            if ($firstVisit) {
                $result[] = ['depth' => $depth, 'node' => $hop['node'], 'via' => $hop['via']];
            }
        });

        // BFS already appends in non-decreasing depth order, so no sort is needed.
        return $result;
    }

    /**
     * Shared BFS primitive. Invokes $onEdge per traversed edge with the hop, the reached node's depth,
     * and whether it's the node's first visit — so callers build a first-visit hop list or an
     * every-encounter via-type map on one scaffolding. Index-pointer queue (not array_shift, which
     * reindexes on every pop) keeps the walk linear; edges append in non-decreasing depth order.
     *
     * @param  array<string, list<array{node: string, via: string}>>  $adjacency
     * @param  list<string>  $from
     * @param  callable(array{node: string, via: string}, int, bool): void  $onEdge
     */
    private function bfs(array $adjacency, array $from, int $maxDepth, callable $onEdge): void
    {
        $seen = [];
        $queue = [];

        foreach ($from as $start) {
            $seen[$start] = 0;
            $queue[] = ['node' => $start, 'depth' => 0];
        }

        for ($head = 0; isset($queue[$head]); ++$head) {
            $current = $queue[$head];

            if ($current['depth'] >= $maxDepth) {
                continue;
            }

            foreach ($adjacency[$current['node']] ?? [] as $hop) {
                $depth = $current['depth'] + 1;
                $firstVisit = ! isset($seen[$hop['node']]);

                $onEdge($hop, $depth, $firstVisit);

                if (! $firstVisit) {
                    continue;
                }

                $seen[$hop['node']] = $depth;
                $queue[] = ['node' => $hop['node'], 'depth' => $depth];
            }
        }
    }
}
