<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Advisory: a `toArray()` key this diff removed from a resource, still read by a frontend
 * file that consumes one of the routes the resource reaches — the consumer-side mirror of
 * {@see PayloadParityChecker}. Findings only — never `risk`, `--fail-on`, or
 * `affected-tests`. The match is access-shaped and name-based (`.key`, `['key']`,
 * destructuring position), so a finding is evidence to check, not a verdict; the
 * `ResourceFqcn::key` ignore form is the escape hatch for a false positive.
 *
 * Everything except the match itself — the routes upstream, the consuming files, the ignore
 * list, the rename hint — is {@see FrontendConsumerLane}, which the caller shares with the
 * request-field lane so one run scans the frontend once rather than once per lane.
 */
final readonly class FrontendConsumerParityChecker
{
    public function __construct(private FrontendConsumerLane $lane) {}

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
        $removed = FrontendConsumerLane::matchable($removedKeys, $ignoredKeys);
        // The rename hint works from the same ignore-filtered view on both sides — an
        // ignored key must neither sharpen the hint's confidence nor be named in it.
        $added = FrontendConsumerLane::matchable($addedKeys, $ignoredKeys);

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
