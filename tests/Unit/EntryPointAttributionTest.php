<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\EntryPointAttribution;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class EntryPointAttributionTest extends TestCase
{
    private const string FEATURE_ROUTE = 'route::GET::/articles/{article}/stats';

    private const string ADMIN_ROUTE = 'route::GET::/admin/settings';

    /**
     * A feature controller reaching one route, beside a hub model reaching both — the shape the
     * ordering exists for.
     */
    private function graph(): CodeGraph
    {
        return new CodeGraph([
            ['source' => self::FEATURE_ROUTE, 'target' => 'App\Http\Controllers\StatsController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\StatsController', 'target' => 'App\Http\Controllers\StatsController::show', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\StatsController::show', 'target' => 'App\Models\Article::stats', 'type' => 'action-to-service'],
            ['source' => self::ADMIN_ROUTE, 'target' => 'App\Http\Controllers\SettingsController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\SettingsController', 'target' => 'App\Http\Controllers\SettingsController::index', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\SettingsController::index', 'target' => 'App\Models\Article::stats', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false);
    }

    private function attribution(?CodeGraph $graph = null): EntryPointAttribution
    {
        $graph ??= $this->graph();

        return new EntryPointAttribution($graph, static function (array $callers): array {
            $nodes = [];

            foreach ($callers as $hop) {
                if (str_starts_with($hop['node'], 'route::')) {
                    $nodes[] = $hop['node'];
                }
            }

            return $nodes;
        });
    }

    #[Test]
    public function it_attributes_an_entry_point_to_the_changed_file_with_the_smallest_own_reach(): void
    {
        $result = $this->attribution()->for(
            [
                'app/Models/Article.php' => ['App\Models\Article::stats'],
                'app/Http/Controllers/StatsController.php' => ['App\Http\Controllers\StatsController::show'],
            ],
            [self::FEATURE_ROUTE, self::ADMIN_ROUTE],
            6,
        );

        // The hub reaches both routes; the controller reaches one. The feature route is explained by
        // the controller — the more specific of the two — while the admin route has only the hub.
        $this->assertSame(
            ['via' => 'app/Http/Controllers/StatsController.php', 'ownReach' => 1],
            $result[self::FEATURE_ROUTE],
        );
        $this->assertSame(
            ['via' => 'app/Models/Article.php', 'ownReach' => 2],
            $result[self::ADMIN_ROUTE],
        );
    }

    #[Test]
    public function it_breaks_a_tie_on_the_changed_file_path(): void
    {
        $graph = new CodeGraph([
            ['source' => self::FEATURE_ROUTE, 'target' => 'App\Services\Second', 'type' => 'route-to-controller'],
            ['source' => 'App\Services\Second', 'target' => 'App\Services\First::run', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false);

        // Both orders, because the tie-break must be a comparison and not an artefact of which file
        // the loop happens to see last: with the files given either way round, the same path wins.
        foreach ([['Zeta', 'Alpha'], ['Alpha', 'Zeta']] as [$first, $second]) {
            $result = $this->attribution($graph)->for(
                [
                    "app/Services/{$first}.php" => ['App\Services\First::run'],
                    "app/Services/{$second}.php" => ['App\Services\First::run'],
                ],
                [self::FEATURE_ROUTE],
                6,
            );

            $this->assertSame('app/Services/Alpha.php', $result[self::FEATURE_ROUTE]['via']);
        }
    }

    #[Test]
    public function an_entry_point_no_per_file_walk_explains_carries_no_attribution(): void
    {
        $result = $this->attribution()->for(
            ['app/Http/Controllers/StatsController.php' => ['App\Http\Controllers\StatsController::show']],
            [self::FEATURE_ROUTE, 'App\Livewire\SelfListedPage'],
            6,
        );

        // A self-listed entry class joins the reported list without any walk reaching it. It gets no
        // entry rather than a fabricated reach number.
        $this->assertArrayHasKey(self::FEATURE_ROUTE, $result);
        $this->assertArrayNotHasKey('App\Livewire\SelfListedPage', $result);
    }

    #[Test]
    public function a_changed_file_that_resolved_to_no_seed_attributes_nothing(): void
    {
        $result = $this->attribution()->for(
            [
                'app/Models/Article.php' => ['App\Models\Article::stats'],
                'config/features.php' => [],
            ],
            [self::FEATURE_ROUTE],
            6,
        );

        $this->assertSame('app/Models/Article.php', $result[self::FEATURE_ROUTE]['via']);
    }

    #[Test]
    public function a_changed_file_with_no_class_of_its_own_is_still_an_attributor(): void
    {
        $result = $this->attribution()->for(
            ['routes/web.php' => ['App\Http\Controllers\StatsController::show']],
            [self::FEATURE_ROUTE],
            6,
        );

        // The identity is the PATH: a route file, a config file and a file declaring two classes all
        // attribute, and none of them has one usable FQCN.
        $this->assertSame('routes/web.php', $result[self::FEATURE_ROUTE]['via']);
    }

    #[Test]
    public function it_excludes_association_edges_the_reported_list_also_excludes(): void
    {
        $graph = new CodeGraph([
            ['source' => self::FEATURE_ROUTE, 'target' => 'App\Http\Controllers\StatsController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\StatsController', 'target' => 'App\Models\Comment', 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false);

        $result = $this->attribution($graph)->for(
            ['app/Models/Comment.php' => ['App\Models\Comment']],
            [self::FEATURE_ROUTE],
            6,
        );

        // Reachable only across a `model-relationship`, which the reported entry-point walk excludes.
        // Attributing it here would explain a surface through a path the report refuses to count.
        $this->assertSame([], $result);
    }

    #[Test]
    public function a_caller_that_only_selects_tests_pays_for_no_attribution(): void
    {
        $changed = [new ChangedFileSymbols(
            'app/Http/Controllers/StatsController.php',
            'App\Http\Controllers\StatsController',
            [new MemberChange('show', 'method', MemberChange::CHANGE_MODIFIED, true)],
            false,
            ['App\Http\Controllers\StatsController::show'],
        )];

        $analyzer = new ImpactAnalyzer($this->graph());

        // `affected-tests` reads the entry-point SET, never its order, so it must not pay one upward
        // walk per changed file for a map it discards.
        $this->assertNotSame([], $analyzer->detectChanges($changed)['entryPointAttribution']);
        $this->assertSame([], $analyzer->detectChanges($changed, attributionEnabled: false)['entryPointAttribution']);
    }

    #[Test]
    public function it_returns_nothing_when_the_run_reached_no_entry_point(): void
    {
        $this->assertSame([], $this->attribution()->for(['app/Models/Article.php' => ['App\Models\Article::stats']], [], 6));
    }
}
