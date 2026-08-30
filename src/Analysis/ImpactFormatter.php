<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Str;
use SanderMuller\Richter\Graph\NodeMetadata;
use SanderMuller\Richter\Support\AssociationSurfaces;
use SanderMuller\Richter\Support\InheritanceSurfaces;

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

    /**
     * @param  array{target: string, callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, entryPoints?: list<string>, associationEntryPoints?: list<string>, associationEntryPointsVia?: array<string, list<string>>, entryPointPaths?: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations?: array<string, array{file: string, line?: int}>, entryPointSecurity?: array<string, SecurityShape>, entryPointGates?: array<string, list<string>>, entryPointAuthGates?: array<string, list<string>>, entryPointAuthMiddleware?: array<string, list<string>>, entryPointAttribution?: array<string, array{via: string, ownReach: int}>, suggestions?: list<string>, graphNodeCount?: int}  $result
     * @param  bool  $explain  render the call chain from each reached entry surface down to the symbol
     */
    public static function impact(array $result, ?TestReferenceIndex $tests = null, bool $explain = false): string
    {
        if ($result['callers'] === [] && $result['dependencies'] === []) {
            return "No graph nodes matched \"{$result['target']}\". It may be spelled differently, sit under another root namespace, or be reached only through a call shape richter does not trace."
                . self::missDiagnostic($result['suggestions'] ?? [], $result['graphNodeCount'] ?? null);
        }

        $entryPoints = $result['entryPoints'] ?? [];

        $lines = [
            "Callers (what breaks if you change \"{$result['target']}\"):",
            ...self::hops($result['callers']),
            '',
            'Entry surfaces reached (' . count($entryPoints) . '):',
            ...($entryPoints === [] ? ['  (none)'] : self::entryPointList(
                $entryPoints,
                $explain ? ($result['entryPointPaths'] ?? []) : [],
                $result['entryPointLocations'] ?? [],
                $result['entryPointSecurity'] ?? [],
                $result['entryPointGates'] ?? [],
                $result['entryPointAuthGates'] ?? [],
                $result['entryPointAuthMiddleware'] ?? [],
                $tests,
            )),
            ...self::associationSurfaces($result['associationEntryPoints'] ?? [], $result['associationEntryPointsVia'] ?? []),
            '',
            "Dependencies (what \"{$result['target']}\" reaches):",
            ...self::hops($result['dependencies']),
        ];

        return implode("\n", $lines);
    }

    /** @param  array{from: string, to: string, resolvedFrom: list<string>, resolvedTo: list<string>, found: bool, path: list<array{node: string, via: string, file?: string, line?: int}>, furthestReached?: array{node: string, depth: int, file?: string, line?: int}}  $result */
    public static function trace(array $result): string
    {
        if ($result['found']) {
            $hops = count($result['path']) - 1;

            return implode("\n", [
                "Path from \"{$result['from']}\" to \"{$result['to']}\" (call direction, {$hops} hop(s)):",
                '  ↳ ' . self::pathChain(NodeLabel::display($result['path'][0]['node']), $result['path']),
            ]);
        }

        $furthest = $result['furthestReached'] ?? null;

        return implode("\n", [
            "No path from \"{$result['from']}\" to \"{$result['to']}\" in call direction.",
            $furthest === null
                ? "  \"{$result['to']}\" has no callers in the graph."
                : '  Upstream walk from "' . $result['to'] . '" reached ' . NodeLabel::display($furthest['node']) . " (d{$furthest['depth']}) — the deepest caller within the depth limit, not a pointer toward \"{$result['from']}\".",
            '  Swap the arguments to query the reverse direction.',
        ]);
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, newFiles?: list<string>, fqcns?: array<string, string>, entryPoints: list<string>, associationEntryPoints?: list<string>, associationEntryPointsVia?: array<string, list<string>>, entryPointPaths?: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations?: array<string, array{file: string, line?: int}>, entryPointSecurity?: array<string, SecurityShape>, entryPointGates?: array<string, list<string>>, entryPointAuthGates?: array<string, list<string>>, entryPointAuthMiddleware?: array<string, list<string>>, entryPointAttribution?: array<string, array{via: string, ownReach: int}>, impacted: int, relatedModels: list<string>, traitAndOverrideReach?: list<string>, traitAndOverrideReachVia?: array<string, list<string>>, risk: RiskLevel, riskCause?: string, hazards?: list<Hazard>, lowConfidence: bool, findings?: list<string>, ...}  $result
     * @param  bool  $gateActive  when a `--fail-on*` gate is active the command prints its own verdict, so the advisory suffix is dropped to avoid contradicting it
     * @param  bool  $explain  render the call chain from each reached entry point down to the changed symbol
     */
    public static function detectChanges(array $result, ?TestReferenceIndex $tests = null, bool $gateActive = false, bool $explain = false): string
    {
        $lines = ['Changed files:'];

        $newFiles = $result['newFiles'] ?? [];

        foreach ($result['changed'] as $file => $nodeCount) {
            $note = ($result['coverage'][$file] ?? 'analyzed') === 'unresolved'
                ? '  (UNRESOLVED: reach for this file could not be fully determined)'
                : '';
            // Says why the node count is a whole-class seed, and why an adds-only diff can carry risk.
            $marker = in_array($file, $newFiles, strict: true) ? ' [new file]' : '';
            $lines[] = "  {$file}" . self::derivedFqcn($result, $file, $note !== '', $explain) . " ({$nodeCount} graph nodes){$marker}{$note}";
        }

        $unresolved = in_array('unresolved', $result['coverage'], strict: true);

        $unresolvedSuffix = $unresolved ? ' (some changed files could not be fully placed — see UNRESOLVED above)' : '';
        $lines[] = '';
        $lines[] = 'Entry points reached: ' . count($result['entryPoints']) . $unresolvedSuffix;
        $lines = [...$lines, ...self::entryPointList(
            $result['entryPoints'],
            $explain ? ($result['entryPointPaths'] ?? []) : [],
            $result['entryPointLocations'] ?? [],
            $result['entryPointSecurity'] ?? [],
            $result['entryPointGates'] ?? [],
            $result['entryPointAuthGates'] ?? [],
            $result['entryPointAuthMiddleware'] ?? [],
            $tests,
            $result['entryPointAttribution'] ?? [],
        )];

        // Beside the call-reached list, never inside it. These surfaces are connected to the change
        // by a model relation, which associates rather than invokes: nothing here runs the changed
        // code. Listing them together made a long list unreadable and named admin screens as reached
        // surfaces while the routes that do run the code sat elsewhere in the report.
        $lines = [...$lines, ...self::associationSurfaces($result['associationEntryPoints'] ?? [], $result['associationEntryPointsVia'] ?? [])];

        if ($result['relatedModels'] !== []) {
            $lines[] = '';
            $lines[] = 'Related models (association reach — context, not risk): ' . count($result['relatedModels']);
            $lines = [...$lines, ...self::summarisedList($result['relatedModels'])];
        }

        if (($result['traitAndOverrideReach'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'Related by inheritance, not by a call (trait or override — context, not risk): ' . count($result['traitAndOverrideReach'] ?? []);
            /** @var array<string, list<string>> $via */
            $via = $result['traitAndOverrideReachVia'] ?? [];
            $lines = [...$lines, ...self::inheritanceLines($result['traitAndOverrideReach'] ?? [], $via)];
        }

        $lines = [...$lines, ...self::hazardLines($result['hazards'] ?? [])];

        if (($result['findings'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'Findings (in the changed source itself):';

            foreach ($result['findings'] as $finding) {
                $lines[] = "  ! {$finding}";
            }
        }

        $lines[] = '';
        $lines[] = 'Risk:   ' . Str::upper($result['risk']->value) . ($gateActive ? '' : ' (advisory)')
            . (($result['riskCause'] ?? '') === '' ? '' : ' — ' . $result['riskCause']);
        // Breadth, decoupled. It describes the change; it no longer grades it.
        $lines[] = 'Impact: ' . count($result['entryPoints']) . ' entry point(s) · ' . $result['impacted'] . ' impacted node(s)';

        // A level computed over nothing is not a level. When NOT ONE changed file could be placed, the
        // figures above describe the search rather than the change, and LOW is what a reviewer takes
        // from the bottom line — the UNRESOLVED markers sit further up, beside the files. This says it
        // where the level is read. It does not alter the level: risk is a function of the graph by
        // contract, and a report that silently withheld one would break every `--fail-on` in CI.
        if (self::nothingCouldBePlaced($result['coverage'])) {
            $lines[] = 'Note: none of the changed files could be placed in the graph, so this level says what was not found, not that nothing is affected.';
        }

        if ($result['lowConfidence']) {
            $lines[] = 'Note: low confidence — a changed member could not be pinned to a graph node, so part of this is a coarse class-level estimate.';
        }

        // A LOW on a frontend-heavy diff is easily misread as "nothing to see" — say what the
        // number does and does not measure.
        if (self::hasFrontendFiles($result['changed'])) {
            $lines[] = 'Note: frontend change — risk reflects backend impact only; the routes listed are the surface this change touches.';
        }

        return implode("\n", $lines);
    }

    /**
     * The FQCN a changed path resolved to, as ` → App\Models\Post`, or '' when there is nothing worth
     * saying. Shown for an UNRESOLVED file, where the derived name is the whole diagnosis (a wrong root
     * namespace, a class outside `app/`, a typo'd path) and a reader has no other way to see it, and
     * under `--explain`, where the ask is to show the working.
     *
     * Deliberately NOT keyed on a zero node count: an additive-only change reports zero by design (a
     * new member has no existing callers), and echoing there would imply a placement failure that did
     * not happen. Empty for a file with no class at all (a Blade view, a frontend file).
     *
     * @param  array{fqcns?: array<string, string>, ...}  $result
     */
    private static function derivedFqcn(array $result, string $file, bool $unresolved, bool $explain): string
    {
        if (! $unresolved && ! $explain) {
            return '';
        }

        $fqcn = ($result['fqcns'] ?? [])[$file] ?? '';

        return $fqcn === '' ? '' : " → {$fqcn}";
    }

    /**
     * What to add to a "no graph nodes matched" message so it is a lead rather than a dead end: the
     * nearest node ids, or — when nothing in the graph even resembles the symbol — how many nodes were
     * scanned, which distinguishes "wrong name" from "the graph is empty/tiny".
     *
     * Public because {@see SymbolTracer} raises the same miss as an exception rather than a rendered
     * report, and the two surfaces must not drift into two different diagnostics.
     *
     * @param  list<string>  $suggestions
     */
    public static function missDiagnostic(array $suggestions, ?int $graphNodeCount): string
    {
        if ($suggestions !== []) {
            return "\nNearest graph nodes: " . implode(', ', $suggestions);
        }

        return $graphNodeCount === null ? '' : "\nScanned {$graphNodeCount} graph nodes; none share an identifier with it.";
    }

    /**
     * Whether every changed file came back unresolved — no node, no reach, nothing to score.
     *
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     */
    private static function nothingCouldBePlaced(array $coverage): bool
    {
        return $coverage !== [] && ! in_array('analyzed', $coverage, strict: true);
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
     * The hazards, worst tier first, each with its CWE where it has one and its reach class. Above
     * `Findings` deliberately: a hazard says something may BREAK, a finding says something could not
     * be SEEN, and the first is what a reviewer must read.
     *
     * @param  list<Hazard>  $hazards
     * @return list<string>
     */
    private static function hazardLines(array $hazards): array
    {
        if ($hazards === []) {
            return [];
        }

        $lines = ['', 'Hazards (' . count($hazards) . '):'];

        foreach ($hazards as $hazard) {
            $cwe = $hazard->cwe === null ? '' : " {$hazard->cwe}";
            $lines[] = "  ! [tier {$hazard->tier} {$hazard->lane}{$cwe}] {$hazard->member} — {$hazard->evidence}";
            $lines[] = '      reach: ' . $hazard->reachLabel();
        }

        return $lines;
    }

    /**
     * The inheritance-reach list, split by what each entry claims: a trait user runs the changed method
     * verbatim, an override declares its own version of it. See {@see InheritanceSurfaces} for the split
     * and for why the grouped lane claims only the second thing.
     *
     * @param  list<string>  $reach
     * @param  array<string, list<string>>  $via  entry => the edge types that put it here
     * @return list<string>
     */
    private static function inheritanceLines(array $reach, array $via): array
    {
        [$inline, $groups] = InheritanceSurfaces::partition($reach, $via);

        $lines = self::summarisedList(array_map(
            static fn (string $entry): string => $entry . (($via[$entry] ?? []) === [] ? '' : ' (' . implode(', ', $via[$entry]) . ')'),
            $inline,
        ));

        if ($groups === []) {
            return $lines;
        }

        // One line per member name rather than one per class. This format caps its lists, so fifteen
        // lines describing fifteen members say far more than fifteen siblings of the same member — and
        // the classes stay on the line, because a cap is a promise about LENGTH and dropping every name
        // would be a different promise.
        $grouped = [];

        foreach ($groups as $member => $classes) {
            $grouped[] = sprintf(
                '%s — %d %s: %s',
                $member,
                count($classes),
                count($classes) === 1 ? 'class' : 'classes',
                implode(', ', $classes),
            );
        }

        return [
            ...$lines,
            sprintf(
                '  %d override%s across %d member name%s — each declares the member itself:',
                count($reach) - count($inline),
                count($reach) - count($inline) === 1 ? '' : 's',
                count($groups),
                count($groups) === 1 ? '' : 's',
            ),
            ...self::summarisedList($grouped),
        ];
    }

    /**
     * Surfaces a model relation connects to the change rather than a call. Rendered by both
     * commands: demoting them out of the reached list without printing them anywhere would lose
     * them outright, which is a worse report than the over-counting this split exists to end.
     *
     * @param  list<string>  $association
     * @param  array<string, list<string>>  $via  surface => the association edge types on the path the surface DEPENDS on; a fan-out is named only where it is required
     * @return list<string>
     */
    private static function associationSurfaces(array $association, array $via): array
    {
        if ($association === []) {
            return [];
        }

        [$named, $fanout] = AssociationSurfaces::partition($association, $via);

        // The fan-out group shares one cause, so it is counted and named once rather than listed. A
        // registry that names sixty classes answers the same for every one of them, so sixty lines
        // would spend the reader's attention on a single fact.
        // "… and N" only reads directly after a list. It contrasts with nothing where nothing stayed
        // inline, and where the inline list was CAPPED {@see summarisedList()} has already written its
        // own "… and N more" plus a breadth note, so a second one would follow a sentence. Both cases
        // drop the prefix. Same reason no format says "more" any more.
        $tail = $fanout === [] ? [] : [sprintf(
            '  %s%d %s reached only through a registry lookup that names no single class — the same surfaces answer for every class it lists',
            $named === [] || count($named) > self::LIST_CAP ? '' : '… and ',
            count($fanout),
            count($fanout) === 1 ? 'surface' : 'surfaces',
        )];

        return [
            '',
            'Entry surfaces reached only by association (context, not callers): ' . count($association),
            ...self::summarisedList($named),
            ...$tail,
        ];
    }

    /**
     * The entry-point list with {@see summarisedList}'s sorting and capping, plus — when paths are
     * given — an explain chain under each entry showing how it reaches the changed symbol. Chain
     * sub-lines don't count toward the cap, and a path-less entry (a self-listed entry class) renders
     * its bullet alone. Each entry carries its defining location when known, and a route classified
     * by Brain's security surface carries its exposure plus any issues, inherited from Brain. A
     * `PUBLIC_WRITE` issue there is also what makes a hazard's reach class `public-write`.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths  keyed by entry-point node; empty when not explaining
     * @param  array<string, array{file: string, line?: int}>  $locations  keyed by entry-point node
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @param  array<string, list<string>>  $gates  keyed by entry-point node; Pennant flags gating the route
     * @param  array<string, list<string>>  $authGates  keyed by entry-point node; policy gates that contradict a PUBLIC_WRITE finding
     * @param  array<string, list<string>>  $authMiddleware  keyed by entry-point node; auth middleware that contradicts one
     * @param  array<string, array{via: string, ownReach: int}>  $attribution  keyed by entry-point node; empty for a single-symbol report
     * @return list<string>
     */
    private static function entryPointList(array $entryPoints, array $paths, array $locations, array $security, array $gates, array $authGates, array $authMiddleware, ?TestReferenceIndex $tests, array $attribution = []): array
    {
        $rows = EntryPointRow::build($entryPoints, $paths, $locations, $security, $gates, $authGates, $authMiddleware, $tests, $attribution);

        $overCap = count($rows) > self::LIST_CAP;
        $shown = $overCap ? array_slice($rows, 0, self::LIST_CAP) : $rows;
        $lines = [];

        foreach ($shown as $row) {
            $label = $row->label
                . self::locationSuffix($row->location)
                . self::testReferenceSuffix($row->testReferenced, $row->assertionWeak)
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

            if ($row->authMiddleware !== []) {
                $lines[] = '      richter: ' . implode(', ', $row->authMiddleware)
                    . ' is applied to this route and extends a framework authentication middleware, '
                    . 'so the finding above is likely wrong (Brain walks an extends chain to Authenticate only, '
                    . 'so a descendant of another auth middleware still reads public).';
            }

            if ($row->authGates !== []) {
                $lines[] = '      richter: an authorization policy (' . implode(', ', $row->authGates)
                    . ') is applied in this route\'s reach — verify whether it gates this write '
                    . '(Brain does not resolve middleware groups or in-controller gates).';
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
            $chain .= " →({$path[$i - 1]['via']}) " . NodeLabel::display($path[$i]['node']);
        }

        return $chain;
    }

    /**
     * "Referenced" is deliberately weak phrasing: the index matches references, it does not prove
     * coverage. The assertion-weak variant is a heuristic prompt, not a coverage verdict — always
     * "no behavioural assertion found", never "not covered" / "untested".
     */
    private static function testReferenceSuffix(?bool $referenced, bool $assertionWeak): string
    {
        return match (true) {
            $referenced === true && $assertionWeak => '  [test-referenced — no behavioural assertion found]',
            $referenced === true => '  [test-referenced]',
            $referenced === false => '  [⚠ no test references this]',
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

                return '  d' . $hop['depth'] . '  ' . NodeLabel::display($hop['node']) . "  (via {$hop['via']}){$location}";
            },
            $hops,
        );
    }
}
