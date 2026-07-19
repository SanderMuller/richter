<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class AffectedTestsTest extends TestCase
{
    /**
     * @param  list<string>  $entryPoints
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<array{depth: int, node: string, via: string}>  $callers
     * @return array{coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, lowConfidence: bool, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>}
     */
    private function detectResult(array $entryPoints, array $coverage = ['app/Services/X.php' => 'analyzed'], bool $lowConfidence = false, array $callers = []): array
    {
        return ['coverage' => $coverage, 'entryPoints' => $entryPoints, 'lowConfidence' => $lowConfidence, 'callers' => $callers, 'dependencies' => []];
    }

    private function changed(string $file, string $fqcn): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange('run', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
        ], cosmeticOnly: false);
    }

    private function index(): TestReferenceIndex
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/errors/log");', 'tests/Feature/ErrorLogTest.php');
        $index->addSource("<?php\nuse App\Services\X;\n", 'tests/Unit/XTest.php');

        return $index;
    }

    #[Test]
    public function selection_unions_entry_point_references_and_changed_class_imports(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $this->index(),
            hasUnresolvedDispatches: false,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['reasons']);
        $this->assertSame(['tests/Feature/ErrorLogTest.php', 'tests/Unit/XTest.php'], $selection['tests']);
        $this->assertSame(0, $selection['unreferencedEntryPoints']);
    }

    #[Test]
    public function an_unresolved_file_makes_the_selection_undeterminable_with_a_reason(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log'], coverage: ['app/Services/Lost.php' => 'unresolved']),
            [],
            $this->index(),
            hasUnresolvedDispatches: false,
        );

        $this->assertFalse($selection['determinable']);
        $this->assertSame(['changed file(s) could not be placed in the graph (UNRESOLVED)'], $selection['reasons']);
        // The (incomplete) selection is still reported as context.
        $this->assertSame(['tests/Feature/ErrorLogTest.php'], $selection['tests']);
    }

    #[Test]
    public function low_confidence_and_unfollowable_dispatches_each_block_determination(): void
    {
        $lowConfidence = AffectedTests::select(
            $this->detectResult([], lowConfidence: true),
            [],
            $this->index(),
            hasUnresolvedDispatches: false,
        );
        $this->assertFalse($lowConfidence['determinable']);
        $this->assertStringContainsString('low confidence', $lowConfidence['reasons'][0]);

        $dispatches = AffectedTests::select(
            $this->detectResult([]),
            [],
            $this->index(),
            hasUnresolvedDispatches: true,
        );
        $this->assertFalse($dispatches['determinable']);
        $this->assertStringContainsString('dispatches', $dispatches['reasons'][0]);
    }

    #[Test]
    public function an_uncheckable_entry_point_blocks_determination(): void
    {
        // A schedule:: node has no reference detection — silently skipping it would shrink the set.
        $selection = AffectedTests::select(
            $this->detectResult(['schedule::videos:cleanup']),
            [],
            $this->index(),
            hasUnresolvedDispatches: false,
        );

        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('schedule::videos:cleanup', $selection['reasons'][0]);
    }

    #[Test]
    public function reached_entry_points_without_references_are_counted_not_hidden(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log', 'route::GET::/uncovered']),
            [],
            $this->index(),
            hasUnresolvedDispatches: false,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(1, $selection['unreferencedEntryPoints']);
        $this->assertSame(['tests/Feature/ErrorLogTest.php'], $selection['tests']);
    }

    #[Test]
    public function duplicate_selections_collapse_and_sort(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Services\X;\n\$this->get('/errors/log');", 'tests/Feature/ZTest.php');
        $index->addSource("<?php\nuse App\Services\X;\n", 'tests/Feature/ATest.php');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $index,
            hasUnresolvedDispatches: false,
        );

        $this->assertSame(['tests/Feature/ATest.php', 'tests/Feature/ZTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_pure_rename_selects_the_tests_referencing_the_old_class_name(): void
    {
        // A rename carries the vanished old FQCN as a direct seed — a test importing the old name
        // breaks on the rename and must be selected, not silently skipped.
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Services\OldName;\n", 'tests/Unit/OldNameTest.php');

        $selection = AffectedTests::select(
            $this->detectResult([]),
            [new ChangedFileSymbols('app/Services/NewName.php', 'App\Services\NewName', [
                new MemberChange('', MemberChange::KIND_CLASS, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false, directSeeds: ['App\Services\OldName'])],
            $index,
            hasUnresolvedDispatches: false,
        );

        $this->assertSame(['tests/Unit/OldNameTest.php'], $selection['tests']);
    }

    #[Test]
    public function non_test_support_files_filter_out_of_the_import_axis_silently(): void
    {
        // A fixture importing an app class is not a runnable test — it must neither be selected
        // nor block determination (imports are the weak, over-selection-safe axis).
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Services\X;\n", 'tests/Fixtures/XFixture.php');

        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $index,
            hasUnresolvedDispatches: false,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['tests']);
    }

    #[Test]
    public function an_entry_point_referenced_only_from_a_support_file_blocks_determination(): void
    {
        // A route reference inside a helper trait is a real coverage signal, but the tests using
        // that trait cannot be mapped — a smaller set would silently drop them.
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/errors/log");', 'tests/Support/VisitsErrors.php');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [],
            $index,
            hasUnresolvedDispatches: false,
        );

        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('non-test support files', $selection['reasons'][0]);
    }

    #[Test]
    public function a_mixed_reference_set_selects_the_runnable_test_and_drops_the_helper(): void
    {
        // Only a helper-ONLY reference set blocks determination — when a runnable test references
        // the entry point too, the helper silently filters out and the selection proceeds.
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/errors/log");', 'tests/Support/VisitsErrors.php');
        $index->addSource('<?php $this->get("/errors/log");', 'tests/Feature/ErrorLogTest.php');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [],
            $index,
            hasUnresolvedDispatches: false,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/ErrorLogTest.php'], $selection['tests']);
    }

    #[Test]
    public function tests_importing_a_reached_intermediate_class_are_selected(): void
    {
        // A unit test of an upstream caller never references an entry point — the import axis
        // covers every class the change reaches, not only the changed ones.
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Services\Upstream;\n", 'tests/Unit/UpstreamTest.php');

        $selection = AffectedTests::select(
            $this->detectResult([], callers: [['depth' => 1, 'node' => 'App\Services\Upstream::run', 'via' => 'call']]),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $index,
            hasUnresolvedDispatches: false,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Unit/UpstreamTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_schedule_entry_resolves_through_its_scheduled_command_when_the_graph_is_given(): void
    {
        $graph = new CodeGraph([
            ['source' => 'schedule::abc123', 'target' => 'command::video:seed-views {--without-relations : x}', 'type' => 'schedule-to-command'],
        ]);
        $index = new TestReferenceIndex();
        $index->addSource("<?php \$this->artisan('video:seed-views');", 'tests/Feature/SeedViewsTest.php');

        $selection = AffectedTests::select(
            $this->detectResult(['schedule::abc123']),
            [],
            $index,
            hasUnresolvedDispatches: false,
            graph: $graph,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/SeedViewsTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_schedule_entry_without_a_command_target_still_blocks_determination(): void
    {
        $graph = new CodeGraph([
            ['source' => 'schedule::abc123', 'target' => 'App\Jobs\NightlyJob', 'type' => 'schedule-to-job'],
        ]);

        $selection = AffectedTests::select(
            $this->detectResult(['schedule::abc123']),
            [],
            $this->index(),
            hasUnresolvedDispatches: false,
            graph: $graph,
        );

        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('schedule::abc123', $selection['reasons'][0]);
    }

    #[Test]
    public function a_blade_only_change_selects_on_entry_points_alone(): void
    {
        // A changed view has no FQCN — the class-import axis must simply not fire.
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [new ChangedFileSymbols('resources/views/errors.blade.php', '', [], cosmeticOnly: false, directSeeds: ['view::blade__errors'])],
            $this->index(),
            hasUnresolvedDispatches: false,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/ErrorLogTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_frontend_only_change_selects_tests_referencing_its_touched_routes(): void
    {
        // A frontend file carries no FQCN and its route seeds match no class pattern — selection
        // runs purely on the entry-point axis the frontend lane appended to.
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log'], coverage: ['resources/js/Pages/Errors.vue' => 'analyzed']),
            [new ChangedFileSymbols('resources/js/Pages/Errors.vue', '', [], cosmeticOnly: false, directSeeds: ['route::GET::/errors/log'])],
            $this->index(),
            hasUnresolvedDispatches: false,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/ErrorLogTest.php'], $selection['tests']);
    }

    #[Test]
    public function an_unresolved_frontend_file_makes_the_selection_undeterminable(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult([], coverage: ['resources/js/Pages/Errors.vue' => 'unresolved']),
            [new ChangedFileSymbols('resources/js/Pages/Errors.vue', '', [], cosmeticOnly: false, unresolvedFrontendReferences: true)],
            $this->index(),
            hasUnresolvedDispatches: false,
        );

        $this->assertFalse($selection['determinable']);
        $this->assertSame(['changed file(s) could not be placed in the graph (UNRESOLVED)'], $selection['reasons']);
    }
}
