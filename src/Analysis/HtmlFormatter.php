<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * Renders {@see ImpactAnalyzer} results as one self-contained HTML file — all CSS and JS inline —
 * so it opens offline from `file://` and travels as a CI artifact.
 *
 * Every interpolated value is project-derived and untrusted: diff paths, node ids carrying route
 * URIs, finding and security text. Unlike {@see MarkdownFormatter} there is no structurally-safe
 * exception list — run everything through {@see e()}.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 * @phpstan-import-type Layout from RadialLayout
 * @phpstan-type GateVerdict array{failOn: string|null, failOnUnresolved: bool, tripped: bool, reasons: list<string>}
 * @phpstan-type DetectChangesResult array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, SecurityShape>, entryPointGates: array<string, list<string>>, seeds: list<string>, reach: array<string, array<string, true>>, edges: list<array{source: string, target: string, via: string, depth: int}>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied: bool, findings: list<string>, ...}
 */
final class HtmlFormatter
{
    private const int OVERVIEW_ENTRY_POINT_CAP = 15;

    /**
     * @param  DetectChangesResult  $result
     * @param  list<ChangedFileSymbols>  $changed  the member-level change set, taken straight from the command — it never travels through the analyzer result
     * @param  GateVerdict|null  $gate  the gate's verdict, when a `--fail-on*` flag is active
     * @param  EditorLink|null  $editor  when set, file references render as editor deep links
     */
    public static function detectChanges(array $result, array $changed, string $base, ?TestReferenceIndex $tests = null, bool $gateActive = false, ?array $gate = null, ?EditorLink $editor = null): string
    {
        $rows = EntryPointRow::build(
            $result['entryPoints'],
            $result['entryPointPaths'],
            $result['entryPointLocations'],
            $result['entryPointSecurity'],
            $result['entryPointGates'],
            $tests,
        );

        $layout = RadialLayout::compute($result['edges'], $result['reach'], $result['seeds'], $result['entryPointLocations']);

        $body = implode("\n", [
            self::header($base, $gateActive),
            self::tabBar(),
            self::panel('overview', self::overview($result, $rows, $editor), active: true),
            self::panel('graph', BlastDiagram::render($layout, $result['impacted'])),
            self::panel('paths', self::paths($rows, $editor)),
            self::panel('changes', self::changes($result, $changed, $editor)),
            self::panel('advisory', self::advisory($result, $rows, $gateActive, $gate)),
        ]);

        return self::document($body);
    }

    // ---------------------------------------------------------------- tabs

    private static function tabBar(): string
    {
        $buttons = '';

        foreach ([['overview', 'Overview'], ['graph', 'Graph'], ['paths', 'Paths'], ['changes', 'Changes'], ['advisory', 'Advisory']] as [$id, $label]) {
            $active = $id === 'overview';
            $buttons .= '<button role="tab" data-tab="' . $id . '" aria-controls="' . $id . '"'
                . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
                . ' tabindex="' . ($active ? '0' : '-1') . '"'
                . ($active ? ' class="on"' : '') . '>' . $label . '</button>';
        }

        return '<div class="tabs" role="tablist" aria-label="Report sections">' . $buttons . '</div>';
    }

    private static function panel(string $id, string $inner, bool $active = false): string
    {
        return '<section id="' . $id . '" role="tabpanel" tabindex="0"' . ($active ? '' : ' hidden') . '>' . $inner . '</section>';
    }

    private static function header(string $base, bool $gateActive): string
    {
        $advisory = $gateActive ? '' : ' <span class="muted">(advisory — not a gate)</span>';

        return '<header><h1>Richter change impact</h1><p class="muted">against <code>' . Html::e($base) . '</code>' . $advisory . '</p></header>';
    }

    // ------------------------------------------------------------ overview

