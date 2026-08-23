<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Support\InheritanceSurfaces;

/**
 * The body of {@see HtmlFormatter}'s inheritance-reach card: the trait users in front of the reader, the
 * overrides folded and grouped by member name.
 *
 * Its own class because {@see HtmlFormatter} has no headroom — it measured 83 against phpstan.neon's
 * class cognitive-complexity ceiling of 80 with this rendering inline. The split is forced rather than
 * stylistic, and the card WRAPPER stays behind in the formatter so every card is still declared in one
 * place.
 *
 * What the folded summary may claim, and why it is the narrow claim rather than the obvious wider one,
 * lives in {@see InheritanceSurfaces}. Read it before rewording anything here.
 *
 * @internal
 */
final class InheritanceCard
{
    /**
     * @param  list<string>  $reach  the reported inheritance entries
     * @param  array<string, list<string>>  $via  entry => the edge types that put it here
     */
    public static function body(array $reach, array $via): string
    {
        [$inline, $groups] = InheritanceSurfaces::partition($reach, $via);

        $items = implode('', array_map(
            static fn (string $node): string => '<li><code>' . Html::e(NodeLabel::display($node)) . '</code>'
                . (($via[$node] ?? []) === [] ? '' : ' <span class="muted">' . Html::e(implode(', ', $via[$node])) . '</span>')
                . '</li>',
            $inline,
        ));

        return '<p class="muted">Uses the trait declaring the changed member, or implements the ancestor it overrides — context, not counted toward impact or risk.</p>'
            . ($inline === [] ? '' : '<ul role="list">' . $items . '</ul>')
            . self::groups(count($reach) - count($inline), $groups);
    }

    /**
     * The `override` lane, folded the way {@see HtmlFormatter}'s association card folds its fan-out
     * group, with one nested list per member name.
     *
     * @param  array<string, list<string>>  $groups  member name => the classes declaring it
     */
    private static function groups(int $total, array $groups): string
    {
        if ($groups === []) {
            return '';
        }

        $body = implode('', array_map(
            static fn (string $member, array $classes): string => '<li><code>' . Html::e($member) . '</code> <span class="muted">'
                . Html::e(sprintf('%d %s', count($classes), count($classes) === 1 ? 'class' : 'classes'))
                . '</span><ul role="list">'
                . implode('', array_map(static fn (string $class): string => '<li><code>' . Html::e($class) . '</code></li>', $classes))
                . '</ul></li>',
            array_keys($groups),
            $groups,
        ));

        return sprintf(
            '<details><summary>%s</summary><ul role="list">%s</ul></details>',
            Html::e(sprintf(
                '%d override%s across %d member name%s — each declares the member itself',
                $total,
                $total === 1 ? '' : 's',
                count($groups),
                count($groups) === 1 ? '' : 's',
            )),
            $body,
        );
    }
}
