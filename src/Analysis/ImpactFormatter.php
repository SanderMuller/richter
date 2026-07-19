<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Str;
use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * Renders {@see ImpactAnalyzer} results as plain text, shared by the artisan commands
 * and the MCP tools so output stays consistent across both surfaces.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final class ImpactFormatter
{
    /**
     * Rendered breadth lists are capped at this many entries — a 100+-entry-point hub change is
     * breadth context, not a checklist, so a long list buries the signal rather than adding to it.
     */
    private const int LIST_CAP = 15;

    /** @param  array{target: string, callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>}  $result */
    public static function impact(array $result): string
    {
        if ($result['callers'] === [] && $result['dependencies'] === []) {
            return "No graph nodes matched \"{$result['target']}\". It may not be reachable from a traced entry point yet (queue/console coverage is being widened).";
        }

        $lines = [
            "Callers (what breaks if you change \"{$result['target']}\"):",
            ...self::hops($result['callers']),
            '',
            "Dependencies (what \"{$result['target']}\" reaches):",
            ...self::hops($result['dependencies']),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths?: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations?: array<string, array{file: string, line?: int}>, entryPointSecurity?: array<string, SecurityShape>, entryPointGates?: array<string, list<string>>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied?: bool, findings?: list<string>, ...}  $result
     * @param  bool  $gateActive  when a `--fail-on*` gate is active the command prints its own verdict, so the advisory suffix is dropped to avoid contradicting it
     * @param  bool  $explain  render the call chain from each reached entry point down to the changed symbol
     */
    public static function detectChanges(array $result, ?TestReferenceIndex $tests = null, bool $gateActive = false, bool $explain = false): string
    {
        $lines = ['Changed files:'];

        foreach ($result['changed'] as $file => $nodeCount) {
            $note = ($result['coverage'][$file] ?? 'analyzed') === 'unresolved'
                ? '  (coverage incomplete for this area — UNRESOLVED, not "no impact")'
                : '';
            $lines[] = "  {$file} ({$nodeCount} graph nodes){$note}";
        }

        $unresolved = in_array('unresolved', $result['coverage'], strict: true);

        $unresolvedSuffix = $unresolved ? ' (some changed files are in an area not yet graphed — see UNRESOLVED above)' : '';
        $lines[] = '';
        $lines[] = 'Entry points reached: ' . count($result['entryPoints']) . $unresolvedSuffix;
        $lines = [...$lines, ...self::entryPointList(
            $result['entryPoints'],
            $explain ? ($result['entryPointPaths'] ?? []) : [],
            $result['entryPointLocations'] ?? [],
            $result['entryPointSecurity'] ?? [],
            $result['entryPointGates'] ?? [],
            $tests,
        )];

        if ($result['relatedModels'] !== []) {
            $lines[] = '';
            $lines[] = 'Related models (association reach — context, not risk): ' . count($result['relatedModels']);
            $lines = [...$lines, ...self::summarisedList($result['relatedModels'])];
        }

        if (($result['findings'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'Findings (in the changed source itself):';

            foreach ($result['findings'] as $finding) {
                $lines[] = "  ! {$finding}";
            }
        }

        $lines[] = '';
        $lines[] = 'Impacted nodes: ' . $result['impacted'];
        $lines[] = 'Risk: ' . Str::upper($result['risk']->value) . ($gateActive ? '' : ' (advisory — not a gate)');

        if ($result['lowConfidence']) {
            // Only claim the cap when it actually bound the result — when precise seeds genuinely
            // drove HIGH, the risk was not capped and saying so would contradict the printed level.
            $cap = ($result['coarseCapApplied'] ?? false) ? ' (risk capped at MEDIUM)' : '';
            $lines[] = "Note: low confidence — a changed member could not be pinned to a graph node, so part of this is a coarse class-level estimate{$cap}.";
        }

        // A LOW on a frontend-heavy diff is easily misread as "nothing to see" — say what the
        // number does and does not measure.
        if (self::hasFrontendFiles($result['changed'])) {
            $lines[] = 'Note: frontend change — risk reflects backend impact only; the routes listed are the surface this change touches.';
        }

        return implode("\n", $lines);
    }

    /**
     * Whether the changed set contains frontend files (never `.php`; Blade is `.blade.php`).
     *
     * @param  array<string, int>  $changed
     */
    public static function hasFrontendFiles(array $changed): bool
    {
        return array_any(array_keys($changed), static fn (string $file): bool => ! str_ends_with($file, '.php'));
    }

    /**
     * The entry-point list with {@see summarisedList}'s sorting and capping, plus — when paths are
     * given — an explain chain under each entry showing how it reaches the changed symbol. Chain
     * sub-lines don't count toward the cap, and a path-less entry (a self-listed entry class) renders
     * its bullet alone. Each entry carries its defining location when known, and a route classified
     * by Brain's security surface carries its exposure plus any issues — inherited advisory
     * annotation, never an input to the risk level.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths  keyed by entry-point node; empty when not explaining
     * @param  array<string, array{file: string, line?: int}>  $locations  keyed by entry-point node
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @param  array<string, list<string>>  $gates  keyed by entry-point node; Pennant flags gating the route
     * @return list<string>
     */
    private static function entryPointList(array $entryPoints, array $paths, array $locations, array $security, array $gates, ?TestReferenceIndex $tests): array
    {
        $rows = EntryPointRow::build($entryPoints, $paths, $locations, $security, $gates, $tests);

        $overCap = count($rows) > self::LIST_CAP;
        $shown = $overCap ? array_slice($rows, 0, self::LIST_CAP) : $rows;
        $lines = [];

        foreach ($shown as $row) {
            $label = $row->label
                . self::locationSuffix($row->location)
                . self::testReferenceSuffix($row->testReferenced)
                . ($row->security !== null ? "  [{$row->security['exposure']}]" : '')
                . ($row->gates !== [] ? '  [gated: ' . implode(', ', $row->gates) . ']' : '');
            $lines[] = "  - {$label}";

            // A single-hop path is the entry point itself — there is no chain to explain.
            if (count($row->path) > 1) {
                $lines[] = '      ↳ ' . self::pathChain($row->label, $row->path);
            }

            foreach ($row->security['issues'] ?? [] as $issue) {
                $issueLocation = isset($issue['file'])
                    ? ' — ' . $issue['file'] . (isset($issue['line']) ? ":{$issue['line']}" : '')
                    : '';
                $lines[] = "      ⚠ {$issue['type']} ({$issue['severity']}): {$issue['message']}{$issueLocation}";
            }
        }

        if ($overCap) {
            $more = count($rows) - self::LIST_CAP;
            $lines[] = "  … and {$more} more";
            $lines[] = '  Note: a large reach here is breadth (a central change touching many call sites), not a precise checklist to verify one by one.';
        }

        return $lines;
    }

    /** @param  array{file: string, line?: int}|null  $location */
    private static function locationSuffix(?array $location): string
    {
        if ($location === null) {
            return '';
        }

        return '  (' . $location['file'] . (isset($location['line']) ? ":{$location['line']}" : '') . ')';
    }

    /**
     * One explain chain: the entry point first, the changed symbol last, each arrow labelled
     * with the edge type connecting its two hops.
     *
     * @param  list<array{node: string, via: string, file?: string, line?: int}>  $path
     */
    private static function pathChain(string $firstLabel, array $path): string
    {
        $chain = $firstLabel;
        $count = count($path);

        for ($i = 1; $i < $count; ++$i) {
            $chain .= " →({$path[$i - 1]['via']}) {$path[$i]['node']}";
        }

        return $chain;
    }

    /** "Referenced" is deliberately weak phrasing: the index matches references, it does not prove coverage. */
    private static function testReferenceSuffix(?bool $referenced): string
    {
        return match ($referenced) {
            true => '  [test-referenced]',
            false => '  [⚠ no test references this]',
            default => '',
        };
    }

    /**
     * Renders a breadth list sorted and capped at {@see LIST_CAP}: the first cap entries, then an
     * `… and M more` line and a one-line breadth note when the list is longer. Sorting makes the
     * rendered sample stable run-to-run (the analyzer walk is BFS, not ordered), so the comment
     * doesn't churn. The machine-readable result arrays are untouched — only the text is capped.
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function summarisedList(array $items): array
    {
        sort($items);

        if (count($items) <= self::LIST_CAP) {
            return array_map(static fn (string $item): string => "  - {$item}", $items);
        }

        $shown = array_slice($items, 0, self::LIST_CAP);
        $more = count($items) - self::LIST_CAP;

        return [
            ...array_map(static fn (string $item): string => "  - {$item}", $shown),
            "  … and {$more} more",
            '  Note: a large reach here is breadth (a central change touching many call sites), not a precise checklist to verify one by one.',
        ];
    }

    /**
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $hops
     * @return list<string>
     */
    private static function hops(array $hops): array
    {
        if ($hops === []) {
            return ['  (none)'];
        }

        return array_map(
            static function (array $hop): string {
                $location = isset($hop['file'])
                    ? '  — ' . $hop['file'] . (isset($hop['line']) ? ":{$hop['line']}" : '')
                    : '';

                return "  d{$hop['depth']}  {$hop['node']}  (via {$hop['via']}){$location}";
            },
            $hops,
        );
    }
}
