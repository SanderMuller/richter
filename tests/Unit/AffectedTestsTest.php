<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Models\Post;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\FrontendTestIndex;
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
     * @param  list<string>  $registryEntryPoints
     * @return array{coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, registryEntryPoints: list<string>, lowConfidence: bool, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>}
     */
    private function detectResult(array $entryPoints, array $coverage = ['app/Services/X.php' => 'analyzed'], bool $lowConfidence = false, array $callers = [], array $registryEntryPoints = []): array
    {
        return ['coverage' => $coverage, 'entryPoints' => $entryPoints, 'registryEntryPoints' => $registryEntryPoints, 'lowConfidence' => $lowConfidence, 'callers' => $callers, 'dependencies' => []];
    }

    private function changed(string $file, string $fqcn): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange('run', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
        ], cosmeticOnly: false);
    }

    /** A file whose only change is a member the graph has no node for — the low-confidence trigger. */
    private function unpinnable(string $file, string $fqcn, string $name = 'perPage', string $kind = MemberChange::KIND_PROPERTY): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange($name, $kind, MemberChange::CHANGE_MODIFIED, resolvable: false),
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
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['reasons']);
        $this->assertSame(['tests/Feature/ErrorLogTest.php', 'tests/Unit/XTest.php'], $selection['tests']);
        $this->assertSame(0, $selection['unreferencedEntryPoints']);
    }

    #[Test]
    public function a_surface_behind_a_registry_fan_out_still_selects_its_tests(): void
    {
        // The report calls it context, because it names every class the config file lists and cannot
        // tell one from another. The dispatch is real all the same, so a test that drives that route
        // exercises the change — skipping it would under-select, the one direction this must never
        // fail in.
        $selection = AffectedTests::select(
            $this->detectResult([], registryEntryPoints: ['route::GET::/errors/log']),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $this->index(),
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertContains('tests/Feature/ErrorLogTest.php', $selection['tests']);
    }

    #[Test]
    public function a_test_file_the_diff_touched_is_selected_without_any_graph_reasoning(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            changedTests: ['tests/Feature/PremiumTest.php'],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/PremiumTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_test_named_file_outside_the_tests_tree_is_not_a_runner_argument(): void
    {
        // `outOfScope` is every changed file no lane read, so a `tools/SmokeTest.php` reaches the
        // fallback too. Handing it to the suite runner would run nothing for that path.
        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            changedTests: ['tests/Feature/RealTest.php'],
        );

        $this->assertSame(['tests/Feature/RealTest.php'], $selection['tests']);
    }

    #[Test]
    public function an_undetermined_verdict_still_names_the_tests_it_could_find(): void
    {
        // The verdict blocks the selection from being trusted; it does not make the tests it did
        // find disappear. Reporting nothing reads as "no connection found", which is a different
        // and wrong statement.
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log'], lowConfidence: true),
            [$this->unpinnable('app/Services/X.php', 'App\Services\X')],
            $this->index(),
            unresolvedDispatchSites: [],
            changedTests: ['tests/Feature/PremiumTest.php'],
        );

        $this->assertFalse($selection['determinable']);
        // The reason's exact wording belongs to the tests that own it; here only its presence matters.
        $this->assertCount(1, $selection['reasons']);
        $this->assertStringContainsString('could not be pinned to a graph node', $selection['reasons'][0]);
        $this->assertSame([
            'tests/Feature/ErrorLogTest.php',
            'tests/Feature/PremiumTest.php',
            'tests/Unit/XTest.php',
        ], $selection['tests']);
    }

    #[Test]
    public function an_unresolved_file_makes_the_selection_undeterminable_with_a_reason(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log'], coverage: ['app/Services/Lost.php' => 'unresolved']),
            [],
            $this->index(),
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
        );
        $this->assertFalse($lowConfidence['determinable']);
        $this->assertStringContainsString('low confidence', $lowConfidence['reasons'][0]);

        // Scoped (plan 036 S2): an unfollowable dispatch blocks only when the change reaches a
        // dispatch target — here the changed class is itself a `\Jobs\` job (GUARD S2-job).
        $dispatches = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Jobs/PublishPostJob.php', 'App\Jobs\PublishPostJob')],
            $this->index(),
            unresolvedDispatchSites: [['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']],
        );
        $this->assertFalse($dispatches['determinable']);
        $this->assertStringContainsString('dispatches', $dispatches['reasons'][0]);
    }

    #[Test]
    public function the_dispatch_reason_names_every_site_in_order(): void
    {
        // The point of the whole lane: "a dispatch somewhere could not be followed" is a verdict a
        // project can only ever accept, while a named statement is one it can go and restructure.
        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Jobs/PublishPostJob.php', 'App\Jobs\PublishPostJob')],
            $this->index(),
            unresolvedDispatchSites: [
                ['file' => 'app/Jobs/Fanout.php', 'line' => 88, 'dispatcher' => 'App\Jobs\Fanout::handle'],
                ['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run'],
            ],
        );

        $this->assertFalse($selection['determinable']);
        $this->assertSame(
            'the graph contains job dispatches that could not be followed: '
            . 'app/Jobs/Fanout.php:88 (App\Jobs\Fanout::handle), '
            . 'app/Services/Importer.php:12 (App\Services\Importer::run)',
            $selection['reasons'][0],
        );
    }

    #[Test]
    public function no_dispatch_sites_adds_no_dispatch_reason(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Jobs/PublishPostJob.php', 'App\Jobs\PublishPostJob')],
            $this->index(),
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['reasons']);
    }

    #[Test]
    public function the_payload_carries_every_site_the_reason_truncates(): void
    {
        // The whole point of the key. The reason is prose for a reader and stays capped, but a
        // payload that could only ever express the first 15 leaves the rest reachable from nothing
        // but the MCP graph-stats resource — which a CI job running the CLI cannot reach at all.
        $sites = [];

        for ($i = 1; $i <= 18; ++$i) {
            $sites[] = ['file' => "app/Services/S{$i}.php", 'line' => $i, 'dispatcher' => "App\\Services\\S{$i}::run"];
        }

        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Jobs/PublishPostJob.php', 'App\Jobs\PublishPostJob')],
            $this->index(),
            unresolvedDispatchSites: $sites,
        );

        $this->assertSame($sites, $selection['unresolvedDispatchSites']);
        $this->assertStringEndsWith(', … and 3 more', $selection['reasons'][0]);
    }

    #[Test]
    public function sites_that_blocked_nothing_are_not_listed(): void
    {
        // The key reports what blocked THIS selection, not an inventory of the project. A dispatch
        // the change cannot be reached through leaves the selection determinable and belongs in no
        // reason, so listing it would hand a script work nobody has to do. The project-wide list
        // lives on the graph-stats resource.
        $selection = AffectedTests::select(
            $this->detectResult([]),
            // A model that really exists in the fixture app, so DispatchTarget can load it and rule
            // it out. An unloadable class fails toward "yes" by design, which would taint anyway.
            [$this->changed('app/Models/Post.php', Post::class)],
            $this->index(),
            unresolvedDispatchSites: [['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['reasons']);
        $this->assertSame([], $selection['unresolvedDispatchSites']);
    }

    #[Test]
    public function a_determinable_selection_still_declares_the_key(): void
    {
        // A shape that appears only sometimes is one every consumer has to guard against; an empty
        // list says "none" in the same words a full one says "these".
        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Jobs/PublishPostJob.php', 'App\Jobs\PublishPostJob')],
            $this->index(),
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['unresolvedDispatchSites']);
    }

    #[Test]
    public function a_long_site_list_is_capped_and_reports_the_remainder(): void
    {
        // A project dispatching dynamically throughout would otherwise push every other reason off
        // the screen; the count keeps the report honest about what it stopped naming.
        $sites = [];

        for ($i = 1; $i <= 18; ++$i) {
            $sites[] = ['file' => "app/Services/S{$i}.php", 'line' => $i, 'dispatcher' => "App\\Services\\S{$i}::run"];
        }

        $selection = AffectedTests::select(
            $this->detectResult([]),
            [$this->changed('app/Jobs/PublishPostJob.php', 'App\Jobs\PublishPostJob')],
            $this->index(),
            unresolvedDispatchSites: $sites,
        );

        $this->assertStringEndsWith(', … and 3 more', $selection['reasons'][0]);
        $this->assertStringContainsString('app/Services/S15.php:15', $selection['reasons'][0]);
        $this->assertStringNotContainsString('app/Services/S16.php', $selection['reasons'][0]);
    }

    #[Test]
    public function an_unfollowable_dispatch_does_not_block_a_change_with_no_dispatch_target_upstream(): void
    {
        // THE UNLOCK: S2 is a graph-global signal, but a change whose upward-caller closure holds
        // no dispatch target cannot be reached through a hidden `dispatcher → target` edge, so it
        // narrows instead of falling back to the full suite.
        $selection = AffectedTests::select(
            $this->detectResult(
                ['route::GET::/errors/log'],
                callers: [['depth' => 1, 'node' => 'route::GET::/errors/log', 'via' => 'route-to-controller']],
            ),
            [$this->changed('app/Models/Post.php', 'App\Models\Post')],
            $this->index(),
            unresolvedDispatchSites: [['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']],
        );
        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['reasons']);
    }

    #[Test]
    public function an_unparseable_file_blocks_determination_globally_regardless_of_the_change(): void
    {
        // GUARD S1: an unparseable file has no edges and could hide a caller of any change — it is
        // could-be-anything taint, so it blocks globally even when the change reaches no dispatchable.
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [$this->changed('app/Models/Post.php', 'App\Models\Post')],
            $this->index(),
            unresolvedDispatchSites: [],
            hasUnparseableFiles: true,
        );
        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('could not be parsed', $selection['reasons'][0]);
    }

    #[Test]
    public function an_unfollowable_dispatch_blocks_when_a_dispatchable_command_reaches_the_change(): void
    {
        // GUARD S2-command (the A2 fix): a `Dispatchable` command that is neither `\Jobs\` nor
        // `ShouldQueue`, reaching the change as a caller, still blocks — v1's job-only predicate
        // missed exactly this and would have under-selected.
        $selection = AffectedTests::select(
            $this->detectResult(
                [],
                callers: [['depth' => 1, 'node' => 'App\Actions\GenerateReport::handle', 'via' => 'call']],
            ),
            [$this->changed('app/Models/Post.php', 'App\Models\Post')],
            $this->index(),
            unresolvedDispatchSites: [['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']],
        );
        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('dispatches', $selection['reasons'][0]);
    }

    #[Test]
    public function an_unfollowable_dispatch_blocks_when_a_caller_class_cannot_be_classified(): void
    {
        // GUARD S2-unclassifiable: an unloadable caller (e.g. an unresolved short id) is uncertainty,
        // and uncertainty fails toward "could be a dispatch target" — never toward narrowing.
        $selection = AffectedTests::select(
            $this->detectResult(
                [],
                callers: [['depth' => 1, 'node' => 'App\Ghost\Unloadable::handle', 'via' => 'call']],
            ),
            [$this->changed('app/Models/Post.php', 'App\Models\Post')],
            $this->index(),
            unresolvedDispatchSites: [['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']],
        );
        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('dispatches', $selection['reasons'][0]);
    }

    #[Test]
    public function an_unfollowable_dispatch_blocks_when_a_self_handling_command_reaches_the_change(): void
    {
        // GUARD S2-self-handling (the codex-found gap): a plain command with handle() and no
        // Dispatchable/ShouldQueue/\Jobs\, dispatched via dispatch($x), still reaches the change —
        // the widened predicate (rule 5) must fire so it blocks rather than silently narrowing.
        $selection = AffectedTests::select(
            $this->detectResult(
                [],
                callers: [['depth' => 1, 'node' => 'App\Commands\ArchiveStalePosts::handle', 'via' => 'call']],
            ),
            [$this->changed('app/Models/Post.php', 'App\Models\Post')],
            $this->index(),
            unresolvedDispatchSites: [['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']],
        );
        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('dispatches', $selection['reasons'][0]);
    }

    #[Test]
    public function an_uncheckable_entry_point_blocks_determination(): void
    {
        // A schedule:: node has no reference detection — silently skipping it would shrink the set.
        $selection = AffectedTests::select(
            $this->detectResult(['schedule::posts:cleanup']),
            [],
            $this->index(),
            unresolvedDispatchSites: [],
        );

        $this->assertFalse($selection['determinable']);
        $this->assertStringContainsString('schedule::posts:cleanup', $selection['reasons'][0]);
    }

    #[Test]
    public function reached_entry_points_without_references_are_counted_not_hidden(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log', 'route::GET::/uncovered']),
            [],
            $this->index(),
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Unit/UpstreamTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_class_driven_route_is_selected_when_the_graph_is_given(): void
    {
        // A Filament page is driven by neither route name nor URI — `livewire(AdminPanel::class)`
        // names the CLASS. Without the graph the index cannot follow the route to its handler, so the
        // route reads unreferenced and its test is dropped: under-selection.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/admin/panel', 'target' => 'App\Filament\Pages\AdminPanel', 'type' => 'filament-route-to-page'],
        ], hasUnparseableFiles: false);
        $index = new TestReferenceIndex();
        $index->addSource("<?php\n\nuse App\\Filament\\Pages\\AdminPanel;\n", 'tests/Feature/AdminPanelTest.php');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/admin/panel']),
            [],
            $index,
            unresolvedDispatchSites: [],
            graph: $graph,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/AdminPanelTest.php'], $selection['tests']);
        $this->assertSame(0, $selection['unreferencedEntryPoints']);
    }

    #[Test]
    public function a_class_driven_route_reads_unreferenced_without_a_graph(): void
    {
        // The same inputs with no graph: the handler fallback is gated on one, so nothing connects
        // the route to the page class and the entry point counts as unreferenced. This pins WHY the
        // graph has to be attached — remove `useGraph()` and the test above becomes this one.
        $index = new TestReferenceIndex();
        $index->addSource("<?php\n\nuse App\\Filament\\Pages\\AdminPanel;\n", 'tests/Feature/AdminPanelTest.php');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/admin/panel']),
            [],
            $index,
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame([], $selection['tests']);
        $this->assertSame(1, $selection['unreferencedEntryPoints']);
    }

    #[Test]
    public function a_class_driven_route_resolves_through_two_hops(): void
    {
        // A Filament RESOURCE route arrives as `filament-route-to-resource` and needs the second hop
        // to reach the page. One hop would leave it unreferenced.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/admin/orders', 'target' => 'App\Filament\Resources\OrderResource', 'type' => 'filament-route-to-resource'],
            ['source' => 'App\Filament\Resources\OrderResource', 'target' => 'App\Filament\Resources\OrderResource\Pages\ListOrders', 'type' => 'filament-resource-to-page'],
        ], hasUnparseableFiles: false);
        $index = new TestReferenceIndex();
        $index->addSource("<?php\n\nuse App\\Filament\\Resources\\OrderResource\\Pages\\ListOrders;\n", 'tests/Feature/ListOrdersTest.php');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/admin/orders']),
            [],
            $index,
            unresolvedDispatchSites: [],
            graph: $graph,
        );

        $this->assertSame(['tests/Feature/ListOrdersTest.php'], $selection['tests']);
        $this->assertSame(0, $selection['unreferencedEntryPoints']);
    }

    #[Test]
    public function a_class_driven_route_whose_handler_no_test_imports_stays_unreferenced(): void
    {
        // The fallback must not read as "always referenced". A graph edge with no importing test is
        // still an unreferenced surface.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/admin/panel', 'target' => 'App\Filament\Pages\AdminPanel', 'type' => 'filament-route-to-page'],
        ], hasUnparseableFiles: false);

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/admin/panel']),
            [],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            graph: $graph,
        );

        $this->assertSame([], $selection['tests']);
        $this->assertSame(1, $selection['unreferencedEntryPoints']);
    }

    #[Test]
    public function a_class_driven_route_referenced_only_by_a_support_file_blocks_determination(): void
    {
        // The fail-closed half. A shared trait or base case importing the handler IS a reference, but
        // it cannot be handed to a test runner — so the selection must say it could not map it, not
        // report the surface as unreferenced and stay determinable. This is the same answer the
        // name/URI path has always given for a support-file-only reference; the handler path now
        // agrees with it.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/admin/panel', 'target' => 'App\Filament\Pages\AdminPanel', 'type' => 'filament-route-to-page'],
        ], hasUnparseableFiles: false);
        $index = new TestReferenceIndex();
        $index->addSource("<?php\n\nuse App\\Filament\\Pages\\AdminPanel;\n", 'tests/Support/PanelFixture.php');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/admin/panel']),
            [],
            $index,
            unresolvedDispatchSites: [],
            graph: $graph,
        );

        $this->assertFalse($selection['determinable']);
        $this->assertSame(
            ['tests referencing "route::GET::/admin/panel" live only in non-test support files — cannot map them to runnable tests'],
            $selection['reasons'],
        );
        $this->assertSame([], $selection['tests']);
    }

    #[Test]
    public function a_schedule_entry_resolves_through_its_scheduled_command_when_the_graph_is_given(): void
    {
        $graph = new CodeGraph([
            ['source' => 'schedule::abc123', 'target' => 'command::post:seed-views {--without-relations : x}', 'type' => 'schedule-to-command'],
        ], hasUnparseableFiles: false);
        $index = new TestReferenceIndex();
        $index->addSource("<?php \$this->artisan('post:seed-views');", 'tests/Feature/SeedViewsTest.php');

        $selection = AffectedTests::select(
            $this->detectResult(['schedule::abc123']),
            [],
            $index,
            unresolvedDispatchSites: [],
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
        ], hasUnparseableFiles: false);

        $selection = AffectedTests::select(
            $this->detectResult(['schedule::abc123']),
            [],
            $this->index(),
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
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
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/ErrorLogTest.php'], $selection['tests']);
    }

    #[Test]
    public function frontend_specs_referencing_a_reached_route_are_suggested_without_gating_determinability(): void
    {
        Route::get('/errors/log', ['App\Http\Controllers\ErrorController', 'index'])->name('errors.log');
        $frontendTests = new FrontendTestIndex();
        $frontendTests->addSource("route('errors.log');", 'resources/js/errors.spec.ts');

        $selection = AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $this->index(),
            unresolvedDispatchSites: [],
            frontendTests: $frontendTests,
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['resources/js/errors.spec.ts'], $selection['frontendTests']);
        // The PHP selection is untouched by the frontend axis.
        $this->assertSame(['tests/Feature/ErrorLogTest.php', 'tests/Unit/XTest.php'], $selection['tests']);
    }

    #[Test]
    public function an_unresolved_frontend_file_makes_the_selection_undeterminable(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult([], coverage: ['resources/js/Pages/Errors.vue' => 'unresolved']),
            [new ChangedFileSymbols('resources/js/Pages/Errors.vue', '', [], cosmeticOnly: false, unresolvedFrontendReferences: true)],
            $this->index(),
            unresolvedDispatchSites: [],
        );

        $this->assertFalse($selection['determinable']);
        $this->assertSame(['changed file(s) could not be placed in the graph (UNRESOLVED)'], $selection['reasons']);
    }

    #[Test]
    public function the_low_confidence_reason_names_the_member_it_could_not_pin(): void
    {
        // A bare boolean withdrew a whole test selection over a member it would not name, which left a
        // reader with nothing to look at and no way to judge whether the veto was right. The KIND is the
        // half that decides what to do: a property has no member node by design, so the veto is correct
        // and there is nothing to restructure.
        $selection = AffectedTests::select(
            $this->detectResult([], lowConfidence: true),
            [$this->unpinnable('app/Models/Post.php', 'App\Models\Post')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
        );

        $this->assertSame([
            'a changed member could not be pinned to a graph node (low confidence): '
                . 'app/Models/Post.php (App\Models\Post::perPage, property)',
        ], $selection['reasons']);
        $this->assertFalse($selection['determinable']);
    }

    #[Test]
    public function the_low_confidence_reason_names_every_unpinnable_member_across_files(): void
    {
        $selection = AffectedTests::select(
            $this->detectResult([], lowConfidence: true),
            [
                $this->unpinnable('app/Models/Post.php', 'App\Models\Post'),
                $this->unpinnable('app/Models/Comment.php', 'App\Models\Comment', 'casts'),
            ],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
        );

        $this->assertSame([
            'a changed member could not be pinned to a graph node (low confidence): '
                . 'app/Models/Post.php (App\Models\Post::perPage, property); '
                . 'app/Models/Comment.php (App\Models\Comment::casts, property)',
        ], $selection['reasons']);
    }

    #[Test]
    public function a_class_declaration_change_reads_as_the_class_not_as_an_empty_member(): void
    {
        // A class-level modifier change carries an empty member name — there is no member, the
        // declaration itself changed — so it must not render as `App\Models\Post::`.
        $selection = AffectedTests::select(
            $this->detectResult([], lowConfidence: true),
            [$this->unpinnable('app/Models/Post.php', 'App\Models\Post', '', MemberChange::KIND_CLASS)],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
        );

        $this->assertSame([
            'a changed member could not be pinned to a graph node (low confidence): '
                . 'app/Models/Post.php (App\Models\Post, class declaration)',
        ], $selection['reasons']);
    }

    #[Test]
    public function a_pinnable_member_is_never_named_as_the_low_confidence_cause(): void
    {
        // The list is the trigger's own predicate, so a resolvable member must not appear in it even when
        // the run is low-confidence for another file's sake.
        $selection = AffectedTests::select(
            $this->detectResult([], lowConfidence: true),
            [
                $this->changed('app/Services/X.php', 'App\Services\X'),
                $this->unpinnable('app/Models/Post.php', 'App\Models\Post'),
            ],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
        );

        $this->assertStringNotContainsString('App\Services\X', $selection['reasons'][0]);
        $this->assertStringContainsString('App\Models\Post::perPage', $selection['reasons'][0]);
    }
}
