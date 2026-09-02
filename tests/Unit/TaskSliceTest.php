<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\TaskSlice;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The composition half of `specs/task-slice.md`: one document an agent acts on, and the two promises
 * that make it safe to hand over — the selection is never narrowed, and nothing here grades the change.
 */
final class TaskSliceTest extends TestCase
{
    private const string OWN_ROUTE = 'route::GET::/stats/{article}';

    private const string HUB_REACHED = 'App\Filament\Resources\ArticleResource';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides = []): array
    {
        return [
            'base' => 'HEAD~1',
            'changed' => ['app/Http/Controllers/StatsController.php' => 2],
            'entryPoints' => [self::OWN_ROUTE, self::HUB_REACHED],
            'entryPointKeepSet' => ['kept' => [self::OWN_ROUTE], 'droppedHub' => 1],
            'entryPointAttribution' => [
                self::OWN_ROUTE => ['via' => 'app/Http/Controllers/StatsController.php', 'ownReach' => 9],
                self::HUB_REACHED => ['via' => 'app/Models/Article.php', 'ownReach' => 90],
            ],
            'entryPointTestReferences' => [self::OWN_ROUTE => 'referenced'],
            'verification' => [self::OWN_ROUTE => true],
            'hazards' => [],
            'findings' => ['an eager-load string matches no relation'],
            'risk' => 'low',
            'riskCause' => 'nothing to assess',
            'unresolved' => false,
            'lowConfidence' => false,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, testsTotal: int, testsShare: float, testsExcluded: int, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>}
     */
    private function selection(array $overrides = []): array
    {
        /** @var array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, testsTotal: int, testsShare: float, testsExcluded: int, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>} $selection */
        $selection = [
            'base' => 'HEAD~1',
            'determinable' => true,
            'reasons' => [],
            'tests' => ['tests/Feature/StatsTest.php'],
            'testsTotal' => 1,
            'testsShare' => 1.0,
            'testsExcluded' => 0,
            'frontendTests' => [],
            'unreferencedEntryPoints' => 0,
            'unresolvedDispatchSites' => [],
            ...$overrides,
        ];

        return $selection;
    }

    #[Test]
    public function it_reports_only_the_surfaces_the_task_owns(): void
    {
        $slice = TaskSlice::compose($this->document(), $this->selection());

        $this->assertSame([self::OWN_ROUTE], $slice['kept']);
        $this->assertSame(1, $slice['droppedHubCount']);
        $this->assertSame(2, $slice['entryPointCount']);
    }

    #[Test]
    public function a_folded_keep_set_degrades_determinability_and_says_why(): void
    {
        $slice = TaskSlice::compose($this->document(), $this->selection());

        $this->assertFalse($slice['affectedTestsDeterminable']);
        $this->assertNotSame([], $slice['affectedTestsReasons']);
    }

    #[Test]
    public function the_selection_itself_is_never_narrowed(): void
    {
        // The promise that makes the degradation acceptable: `affectedTests` is passed through
        // untouched, hub list or not. Under-selection is the one failure this package does not accept.
        $selection = $this->selection();

        $folded = TaskSlice::compose($this->document(), $selection);
        $unfolded = TaskSlice::compose(
            $this->document(['entryPointKeepSet' => ['kept' => [self::OWN_ROUTE, self::HUB_REACHED], 'droppedHub' => 0]]),
            $selection,
        );

        $this->assertSame($selection['tests'], $folded['affectedTests']);
        $this->assertSame($selection['tests'], $unfolded['affectedTests']);
        $this->assertTrue($unfolded['affectedTestsDeterminable']);
    }

    #[Test]
    public function an_existing_undeterminable_reason_is_kept_not_replaced(): void
    {
        $slice = TaskSlice::compose(
            $this->document(),
            $this->selection(['determinable' => false, 'reasons' => ['an unfollowable dispatch site']]),
        );

        $this->assertFalse($slice['affectedTestsDeterminable']);
        $this->assertIsArray($slice['affectedTestsReasons']);
        $this->assertContains('an unfollowable dispatch site', $slice['affectedTestsReasons']);
        // Already undeterminable: the hub reason is not appended a second time on top of it.
        $this->assertSame(['an unfollowable dispatch site'], $slice['affectedTestsReasons']);
    }

    #[Test]
    public function a_surface_referenced_only_by_a_test_that_asserts_nothing_is_unproven(): void
    {
        $slice = TaskSlice::compose(
            $this->document(['entryPointTestReferences' => [self::OWN_ROUTE => 'referenced-no-behavioural-assertion']]),
            $this->selection(),
        );

        $this->assertSame([self::OWN_ROUTE], $slice['unreferencedKept']);
    }

    #[Test]
    public function a_referenced_surface_is_not_reported_as_unproven(): void
    {
        $this->assertSame([], TaskSlice::compose($this->document(), $this->selection())['unreferencedKept']);
    }

    #[Test]
    public function a_verification_state_that_could_not_be_checked_counts_as_unverified(): void
    {
        // null is "could not check", which the ladder already reads as unverified. Reporting only
        // false would hand an agent an unknown as a pass.
        $slice = TaskSlice::compose(
            $this->document(['verification' => [self::OWN_ROUTE => null, self::HUB_REACHED => false, 'App\Models\Article' => true]]),
            $this->selection(),
        );

        $this->assertSame([self::OWN_ROUTE, self::HUB_REACHED], $slice['verificationFalse']);
    }

    #[Test]
    public function an_empty_keep_set_asks_for_an_impact_call_instead_of_answering_nothing(): void
    {
        $slice = TaskSlice::compose(
            $this->document([
                'entryPoints' => [],
                'entryPointKeepSet' => ['kept' => [], 'droppedHub' => 0],
                'entryPointAttribution' => [],
                'verification' => [],
                'changed' => ['app/Builders/ArticleLoader.php' => 3],
            ]),
            $this->selection(),
        );

        $this->assertTrue($slice['runImpact']);
        $this->assertIsArray($slice['runImpactOn']);
        $this->assertContains('App\Builders\ArticleLoader', $slice['runImpactOn']);
    }

    #[Test]
    public function an_empty_diff_asks_for_nothing(): void
    {
        $slice = TaskSlice::compose(
            $this->document(['entryPoints' => [], 'entryPointKeepSet' => ['kept' => [], 'droppedHub' => 0], 'changed' => [], 'verification' => []]),
            $this->selection(),
        );

        $this->assertFalse($slice['runImpact']);
        $this->assertSame([], $slice['runImpactOn']);
    }

    #[Test]
    public function the_impact_list_skips_the_hubs_it_just_folded(): void
    {
        $slice = TaskSlice::compose(
            $this->document([
                'entryPoints' => [self::HUB_REACHED],
                'entryPointKeepSet' => ['kept' => [], 'droppedHub' => 1],
                'entryPointAttribution' => [self::HUB_REACHED => ['via' => 'app/Models/Article.php', 'ownReach' => 90]],
                'verification' => [],
                'changed' => ['app/Models/Article.php' => 1, 'app/Builders/ArticleLoader.php' => 3],
            ]),
            $this->selection(),
        );

        $this->assertSame(['App\Builders\ArticleLoader'], $slice['runImpactOn']);
    }

    #[Test]
    public function a_diff_of_nothing_but_hubs_still_names_something_to_analyse(): void
    {
        // Dropping the hubs would leave an empty list, and "analyse nothing" is a worse answer than
        // "analyse the hub you edited" — the point of runImpactOn is to never answer nothing.
        $slice = TaskSlice::compose(
            $this->document([
                'entryPoints' => [self::HUB_REACHED],
                'entryPointKeepSet' => ['kept' => [], 'droppedHub' => 1],
                'entryPointAttribution' => [self::HUB_REACHED => ['via' => 'app/Models/Article.php', 'ownReach' => 90]],
                'verification' => [],
                'changed' => ['app/Models/Article.php' => 1],
            ]),
            $this->selection(),
        );

        $this->assertTrue($slice['runImpact']);
        $this->assertSame(['App\Models\Article'], $slice['runImpactOn']);
    }

    #[Test]
    public function the_risk_verdict_passes_through_untouched(): void
    {
        $slice = TaskSlice::compose($this->document(['risk' => 'high', 'riskCause' => 'a guard was removed']), $this->selection());

        $this->assertSame('high', $slice['risk']);
        $this->assertSame('a guard was removed', $slice['riskCause']);
    }
}