    /**
     * @param  DetectChangesResult  $result
     * @param  list<EntryPointRow>  $rows
     */
    private static function overview(array $result, array $rows, ?EditorLink $editor): string
    {
        return self::statRow($result)
            . self::lowConfidenceNote($result)
            . '<div class="cards">'
            . self::card('Entry points reached', self::entryPointList($rows, $editor))
            . self::card('What to focus on', self::focusList($result, $rows))
            . '</div>';
    }

    /**
     * No "Functions" tile: `impacted` spans routes, commands, views and models, so a function count
     * is a number the analyzer never computes.
     *
     * @param  DetectChangesResult  $result
     */
    private static function statRow(array $result): string
    {
        $tiles = self::tile('Files', (string) count($result['changed']))
            . self::tile('Impacted', (string) $result['impacted'])
            . self::tile('Depth', (string) self::maxDepth($result))
            . self::tile('Risk', strtoupper($result['risk']->value), self::riskClass($result['risk']));

        return "<div class=\"stats\">{$tiles}</div>";
    }

    private static function tile(string $label, string $value, string $class = ''): string
    {
        $modifier = $class === '' ? '' : ' ' . $class;

        return '<div class="tile' . $modifier . '"><span class="k">' . Html::e($label) . '</span><strong>' . Html::e($value) . '</strong></div>';
    }

    /** @param  DetectChangesResult  $result */
    private static function maxDepth(array $result): int
    {
        $depths = array_map(static fn (array $edge): int => $edge['depth'], $result['edges']);

        return $depths === [] ? 0 : max($depths);
    }

    /** @param  list<EntryPointRow>  $rows */
    private static function entryPointList(array $rows, ?EditorLink $editor): string
    {
        if ($rows === []) {
            return '<p class="muted">None reached from the changed code.</p>';
        }

        $shown = self::entryPointItems(array_slice($rows, 0, self::OVERVIEW_ENTRY_POINT_CAP), $editor);

        if (count($rows) <= self::OVERVIEW_ENTRY_POINT_CAP) {
            return '<ul class="entries" role="list">' . $shown . '</ul>';
        }

        $rest = array_slice($rows, self::OVERVIEW_ENTRY_POINT_CAP);

        return '<ul class="entries" role="list">' . $shown . '</ul><details><summary>… and ' . count($rest)
            . ' more</summary><ul class="entries" role="list">' . self::entryPointItems($rest, $editor) . '</ul></details>';
    }

    /** @param  list<EntryPointRow>  $rows */
    private static function entryPointItems(array $rows, ?EditorLink $editor): string
    {
        $items = '';

        foreach ($rows as $row) {
            $meta = Html::location($editor, $row->location)
                . self::testTag($row->testReferenced, $row->assertionWeak)
                . ($row->security !== null ? '<span class="badge">' . Html::e($row->security['exposure']) . '</span>' : '')
                . ($row->gates !== [] ? '<span class="badge flag">' . Html::e(implode(', ', $row->gates)) . '</span>' : '');

            $items .= '<li><p class="entry"><code>' . Html::e(Html::nodeLabel($row->node)) . '</code></p>'
                . ($meta === '' ? '' : '<p class="entry-meta">' . $meta . '</p>')
                . self::securityIssues($row->security, $editor)
                . '</li>';
        }

        return $items;
    }

    /**
     * The exposure badge alone would hide the actual finding: "public" is context, `PUBLIC_WRITE:
     * no auth middleware` is the thing a reviewer has to act on. Text and markdown both list these.
     *
     * @param  SecurityShape|null  $security
     */
    private static function securityIssues(?array $security, ?EditorLink $editor): string
    {
        $items = '';

        foreach ($security['issues'] ?? [] as $issue) {
            $location = isset($issue['file'])
                ? ' — ' . Html::link($editor, $issue['file'], $issue['line'] ?? null, Html::e($issue['file'] . (isset($issue['line']) ? ":{$issue['line']}" : '')))
                : '';

            $items .= '<li class="warn"><strong>' . Html::e($issue['type']) . '</strong> (' . Html::e($issue['severity'])
                . '): ' . Html::e($issue['message']) . $location . '</li>';
        }

        return $items === '' ? '' : '<ul class="issues" role="list">' . $items . '</ul>';
    }

