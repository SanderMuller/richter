<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The Graph tab: {@see RadialLayout}'s geometry as SVG, plus the legend and the notes that keep it
 * honest. Split from {@see HtmlFormatter} because the diagram is a different rendering problem from
 * the document around it — and because the two together outgrew one class.
 *
 * @internal
 *
 * @phpstan-import-type Layout from RadialLayout
 */
final class BlastDiagram
{
    /** @param  Layout  $layout */
    public static function render(array $layout, int $impacted): string
    {
        if ($layout['nodes'] === []) {
            return '<p class="muted">Nothing reached — no graph to draw.</p>';
        }

        return '<div class="graph-wrap">' . self::svg($layout) . '<div class="tip" id="tip" hidden></div></div>'
            . self::legend()
            . self::hiddenNote($layout)
            . self::divergenceNote($layout, $impacted);
    }

    /**
     * Nodes carry their facts as data attributes rather than an SVG `<title>`: the native tooltip is
     * slow to appear, unstyled, and shows one unlabelled line, which left a reader looking at
     * anonymous dots. The script renders them instead, and edges name their endpoints so hovering a
     * node can light up what it actually connects to.
     *
     * @param  Layout  $layout
     */
    private static function svg(array $layout): string
    {
        $size = $layout['size'];
        // role="group", not role="img": an img is a leaf, so assistive tech would never reach the
        // per-node labels inside — leaving a keyboard user tabbing through silent focus stops.
        $svg = '<svg class="graph" viewBox="0 0 ' . $size . ' ' . $size . '" role="group" aria-label="Blast radius: '
            . count($layout['nodes']) . ' nodes arranged by depth from the change">';

        foreach ($layout['rings'] as $ring) {
            $svg .= '<circle class="ring" cx="' . $layout['centre'] . '" cy="' . $layout['centre'] . '" r="' . $ring['r'] . '"/>';
        }

        foreach ($layout['edges'] as $edge) {
            $svg .= '<line x1="' . $edge['x1'] . '" y1="' . $edge['y1'] . '" x2="' . $edge['x2'] . '" y2="' . $edge['y2']
                . '" data-from="' . Html::e($edge['source']) . '" data-to="' . Html::e($edge['target']) . '"/>';
        }

        foreach ($layout['nodes'] as $node) {
            $svg .= self::node($node);
        }

        return $svg . '</svg>';
    }

    /** @param  array{id: string, x: float, y: float, depth: int, kind: 'seed'|'impacted'|'association', location: array{file: string, line?: int}|null}  $node */
    private static function node(array $node): string
    {
        $label = Html::nodeLabel($node['id']);
        $kind = self::kindLabel($node['kind']);

        return '<circle class="n-' . $node['kind'] . '" cx="' . $node['x'] . '" cy="' . $node['y'] . '" r="6"'
            . ' tabindex="0" role="button"'
            . ' data-id="' . Html::e($node['id']) . '"'
            . ' data-label="' . Html::e($label) . '"'
            . ' data-kind="' . Html::e($kind) . '"'
            . ' data-raw="' . Html::e($node['id'] === $label ? '' : $node['id']) . '"'
            . ' data-depth="' . $node['depth'] . '"'
            . ' data-loc="' . Html::e(Html::locationText($node['location'])) . '"'
            . ' aria-label="' . Html::e("{$label}, {$kind}, depth {$node['depth']}") . '"/>';
    }

    /** @param  'seed'|'impacted'|'association'  $kind */
    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'seed' => 'Directly changed',
            'impacted' => 'Impacted',
            'association' => 'Outside impact',
        };
    }

    private static function legend(): string
    {
        $items = '';

        foreach ([
            ['n-seed', 'Directly changed'],
            ['n-impacted', 'Impacted'],
            ['n-association', 'Outside impact (association only)'],
        ] as [$class, $label]) {
            $items .= '<li><span class="dot ' . $class . '"></span>' . Html::e($label) . '</li>';
        }

        return '<ul class="legend" role="list">' . $items
            . '<li class="legend-hint">Each ring is one step further from the change. Hover or focus a node for details.</li></ul>';
    }

    /** @param  Layout  $layout */
    private static function hiddenNote(array $layout): string
    {
        if ($layout['hiddenCount'] === 0 && $layout['hiddenEdgeCount'] === 0) {
            return '';
        }

        return '<p class="note warn">' . $layout['hiddenCount'] . ' node(s) and ' . $layout['hiddenEdgeCount']
            . ' edge(s) hidden — the diagram is capped at ' . RadialLayout::MAX_NODES
            . ' nodes; the counts above are not.</p>';
    }

    /**
     * The diagram classifies nodes from the reach map and the Impacted tile counts from the same
     * map, so the two agree. If they ever stop agreeing, say so rather than showing a reader a
     * picture and a number that quietly contradict each other.
     *
     * @param  Layout  $layout
     */
    private static function divergenceNote(array $layout, int $impacted): string
    {
        if ($layout['impactedCount'] === $impacted) {
            return '';
        }

        return '<p class="note warn">The diagram classifies ' . $layout['impactedCount']
            . ' impacted node(s) but the summary counts ' . $impacted
            . '. Trust the summary and report this — the two are computed from the same reach map and should agree.</p>';
    }
}
