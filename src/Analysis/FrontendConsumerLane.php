<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\FrontendChanges;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * What the two consumer-facing parity lanes share: the endpoints a changed class sits behind, the
 * frontend files that call those endpoints, their scannable content, and the ignore-list and
 * rename-hint conventions the findings are worded with.
 *
 * The lanes themselves differ in one thing each — {@see FrontendConsumerParityChecker} looks for a
 * consumer READING a response key, {@see RequestFieldParityChecker} for one SENDING a request field
 * — and that difference is the whole reason they are separate classes. Everything around it is one
 * question asked twice.
 *
 * Non-readonly: the index and the per-file scan contents are memoized for the run's lifetime.
 *
 * @internal
 */
final class FrontendConsumerLane
{
    /** @var array<string, string> file => scan content (Blade views reduced to script slices) */
    private array $contentCache = [];

    /** @param  list<string>  $ignore  `Fqcn` or `Fqcn::key` entries, from richter.payload_parity.ignore */
    public function __construct(
        private readonly CodeGraph $graph,
        private readonly array $ignore = [],
        private readonly ?string $projectRoot = null,
        private ?FrontendConsumerIndex $index = null,
    ) {}

    public function isIgnored(string $fqcn): bool
    {
        return in_array($fqcn, $this->ignore, strict: true);
    }

    /**
     * The keys the ignore list suppresses for one class, as bare names.
     *
     * @return list<string>
     */
    public function ignoredKeysFor(string $fqcn): array
    {
        $prefix = "{$fqcn}::";
        $keys = [];

        foreach ($this->ignore as $entry) {
            if (str_starts_with($entry, $prefix)) {
                $keys[] = substr($entry, strlen($prefix));
            }
        }

        return $keys;
    }

    /**
     * The names a finding may be raised for: the diff's set minus the ignore list, minus any empty
     * name. An empty key is a legal PHP array key and the contract parsers report it faithfully,
     * but as a match pattern it degenerates — the response lane's `.<key>` pattern becomes a dot
     * followed by a word boundary, which hits nearly every consumer file — so one absurd key would
     * flag the whole frontend. Dropped here, once, for both lanes.
     *
     * @param  list<string>  $names
     * @param  list<string>  $ignored
     * @return list<string>
     */
    public static function matchable(array $names, array $ignored): array
    {
        return array_values(array_filter(
            array_diff($names, $ignored),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * The `route::` nodes upstream of a class — the endpoints that can reach it (nested
     * composition rides the `resource` edges up through parent resources; a form request rides
     * the action that type-hints it). An upstream match does not prove the route serializes or
     * validates this class; the consumer-side match is what turns reach into a finding.
     *
     * @return list<string>
     */
    public function routesUpstreamOf(string $fqcn): array
    {
        $seeds = $this->graph->nodesContaining(ltrim($fqcn, '\\'));

        if ($seeds === []) {
            return [];
        }

        // The analyzer's full walk depth, deliberately — a resource can sit behind deep
        // serialization chains. Over-approximate by design (any-edge upstream reach), the
        // opposite trade from the model lane's locality-first depth 2: a route matched
        // here still only produces a finding when a consumer actually matches.
        $routes = array_filter(
            array_map(static fn (array $hop): string => $hop['node'], $this->graph->callersOf($seeds, maxDepth: 6)),
            static fn (string $node): bool => str_starts_with($node, 'route::'),
        );

        return array_values(array_unique($routes));
    }

    /** @return list<string> */
    public function filesReferencing(string $route): array
    {
        $this->index ??= FrontendConsumerIndex::fromProject($this->projectRoot ?? base_path());

        return $this->index->filesReferencing($route);
    }

    /** Blade consumers are scanned on their `<script>` slices only: server-side PHP is not a consumer. */
    public function content(string $file): string
    {
        return $this->contentCache[$file] ??= $this->scan($file);
    }

    /** `route::PATCH::/api/posts/{post}` → `PATCH /api/posts/{post}` — the node id is an internal spelling. */
    public static function routeLabel(string $node): string
    {
        $parts = explode('::', $node, 3);

        return count($parts) === 3 ? "{$parts[1]} {$parts[2]}" : $node;
    }

    /**
     * Deterministic, never a similarity guess: exactly one removed + one added name makes the added
     * one a possible rename; any other non-empty added set gets a generic co-added note.
     *
     * @param  list<string>  $removed
     * @param  list<string>  $added
     */
    public static function renameSuffix(array $removed, array $added): string
    {
        if ($added === []) {
            return '';
        }

        if (count($removed) === 1 && count($added) === 1) {
            return " (renamed to '{$added[0]}'?)";
        }

        return ' (this diff also adds ' . implode(', ', array_map(static fn (string $key): string => "'{$key}'", $added)) . ')';
    }

    private function scan(string $file): string
    {
        $absolute = rtrim($this->projectRoot ?? base_path(), '/') . '/' . ltrim($file, '/');
        $source = is_file($absolute) ? (string) file_get_contents($absolute) : '';

        return str_ends_with($file, '.blade.php') ? new FrontendChanges()->scriptSlices($source) : $source;
    }
}
