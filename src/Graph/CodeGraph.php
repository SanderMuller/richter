<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use SanderMuller\Richter\Analysis\ImpactAnalyzer;

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
    /**
     * Edges that express "is part of / relates to this type", as opposed to "runs this code". A path
     * built only from these reached its end by TYPE STRUCTURE alone, never by a call — which is what
     * {@see bfs()}'s hierarchy gate keys on.
     *
     * @var list<string>
     */
    private const array STRUCTURAL_EDGE_TYPES = ['implements', 'declares', 'inherits', 'uses-trait', 'override'];

    /**
     * The two edges Class-Hierarchy Analysis draws between one member and its counterpart elsewhere
     * in a hierarchy, and the only ones {@see bfs()}'s gate refuses. Both fan out by BREADTH — one
     * ancestor member to every descendant that overrides it, one ancestor member to every descendant
     * that inherits it — so reaching either end through type structure alone puts the change's
     * cousins in the report. The other structural edges connect a type to its own parts and do not
     * multiply.
     *
     * @var list<string>
     */
    private const array HIERARCHY_EDGE_TYPES = ['override', 'inherits'];

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
     * Lazily-built defining file → node ids index, used by {@see nodesDefinedIn()}.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $nodesByFile = null;

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  bool  $hasUnparseableFiles  an app file could not be parsed at all (S1 — see plan
     *   036). Its content and edges are unknown, so it could reach anything — this is a GLOBAL,
     *   unscopeable determinability blocker. Required (no default) so a missed construction site
     *   fails loud (ArgumentCountError → fail-safe backstop) instead of silently reading `false`.
     * @param  list<array{file: string, line: int, dispatcher: string}>  $unresolvedDispatchSites  every
     *   dispatch verb seen whose target couldn't be statically resolved (S2 — see plan 036), each
     *   named by the statement it sits on. The target is still bounded to "a dispatchable", so unlike
     *   `$hasUnparseableFiles` this one is change-scopeable by the caller. Carried as the sites rather
     *   than as a flag so a report can send a reader to the line instead of saying only that one
     *   exists; `hasUnresolvedDispatches()` derives from it, so the two can never disagree.
     * @param  array<string, MetadataShape>  $nodeMetadata  sparse per-node annotation, keyed by node id
     */
    public function __construct(array $edges, private readonly bool $hasUnparseableFiles, private readonly array $unresolvedDispatchSites = [], private readonly array $nodeMetadata = [])
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
        return $this->unresolvedDispatchSites !== [];
    }

    /**
     * The dispatch statements whose target could not be followed, sorted by file then line.
     *
     * @return list<array{file: string, line: int, dispatcher: string}>
     */
    public function unresolvedDispatchSites(): array
    {
        return $this->unresolvedDispatchSites;
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
     * Whether any edge in the graph carries this type. Lets a caller skip a second walk that would
     * only differ by excluding an edge type the graph does not contain.
     */
    public function hasEdgeType(string $type): bool
    {
        foreach ($this->downstream as $hops) {
            foreach ($hops as $hop) {
                if ($hop['via'] === $type) {
                    return true;
                }
            }
        }

        return false;
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
     * Brain's security surface for a route node — exposure, risk level, issues. It never feeds the
     * walks. It does reach the risk level, through one narrow door: a `PUBLIC_WRITE` issue on a route
     * that reaches a hazardous member makes that hazard's reach class `public-write`.
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
     * @return array{edges: list<array{source: string, target: string, type: string}>, hasUnparseableFiles: bool, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, nodeMetadata: array<string, MetadataShape>}
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
            'unresolvedDispatchSites' => $this->unresolvedDispatchSites,
            'nodeMetadata' => $this->nodeMetadata,
        ];
    }

    /** @param  array{edges: list<array{source: string, target: string, type: string}>, hasUnparseableFiles: bool, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, nodeMetadata?: array<string, MetadataShape>}  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['edges'], $data['hasUnparseableFiles'], $data['unresolvedDispatchSites'], $data['nodeMetadata'] ?? []);
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

    /** How many distinct node ids the graph holds — the "scanned N nodes" denominator behind a miss. */
    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    /**
     * Every distinct node id, sorted for stable output. A read-only enumeration — nothing
     * about the built or serialized graph shape changes.
     *
     * @return list<string>
     */
    public function nodes(): array
    {
        $nodes = array_keys($this->nodes);
        sort($nodes);

        return $nodes;
    }

    /**
     * The node ids the build pinned to this exact project-relative file — the graph's answer to
     * "what does this file define?", which for prefixed nodes no FQCN lookup can reach.
     *
     * `schedule::` is the case that motivates it: Brain ids a schedule entry as
     * `schedule::md5(type.target.frequency)`, so the Console Kernel that declares it matches no
     * node by name and its whole file reads UNRESOLVED. Same for a routes file, which defines
     * `route::` nodes and is not a class at all.
     *
     * Restricted to nodes that appear in an edge: metadata alone means Brain saw a definition, not
     * that the graph can traverse it. Offering an edge-less node as a seed would flip a file from
     * "couldn't place this" to "placed, reaches nothing" — the same falsely-reassuring answer the
     * UNRESOLVED state exists to prevent.
     *
     * @return list<string>
     */
    public function nodesDefinedIn(string $file): array
    {
        return $this->nodesByFile()[$file] ?? [];
    }

    /**
     * Lazily-built file → node ids index, on the same terms as {@see nodesByToken()}: a caller that
     * never resolves a changed file must not pay to walk the metadata.
     *
     * @return array<string, list<string>>
     */
    private function nodesByFile(): array
    {
        if ($this->nodesByFile !== null) {
            return $this->nodesByFile;
        }

        $index = [];

        foreach ($this->nodeMetadata as $node => $metadata) {
            if (isset($metadata['file']) && isset($this->nodes[$node])) {
                $index[$metadata['file']][] = $node;
            }
        }

        // Sorted so a seed list — and every report derived from it — is build-order independent.
        return $this->nodesByFile = array_map(static function (array $nodes): array {
            sort($nodes);

            return $nodes;
        }, $index);
    }

    /**
     * The node ids a missed lookup most likely meant, best first — the lead that "no graph nodes
     * matched" otherwise withholds. Ranked by how many identifier tokens the node shares with the
     * needle, then by edit distance on the needle's LAST token (the class basename, where a typo or a
     * wrong sub-namespace lands), then by id so the order is stable.
     *
     * Only nodes sharing at least one identifier token are candidates. That is what makes the
     * wrong-root-namespace case land first — `App\Services\Inspector` looked up in an `Acme\`-rooted
     * app shares every token but the first — while an unrelated node shares nothing and is never
     * offered as a guess. An empty result is itself information: nothing in the graph resembles the
     * symbol, so the caller falls back to reporting {@see nodeCount()}.
     *
     * @return list<string>
     */
    public function nearestNodes(string $needle, int $limit = 5): array
    {
        $tokens = $this->tokensOf($needle);

        if ($tokens === []) {
            return [];
        }

        // Read before deduping: `array_unique` keeps the FIRST occurrence, so on a needle whose basename
        // repeats an earlier segment (`App\Models\App`) the last unique token is not the basename.
        $term = end($tokens);
        $needleTokens = array_unique($tokens);
        $index = $this->nodesByToken();
        $candidates = [];

        foreach ($needleTokens as $token) {
            foreach ($index[$token] ?? [] as $node) {
                $candidates[$node] = true;
            }
        }

        $scored = [];

        foreach (array_keys($candidates) as $node) {
            $nodeTokens = $this->tokensOf($node);

            // Unreachable via the token index, but an id with nothing to compare must not be guessed at.
            if ($nodeTokens === []) {
                continue;
            }

            $scored[] = [
                'node' => $node,
                // Negated so a plain ascending sort puts the most-shared first.
                'rank' => -count(array_intersect($needleTokens, $nodeTokens)),
                'distance' => min(array_map(static fn (string $token): int => levenshtein($term, $token), $nodeTokens)),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => [$a['rank'], $a['distance'], $a['node']] <=> [$b['rank'], $b['distance'], $b['node']]);

        return array_slice(array_column($scored, 'node'), 0, $limit);
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
     * @param  list<string>  $excludeTypes  edge types the walk refuses to traverse. Not a filter on
     *   the result: a node reachable ONLY through an excluded edge does not appear at all, which is
     *   the point — the caller asking for this wants to know what survives without them.
     * @return list<array{depth: int, node: string, via: string}>
     */
    public function callersOf(array $from, int $maxDepth = 6, array $excludeTypes = []): array
    {
        return $this->walk($this->upstream, $from, $maxDepth, $excludeTypes);
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
     * The two directions may start from DIFFERENT seed sets. A coarse class-level seed answers "who
     * uses this class", which is a caller question; walking the same class node downstream instead
     * follows `implements`/`uses-trait` into everything the class is structurally related to. The
     * caller passes the narrower set as `$dependencySeeds` to keep the second walk on the members it
     * can actually pin. `null` means "same as `$from`", so every existing call site is unaffected.
     *
     * @param  list<string>  $from
     * @param  list<string>|null  $dependencySeeds
     * @return array<string, array<string, true>>
     */
    public function reachedViaTypes(array $from, int $maxDepth = 6, ?array $dependencySeeds = null): array
    {
        $dependencySeeds ??= $from;
        $via = [];

        foreach ([[$this->upstream, $from], [$this->downstream, $dependencySeeds]] as [$adjacency, $seeds]) {
            $this->bfs($adjacency, $seeds, $maxDepth, static function (array $hop, int $depth, bool $firstVisit) use (&$via): void {
                $via[$hop['node']][$hop['via']] = true;
            });
        }

        foreach ([...$from, ...$dependencySeeds] as $seed) {
            unset($via[$seed]);
        }

        return $via;
    }

    /**
     * Every distinct target of an edge of `$type` whose source is one of `$sources`, read straight
     * from the downstream adjacency — so ALL such edges are returned, unlike {@see dependencyEdgesOf()}
     * whose BFS tree keeps only the first edge reaching each node (an edge to an already-visited node
     * is dropped). Used to collect, e.g., the policies a route's reachable handlers authorize against
     * ({@see ImpactAnalyzer}'s PUBLIC_WRITE cross-check).
     *
     * @param  list<string>  $sources
     * @return list<string>  sorted, unique target node ids
     */
    public function outgoingTargetsOfType(array $sources, string $type): array
    {
        $targets = [];

        foreach ($sources as $source) {
            foreach ($this->downstream[$source] ?? [] as $hop) {
                if ($hop['via'] === $type) {
                    $targets[$hop['node']] = true;
                }
            }
        }

        $targets = array_keys($targets);
        sort($targets);

        return $targets;
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
     * @param  list<string>  $excludeTypes  edge types the walk refuses to traverse, so the chain it
     *   reconstructs is the shortest one that avoids them. Without this a target classified by a
     *   call-only walk can still be EXPLAINED through a shorter association hop, and the report
     *   contradicts its own classification.
     * @return array<string, list<array{node: string, via: string}>>
     */
    public function callerPathsTo(array $from, array $targets, int $maxDepth = 6, array $excludeTypes = []): array
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
        }, $excludeTypes);

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
     * @param  list<string>  $excludeTypes
     * @return list<array{depth: int, node: string, via: string}>
     */
    private function walk(array $adjacency, array $from, int $maxDepth, array $excludeTypes = []): array
    {
        $result = [];

        $this->bfs($adjacency, $from, $maxDepth, static function (array $hop, int $depth, bool $firstVisit) use (&$result): void {
            if ($firstVisit) {
                $result[] = ['depth' => $depth, 'node' => $hop['node'], 'via' => $hop['via']];
            }
        }, $excludeTypes);

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
     * A node {@see bfs()} re-reaches on a call-carrying path keeps the first edge that reached it —
     * see that method's note on what this tree does and does not claim.
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
     * One traversal rule lives here, because every walk needs it to agree: a hierarchy hop
     * ({@see HIERARCHY_EDGE_TYPES}) out of a node whose whole path from the seed was STRUCTURAL is
     * refused. `Class` -[implements]-> `Interface` -[declares]-> `Interface::method` -[override]->
     * every implementor is how a change to one class in a wide hierarchy reported every sibling as
     * reached — siblings that neither call nor run it. `inherits` reaches the same cousins from the
     * other side: every descendant that inherits an ancestor member, rather than every one that
     * overrides it. The rule reads the PATH, not the arriving edge: a call chain that happens to end
     * on a `declares` hop (a container `service` edge onto a class node, then its interface method)
     * IS real polymorphic dispatch, and Class-Hierarchy Analysis exists to follow it. A seed's path
     * is empty, so a seed-adjacent hierarchy hop is always legal — that keeps both directions of CHA:
     * a changed concrete override still climbs to the abstract call site, a changed abstract still
     * reaches its overrides, and a changed member still reaches the ancestor whose body runs.
     *
     * The flag is fixed at first visit, so a node first reached structurally would stay gated even
     * when a call-carrying path arrives later — a silent under-reach that would also depend on
     * adjacency order at equal depth. Such a node is re-enqueued ONCE; the flag only moves
     * `true` → `false`, so each node is enqueued at most twice.
     *
     * The re-enqueue changes REACH only. `$firstVisit` still reports a node's first arrival, so the
     * parent pointers and edge rows built on it keep the first — shortest — route: that is what makes
     * {@see callerPathsTo()} shortest-path, holds every chain inside `$maxDepth`, and keeps
     * {@see walkEdges()} in non-decreasing depth order. The cost is one narrow inconsistency: where a
     * node is reached structurally first and by a call later, a drawn chain through it can name the
     * structural route ahead of an `override` hop this walk would refuse on that route. Reach and the
     * counts stay right; only the explanation of a rare mixed-path node is off. Repointing the parent
     * was tried and rejected: it returns chains past `$maxDepth` and reorders the edge list, breaking
     * three contracts to tidy one.
     *
     * @param  array<string, list<array{node: string, via: string}>>  $adjacency
     * @param  list<string>  $from
     * @param  callable(array{node: string, via: string}, int, bool, string): void  $onEdge
     * @param  list<string>  $excludeTypes  edge types never traversed
     */
    private function bfs(array $adjacency, array $from, int $maxDepth, callable $onEdge, array $excludeTypes = []): void
    {
        /** @var array<string, bool> $structuralOnly whether every edge from the seed to this node was structural */
        $structuralOnly = [];
        $seen = [];
        $queue = [];

        foreach ($from as $start) {
            $seen[$start] = 0;
            $structuralOnly[$start] = false;
            $queue[] = ['node' => $start, 'depth' => 0, 'structuralOnly' => false];
        }

        for ($head = 0; isset($queue[$head]); ++$head) {
            $current = $queue[$head];

            if ($current['depth'] >= $maxDepth) {
                continue;
            }

            foreach ($adjacency[$current['node']] ?? [] as $hop) {
                if ($excludeTypes !== [] && in_array($hop['via'], $excludeTypes, strict: true)) {
                    continue;
                }

                if ($current['structuralOnly'] && in_array($hop['via'], self::HIERARCHY_EDGE_TYPES, strict: true)) {
                    continue;
                }

                $depth = $current['depth'] + 1;
                $firstVisit = ! isset($seen[$hop['node']]);
                // Depth 1 takes the edge's own kind: a seed carries `false` so its own override hops
                // stay legal, and AND-ing against that would clear every path from the start.
                $isStructural = in_array($hop['via'], self::STRUCTURAL_EDGE_TYPES, strict: true);
                $hopStructuralOnly = $current['depth'] === 0 ? $isStructural : ($current['structuralOnly'] && $isStructural);

                $onEdge($hop, $depth, $firstVisit, $current['node']);

                if ($firstVisit) {
                    $seen[$hop['node']] = $depth;
                    $structuralOnly[$hop['node']] = $hopStructuralOnly;
                    $queue[] = ['node' => $hop['node'], 'depth' => $depth, 'structuralOnly' => $hopStructuralOnly];

                    continue;
                }

                // Already seen, but reached before only through type structure: this path carries a
                // call, so re-walk it once with the gate lifted.
                if (! $hopStructuralOnly && $structuralOnly[$hop['node']]) {
                    $structuralOnly[$hop['node']] = false;
                    $queue[] = ['node' => $hop['node'], 'depth' => $depth, 'structuralOnly' => false];
                }
            }
        }
    }
}
