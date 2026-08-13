<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use LaraMint\LaravelBrain\Graph\Edge;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;

/**
 * Brain's own `Graph` to and from the plain arrays richter's cache stores.
 *
 * Richter normally consumes Brain's graph immediately and discards it. An incremental rebuild needs
 * it kept: `ProjectAnalyzer::scopedTo()` merges a scoped pass into a *previous* graph, so without one
 * on disk there is nothing to merge into.
 *
 * Decoding is fail-closed in the same way {@see GraphCache::validEdges()} is — one mis-shaped node or
 * edge discards the whole graph rather than reviving a partial one. A half-loaded merge base is worse
 * than none: none costs a full build, a partial one produces a graph missing edges nobody can see are
 * missing.
 */
final class BrainGraphCodec
{
    /** @return array{meta: array<string, mixed>, nodes: list<array{id: string, type: string, label: string, data: array<string, mixed>}>, edges: list<array{id: string, source: string, target: string, label: string, type: string}>} */
    public static function toArray(Graph $graph): array
    {
        /** @var array{meta: array<string, mixed>, nodes: list<array{id: string, type: string, label: string, data: array<string, mixed>}>, edges: list<array{id: string, source: string, target: string, label: string, type: string}>} $decoded */
        $decoded = json_decode($graph->toJson(), associative: true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The graph a stored payload describes, or null when the payload is not one.
     *
     * `setMeta()` is given the stored meta verbatim including Brain's own `nodeCount`/`edgeCount`;
     * {@see Graph::toJson()} recomputes both on the way out, so carrying them back in cannot make a
     * revived graph disagree with itself.
     */
    public static function fromArray(mixed $data): ?Graph
    {
        if (! is_array($data) || ! is_array($data['meta'] ?? null) || ! is_array($data['nodes'] ?? null) || ! is_array($data['edges'] ?? null)) {
            return null;
        }

        // The payload states its own size, so a graph that decodes to fewer elements than it claims
        // is detectable without trusting the file's length. Shape checks alone would accept it: every
        // surviving node still looks like a node. As a merge base that graph is the worst kind of
        // wrong — structurally valid, silently short, and its missing elements are carried forward
        // into every scoped rebuild after it as though they had never existed.
        $declaredNodes = $data['meta']['nodeCount'] ?? null;
        $declaredEdges = $data['meta']['edgeCount'] ?? null;

        if (! is_int($declaredNodes) || ! is_int($declaredEdges)) {
            return null;
        }

        $graph = new Graph();

        foreach ($data['nodes'] as $node) {
            if (! is_array($node)
                || ! is_string($node['id'] ?? null)
                || ! is_string($node['type'] ?? null)
                || ! is_string($node['label'] ?? null)
                || ! is_array($node['data'] ?? null)) {
                return null;
            }

            $graph->addNode(new Node($node['id'], $node['type'], $node['label'], $node['data']));
        }

        foreach ($data['edges'] as $edge) {
            if (! is_array($edge)
                || ! is_string($edge['id'] ?? null)
                || ! is_string($edge['source'] ?? null)
                || ! is_string($edge['target'] ?? null)
                || ! is_string($edge['label'] ?? null)
                || ! is_string($edge['type'] ?? null)) {
                return null;
            }

            $graph->addEdge(new Edge($edge['id'], $edge['source'], $edge['target'], $edge['label'], $edge['type']));
        }

        // Counted after the build, not before: `addNode()` is keyed by id, so a payload carrying two
        // nodes with one id decodes to fewer than it lists — a mismatch the array lengths never show.
        if ($graph->nodeCount() !== $declaredNodes || $graph->edgeCount() !== $declaredEdges) {
            return null;
        }

        /** @var array<string, mixed> $meta */
        $meta = $data['meta'];
        $graph->setMeta($meta);

        return $graph;
    }
}
