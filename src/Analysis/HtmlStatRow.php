<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The head of {@see HtmlFormatter}'s overview: the stat tiles, and the line under them that says why
 * the level is what it is.
 *
 * Its own class for the same reason as {@see InheritanceCard}: {@see HtmlFormatter} is at the class
 * cognitive-complexity limit in phpstan.neon.
 *
 * @phpstan-import-type DetectChangesResult from HtmlFormatter
 *
 * @internal
 */
final class HtmlStatRow
{
    /**
     * No "Functions" tile: `impacted` spans routes, commands, views and models, so a function count
     * is a number the analyzer never computes.
     *
     * @param  DetectChangesResult  $result
     */
    public static function render(array $result): string
    {
        $tiles = self::tile('Files', (string) count($result['changed']))
            . self::tile('Impacted', (string) $result['impacted'])
            . self::tile('Depth', (string) self::maxDepth($result))
            . self::tile('Risk', strtoupper($result['risk']->value), self::riskClass($result['risk']));

        return "<div class=\"stats\">{$tiles}</div>" . self::riskCause($result);
    }

    /** @param  DetectChangesResult  $result */
    private static function riskCause(array $result): string
    {
        $cause = $result['riskCause'] ?? '';

        return $cause === '' ? '' : '<p class="muted">' . Html::e(strtoupper($result['risk']->value) . ' — ' . $cause) . '</p>';
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

    private static function riskClass(RiskLevel $risk): string
    {
        return match ($risk) {
            RiskLevel::High => 'risk-high',
            RiskLevel::Medium => 'risk-medium',
            RiskLevel::Low => 'risk-low',
        };
    }
}
