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
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied?: bool, findings?: list<string>, ...}  $result
     * @param  bool  $gateActive  when a `--fail-on*` gate is active the command prints its own verdict, so the advisory suffix is dropped to avoid contradicting it
     */
    public static function detectChanges(array $result, ?TestReferenceIndex $tests = null, bool $gateActive = false): string
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
        $entryLabels = array_map(
            static fn (string $node): string => self::entryLabel($node) . self::testReferenceSuffix($tests, $node),
            $result['entryPoints'],
        );
        $lines = [...$lines, ...self::summarisedList($entryLabels)];

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
