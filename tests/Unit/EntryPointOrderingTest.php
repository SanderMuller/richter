<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\EntryPointRow;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The ordering half of `specs/primary-entry-points.md`: rows are ranked by how specifically the diff
 * explains them, and nothing else in the report moves because of it.
 */
final class EntryPointOrderingTest extends TestCase
{
    /** Sorts first alphabetically, explained only by a hub — the shape that fills a report today. */
    private const string ADMIN = 'App\Filament\Resources\ArticleResource';

    private const string FEATURE_ROUTE = 'route::GET::/stats/{article}';

    private const string SELF_LISTED = 'App\Livewire\Standalone';

    /** @return array<string, array{via: string, ownReach: int}> */
    private function attribution(): array
    {
        return [
            self::ADMIN => ['via' => 'app/Models/Article.php', 'ownReach' => 90],
            self::FEATURE_ROUTE => ['via' => 'app/Http/Controllers/StatsController.php', 'ownReach' => 9],
        ];
    }

    /**
     * @param  list<string>  $entryPoints
     * @param  array<string, array{via: string, ownReach: int}>  $attribution
     * @return list<string>
     */
    private function rows(array $entryPoints, array $attribution): array
    {
        return array_map(
            static fn (EntryPointRow $row): string => $row->node,
            EntryPointRow::build($entryPoints, [], [], [], [], [], [], null, $attribution),
        );
    }

    #[Test]
    public function it_ranks_the_specifically_explained_surface_above_the_hub_reached_one(): void
    {
        // Alphabetically the admin resource wins, and today's report shows it first. The controller's
        // route is what the change is about.
        $this->assertSame(
            [self::FEATURE_ROUTE, self::ADMIN],
            $this->rows([self::ADMIN, self::FEATURE_ROUTE], $this->attribution()),
        );
    }

    #[Test]
    public function an_unattributed_entry_point_sorts_last_and_is_never_dropped(): void
    {
        $rows = $this->rows([self::SELF_LISTED, self::ADMIN, self::FEATURE_ROUTE], $this->attribution());

        $this->assertSame([self::FEATURE_ROUTE, self::ADMIN, self::SELF_LISTED], $rows);
    }

    #[Test]
    public function without_an_attribution_map_the_order_is_the_plain_label(): void
    {
        // `richter:impact` analyses one symbol and has no per-file attribution to make. Its rows must
        // render exactly as they did before this feature.
        $this->assertSame(
            [self::ADMIN, self::FEATURE_ROUTE],
            $this->rows([self::FEATURE_ROUTE, self::ADMIN], []),
        );
    }

    #[Test]
    public function equal_specificity_falls_back_to_the_label(): void
    {
        $attribution = [
            self::ADMIN => ['via' => 'app/A.php', 'ownReach' => 4],
            self::FEATURE_ROUTE => ['via' => 'app/B.php', 'ownReach' => 4],
        ];

        $this->assertSame(
            [self::ADMIN, self::FEATURE_ROUTE],
            $this->rows([self::FEATURE_ROUTE, self::ADMIN], $attribution),
        );
    }

    #[Test]
    public function the_text_and_markdown_reports_agree_on_the_order(): void
    {
        $result = [
            'changed' => ['app/Http/Controllers/StatsController.php' => 1],
            'coverage' => ['app/Http/Controllers/StatsController.php' => 'analyzed'],
            'entryPoints' => [self::ADMIN, self::FEATURE_ROUTE],
            'entryPointPaths' => [],
            'entryPointLocations' => [],
            'entryPointSecurity' => [],
            'entryPointGates' => [],
            'entryPointAttribution' => $this->attribution(),
            'impacted' => 2,
            'relatedModels' => [],
            'risk' => RiskLevel::Low,
            'lowConfidence' => false,
            'findings' => [],
        ];

        $text = ImpactFormatter::detectChanges($result);
        $markdown = MarkdownFormatter::detectChanges($result);

        foreach ([$text, $markdown] as $output) {
            $route = strpos($output, self::FEATURE_ROUTE);
            $admin = strpos($output, self::ADMIN);

            $this->assertIsInt($route);
            $this->assertIsInt($admin);
            $this->assertLessThan($admin, $route, 'the ranked row must render before the hub-reached one');
        }
    }

    #[Test]
    public function the_test_selection_does_not_depend_on_the_entry_point_order(): void
    {
        // The whole safety argument of the ordering: `affected-tests` walks the set, never the order.
        $tests = new TestReferenceIndex();
        $tests->addSource("<?php\nclass StatsTest { public function test_it() { \$this->get(route('stats.show')); } }", 'tests/Feature/StatsTest.php');

        $result = [
            'coverage' => [],
            'entryPoints' => [self::ADMIN, self::FEATURE_ROUTE],
            'lowConfidence' => false,
            'callers' => [],
            'dependencies' => [],
        ];

        $forward = AffectedTests::select($result, [], $tests, []);
        $result['entryPoints'] = array_reverse($result['entryPoints']);
        $reversed = AffectedTests::select($result, [], $tests, []);

        $this->assertSame($forward['tests'], $reversed['tests']);
        $this->assertSame($forward['determinable'], $reversed['determinable']);
        $this->assertSame($forward['reasons'], $reversed['reasons']);
        $this->assertSame($forward['unreferencedEntryPoints'], $reversed['unreferencedEntryPoints']);
    }
}
