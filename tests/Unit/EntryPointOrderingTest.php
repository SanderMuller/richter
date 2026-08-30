<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\EntryPointRow;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
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

    /** An index that can answer for the app-class nodes, so the annotation map is not empty. */
    private function indexReferencing(string $fqcn): TestReferenceIndex
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse " . $fqcn . ";\nclass StandaloneTest { public function test_it() { \$this->assertTrue(true); } }", 'tests/Feature/StandaloneTest.php');

        return $index;
    }

    /**
     * The machine payload and the prose reports are ONE reading order, not two implementations of it.
     *
     * They were two, and they disagreed: the formatters sorted while `JsonPresenter` copied the walk
     * order, so a consumer measured the two lists sharing no prefix at all on a 353-surface report.
     * Reversing the input proves the JSON list is genuinely ordered rather than inheriting the order
     * it was handed.
     *
     * What this canNOT prove is that both sides key on the rendered LABEL rather than the raw node
     * id: `NodeLabel::display()` truncates a `command::` id at the first whitespace, and a space sorts
     * below every printable character, so the two keys order any realistic pair identically. A
     * mutation swapping the label for the node id survives this test, deliberately and knowingly —
     * the protection against drift is the single shared call, not this assertion.
     */
    #[Test]
    public function the_json_payload_and_the_prose_reports_agree_on_the_order(): void
    {
        $command = 'command::z:sync --force';
        $entryPoints = [self::ADMIN, $command, self::FEATURE_ROUTE, self::SELF_LISTED];
        $attribution = [...$this->attribution(), $command => ['via' => 'app/Console/Commands/Sync.php', 'ownReach' => 9]];

        foreach ([$entryPoints, array_reverse($entryPoints)] as $order) {
            $document = JsonPresenter::detectChanges([
                'changed' => [],
                'coverage' => [],
                'entryPoints' => $order,
                'entryPointPaths' => [],
                'entryPointLocations' => [],
                'entryPointSecurity' => [],
                'entryPointGates' => [],
                'entryPointAttribution' => $attribution,
                'impacted' => 0,
                'relatedModels' => [],
                'risk' => RiskLevel::Low,
                'lowConfidence' => false,
                'findings' => [],
            ], 'HEAD~1', $this->indexReferencing(self::SELF_LISTED));

            $this->assertSame($this->rows($order, $attribution), $document['entryPoints']);

            // The annotation map is keyed off the same list, so it cannot drift from it either. It
            // holds only the nodes whose reference state is determinable, so the claim is that its
            // keys appear in the list's order, not that it holds every entry.
            $keys = array_keys($document['entryPointTestReferences']);
            $this->assertSame($keys, array_values(array_intersect($document['entryPoints'], $keys)));
        }
    }

    /**
     * The ranked surface really does move to the front of the machine list. Without this the parity
     * test above passes on two implementations that are equally wrong.
     */
    #[Test]
    public function the_json_payload_leads_with_the_specifically_explained_surface(): void
    {
        $document = JsonPresenter::detectChanges([
            'changed' => [],
            'coverage' => [],
            'entryPoints' => [self::ADMIN, self::FEATURE_ROUTE],
            'entryPointPaths' => [],
            'entryPointLocations' => [],
            'entryPointSecurity' => [],
            'entryPointGates' => [],
            'entryPointAttribution' => $this->attribution(),
            'impacted' => 0,
            'relatedModels' => [],
            'risk' => RiskLevel::Low,
            'lowConfidence' => false,
            'findings' => [],
        ], 'HEAD~1');

        $this->assertSame([self::FEATURE_ROUTE, self::ADMIN], $document['entryPoints']);
    }

    /**
     * Two commands that RENDER as one label still get a total order, because the key ends on the node
     * id. Without that last element they tie, and PHP's stable sort then hands back whatever order the
     * walk supplied — so the reported order would depend on the graph traversal rather than on the
     * ranking, and two callers holding the same set could print it two ways.
     */
    #[Test]
    public function two_commands_sharing_a_rendered_label_still_get_a_total_order(): void
    {
        // NodeLabel::display() truncates at the first whitespace, so both of these render as
        // `command::reports:sync`.
        $withForce = 'command::reports:sync {--force}';
        $withSince = 'command::reports:sync {--since=}';
        $attribution = [
            $withForce => ['via' => 'app/Console/Commands/Sync.php', 'ownReach' => 1],
            $withSince => ['via' => 'app/Console/Commands/Sync.php', 'ownReach' => 1],
        ];

        $this->assertSame(
            $this->rows([$withForce, $withSince], $attribution),
            $this->rows([$withSince, $withForce], $attribution),
        );
    }

    /**
     * `richter:impact` has no attribution to rank on, so its rows are the plain label order — and its
     * machine payload is that same order. It copied the walk order until the machine payload and the
     * prose reports were made one order; the same defect, in the other command.
     */
    #[Test]
    public function the_impact_payload_is_ordered_like_the_impact_report(): void
    {
        $entryPoints = [self::FEATURE_ROUTE, self::ADMIN];

        $document = JsonPresenter::impact([
            'target' => 'App\Models\Article::scopePublished',
            'callers' => [],
            'dependencies' => [],
            'entryPoints' => $entryPoints,
            'associationEntryPoints' => [],
            'entryPointPaths' => [],
            'entryPointLocations' => [],
            'entryPointSecurity' => [],
            'entryPointGates' => [],
            'entryPointAuthGates' => [],
        ]);

        // No attribution: the plain label, which puts the class-named row above the `route::` one.
        $this->assertSame([self::ADMIN, self::FEATURE_ROUTE], $document['entryPoints']);
        $this->assertSame($this->rows($entryPoints, []), $document['entryPoints']);
    }
}
