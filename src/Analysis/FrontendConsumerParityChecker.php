<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;

/**
 * Advisory: a `toArray()` key this diff removed from a resource, still read by a frontend
 * file that consumes one of the routes the resource reaches — the consumer-side mirror of
 * {@see PayloadParityChecker}. Findings only — never `risk`, `--fail-on`, or
 * `affected-tests`. The match is access-shaped and name-based (`.key`, `['key']`,
 * destructuring position), so a finding is evidence to check, not a verdict; the
 * `ResourceFqcn::key` ignore form is the escape hatch for a false positive.
 *
 * Everything except the match itself — the routes upstream, the consuming files, the ignore
 * list, the rename hint — is {@see FrontendConsumerLane}, shared with the request-field lane.
 */
final readonly class FrontendConsumerParityChecker
{
    private FrontendConsumerLane $lane;

    /** @param  list<string>  $ignore  resource FQCN or `ResourceFqcn::key` entries, from richter.payload_parity.ignore */
    public function __construct(CodeGraph $graph, array $ignore = [], ?string $projectRoot = null, ?FrontendConsumerIndex $index = null)
    {
        $this->lane = new FrontendConsumerLane($graph, $ignore, $projectRoot, $index);
    }

    /**
     * @param  list<string>  $removedKeys
     * @param  list<string>  $addedKeys
     * @return list<string> advisory findings, consumer-file- and route-named
     */
    public function findingsFor(string $resourceFqcn, array $removedKeys, array $addedKeys): array
    {
        if ($this->lane->isIgnored($resourceFqcn)) {
            return [];
        }

        $ignoredKeys = $this->lane->ignoredKeysFor($resourceFqcn);
        $removed = array_values(array_diff($removedKeys, $ignoredKeys));
        // The rename hint works from the same ignore-filtered view on both sides — an
        // ignored key must neither sharpen the hint's confidence nor be named in it.
        $added = array_values(array_diff($addedKeys, $ignoredKeys));

        if ($removed === []) {
            return [];
        }

        $findings = [];

        foreach ($this->lane->routesUpstreamOf($resourceFqcn) as $route) {
            foreach ($this->lane->filesReferencing($route) as $file) {
                foreach ($removed as $key) {
                    if ($this->readsKey($file, $key)) {
                        $findings[] = sprintf(
                            "%s references %s and reads '%s', which this diff removes from %s%s",
                            $file,
                            FrontendConsumerLane::routeLabel($route),
                            $key,
                            $resourceFqcn,
                            FrontendConsumerLane::renameSuffix($removed, $added),
                        );
                    }
                }
            }
        }

        return array_values(array_unique($findings));
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
        $content = $this->lane->content($file);
        $quoted = preg_quote($key, '/');

        return preg_match('/\.' . $quoted . '\b/', $content) === 1
            || preg_match('/\[\s*[\'"]' . $quoted . '[\'"]\s*\]/', $content) === 1
            || preg_match('/[{,]\s*' . $quoted . '\s*[,}:=]/', $content) === 1;
    }
}
