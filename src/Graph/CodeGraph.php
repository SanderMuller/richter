<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * A directed code graph (nodes connected by typed edges) with upstream/downstream traversal. Built
 * from Laravel Brain's analysis by {@see CodeGraphBuilder} but knows nothing about Brain, so it stays
 * trivially testable. Node ids are opaque strings carried verbatim from the edges. Nodes may carry
 * a sparse metadata record ({@see NodeMetadata}) — defining file/line, route uri, security surface —
 * which annotates reports but never influences the walks.
 *
 * @phpstan-import-type MetadataShape from NodeMetadata
 * @phpstan-import-type SecurityShape from NodeMetadata
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
     * Lazily-built token → node keys index, used by {@see nodesContaining()} to shrink the regex
     * scan down from every node to only those sharing an identifier token with the needle. Built
     * on first use (not in the constructor) so `callersOf`/`dependenciesOf`-only callers never pay
     * for it.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $nodesByToken = null;

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  bool  $hasUnparseableFiles  an app file could not be parsed at all (S1 — see plan
     *   036). Its content and edges are unknown, so it could reach anything — this is a GLOBAL,
     *   unscopeable determinability blocker. Required (no default) so a missed construction site
     *   fails loud (ArgumentCountError → fail-safe backstop) instead of silently reading `false`.
     * @param  bool  $hasUnresolvedDispatches  a dispatch verb was seen whose target couldn't be
     *   statically resolved (S2 — see plan 036). The target is still bounded to "a dispatchable",
     *   so unlike `$hasUnparseableFiles` this one is change-scopeable by the caller.
     * @param  array<string, MetadataShape>  $nodeMetadata  sparse per-node annotation, keyed by node id
     */
    public function __construct(array $edges, private readonly bool $hasUnparseableFiles, private readonly bool $hasUnresolvedDispatches = false, private readonly array $nodeMetadata = [])
    {
        // Canonical order before building adjacency: a fresh build receives edges build-ordered,
        // a cache-revived graph receives them regrouped by source ({@see toArray()}). Without a
        // shared order the BFS tie-breaks differently, and --explain would show a different (equal
        // length) chain on a warm cache than on --no-cache for the same commit.
        usort($edges, static fn (array $a, array $b): int => [$a['source'], $a['target'], $a['type']] <=> [$b['source'], $b['target'], $b['type']]);

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
     * An app file the build could not parse at all — S1, see plan 036. Its content is unknown, so
     * it could hide an edge to anything; unlike {@see hasUnresolvedDispatches()} this can never be
     * scoped to a change and must stay a global determinability blocker.
     */
    public function hasUnparseableFiles(): bool
    {
        return $this->hasUnparseableFiles;
    }

    /**
     * Exact node-id membership — unlike {@see nodesContaining()}, `route::GET::/posts` never
     * matches its own prefix inside `route::GET::/posts/{post}`.
     */
    public function hasNode(string $node): bool
    {
        return isset($this->nodes[$node]);
    }

    /**
     * The defining source location of a node, when the build could pin one. Sparse like the
     * metadata itself: `line` is present only when known, so JSON consumers never see nulls.
     *
     * @return array{file: string, line?: int}|null
     */
    public function locationOf(string $node): ?array
    {
        $metadata = $this->nodeMetadata[$node] ?? [];

        if (! isset($metadata['file'])) {
            return null;
        }

        $location = ['file' => $metadata['file']];

        if (isset($metadata['line'])) {
            $location['line'] = $metadata['line'];
        }

        return $location;
    }

    /**
     * Brain's security surface for a route node — exposure, risk level, issues. Annotation only:
     * it never feeds the risk model or the walks.
     *
     * @return SecurityShape|null
     */
    public function securityOf(string $node): ?array
    {
        return $this->nodeMetadata[$node]['security'] ?? null;
    }

    /**
     * The Pennant feature flags gating a route node ({@see NodeMetadata::withRouteGates()}).
     * Annotation only, like {@see securityOf()}.
     *
     * @return list<string>
     */
    public function gatesOf(string $node): array
    {
        return $this->nodeMetadata[$node]['gates'] ?? [];
    }

    /**
     * The graph as plain constructor input, for on-disk caching. Every edge lives in the downstream
     * adjacency (nodes only exist through edges), so deriving from it loses nothing.
     *
     * @return array{edges: list<array{source: string, target: string, type: string}>, hasUnparseableFiles: bool, hasUnresolvedDispatches: bool, nodeMetadata: array<string, MetadataShape>}
     */
    public function toArray(): array
    {
        $edges = [];

        foreach ($this->downstream as $source => $hops) {
            foreach ($hops as $hop) {
                $edges[] = ['source' => $source, 'target' => $hop['node'], 'type' => $hop['via']];
            }
        }

        return [
            'edges' => $edges,
            'hasUnparseableFiles' => $this->hasUnparseableFiles,
            'hasUnresolvedDispatches' => $this->hasUnresolvedDispatches,
            'nodeMetadata' => $this->nodeMetadata,
        ];
    }

    /** @param  array{edges: list<array{source: string, target: string, type: string}>, hasUnparseableFiles: bool, hasUnresolvedDispatches: bool, nodeMetadata?: array<string, MetadataShape>}  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['edges'], $data['hasUnparseableFiles'], $data['hasUnresolvedDispatches'], $data['nodeMetadata'] ?? []);
    }

    /**
     * Nodes whose identifier contains the needle at identifier boundaries on both sides — so
     * "Post" matches `model::App\Models\Post` but neither `…\PostContainer` nor `SuperPost`.
     * A token index narrows the regex scan down to nodes sharing an identifier token with the
     * needle; the index is an over-approximation, and the regex above remains the source of truth.
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
            $this->candidatesFor($needle),
            static fn (string $node): bool => preg_match($pattern, $node) === 1,
        ));
    }

    /**
     * Nodes worth running the boundary regex over: every node sharing an identifier token with the
     * needle, via the shortest of the needle's token posting lists. Any node genuinely matching the
     * needle must carry ALL of the needle's tokens as exact identifier tokens (the boundary regex
     * copies the needle verbatim, so each of its tokens lands as a complete identifier run in the
     * node) — so it necessarily appears in the shortest posting list too, and intersecting the other
     * lists on top would only cost more without excluding anything the regex wouldn't already reject.
     * A needle with no identifier tokens at all (e.g. `::`) falls back to every node, unchanged from
     * the pre-index full scan.
     *
     * @return list<string>
     */
    private function candidatesFor(string $needle): array
    {
        $needleTokens = $this->tokensOf($needle);

        if ($needleTokens === []) {
            return array_keys($this->nodes);
        }

        $index = $this->nodesByToken();
        $shortest = $index[$needleTokens[0]] ?? [];

        foreach (array_unique($needleTokens) as $token) {
            $postings = $index[$token] ?? [];

            if (count($postings) < count($shortest)) {
                $shortest = $postings;
            }
        }

        return $shortest;
    }

    /**
     * @return array<string, list<string>>
     */
    private function nodesByToken(): array
    {
        if ($this->nodesByToken !== null) {
            return $this->nodesByToken;
        }

        $index = [];

        foreach (array_keys($this->nodes) as $node) {
            foreach (array_unique($this->tokensOf($node)) as $token) {
                $index[$token][] = $node;
            }
        }

        return $this->nodesByToken = $index;
    }

    /**
     * Maximal runs of `[A-Za-z0-9_]`, lowercased — the same identifier-character class the boundary
     * regex in {@see nodesContaining()} treats as "not a boundary".
     *
     * @return list<string>
     */
    private function tokensOf(string $value): array
    {
        $tokens = preg_split('/[^A-Za-z0-9_]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
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
     * {@see callersOf()} with the traversed-from node kept, so the reached region can be drawn as a
     * node-link graph instead of a flat list. Each reached caller appears exactly once (BFS tree,
     * one parent per node) — the clean radial shape the HTML report draws, not the induced subgraph.
     *
     * Pairs with {@see dependencyEdgesOf()}: the two walks keep independent seen-sets, so a node
     * reachable both upstream and downstream appears in BOTH lists, possibly at different depths.
     * That is correct; a consumer merging them collapses each node to its MINIMUM depth.
     *
     * @param  list<string>  $from
     * @return list<array{source: string, target: string, via: string, depth: int}>
     */
    public function callerEdgesOf(array $from, int $maxDepth = 6): array
    {
        return $this->walkEdges($this->upstream, $from, $maxDepth, hopIsSource: true);
    }

    /**
     * {@see dependenciesOf()} with the traversed-from node kept. Same BFS-tree and duplicate-node
     * semantics as {@see callerEdgesOf()}.
     *
     * @param  list<string>  $from
     * @return list<array{source: string, target: string, via: string, depth: int}>
     */
    public function dependencyEdgesOf(array $from, int $maxDepth = 6): array
    {
        return $this->walkEdges($this->downstream, $from, $maxDepth, hopIsSource: false);
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
     * Shortest caller chain from the walk's seeds up to each requested target, keyed by target.
     * Each chain runs target-first and seed-last in call direction — `route::POST::/checkout`
     * calls the next hop, which calls the next, down to the changed symbol — so a reviewer reads
     * it as "this entry point reaches the change via …". Every hop's `via` is the type of the edge
     * to the NEXT hop in the chain; the final (seed) hop carries `''`. A target the walk never
     * reaches (e.g. a self-listed entry class appended outside the graph) is simply absent.
     *
     * @param  list<string>  $from
     * @param  list<string>  $targets
     * @return array<string, list<array{node: string, via: string}>>
     */
    public function callerPathsTo(array $from, array $targets, int $maxDepth = 6): array
    {
        if ($from === [] || $targets === []) {
            return [];
        }

        // First-visit parent pointers make each reconstructed chain a BFS-shortest path, and
        // guarantee termination on cycles (a seed never gains a parent).
        $parents = [];

        $this->bfs($this->upstream, $from, $maxDepth, static function (array $hop, int $depth, bool $firstVisit, string $fromNode) use (&$parents): void {
            if ($firstVisit) {
                $parents[$hop['node']] = ['node' => $fromNode, 'via' => $hop['via']];
            }
        });

        $seeds = array_flip($from);
        $paths = [];

        foreach ($targets as $target) {
            if (! isset($parents[$target]) && ! isset($seeds[$target])) {
                continue;
            }

            $path = [];
            $node = $target;

            while (isset($parents[$node])) {
                $path[] = ['node' => $node, 'via' => $parents[$node]['via']];
                $node = $parents[$node]['node'];
            }

            $path[] = ['node' => $node, 'via' => ''];
            $paths[$target] = $path;
        }

        return $paths;
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
     * {@see walk()}, but keeping the node each edge was traversed FROM — the one thing `walk()`
     * drops. `source`/`target` follow GRAPH direction, never walk direction: on the upstream
     * adjacency the reached hop IS the caller and becomes `source`; on the downstream adjacency it
     * is the callee and becomes `target`. `$hopIsSource` picks which, so both directions share one
     * body and one orientation rule.
     *
     * The first-visit guard is kept deliberately: each REACHED node — the end the walk stepped to,
     * which is `source` upstream and `target` downstream — is emitted exactly once, so the result is
     * a BFS tree rather than the induced subgraph. Seeds sit at depth 0 and are never a reached
     * node; walking callers they still appear as a `target`, since their caller points at them.
     *
     * @param  array<string, list<array{node: string, via: string}>>  $adjacency
     * @param  list<string>  $from
     * @return list<array{source: string, target: string, via: string, depth: int}>
     */
    private function walkEdges(array $adjacency, array $from, int $maxDepth, bool $hopIsSource): array
    {
        $result = [];

        $this->bfs($adjacency, $from, $maxDepth, static function (array $hop, int $depth, bool $firstVisit, string $fromNode) use (&$result, $hopIsSource): void {
            if (! $firstVisit) {
                return;
            }

            $result[] = [
                'source' => $hopIsSource ? $hop['node'] : $fromNode,
                'target' => $hopIsSource ? $fromNode : $hop['node'],
                'via' => $hop['via'],
                'depth' => $depth,
            ];
        });

        return $result;
    }

    /**
     * Shared BFS primitive. Invokes $onEdge per traversed edge with the hop, the reached node's depth,
     * whether it's the node's first visit, and the node the edge was traversed from — so callers build
     * a first-visit hop list, an every-encounter via-type map, or a parent-pointer path index on one
     * scaffolding. Index-pointer queue (not array_shift, which reindexes on every pop) keeps the walk
     * linear; edges append in non-decreasing depth order.
     *
     * @param  array<string, list<array{node: string, via: string}>>  $adjacency
     * @param  list<string>  $from
     * @param  callable(array{node: string, via: string}, int, bool, string): void  $onEdge
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

                $onEdge($hop, $depth, $firstVisit, $current['node']);

                if (! $firstVisit) {
                    continue;
                }

                $seen[$hop['node']] = $depth;
                $queue[] = ['node' => $hop['node'], 'depth' => $depth];
            }
        }
    }
}