    /**
     * @param  DetectChangesResult  $result
     * @param  list<EntryPointRow>  $rows
     */
    private static function focusList(array $result, array $rows): string
    {
        $items = [];

        foreach ($result['findings'] as $finding) {
            $items[] = '<li class="warn">' . Html::e($finding) . '</li>';
        }

        $unreferenced = array_filter($rows, static fn (EntryPointRow $row): bool => $row->testReferenced === false);

        if ($unreferenced !== []) {
            $items[] = '<li class="warn">' . count($unreferenced) . ' reached entry point(s) have no test referencing them</li>';
        }

        $weak = array_filter($rows, static fn (EntryPointRow $row): bool => $row->assertionWeak);

        if ($weak !== []) {
            $items[] = '<li class="warn">' . count($weak) . ' referenced entry point(s) have no behavioural assertion found</li>';
        }

        return $items === [] ? '<p class="muted">Nothing flagged.</p>' : '<ul role="list">' . implode('', $items) . '</ul>';
    }

    /** @param  DetectChangesResult  $result */
    private static function lowConfidenceNote(array $result): string
    {
        if (! $result['lowConfidence']) {
            return '';
        }

        $cap = $result['coarseCapApplied'] ? ' (risk capped at MEDIUM)' : '';

        return '<p class="note warn">Low confidence: a changed member could not be pinned to a graph node, so part of this is a coarse class-level estimate'
            . Html::e($cap) . '. The Changes tab names the members.</p>';
    }

    // --------------------------------------------------------------- paths

    /** @param  list<EntryPointRow>  $rows */
    private static function paths(array $rows, ?EditorLink $editor): string
    {
        $chains = '';

        foreach ($rows as $row) {
            if (count($row->path) > 1) {
                $chains .= self::chain($row, $editor);
            }
        }

        return $chains === ''
            ? '<p class="muted">No call chains — run with the graph reached from a changed symbol.</p>'
            : '<ol class="paths" role="list">' . $chains . '</ol>';
    }

    /**
     * A vertical chain rather than one wrapping line: entry point first, changed symbol last, each
     * step labelled with the edge that reached it.
     *
     * Each step shows the PREVIOUS hop's `via`, because a hop's `via` is the edge to the NEXT hop
     * and the final seed hop carries `''` — see {@see CodeGraph::callerPathsTo()}.
     */
    private static function chain(EntryPointRow $row, ?EditorLink $editor): string
    {
        $count = count($row->path);
        $steps = '';

        for ($i = 1; $i < $count; ++$i) {
            $steps .= '<li><span class="via">' . Html::e($row->path[$i - 1]['via']) . '</span>'
                . '<code>' . Html::e(Html::nodeLabel($row->path[$i]['node'])) . '</code>'
                . self::hopLocation($row->path[$i], $editor) . '</li>';
        }

        return '<li class="path"><p class="path-entry"><code>' . Html::e(Html::nodeLabel($row->node)) . '</code>'
            . Html::location($editor, $row->location) . '</p>'
            . '<ol class="hops" role="list">' . $steps . '</ol></li>';
    }

    /** @param  array{node: string, via: string, file?: string, line?: int}  $hop */
    private static function hopLocation(array $hop, ?EditorLink $editor): string
    {
        if (! isset($hop['file'])) {
            return '';
        }

        $span = '<span class="loc">' . Html::e($hop['file'] . (isset($hop['line']) ? ":{$hop['line']}" : '')) . '</span>';

        return ' ' . Html::link($editor, $hop['file'], $hop['line'] ?? null, $span);
    }

    // ------------------------------------------------------------- changes

