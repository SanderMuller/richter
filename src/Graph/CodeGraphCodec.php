<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * Richter's own graph from the plain array its cache entry stores, the counterpart of
 * {@see CodeGraph::toArray()}.
 *
 * One rule for every key: it must be present and have the shape {@see CodeGraph::toArray()} writes,
 * or the whole entry is refused. A partly revived graph is worse than a rebuild, because each key
 * defaulted on the way in is a fact the report then states about a project nobody checked:
 *
 * - `edges` short or mis-shaped reports falsely small impact.
 * - `hasUnparseableFiles` defaulted to `false` reads as "every file parsed" and drops the S1 taint.
 * - `unresolvedDispatchSites` coalesced to `[]` reads as "no unfollowable dispatch" and drops the S2
 *   taint. Either one lets a test selection report determinable when it is not.
 *
 * `nodeMetadata` is the one named exception, per record and not per key. A record that does not
 * shape-check loses its unusable fields: metadata annotates reports and never feeds the impact walk,
 * so partial loss there cannot under-report impact. The map itself is still required.
 *
 * @phpstan-import-type MetadataShape from NodeMetadata
 *
 * @internal
 */
final class CodeGraphCodec
{
    /** The graph a stored payload describes, or null when the payload is not one. */
    public static function fromArray(mixed $data): ?CodeGraph
    {
        return is_array($data) ? self::decode($data, 'the cache entry')->graph : null;
    }

    /**
     * The graph a stored payload describes, or the first key that refuses it — so a reader can say
     * which part of the entry is broken. `$file` names the entry in that message.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function decode(array $data, string $file): ReadOutcome
    {
        $edges = self::validEdges($data['edges'] ?? null);

        if ($edges === null) {
            return ReadOutcome::refused('edges-rejected', "the edge list in {$file} is not one this version can revive");
        }

        $metadata = self::validNodeMetadata($data['nodeMetadata'] ?? null);

        if ($metadata === null) {
            return ReadOutcome::refused('metadata-rejected', "the node metadata in {$file} is not one this version can revive");
        }

        $dispatchSites = self::validDispatchSites($data['unresolvedDispatchSites'] ?? null);

        if ($dispatchSites === null) {
            return ReadOutcome::refused('dispatch-sites-rejected', "the unresolved-dispatch list in {$file} is not one this version can revive");
        }

        $unparseable = $data['hasUnparseableFiles'] ?? null;

        if (! is_bool($unparseable)) {
            return ReadOutcome::refused('unparseable-flag-rejected', "the unparseable-files flag in {$file} is not a boolean");
        }

        return ReadOutcome::of(new CodeGraph($edges, $unparseable, $dispatchSites, $metadata));
    }

    /**
     * A revived unresolved-dispatch site list, or null when the stored value is not one.
     *
     * Null and `[]` mean different things and the caller must keep them apart: `[]` is "the build
     * found none", null is "this file cannot be trusted". Null therefore fails the whole read, the
     * same as a malformed edge list.
     *
     * @return list<array{file: string, line: int, dispatcher: string}>|null
     */
    private static function validDispatchSites(mixed $sites): ?array
    {
        if (! is_array($sites) || ! array_is_list($sites)) {
            return null;
        }

        $valid = [];

        foreach ($sites as $site) {
            if (! is_array($site)
                || ! is_string($site['file'] ?? null)
                || ! is_string($site['dispatcher'] ?? null)
                || ! is_int($site['line'] ?? null)) {
                return null;
            }

            $valid[] = ['file' => $site['file'], 'line' => $site['line'], 'dispatcher' => $site['dispatcher']];
        }

        return $valid;
    }

    /**
     * An edge list that does not parse as edge shapes is corrupt — the whole read is a miss, never a
     * partially-loaded graph (which would report falsely-small impact).
     *
     * @return list<array{source: string, target: string, type: string}>|null
     */
    private static function validEdges(mixed $edges): ?array
    {
        if (! is_array($edges)) {
            return null;
        }

        $valid = [];

        foreach ($edges as $edge) {
            if (! is_array($edge) || ! is_string($edge['source'] ?? null) || ! is_string($edge['target'] ?? null) || ! is_string($edge['type'] ?? null)) {
                return null;
            }

            $valid[] = ['source' => $edge['source'], 'target' => $edge['target'], 'type' => $edge['type']];
        }

        return $valid;
    }

    /**
     * The node-metadata map from a cache entry, re-shaped through {@see NodeMetadata} so a tampered
     * or drifted entry degrades to the same conservative shapes a fresh build would produce. A
     * missing or non-map value is corrupt (→ miss, like {@see validEdges}); an individual record that
     * doesn't shape-check simply loses its unusable fields — metadata annotates reports, it never
     * feeds the impact walk, so partial loss here cannot under-report impact.
     *
     * @return array<string, MetadataShape>|null
     */
    private static function validNodeMetadata(mixed $metadata): ?array
    {
        if (! is_array($metadata)) {
            return null;
        }

        $valid = [];

        foreach ($metadata as $node => $record) {
            if (! is_string($node) || ! is_array($record)) {
                return null;
            }

            // Re-extract through the same shape gate the builder uses: '' as root means "keep
            // stored paths verbatim" — they were made project-relative at build time.
            $shaped = NodeMetadata::fromBrainNodeData($record, '');

            if ($shaped !== null) {
                $valid[$node] = $shaped;
            }
        }

        return $valid;
    }
}
