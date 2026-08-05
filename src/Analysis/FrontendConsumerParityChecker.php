<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\FrontendChanges;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * Advisory: a `toArray()` key this diff removed from a resource, still read by a frontend
 * file that consumes one of the routes the resource reaches — the consumer-side mirror of
 * {@see PayloadParityChecker}. Findings only — never `risk`, `--fail-on`, or
 * `affected-tests`. The match is access-shaped and name-based (`.key`, `['key']`,
 * destructuring position), so a finding is evidence to check, not a verdict; the
 * `ResourceFqcn::key` ignore form is the escape hatch for a false positive.
 *
 * Non-readonly like {@see PayloadParityChecker}: the consumer index and per-file scan
 * contents are memoized for the run's lifetime, and the index is only ever built on a
 * run where a removed key survived the ignore list.
 */
final class FrontendConsumerParityChecker
{
    /** @var array<string, string> file => scan content (Blade views reduced to script slices) */
    private array $contentCache = [];

    /** @param  list<string>  $ignore  resource FQCN or `ResourceFqcn::key` entries, from richter.payload_parity.ignore */
    public function __construct(private readonly CodeGraph $graph, private readonly array $ignore = [], private readonly ?string $projectRoot = null, private ?FrontendConsumerIndex $index = null) {}

    /**
     * @param  list<string>  $removedKeys
     * @param  list<string>  $addedKeys
     * @return list<string> advisory findings, consumer-file- and route-named
     */
    public function findingsFor(string $resourceFqcn, array $removedKeys, array $addedKeys): array
    {
        if (in_array($resourceFqcn, $this->ignore, strict: true)) {
            return [];
        }

        $ignoredKeys = $this->ignoredKeysFor($resourceFqcn);
        $removed = array_values(array_diff($removedKeys, $ignoredKeys));
        // The rename hint works from the same ignore-filtered view on both sides — an
        // ignored key must neither sharpen the hint's confidence nor be named in it.
        $added = array_values(array_diff($addedKeys, $ignoredKeys));

        if ($removed === []) {
            return [];
        }

        $routes = $this->affectedRoutes($resourceFqcn);

        if ($routes === []) {
            return [];
        }

        $findings = [];

        foreach ($routes as $route) {
            foreach ($this->index()->filesReferencing($route) as $file) {
                foreach ($removed as $key) {
                    if ($this->readsKey($file, $key)) {
                        $findings[] = sprintf(
                            "%s references %s and reads '%s', which this diff removes from %s%s",
                            $file,
                            $this->routeLabel($route),
                            $key,
                            $resourceFqcn,
                            $this->renameSuffix($removed, $added),
                        );
                    }
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * The `route::` nodes upstream of the resource — the endpoints that can reach it
     * (nested composition rides the `resource` edges up through parent resources). An
     * upstream match does not prove the route serializes this resource; the consumer
     * key-read is what turns reach into a finding.
     *
     * @return list<string>
     */
    private function affectedRoutes(string $resourceFqcn): array
    {
        $seeds = $this->graph->nodesContaining(ltrim($resourceFqcn, '\\'));

        if ($seeds === []) {
            return [];
        }

        // The analyzer's full walk depth, deliberately — a resource can sit behind deep
        // serialization chains. Over-approximate by design (any-edge upstream reach), the
        // opposite trade from the model lane's locality-first depth 2: a route matched
        // here still only produces a finding when a consumer actually reads the key.
        $routes = array_filter(
            array_map(static fn (array $hop): string => $hop['node'], $this->graph->callersOf($seeds, maxDepth: 6)),
            static fn (string $node): bool => str_starts_with($node, 'route::'),
        );

        return array_values(array_unique($routes));
    }

    /**
     * Access-shaped only (never bare tokens): `.key`, `['key']` / `["key"]`, and
     * destructuring-position `key` — a translation key or unrelated variable must not
     * trigger. Blade consumers are scanned on the same `<script>` slices the index
     * matched, never the whole file: server-side `$item['key']` PHP is not a consumer read.
     * Known false-positive class: the destructuring pattern also matches object-literal
     * WRITES (`{ key: value }` in a request body) and named imports — the `:` alternative
     * is what catches destructure-renames, so it stays; the advisory framing and the
     * `ResourceFqcn::key` ignore entry carry that cost.
     */
    private function readsKey(string $file, string $key): bool
    {
        $content = $this->contentCache[$file] ??= $this->scanContent($file);
        $quoted = preg_quote($key, '/');

        return preg_match('/\.' . $quoted . '\b/', $content) === 1
            || preg_match('/\[\s*[\'"]' . $quoted . '[\'"]\s*\]/', $content) === 1
            || preg_match('/[{,]\s*' . $quoted . '\s*[,}:=]/', $content) === 1;
    }

    private function scanContent(string $file): string
    {
        $absolute = rtrim($this->projectRoot ?? base_path(), '/') . '/' . ltrim($file, '/');
        $source = is_file($absolute) ? (string) file_get_contents($absolute) : '';

        return str_ends_with($file, '.blade.php') ? new FrontendChanges()->scriptSlices($source) : $source;
    }

    /** `route::PATCH::/api/posts/{post}` → `PATCH /api/posts/{post}` — the node id is an internal spelling. */
    private function routeLabel(string $node): string
    {
        $parts = explode('::', $node, 3);

        return count($parts) === 3 ? "{$parts[1]} {$parts[2]}" : $node;
    }

    /**
     * Deterministic, never a similarity guess: exactly one removed + one added key names
     * the added key as a possible rename; any other non-empty added set gets a generic
     * co-added note.
     *
     * @param  list<string>  $removed
     * @param  list<string>  $added
     */
    private function renameSuffix(array $removed, array $added): string
    {
        if ($added === []) {
            return '';
        }

        if (count($removed) === 1 && count($added) === 1) {
            return " (renamed to '{$added[0]}'?)";
        }

        return ' (this diff also adds ' . implode(', ', array_map(static fn (string $key): string => "'{$key}'", $added)) . ')';
    }

    private function index(): FrontendConsumerIndex
    {
        return $this->index ??= FrontendConsumerIndex::fromProject($this->projectRoot ?? base_path());
    }

    /** @return list<string> */
    private function ignoredKeysFor(string $resourceFqcn): array
    {
        $prefix = "{$resourceFqcn}::";
        $keys = [];

        foreach ($this->ignore as $entry) {
            if (str_starts_with($entry, $prefix)) {
                $keys[] = substr($entry, strlen($prefix));
            }
        }

        return $keys;
    }
}