    /**
     * @param  DetectChangesResult  $result
     * @param  list<ChangedFileSymbols>  $changed
     */
    private static function changes(array $result, array $changed, ?EditorLink $editor): string
    {
        if ($changed === []) {
            return '<p class="muted">No changed files.</p>';
        }

        $blocks = '';

        foreach ($changed as $file) {
            $blocks .= self::changedFile($file, self::coverageOf($result, $file->file), $editor);
        }

        return $blocks;
    }

    private static function changedFile(ChangedFileSymbols $file, string $coverage, ?EditorLink $editor): string
    {
        $badges = $coverage === 'unresolved'
            ? '<span class="badge warn">UNRESOLVED — not graphed, never "no impact"</span>'
            : '<span class="badge">analyzed</span>';

        if ($file->cosmeticOnly) {
            $badges .= '<span class="badge">cosmetic only</span>';
        }

        if ($file->unresolvedFrontendReferences) {
            $badges .= '<span class="badge warn">unresolved frontend references</span>';
        }

        $fqcn = $file->fqcn === '' ? '' : '<code>' . Html::e($file->fqcn) . '</code> ';
        $heading = Html::link($editor, $file->file, null, '<code>' . Html::e($file->file) . '</code>');

        return '<section class="file"><h3>' . $heading . '</h3>'
            . '<p class="file-meta">' . $fqcn . $badges . '</p>'
            . self::findings($file->findings)
            . self::memberTable($file->members)
            . '</section>';
    }

