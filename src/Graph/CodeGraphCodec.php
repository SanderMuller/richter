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
 * `nodeMetadata` is the one named exception, and only inside a record. A record that is a map keeps
 * the fields that shape-check and loses the rest: metadata annotates reports and never feeds the
 * impact walk, so that loss cannot under-report impact. A missing map, a non-string node key or a
 * record that is not a map still refuses the entry.
 *
 * @phpstan-import-type MetadataShape from NodeMetadata
 *
 * @internal
 */
final class CodeGraphCodec
{
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
     * Each record goes through {@see NodeMetadata}, the same shape gate a fresh build uses.
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
