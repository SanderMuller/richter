<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * Renders {@see ImpactAnalyzer} results as GitHub-flavoured markdown for pull-request descriptions
 * and comments — the workflow the README describes ("hand the reviewer your blast radius"). Unlike
 * {@see ImpactFormatter}'s capped text lists, nothing is truncated: entries beyond the cap collapse
 * into a `<details>` block, so the PR stays scannable while the full reach remains one click away.
 * Cell and code-span content is repo-derived (FQCNs, route/command ids, node names), so no markdown
 * escaping is applied to those — a `|` or backtick cannot occur in identifier-shaped values. File
 * paths are the exception: they come straight from the diff and can legally contain `|` or
 * backticks, so the changed-files table escapes them via {@see pathCell()}.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final class MarkdownFormatter
{
    /** Entries rendered before the remainder collapses into a `<details>` block. */
    private const int LIST_CAP = 15;

    /** @param  array{target: string, callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>}  $result */
    public static function impact(array $result): string
    {
        $lines = ["## Richter blast radius: `{$result['target']}`", ''];

        if ($result['callers'] === [] && $result['dependencies'] === []) {
            $lines[] = '_No graph nodes matched. It may not be reachable from a traced entry point yet — a no-match is a signal, not proof of no impact._';

            return implode("\n", $lines);
        }

        $lines[] = sprintf('### Callers (what breaks if you change it) (%d)', count($result['callers']));
        $lines[] = '';
        $lines = [...$lines, ...self::hopList($result['callers']), ''];
        $lines[] = sprintf('### Dependencies (what it reaches) (%d)', count($result['dependencies']));
        $lines[] = '';
        $lines = [...$lines, ...self::hopList($result['dependencies'])];

        return implode("\n", $lines);
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths?: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations?: array<string, array{file: string, line?: int}>, entryPointSecurity?: array<string, SecurityShape>, entryPointGates?: array<string, list<string>>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied?: bool, findings?: list<string>, ...}  $result
     * @param  bool  $gateActive  when a `--fail-on*` gate is active the command appends its own verdict, so the advisory suffix is dropped to avoid contradicting it
     * @param  bool  $explain  render the call chain from each reached entry point down to the changed symbol
     */
    public static function detectChanges(array $result, ?TestReferenceIndex $tests = null, bool $gateActive = false, bool $explain = false): string
    {
        $unresolved = in_array('unresolved', $result['coverage'], strict: true);

        $lines = ['## Richter change impact', ''];
        $lines[] = sprintf(
            '**Risk:** %s%s · **Entry points reached:** %d · **Impacted nodes:** %d',
            self::riskBadge($result['risk']),
            $gateActive ? '' : ' _(advisory — not a gate)_',
            count($result['entryPoints']),
            $result['impacted'],
        );

        if ($result['lowConfidence']) {
            $cap = ($result['coarseCapApplied'] ?? false) ? ' (risk capped at MEDIUM)' : '';
            $lines[] = '';
            $lines[] = "> ⚠️ Low confidence: a changed member could not be pinned to a graph node, so part of this is a coarse class-level estimate{$cap}.";
        }

        if (ImpactFormatter::hasFrontendFiles($result['changed'])) {
            $lines[] = '';
            $lines[] = '> ℹ️ Frontend change: risk reflects backend impact only — the routes listed below are the surface this change touches.';
        }

        $lines = [...$lines, '', '### Changed files', ''];
        $lines[] = '| File | Graph nodes | Coverage |';
        $lines[] = '|---|---:|---|';

        foreach ($result['changed'] as $file => $nodeCount) {
            $coverage = ($result['coverage'][$file] ?? 'analyzed') === 'unresolved'
                ? '⚠️ **UNRESOLVED** — not graphed, never "no impact"'
                : 'analyzed';
            $lines[] = '| ' . self::pathCell($file) . " | {$nodeCount} | {$coverage} |";
        }

        $lines = [...$lines, '', sprintf('### Entry points reached (%d)', count($result['entryPoints'])), ''];

        if ($unresolved) {
            $lines[] = '> ⚠️ Some changed files could not be placed in the graph — the reach below may be incomplete.';
            $lines[] = '';
        }

        $lines = [...$lines, ...self::entryPointChecklist(
            $result['entryPoints'],
            $explain ? ($result['entryPointPaths'] ?? []) : [],
            $result['entryPointLocations'] ?? [],
            $result['entryPointSecurity'] ?? [],
            $result['entryPointGates'] ?? [],
            $tests,
        )];

        if ($result['relatedModels'] !== []) {
            $lines = [...$lines, '', ...self::collapsed(
                sprintf('Related models (association reach — context, not risk): %d', count($result['relatedModels'])),
                array_map(static fn (string $model): string => "- `{$model}`", self::sorted($result['relatedModels'])),
            )];
        }

        if (($result['findings'] ?? []) !== []) {
            $lines = [...$lines, '', '### Findings (in the changed source itself)', ''];

            foreach ($result['findings'] as $finding) {
                $lines[] = "- ⚠️ {$finding}";
            }
        }

        return implode("\n", $lines);
    }

    /** A diff-derived file path may contain `|` or backticks — the one repo-derived value the
     *  no-escaping rule in the class docblock cannot cover. Escape the pipe for table cells and
     *  swap backticks out of the code span. */
    private static function pathCell(string $file): string
    {
        $escaped = str_replace('|', '\|', $file);

        return str_contains($escaped, '`') ? '``' . $escaped . '``' : "`{$escaped}`";
    }

    private static function riskBadge(RiskLevel $risk): string
    {
        return match ($risk) {
            RiskLevel::High => '🔴 HIGH',
            RiskLevel::Medium => '🟡 MEDIUM',
            RiskLevel::Low => '🟢 LOW',
        };
    }

    /**
     * The entry points as a review checklist — unchecked boxes the reviewer ticks off — with the
     * test-reference state as a suffix and, when explaining, the call chain nested under each entry.
     * The first {@see LIST_CAP} render inline; the remainder collapses into a `<details>` block
     * instead of being truncated.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths  keyed by entry-point node; empty when not explaining
     * @param  array<string, array{file: string, line?: int}>  $locations  keyed by entry-point node
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only, inherited from Brain as advisory annotation
     * @param  array<string, list<string>>  $gates  keyed by entry-point node; Pennant flags gating the route
     * @return list<string>
     */
    private static function entryPointChecklist(array $entryPoints, array $paths, array $locations, array $security, array $gates, ?TestReferenceIndex $tests): array
    {
        if ($entryPoints === []) {
            return ['_None reached from the changed code._'];
        }

        $items = array_map(static fn (string $node): array => [
            'label' => '`' . self::entryLabel($node) . '`'
                . self::locationSuffix($locations[$node] ?? null)
                . self::testReferenceSuffix($tests, $node)
                . (isset($security[$node]) ? ' — ' . self::exposureBadge($security[$node]['exposure']) : '')
                . (isset($gates[$node]) ? ' — 🚩 ' . implode(', ', $gates[$node]) : ''),
            'node' => $node,
        ], $entryPoints);
        usort($items, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        $lines = self::checklistEntries(array_slice($items, 0, self::LIST_CAP), $paths, $security);

        if (count($items) > self::LIST_CAP) {
            $rest = array_slice($items, self::LIST_CAP);
            $lines = [...$lines, '', ...self::collapsed(
                sprintf('… and %d more', count($rest)),
                self::checklistEntries($rest, $paths, $security),
            )];
        }

        return $lines;
    }

    /** @param  array{file: string, line?: int}|null  $location */
    private static function locationSuffix(?array $location): string
    {
        if ($location === null) {
            return '';
        }

        return ' — `' . $location['file'] . (isset($location['line']) ? ":{$location['line']}" : '') . '`';
    }

    /** The exposure levels Brain classifies; an unrecognised value renders bare rather than guessing an icon. */
    private static function exposureBadge(string $exposure): string
    {
        return match ($exposure) {
            'public' => '🔓 public',
            'guest' => '🚪 guest',
            'authed' => '🔒 authed',
            'admin' => '🛡️ admin',
            default => $exposure,
        };
    }

    /**
     * @param  list<array{label: string, node: string}>  $items
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths
     * @param  array<string, SecurityShape>  $security
     * @return list<string>
     */
    private static function checklistEntries(array $items, array $paths, array $security): array
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = "- [ ] {$item['label']}";
            $path = $paths[$item['node']] ?? [];

            // A single-hop path is the entry point itself — there is no chain to explain.
            if (count($path) > 1) {
                $lines[] = '  - ↳ ' . self::pathChain($path);
            }

            foreach ($security[$item['node']]['issues'] ?? [] as $issue) {
                $issueLocation = isset($issue['file'])
                    ? ' — `' . $issue['file'] . (isset($issue['line']) ? ":{$issue['line']}" : '') . '`'
                    : '';
                $lines[] = "  - ⚠️ **{$issue['type']}** ({$issue['severity']}): {$issue['message']}{$issueLocation}";
            }
        }

        return $lines;
    }

    /**
     * One explain chain: the entry point first, the changed symbol last, each arrow labelled with
     * the edge type connecting its two hops.
     *
     * @param  list<array{node: string, via: string, file?: string, line?: int}>  $path
     */
    private static function pathChain(array $path): string
    {
        $chain = '`' . self::entryLabel($path[0]['node']) . '`';
        $count = count($path);

        for ($i = 1; $i < $count; ++$i) {
            $chain .= " →({$path[$i - 1]['via']}) `{$path[$i]['node']}`";
        }

        return $chain;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function sorted(array $items): array
    {
        sort($items);

        return $items;
    }

    /**
     * @param  list<string>  $body
     * @return list<string>
     */
    private static function collapsed(string $summary, array $body): array
    {
        return ['<details>', "<summary>{$summary}</summary>", '', ...$body, '', '</details>'];
    }

    /**
     * A breadth list of walk hops, sorted by node with depth/via context, collapsing past the cap.
     *
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $hops
     * @return list<string>
     */
    private static function hopList(array $hops): array
    {
        if ($hops === []) {
            return ['_(none)_'];
        }

        $items = array_map(
            static function (array $hop): string {
                $location = isset($hop['file'])
                    ? ' — `' . $hop['file'] . (isset($hop['line']) ? ":{$hop['line']}" : '') . '`'
                    : '';

                return "- `{$hop['node']}` _(via {$hop['via']}, depth {$hop['depth']})_{$location}";
            },
            $hops,
        );
        sort($items);

        if (count($items) <= self::LIST_CAP) {
            return $items;
        }

        $rest = array_slice($items, self::LIST_CAP);

        return [
            ...array_slice($items, 0, self::LIST_CAP),
            '',
            ...self::collapsed(sprintf('… and %d more', count($rest)), $rest),
        ];
    }

    /**
     * A console-command entry-point node carries its whole `$signature`; show just the command name,
     * matching {@see ImpactFormatter::entryLabel()}.
     */
    private static function entryLabel(string $node): string
    {
        return str_starts_with($node, 'command::') ? explode(' ', $node, 2)[0] : $node;
    }

    /** "Referenced" is deliberately weak phrasing: the index matches references, it does not prove coverage. */
    private static function testReferenceSuffix(?TestReferenceIndex $tests, string $node): string
    {
        return match ($tests?->hasReference($node)) {
            true => ' — ✅ test-referenced',
            false => ' — ⚠️ no test references this',
            default => '',
        };
    }
}