    /** @param  list<MemberChange>  $members */
    private static function memberTable(array $members): string
    {
        if ($members === []) {
            return '<p class="muted">No PHP members changed.</p>';
        }

        $rows = '';

        foreach ($members as $member) {
            $rows .= self::memberRow($member);
        }

        return '<div class="scroll"><table><thead><tr><th>Member</th><th>Kind</th><th>Change</th><th>Pinned to a node</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private static function memberRow(MemberChange $member): string
    {
        $unpinnable = ! $member->resolvable && ! $member->isAdditive();
        $class = $unpinnable ? ' class="warn"' : '';
        $pinned = $unpinnable
            ? 'no — drives the coarse class-level seed'
            : ($member->resolvable ? 'yes' : 'n/a (additive)');

        return "<tr{$class}><td><code>" . Html::e($member->name) . '</code></td><td>' . Html::e($member->kind)
            . '</td><td>' . Html::e($member->change) . '</td><td>' . Html::e($pinned) . '</td></tr>';
    }

    // ------------------------------------------------------------ advisory

    /**
     * @param  DetectChangesResult  $result
     * @param  list<EntryPointRow>  $rows
     * @param  GateVerdict|null  $gate
     */
    private static function advisory(array $result, array $rows, bool $gateActive, ?array $gate): string
    {
        return self::unresolvedNote($result)
            . self::card('Findings (in the changed source itself)', $result['findings'] === [] ? '<p class="muted">None.</p>' : self::findings($result['findings']))
            . self::card('Test references', self::testReferenceList($rows))
            . ($gateActive && $gate !== null ? self::card('Gate', self::gateBlock($gate)) : '');
    }

    /** @param  GateVerdict  $gate */
    private static function gateBlock(array $gate): string
    {
        $reasons = '';

        foreach ($gate['reasons'] as $reason) {
            $reasons .= '<li>' . Html::e($reason) . '</li>';
        }

        $state = $gate['tripped']
            ? '<strong class="warn">TRIPPED</strong>'
            : '<strong>not tripped</strong>';

        return '<p class="gate">' . $state . ' <span class="muted">fail-on <code>' . Html::e($gate['failOn'] ?? 'none')
            . '</code> · fail-on-unresolved <code>' . ($gate['failOnUnresolved'] ? 'yes' : 'no') . '</code></span></p>'
            . ($reasons === '' ? '' : '<ul role="list">' . $reasons . '</ul>');
    }

    /** @param  list<EntryPointRow>  $rows */
    private static function testReferenceList(array $rows): string
    {
        $items = '';

        foreach ($rows as $row) {
            $tag = self::testTag($row->testReferenced, $row->assertionWeak);

            if ($tag !== '') {
                $items .= '<li><p class="entry"><code>' . Html::e(Html::nodeLabel($row->node)) . '</code></p>'
                    . '<p class="entry-meta">' . $tag . '</p></li>';
            }
        }

        return $items === '' ? '<p class="muted">No entry point could be checked.</p>' : '<ul class="entries" role="list">' . $items . '</ul>';
    }

    /** @param  DetectChangesResult  $result */
    private static function unresolvedNote(array $result): string
    {
        $coverage = $result['coverage'];

        if (! in_array('unresolved', $coverage, strict: true)) {
            return '';
        }

        return '<p class="note warn">Some changed files could not be placed in the graph — the reach reported here may be incomplete. UNRESOLVED never means "no impact".</p>';
    }

    // -------------------------------------------------------------- shared

    private static function card(string $title, string $inner): string
    {
        return '<div class="card"><h2>' . Html::e($title) . '</h2>' . $inner . '</div>';
    }

    /** @param  list<string>  $findings */
    private static function findings(array $findings): string
    {
        if ($findings === []) {
            return '';
        }

        $items = '';

        foreach ($findings as $finding) {
            $items .= '<li class="warn">' . Html::e($finding) . '</li>';
        }

        return '<ul role="list">' . $items . '</ul>';
    }

    /** Reference is not coverage: the index only matches references. Never phrase these as a verdict. */
    private static function testTag(?bool $referenced, bool $assertionWeak): string
    {
        return match (true) {
            $referenced === true && $assertionWeak => ' <span class="badge warn">test-referenced, no behavioural assertion found</span>',
            $referenced === true => ' <span class="badge ok">test-referenced</span>',
            $referenced === false => ' <span class="badge warn">no test references this</span>',
            default => '',
        };
    }

    private static function riskClass(RiskLevel $risk): string
    {
        return match ($risk) {
            RiskLevel::High => 'risk-high',
            RiskLevel::Medium => 'risk-medium',
            RiskLevel::Low => 'risk-low',
        };
    }

    /** @param  DetectChangesResult  $result */
    private static function coverageOf(array $result, string $file): string
    {
        return $result['coverage'][$file] ?? 'analyzed';
    }

    private static function document(string $body): string
    {
        $css = self::css();
        $js = self::js();

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Richter change impact</title>
            <style>{$css}</style>
            </head>
            <body>
            {$body}
            <script>{$js}</script>
            </body>
            </html>
            HTML;
    }

    private static function css(): string
    {
        return <<<'CSS'
            :root{
              color-scheme:light dark;
              --bg:#fff; --fg:#18181b; --muted:#71717a; --faint:#a1a1aa;
              --line:rgb(24 24 27/.08); --line-strong:rgb(24 24 27/.14); --well:#fafafa;
              --accent:#6d28d9; --accent-soft:rgb(109 40 217/.10);
              --seed:#4c1d95; --imp:#8b5cf6; --assoc:#d4d4d8;
              --warn:#b45309; --warn-soft:rgb(180 83 9/.10); --ok:#15803d;
              --tip-bg:#18181b; --tip-fg:#fafafa;
            }
            @media(prefers-color-scheme:dark){:root{
              --bg:#18181b; --fg:#fafafa; --muted:#a1a1aa; --faint:#71717a;
              --line:rgb(250 250 250/.10); --line-strong:rgb(250 250 250/.18); --well:#212124;
              --accent:#a78bfa; --accent-soft:rgb(167 139 250/.14);
              --seed:#c4b5fd; --imp:#8b5cf6; --assoc:#52525b;
              --warn:#f59e0b; --warn-soft:rgb(245 158 11/.12); --ok:#4ade80;
              --tip-bg:#fafafa; --tip-fg:#18181b;
            }}
            *{box-sizing:border-box}
            body{
              margin:0 auto; padding:2.5rem 1.5rem 4rem; max-width:64rem;
              background:var(--bg); color:var(--fg);
              font:1rem/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
              -webkit-font-smoothing:antialiased;
            }
            h1{margin:0; font-size:1.5rem; font-weight:600; letter-spacing:-.02em}
            h2{margin:0 0 .75rem; font-size:.9375rem; font-weight:600; color:var(--fg)}
            h3{margin:0; font-size:.9375rem; font-weight:600}
            p{margin:0}
            code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8125rem; word-break:break-word}
            .muted{color:var(--muted)}
            .warn{color:var(--warn)}
            header p{margin-top:.25rem; font-size:.875rem}

            .tabs{display:flex; gap:.125rem; margin:1.75rem 0 2rem; border-bottom:1px solid var(--line); overflow-x:auto}
            .tabs button{
              flex:0 0 auto; border:0; border-bottom:2px solid transparent; border-radius:.375rem .375rem 0 0;
              background:none; color:var(--muted); font:inherit; font-size:.9375rem;
              padding:.5rem .875rem; cursor:pointer; white-space:nowrap;
            }
            .tabs button:hover{color:var(--fg); background:var(--well)}
            .tabs button.on{color:var(--accent); border-bottom-color:var(--accent)}
            .tabs button:focus-visible{outline:2px solid var(--accent); outline-offset:-2px}

            .stats{display:grid; grid-template-columns:repeat(auto-fit,minmax(9rem,1fr)); margin-bottom:2rem}
            .tile{padding:0 1.25rem; border-left:1px solid var(--line)}
            .tile:first-child{padding-left:0; border-left:0}
            .tile:last-child{padding-right:0}
            .tile .k{display:block; font-size:.8125rem; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
            .tile strong{display:block; margin-top:.125rem; font-size:1.75rem; font-weight:600; letter-spacing:-.02em; font-variant-numeric:tabular-nums}
            .risk-high strong{color:#dc2626}.risk-medium strong{color:#d97706}.risk-low strong{color:var(--ok)}

            .cards{display:grid; grid-template-columns:repeat(auto-fit,minmax(20rem,1fr)); gap:2rem; margin-top:2rem}
            .card{min-width:0}
            .note{margin:1.25rem 0; padding:.75rem 1rem; border-left:2px solid var(--warn); border-radius:0 .375rem .375rem 0; background:var(--warn-soft); font-size:.875rem}

            ul{margin:0; padding-left:1.125rem}
            ul[role=list]{list-style:none; padding-left:0}
            .entries li,.issues li{padding:.625rem 0; border-top:1px solid var(--line); font-size:.875rem}
            .entry{font-size:.875rem}
            .entry code{color:var(--fg)}
            .entry-meta{display:flex; flex-wrap:wrap; align-items:center; gap:.375rem; margin-top:.25rem}
            .entry-meta .badge{margin-left:0}
            .entries>li:first-child{border-top:0}
            .issues{margin-top:.375rem; padding-left:.875rem; border-left:2px solid var(--warn-soft)}
            .card ul[role=list] li{padding:.5rem 0; border-top:1px solid var(--line); font-size:.875rem}
            .card ul[role=list] li:first-child{border-top:0}
            details{margin-top:.5rem; font-size:.875rem}
            summary{color:var(--muted); cursor:pointer; padding:.5rem 0}

            .badge{
              display:inline-block; margin-left:.375rem; padding:.0625rem .5rem; border:1px solid var(--line-strong);
              border-radius:1rem; font-size:.75rem; color:var(--muted); white-space:nowrap;
            }
            .badge.ok{color:var(--ok); border-color:currentColor}
            .badge.warn{color:var(--warn); border-color:currentColor}
            .badge.flag{color:var(--accent); border-color:currentColor}
            .loc{color:var(--faint); font-size:.8125rem}
            a.ref{color:inherit; text-decoration:none}
            a.ref:hover .loc,a.ref:focus-visible .loc{color:var(--accent); text-decoration:underline}
            a.ref:hover code,a.ref:focus-visible code{color:var(--accent)}

            .graph-wrap{position:relative; margin:0 auto; max-width:44rem}
            .graph{display:block; width:100%; height:auto}
            .graph .ring{fill:none; stroke:var(--line); stroke-dasharray:2 5}
            .graph line{stroke:var(--line-strong); stroke-width:1.25}
            .graph line.on{stroke:var(--accent); stroke-width:2}
            .graph circle[data-id]{stroke:var(--bg); stroke-width:2; cursor:pointer; transition:r .12s ease}
            .graph circle[data-id]:hover,.graph circle[data-id]:focus{r:9; outline:none}
            .graph circle[data-id].dim{opacity:.25}
            .n-seed{fill:var(--seed)}.n-impacted{fill:var(--imp)}.n-association{fill:var(--assoc)}

            .tip{
              position:absolute; z-index:10; max-width:22rem; padding:.5rem .625rem; border-radius:.375rem;
              background:var(--tip-bg); color:var(--tip-fg); font-size:.8125rem; line-height:1.45;
              pointer-events:none; transform:translate(-50%,-100%); box-shadow:0 4px 12px rgb(0 0 0/.18);
            }
            .tip.below{transform:translate(-50%,0)}
            .tip b{display:block; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-weight:400; word-break:break-all}
            .tip span{display:block; margin-top:.25rem; opacity:.7; font-size:.75rem}

            .legend{display:flex; flex-wrap:wrap; gap:.25rem 1.25rem; margin-top:1.5rem; font-size:.8125rem; color:var(--muted)}
            .legend .dot{display:inline-block; width:.5rem; height:.5rem; margin-right:.375rem; border-radius:1rem; vertical-align:middle}
            .legend-hint{flex-basis:100%; color:var(--faint)}
            .dot.n-seed{background:var(--seed)}.dot.n-impacted{background:var(--imp)}.dot.n-association{background:var(--assoc)}

            .paths{display:grid; gap:1.75rem}
            .path-entry{font-size:.9375rem}
            .path-entry code{color:var(--accent)}
            .path-entry .loc{margin-left:.5rem}
            .hops{margin:.5rem 0 0; padding-left:0; list-style:none}
            .hops li{position:relative; padding:.375rem 0 .375rem 1.5rem; font-size:.875rem}
            .hops li::before{
              content:""; position:absolute; left:.3125rem; top:0; bottom:0; width:1px; background:var(--line-strong);
            }
            .hops li:last-child::before{bottom:50%}
            .hops li::after{
              content:""; position:absolute; left:.0625rem; top:50%; width:.5rem; height:.5rem;
              margin-top:-.25rem; border-radius:1rem; background:var(--line-strong);
            }
            .hops li:last-child::after{background:var(--accent)}
            .via{display:block; font-size:.75rem; color:var(--faint)}

            .file{padding:1.5rem 0; border-top:1px solid var(--line)}
            .file:first-of-type{padding-top:0; border-top:0}
            .file-meta{margin-top:.375rem; font-size:.875rem; color:var(--muted)}
            .scroll{overflow-x:auto; margin-top:.75rem}
            table{width:100%; border-collapse:collapse}
            th,td{padding:.5rem .75rem .5rem 0; text-align:left; font-size:.875rem; border-bottom:1px solid var(--line)}
            th{color:var(--muted); font-weight:500; white-space:nowrap}
            tr.warn td{color:var(--warn)}

            .gate strong{font-size:.9375rem}
            .gate .muted{font-size:.875rem}
            CSS;
    }

    private static function js(): string
    {
        return <<<'JS'
            (function () {
                var tabs = document.querySelector('[role=tablist]');
                var buttons = Array.prototype.slice.call(tabs.querySelectorAll('button'));

                var select = function (tab) {
                    buttons.forEach(function (button) {
                        var on = button.dataset.tab === tab;
                        button.classList.toggle('on', on);
                        button.setAttribute('aria-selected', on ? 'true' : 'false');
                        button.tabIndex = on ? 0 : -1;
                    });
                    document.querySelectorAll('[role=tabpanel]').forEach(function (panel) {
                        panel.hidden = panel.id !== tab;
                    });
                };

                tabs.addEventListener('click', function (event) {
                    if (event.target.dataset.tab) { select(event.target.dataset.tab); }
                });

                tabs.addEventListener('keydown', function (event) {
                    var step = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);
                    if (!step) { return; }
                    var index = buttons.indexOf(document.activeElement);
                    if (index < 0) { return; }
                    var next = buttons[(index + step + buttons.length) % buttons.length];
                    select(next.dataset.tab);
                    next.focus();
                    event.preventDefault();
                });

                var svg = document.querySelector('.graph');
                var tip = document.getElementById('tip');
                if (!svg || !tip) { return; }

                var wrap = svg.parentNode;
                var lines = Array.prototype.slice.call(svg.querySelectorAll('line'));
                var nodes = Array.prototype.slice.call(svg.querySelectorAll('circle[data-id]'));

                // Adjacency is built once: scanning every line per node on each hover is
                // nodes x edges work, which stalls visibly on a large graph.
                var neighbours = {};
                var touching = {};
                lines.forEach(function (line) {
                    var from = line.dataset.from;
                    var to = line.dataset.to;
                    (neighbours[from] = neighbours[from] || {})[to] = true;
                    (neighbours[to] = neighbours[to] || {})[from] = true;
                    (touching[from] = touching[from] || []).push(line);
                    (touching[to] = touching[to] || []).push(line);
                });

                var addLine = function (text, tag) {
                    var element = document.createElement(tag);
                    element.textContent = text;
                    tip.appendChild(element);
                };

                // Clamped to the diagram: an outer-ring node near the top or left edge would
                // otherwise render the tooltip outside the container, cutting it off.
                var position = function (node) {
                    var box = wrap.getBoundingClientRect();
                    var dot = node.getBoundingClientRect();
                    var tipBox = tip.getBoundingClientRect();
                    var half = tipBox.width / 2;
                    var x = dot.left - box.left + dot.width / 2;
                    var y = dot.top - box.top - 8;
                    var below = y - tipBox.height < 0;

                    tip.classList.toggle('below', below);
                    tip.style.left = Math.min(Math.max(x, half), Math.max(box.width - half, half)) + 'px';
                    tip.style.top = (below ? dot.bottom - box.top + 8 : y) + 'px';
                };

                var show = function (node) {
                    var id = node.dataset.id;
                    tip.innerHTML = '';
                    addLine(node.dataset.label, 'b');
                    addLine(node.dataset.kind + ' \u00b7 depth ' + node.dataset.depth
                        + (node.dataset.loc ? ' \u00b7 ' + node.dataset.loc : ''), 'span');
                    if (node.dataset.raw) { addLine(node.dataset.raw, 'span'); }

                    tip.hidden = false;
                    position(node);

                    lines.forEach(function (edge) { edge.classList.remove('on'); });
                    (touching[id] || []).forEach(function (edge) { edge.classList.add('on'); });
                    nodes.forEach(function (other) {
                        var near = other === node || (neighbours[id] || {})[other.dataset.id];
                        other.classList.toggle('dim', !near);
                    });
                };

                var hide = function () {
                    // A pointer leaving a node that still has keyboard focus must not clear it.
                    if (nodes.indexOf(document.activeElement) >= 0) { return; }
                    tip.hidden = true;
                    lines.forEach(function (edge) { edge.classList.remove('on'); });
                    nodes.forEach(function (node) { node.classList.remove('dim'); });
                };

                nodes.forEach(function (node) {
                    node.addEventListener('mouseenter', function () { show(node); });
                    node.addEventListener('focus', function () { show(node); });
                    node.addEventListener('mouseleave', hide);
                    node.addEventListener('blur', hide);
                });
            })();
            JS;
    }
}
