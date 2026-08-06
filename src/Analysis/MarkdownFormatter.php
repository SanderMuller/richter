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

    /**
     * @param  array{target: string, callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, entryPoints?: list<string>, entryPointPaths?: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations?: array<string, array{file: string, line?: int}>, entryPointSecurity?: array<string, SecurityShape>, entryPointGates?: array<string, list<string>>, entryPointAuthGates?: array<string, list<string>>, entryPointAuthMiddleware?: array<string, list<string>>, suggestions?: list<string>, graphNodeCount?: int}  $result
     * @param  bool  $explain  render the call chain from each reached entry surface down to the symbol
     */
    public static function impact(array $result, ?TestReferenceIndex $tests = null, bool $explain = false): string
    {
        $lines = ["## Richter blast radius: `{$result['target']}`", ''];

        if ($result['callers'] === [] && $result['dependencies'] === []) {
            $lines[] = '_No graph nodes matched. The change may not be reachable from a traced entry point yet._';

            // Same lead the text report gives on a miss: the nearest ids, or how many were scanned.
            $suggestions = $result['suggestions'] ?? [];
            $nodeCount = $result['graphNodeCount'] ?? null;

            if ($suggestions !== []) {
                $lines = [...$lines, '', '_Nearest graph nodes: ' . implode(', ', array_map(static fn (string $node): string => "`{$node}`", $suggestions)) . '._'];
            } elseif ($nodeCount !== null) {
                $lines = [...$lines, '', "_Scanned {$nodeCount} graph nodes; none share an identifier with it._"];
            }

            return implode("\n", $lines);
        }

        $entryPoints = $result['entryPoints'] ?? [];

        $lines[] = sprintf('### Callers (what breaks if you change it) (%d)', count($result['callers']));
        $lines[] = '';
        $lines = [...$lines, ...self::hopList($result['callers']), ''];
        $lines[] = sprintf('### Entry surfaces reached (%d)', count($entryPoints));
        $lines[] = '';
        // The checklist's own empty state says "from the changed code" — wrong for a
        // symbol report, so the zero case gets its own line.
        $lines = [...$lines, ...($entryPoints === [] ? ['_None — no entry surface reaches this symbol._'] : self::entryPointChecklist(
            $entryPoints,
            $explain ? ($result['entryPointPaths'] ?? []) : [],
            $result['entryPointLocations'] ?? [],
            $result['entryPointSecurity'] ?? [],
            $result['entryPointGates'] ?? [],
            $result['entryPointAuthGates'] ?? [],
            $result['entryPointAuthMiddleware'] ?? [],
            $tests,
        )), ''];
        $lines[] = sprintf('### Dependencies (what it reaches) (%d)', count($result['dependencies']));
        $lines[] = '';
        $lines = [...$lines, ...self::hopList($result['dependencies'])];

        return implode("\n", $lines);
    }

    /** @param  array{from: string, to: string, resolvedFrom: list<string>, resolvedTo: list<string>, found: bool, path: list<array{node: string, via: string, file?: string, line?: int}>, furthestReached?: array{node: string, depth: int, file?: string, line?: int}}  $result */
    public static function trace(array $result): string
    {
        $lines = ["## Richter trace: `{$result['from']}` → `{$result['to']}`", ''];

        if (! $result['found']) {
            $furthest = $result['furthestReached'] ?? null;

            return implode("\n", [
                ...$lines,
                '_No path in call direction._',
                '',
                $furthest === null
                    ? "_`{$result['to']}` has no callers in the graph._"
                    : '_Upstream walk from `' . $result['to'] . '` reached `' . NodeLabel::display($furthest['node']) . "` (d{$furthest['depth']}) — the deepest caller within the depth limit._",
                '',
                '_Swap the arguments to query the reverse direction._',
            ]);
        }

        $lines[] = self::pathChain(NodeLabel::display($result['path'][0]['node']), $result['path']);

        return implode("\n", $lines);
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, newFiles?: list<string>, fqcns?: array<string, string>, entryPoints: list<string>, entryPointPaths?: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations?: array<string, array{file: string, line?: int}>, entryPointSecurity?: array<string, SecurityShape>, entryPointGates?: array<string, list<string>>, entryPointAuthGates?: array<string, list<string>>, entryPointAuthMiddleware?: array<string, list<string>>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied?: bool, findings?: list<string>, ...}  $result
     * @param  bool  $gateActive  when a `--fail-on*` gate is active the command appends its own verdict, so the advisory suffix is dropped to avoid contradicting it
     * @param  bool  $explain  render the call chain from each reached entry point down to the changed symbol
     * @param  string|null  $notice  a caveat about the analysis to render inside the document (the
     *   command's stderr notes never reach a posted comment)
     */
    public static function detectChanges(array $result, ?TestReferenceIndex $tests = null, bool $gateActive = false, bool $explain = false, ?string $notice = null): string
    {
        $unresolved = in_array('unresolved', $result['coverage'], strict: true);

        $lines = ['## Richter change impact', ''];
        $lines[] = sprintf(
            '**Risk:** %s%s · **Entry points reached:** %d · **Impacted nodes:** %d',
            self::riskBadge($result['risk']),
            $gateActive ? '' : ' _(advisory)_',
            count($result['entryPoints']),
            $result['impacted'],
        );

        // This document travels — a PR comment, a CI artifact — where the command's stderr notes do not.
        // A caveat about the analysis itself (an unmatched root namespace) has to ride along, or the
        // reader of the comment sees a confident report and no reason to doubt its scope.
        if ($notice !== null) {
            $lines[] = '';
            $lines[] = "> ⚠️ {$notice}";
        }

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

        $newFiles = $result['newFiles'] ?? [];

        foreach ($result['changed'] as $file => $nodeCount) {
            $isUnresolved = ($result['coverage'][$file] ?? 'analyzed') === 'unresolved';
            $coverage = $isUnresolved
                ? '⚠️ **UNRESOLVED** (not placed in the graph)'
                : 'analyzed';
            // Says why the node count is a whole-class seed, and why an adds-only diff can carry risk.
            $coverage .= in_array($file, $newFiles, strict: true) ? ' · new file' : '';
            $lines[] = '| ' . self::pathCell($file) . self::derivedFqcnCell($result, $file, $isUnresolved, $explain) . " | {$nodeCount} | {$coverage} |";
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
            $result['entryPointAuthGates'] ?? [],
            $result['entryPointAuthMiddleware'] ?? [],
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

    /**
     * The FQCN the path resolved to, appended to the File cell (`<br>` renders inside a GitHub table
     * cell). Shown on the same terms as the text report's echo — see
     * {@see ImpactFormatter::derivedFqcn()} for why an UNRESOLVED file and `--explain`, and not a zero
     * node count.
     *
     * @param  array{fqcns?: array<string, string>, ...}  $result
     */
    private static function derivedFqcnCell(array $result, string $file, bool $unresolved, bool $explain): string
    {
        if (! $unresolved && ! $explain) {
            return '';
        }

        $fqcn = ($result['fqcns'] ?? [])[$file] ?? '';

        return $fqcn === '' ? '' : '<br>→ ' . self::pathCell($fqcn);
    }

    /** A diff-derived file path may contain `|` or backticks — the one repo-derived value the
     *  no-escaping rule in the class docblock cannot cover. */
    private static function pathCell(string $file): string
    {
        $escaped = str_replace('|', '\|', $file);

        if (! str_contains($escaped, '`')) {
            return "`{$escaped}`";
        }

        // The span's fence must outrun the longest backtick run inside the path, or the span
        // closes mid-filename; the padding spaces keep a leading/trailing backtick off the fence
        // (CommonMark strips one symmetric space pair).
        preg_match_all('/`+/', $escaped, $runs);
        $fence = str_repeat('`', max([1, ...array_map(strlen(...), $runs[0])]) + 1);

        return "{$fence} {$escaped} {$fence}";
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
     * @param  array<string, list<string>>  $authGates  keyed by entry-point node; policy gates that contradict a PUBLIC_WRITE finding
     * @param  array<string, list<string>>  $authMiddleware  keyed by entry-point node; auth middleware that contradicts one
     * @return list<string>
     */
    private static function entryPointChecklist(array $entryPoints, array $paths, array $locations, array $security, array $gates, array $authGates, array $authMiddleware, ?TestReferenceIndex $tests): array
    {
        if ($entryPoints === []) {
            return ['_None reached from the changed code._'];
        }

        $rows = EntryPointRow::build($entryPoints, $paths, $locations, $security, $gates, $authGates, $authMiddleware, $tests);

        $lines = self::checklistEntries(array_slice($rows, 0, self::LIST_CAP));

        if (count($rows) > self::LIST_CAP) {
            $rest = array_slice($rows, self::LIST_CAP);
            $lines = [...$lines, '', ...self::collapsed(
                sprintf('… and %d more', count($rest)),
                self::checklistEntries($rest),
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
     * @param  list<EntryPointRow>  $rows
     * @return list<string>
     */
    private static function checklistEntries(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $label = '`' . $row->label . '`'
                . self::locationSuffix($row->location)
                . self::testReferenceSuffix($row->testReferenced, $row->assertionWeak)
                . ($row->security !== null ? ' — ' . self::exposureBadge($row->security['exposure']) : '')
                . ($row->gates !== [] ? ' — 🚩 ' . implode(', ', $row->gates) : '');
            $lines[] = "- [ ] {$label}";

            // A single-hop path is the entry point itself — there is no chain to explain.
            if (count($row->path) > 1) {
                $lines[] = '  - ↳ ' . self::pathChain($row->label, $row->path);
            }

            foreach ($row->security['issues'] ?? [] as $issue) {
                $issueLocation = isset($issue['file'])
                    ? ' — `' . $issue['file'] . (isset($issue['line']) ? ":{$issue['line']}" : '') . '`'
                    : '';
                $lines[] = "  - ⚠️ **{$issue['type']}** ({$issue['severity']}): {$issue['message']}{$issueLocation}";
            }

            if ($row->authMiddleware !== []) {
                $lines[] = '  - ℹ️ richter: `' . implode('`, `', $row->authMiddleware)
                    . '` is applied to this route and extends a framework authentication middleware, so the finding above is likely wrong '
                    . '(Brain matches middleware by name, not by ancestry).';
            }

            if ($row->authGates !== []) {
                $lines[] = '  - ℹ️ richter: an authorization policy (`' . implode('`, `', $row->authGates)
                    . '`) is applied in this route\'s reach — verify whether it gates this write '
                    . "(Brain's route-surface analysis does not resolve middleware groups or in-controller gates).";
            }
        }

        return $lines;
    }

    /**
     * One explain chain: the entry point first, the changed symbol last, each arrow labelled
     * with the edge type connecting its two hops.
     *
     * @param  list<array{node: string, via: string, file?: string, line?: int}>  $path
     */
    private static function pathChain(string $firstLabel, array $path): string
    {
        $chain = '`' . $firstLabel . '`';
        $count = count($path);

        for ($i = 1; $i < $count; ++$i) {
            $chain .= " →({$path[$i - 1]['via']}) `" . NodeLabel::display($path[$i]['node']) . '`';
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

                return '- `' . NodeLabel::display($hop['node']) . "` _(via {$hop['via']}, depth {$hop['depth']})_{$location}";
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
     * "Referenced" is deliberately weak phrasing: the index matches references, it does not prove
     * coverage. The assertion-weak variant is a heuristic prompt, not a coverage verdict — always
     * "no behavioural assertion found", never "not covered" / "untested".
     */
    private static function testReferenceSuffix(?bool $referenced, bool $assertionWeak): string
    {
        return match (true) {
            $referenced === true && $assertionWeak => ' — 🟡 test-referenced, no behavioural assertion found',
            $referenced === true => ' — ✅ test-referenced',
            $referenced === false => ' — ⚠️ no test references this',
            default => '',
        };
    }
}
