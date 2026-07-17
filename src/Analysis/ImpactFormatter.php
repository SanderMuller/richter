<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Str;

/**
 * Renders {@see ImpactAnalyzer} results as plain text, shared by the artisan commands
 * and the MCP tools so output stays consistent across both surfaces.
 */
final class ImpactFormatter
{
    /**
     * Rendered breadth lists are capped at this many entries — a 100+-entry-point hub change is
     * breadth context, not a checklist, so a long list buries the signal rather than adding to it.
     */
    private const int LIST_CAP = 15;

    /** @param  array{target: string, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>}  $result */
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
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths?: array<string, list<array{node: string, via: string}>>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied?: bool, findings?: list<string>, ...}  $result
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

        return implode("\n", $lines);
    }

    /**
     * The entry-point list with {@see summarisedList}'s sorting and capping, plus — when paths are
     * given — an explain chain under each entry showing how it reaches the changed symbol. Chain
     * sub-lines don't count toward the cap, and a path-less entry (a self-listed entry class) renders
     * its bullet alone.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<array{node: string, via: string}>>  $paths  keyed by entry-point node; empty when not explaining
     * @return list<string>
     */
    private static function entryPointList(array $entryPoints, array $paths, ?TestReferenceIndex $tests): array
    {
        $items = array_map(static fn (string $node): array => [
            'label' => self::entryLabel($node) . self::testReferenceSuffix($tests, $node),
            'node' => $node,
        ], $entryPoints);
        usort($items, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        $overCap = count($items) > self::LIST_CAP;
        $shown = $overCap ? array_slice($items, 0, self::LIST_CAP) : $items;
        $lines = [];

        foreach ($shown as $item) {
            $lines[] = "  - {$item['label']}";
            $path = $paths[$item['node']] ?? [];

            // A single-hop path is the entry point itself — there is no chain to explain.
            if (count($path) > 1) {
                $lines[] = '      ↳ ' . self::pathChain($path);
            }
        }

        if ($overCap) {
            $more = count($items) - self::LIST_CAP;
            $lines[] = "  … and {$more} more";
            $lines[] = '  Note: a large reach here is breadth (a central change touching many call sites), not a precise checklist to verify one by one.';
        }

        return $lines;
    }

    /**
     * One explain chain: the entry point first, the changed symbol last, each arrow labelled with
     * the edge type connecting its two hops.
     *
     * @param  list<array{node: string, via: string}>  $path
     */
    private static function pathChain(array $path): string
    {
        $chain = self::entryLabel($path[0]['node']);
        $count = count($path);

        for ($i = 1; $i < $count; ++$i) {
            $chain .= " →({$path[$i - 1]['via']}) {$path[$i]['node']}";
        }

        return $chain;
    }

    /**
     * A console-command entry-point node carries its whole `$signature`
     * (`command::foo {--opt : desc}`); show just the command name. Routes/schedules are unaffected.
     */
    private static function entryLabel(string $node): string
    {
        return str_starts_with($node, 'command::') ? explode(' ', $node, 2)[0] : $node;
    }

    /** "Referenced" is deliberately weak phrasing: the index matches references, it does not prove coverage. */
    private static function testReferenceSuffix(?TestReferenceIndex $tests, string $node): string
    {
        return match ($tests?->hasReference($node)) {
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
     * @param  list<array{depth: int, node: string, via: string}>  $hops
     * @return list<string>
     */
    private static function hops(array $hops): array
    {
        if ($hops === []) {
            return ['  (none)'];
        }

        return array_map(
            static fn (array $hop): string => "  d{$hop['depth']}  {$hop['node']}  (via {$hop['via']})",
            $hops,
        );
    }
}
