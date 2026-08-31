<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Support\HubFold;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The presentation half of `specs/task-slice.md`: hub-reached surfaces fold under their shared cause,
 * and the report never shortens its own count to match.
 */
final class HubFoldTest extends TestCase
{
    private const string HUB_REACHED = 'App\Filament\Resources\ArticleResource';

    private const string OWN_ROUTE = 'route::GET::/stats/{article}';

    /** @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string, file?: string, line?: int}>}>, entryPointGates: array<string, list<string>>, entryPointAttribution: array<string, array{via: string, ownReach: int}>, entryPointKeepSet: array{kept: list<string>, droppedHub: int}, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, findings: list<string>} */
    private function detectChangesResult(): array
    {
        return [
            'changed' => ['app/Models/Article.php' => 1],
            'coverage' => ['app/Models/Article.php' => 'analyzed'],
            'entryPoints' => [self::OWN_ROUTE, self::HUB_REACHED],
            'entryPointPaths' => [],
            'entryPointLocations' => [],
            'entryPointSecurity' => [],
            'entryPointGates' => [],
            'entryPointAttribution' => [
                self::OWN_ROUTE => ['via' => 'app/Http/Controllers/StatsController.php', 'ownReach' => 9],
                self::HUB_REACHED => ['via' => 'app/Models/Article.php', 'ownReach' => 90],
            ],
            'entryPointKeepSet' => ['kept' => [self::OWN_ROUTE], 'droppedHub' => 1],
            'impacted' => 2,
            'relatedModels' => [],
            'risk' => RiskLevel::Low,
            'lowConfidence' => false,
            'findings' => [],
        ];
    }

    #[Test]
    public function the_folded_row_is_named_by_its_cause_and_not_merely_counted(): void
    {
        $counts = HubFold::counts(
            [self::OWN_ROUTE, self::HUB_REACHED],
            [self::OWN_ROUTE],
            $this->detectChangesResult()['entryPointAttribution'],
        );

        $this->assertSame(['app/Models/Article.php' => 1], $counts);

        [$sentence] = HubFold::sentences($counts, afterCappedList: false);

        $this->assertStringContainsString('app/Models/Article.php', $sentence);
        $this->assertStringContainsString('hub', $sentence);
        // The one thing it must never read as.
        $this->assertStringNotContainsString('forgot to', $sentence);
        $this->assertStringContainsString('not surfaces this change forgot', $sentence);
    }

    #[Test]
    public function the_and_opener_is_dropped_after_a_capped_list(): void
    {
        // Same rule the association fold applies: "… and" only reads directly after a list that has
        // not already written its own "… and N more".
        $counts = ['app/Models/Article.php' => 3];

        $this->assertStringStartsWith('… and ', HubFold::sentences($counts, afterCappedList: false)[0]);
        $this->assertStringStartsWith('3 surfaces', HubFold::sentences($counts, afterCappedList: true)[0]);
    }

    #[Test]
    public function one_hub_folding_one_surface_reads_singular(): void
    {
        $this->assertStringContainsString('1 surface reached', HubFold::sentences(['app/Models/Article.php' => 1], true)[0]);
        $this->assertStringContainsString('2 surfaces reached', HubFold::sentences(['app/Models/Article.php' => 2], true)[0]);
    }

    #[Test]
    public function every_hub_gets_its_own_line_sorted_by_path(): void
    {
        $counts = HubFold::counts(
            ['a', 'b', 'c'],
            [],
            [
                'a' => ['via' => 'app/Services/Zeta.php', 'ownReach' => 40],
                'b' => ['via' => 'app/Models/Alpha.php', 'ownReach' => 50],
                'c' => ['via' => 'app/Services/Zeta.php', 'ownReach' => 40],
            ],
        );

        $this->assertSame(['app/Models/Alpha.php' => 1, 'app/Services/Zeta.php' => 2], $counts);
    }

    #[Test]
    public function an_unattributed_dropped_surface_contributes_no_line(): void
    {
        // It cannot: the keep set never drops one. This guards the tail against inventing a cause if
        // that ever changes.
        $this->assertSame([], HubFold::counts(['a'], [], []));
    }

    #[Test]
    public function the_text_report_folds_the_hub_row_and_keeps_the_full_count(): void
    {
        $output = ImpactFormatter::detectChanges($this->detectChangesResult());

        $this->assertStringContainsString('Entry points reached: 2', $output);
        $this->assertStringContainsString(self::OWN_ROUTE, $output);
        $this->assertStringNotContainsString(self::HUB_REACHED, $output);
        $this->assertStringContainsString('1 surface reached only through app/Models/Article.php', $output);
    }

    #[Test]
    public function the_markdown_report_folds_the_same_row(): void
    {
        $output = MarkdownFormatter::detectChanges($this->detectChangesResult());

        $this->assertStringContainsString(self::OWN_ROUTE, $output);
        $this->assertStringNotContainsString(self::HUB_REACHED, $output);
        $this->assertStringContainsString('1 surface reached only through app/Models/Article.php', $output);
    }

    #[Test]
    public function an_unconfigured_project_renders_exactly_as_before(): void
    {
        $result = $this->detectChangesResult();
        $result['entryPointKeepSet'] = ['kept' => $result['entryPoints'], 'droppedHub' => 0];

        $output = ImpactFormatter::detectChanges($result);

        $this->assertStringContainsString(self::HUB_REACHED, $output);
        $this->assertStringNotContainsString('lists as a hub', $output);
    }
}
