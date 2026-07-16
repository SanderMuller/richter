<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Enums\FeatureFlag;
use App\Http\Middleware\PlaylistAuthenticate;
use App\Models\Concerns\WithAudits;
use App\Models\Interaction;
use App\Models\Question;
use App\Models\Theme;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoContainer;
use App\Policies\UserPolicy;
use App\Policies\VideoPolicy;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\Fqcn;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\EntryPointTracer;

final class ImpactAnalyzerTest extends TestCase
{
    private const string ROUTE = 'route::POST::/videos/{video}/publish';

    private function analyzer(): ImpactAnalyzer
    {
        // Models a request path: route → controller → action → service → event,
        // plus an Eloquent relationship edge between two models.
        return new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\VideoController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\VideoController', 'target' => 'App\Http\Controllers\VideoController::publish', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\VideoController::publish', 'target' => 'App\Services\VideoPublisher::publish', 'type' => 'action-to-service'],
            ['source' => 'App\Services\VideoPublisher::publish', 'target' => 'App\Events\VideoPublished', 'type' => 'action-to-event'],
            ['source' => Video::class, 'target' => Interaction::class, 'type' => 'model-relationship'],
        ]));
    }

    /**
     * @param  list<array{depth: int, node: string, via: string}>  $hops
     * @return list<string>
     */
    private function nodes(array $hops): array
    {
        return array_map(static fn (array $hop): string => $hop['node'], $hops);
    }

    /** A modified, resolvable method member. */
    private function changedMethod(string $file, string $fqcn, string $method): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange($method, MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
        ], cosmeticOnly: false);
    }

    /** A modified non-resolvable member (e.g. $fillable) — drives the coarse class seed. */
    private function changedCoarse(string $file, string $fqcn): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange('fillable', MemberChange::KIND_PROPERTY, MemberChange::CHANGE_MODIFIED, resolvable: false),
        ], cosmeticOnly: false);
    }

    /** A purely additive member (new method / enum case). */
    private function changedAdditive(string $file, string $fqcn): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange('added', MemberChange::KIND_METHOD, MemberChange::CHANGE_ADDED, resolvable: true),
        ], cosmeticOnly: false);
    }

    #[Test]
    public function impact_reports_callers_up_to_the_entry_point_and_dependencies(): void
    {
        $result = $this->analyzer()->impact('VideoPublisher::publish');

        $this->assertContains(self::ROUTE, $this->nodes($result['callers']));
        $this->assertContains('App\Http\Controllers\VideoController::publish', $this->nodes($result['callers']));
        $this->assertContains('App\Events\VideoPublished', $this->nodes($result['dependencies']));
    }

    #[Test]
    public function detect_changes_resolves_the_http_entry_point_for_a_service_change(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Services/VideoPublisher.php', 'App\Services\VideoPublisher', 'publish'),
        ]);

        $this->assertSame([self::ROUTE], $result['entryPoints']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function detect_changes_seeds_a_changed_blade_view_and_reaches_its_entry_point_and_policy(): void
    {
        // route → controller → video-item view → action-buttons component → VideoPolicy. A change to
        // the component Blade must walk up to the route and surface the policy it gates on.
        $component = 'view::blade__components.video_dashboard.video_action_buttons';
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\VideoController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\VideoController', 'target' => 'App\Http\Controllers\VideoController::index', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\VideoController::index', 'target' => 'view::blade__dashboard.home.video_item', 'type' => 'action-to-view'],
            ['source' => 'view::blade__dashboard.home.video_item', 'target' => $component, 'type' => 'view-to-view'],
            ['source' => $component, 'target' => VideoPolicy::class, 'type' => 'authorizes'],
        ]));

        $file = 'resources/views/components/video-dashboard/video-action-buttons.blade.php';
        $result = $analyzer->detectChanges([
            new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: [$component]),
        ]);

        $this->assertSame([self::ROUTE], $result['entryPoints']);
        $this->assertSame('analyzed', $result['coverage'][$file]);
        $this->assertFalse($result['lowConfidence']);
        $this->assertContains(VideoPolicy::class, $this->nodes($result['dependencies']));
    }

    #[Test]
    public function a_changed_blade_view_absent_from_the_graph_reads_as_unresolved_not_no_impact(): void
    {
        $file = 'resources/views/orphan.blade.php';
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: ['view::blade__orphan']),
        ]);

        $this->assertSame('unresolved', $result['coverage'][$file]);
    }

    #[Test]
    public function detect_changes_follows_eloquent_relationship_edges(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertContains(Interaction::class, $this->nodes($result['dependencies']));
        $this->assertSame([], $result['entryPoints']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_real_change_to_an_uncharted_entry_point_class_is_at_least_medium(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Jobs/Video/SomeImportJob.php', 'App\Jobs\Video\SomeImportJob', 'handle'),
        ]);

        $this->assertSame(0, $result['changed']['app/Jobs/Video/SomeImportJob.php']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function an_additive_only_change_to_a_job_is_low_not_medium(): void
    {
        // A new method on a job has no callers; the entry-class floor must not fire.
        $result = $this->analyzer()->detectChanges([
            $this->changedAdditive('app/Jobs/Video/SomeImportJob.php', 'App\Jobs\Video\SomeImportJob'),
        ]);

        $this->assertSame(0, $result['changed']['app/Jobs/Video/SomeImportJob.php']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function an_additive_enum_case_seeds_nothing(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedAdditive('app/Enums/FeatureFlag.php', FeatureFlag::class),
        ]);

        $this->assertSame(0, $result['changed']['app/Enums/FeatureFlag.php']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_coarse_hub_change_is_capped_at_medium_not_high(): void
    {
        // 25 controllers load Video — without the cap a coarse $fillable seed would saturate to HIGH.
        $edges = [];
        for ($i = 0; $i < 25; ++$i) {
            $edges[] = ['source' => "App\\Http\\Controllers\\C{$i}::index", 'target' => Video::class, 'type' => 'action-to-model'];
        }

        $result = new ImpactAnalyzer(new CodeGraph($edges))->detectChanges([
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertTrue($result['lowConfidence']);
        $this->assertGreaterThanOrEqual(20, $result['impacted']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
        // The cap genuinely bound the result here (coarse fan-out would otherwise be HIGH).
        $this->assertTrue($result['coarseCapApplied']);
    }

    #[Test]
    public function an_addition_only_column_config_change_to_a_hub_model_reads_low(): void
    {
        // Same 25-controller hub as the coarse test, but here the $fillable edit only ADDS an element.
        // ChangedSymbols reclassifies it as additive (HPB-5382), so it seeds nothing and stays LOW
        // instead of the coarse MEDIUM low-confidence estimate.
        $edges = [];
        for ($i = 0; $i < 25; ++$i) {
            $edges[] = ['source' => "App\\Http\\Controllers\\C{$i}::index", 'target' => Video::class, 'type' => 'action-to-model'];
        }

        $head = "<?php\nclass Video\n{\n    protected array \$fillable = ['a', 'b'];\n}\n";
        $base = "<?php\nclass Video\n{\n    protected array \$fillable = ['a'];\n}\n";
        $hunk = [
            'added' => [['line' => 4, 'text' => "    protected array \$fillable = ['a', 'b'];"]],
            'removed' => [['line' => 4, 'text' => "    protected array \$fillable = ['a'];"]],
        ];

        $result = new ImpactAnalyzer(new CodeGraph($edges))->detectChanges([
            ChangedSymbols::classifyFile('app/Models/Video.php', $head, $base, $hunk),
        ]);

        $this->assertSame(0, $result['changed']['app/Models/Video.php']);
        $this->assertFalse($result['lowConfidence']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_precise_high_impact_change_is_not_capped_by_an_unrelated_coarse_change(): void
    {
        // A genuine HIGH (25 routes reach the changed service) must survive even when the diff also
        // touches a $fillable elsewhere — the coarse cap only applies to coarse-driven HIGH.
        $edges = [['source' => Video::class, 'target' => Interaction::class, 'type' => 'model-relationship']];
        for ($i = 0; $i < 25; ++$i) {
            $edges[] = ['source' => "route::GET::/r{$i}", 'target' => 'App\Services\Big::run', 'type' => 'route-to-controller'];
        }

        $result = new ImpactAnalyzer(new CodeGraph($edges))->detectChanges([
            $this->changedMethod('app/Services/Big.php', 'App\Services\Big', 'run'),
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertTrue($result['lowConfidence']);
        $this->assertSame(RiskLevel::High, $result['risk']);
        // Precise seeds drove HIGH, so the cap did NOT fire — the note must not claim it did.
        $this->assertFalse($result['coarseCapApplied']);
    }

    #[Test]
    public function member_seeding_matches_the_exact_method_not_a_sibling(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Services\X::publish', 'type' => 'action-to-service'],
            ['source' => 'route::GET::/other', 'target' => 'App\Services\X::publishNow', 'type' => 'action-to-service'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/X.php', 'App\Services\X', 'publish'),
        ]);

        // Only the route reaching ::publish — never ::publishNow.
        $this->assertSame([self::ROUTE], $result['entryPoints']);
    }

    #[Test]
    public function a_changed_trait_method_surfaces_its_using_classes(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Models\User::isAdmin', 'target' => 'App\Traits\HasRoles::isAdmin', 'type' => 'inherits-method'],
            ['source' => self::ROUTE, 'target' => 'App\Models\User::isAdmin', 'type' => 'action-to-model'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Traits/HasRoles.php', 'App\Traits\HasRoles', 'isAdmin'),
        ]);

        $this->assertContains('App\Models\User::isAdmin', $this->nodes($result['callers']));
        $this->assertContains(self::ROUTE, $this->nodes($result['callers']));
    }

    #[Test]
    public function a_relationship_only_fan_out_is_context_not_risk(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Video::class, 'target' => Interaction::class, 'type' => 'model-relationship'],
            ['source' => Video::class, 'target' => Question::class, 'type' => 'model-relationship'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertSame(0, $result['impacted']);
        // Related models render as readable FQCNs.
        $this->assertContains(Interaction::class, $result['relatedModels']);
        $this->assertContains(Question::class, $result['relatedModels']);
    }

    #[Test]
    public function a_model_reached_by_two_edges_collapses_to_one_label(): void
    {
        // Two relationship edges to the same model must not list it twice (which would inflate the count).
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Video::class, 'target' => Interaction::class, 'type' => 'model-relationship'],
            ['source' => Video::class, 'target' => Interaction::class, 'type' => 'model-relationship'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertSame([Interaction::class], $result['relatedModels']);
    }

    #[Test]
    public function a_node_reachable_by_both_a_relation_and_a_call_edge_counts_toward_risk(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Video::class, 'target' => 'App\Services\X::run', 'type' => 'model-relationship'],
            ['source' => Video::class, 'target' => 'App\Services\X::run', 'type' => 'action-to-service'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertSame(1, $result['impacted']);
        $this->assertSame([], $result['relatedModels']);
    }

    #[Test]
    public function a_dispatched_job_reaches_the_route_without_double_counting(): void
    {
        // The dispatch edge is FQCN-keyed, so it points straight at the controller's action node —
        // one node per symbol, no scheme duplication.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\X', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\X', 'target' => 'App\Http\Controllers\X::store', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\X::store', 'target' => 'App\Jobs\ImportJob::handle', 'type' => 'action-to-job'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob', 'handle'),
        ]);

        // The route is reachable from the changed job through the merged action node.
        $this->assertSame([self::ROUTE], $result['entryPoints']);
        // The action node counts once, not twice (it is a single id, no scheme duplication).
        $this->assertSame(3, $result['impacted']);
    }

    #[Test]
    public function a_changed_job_reaching_no_entry_point_reads_as_unresolved_when_dispatches_are_unfollowable(): void
    {
        $graph = new CodeGraph([
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\X::run', 'type' => 'job'],
        ], hasUnresolvedDispatches: true);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedMethod('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob', 'handle'),
        ]);

        // No graph caller resolved, so the job self-lists as its own entry surface — the coverage
        // still reads UNRESOLVED because its dispatchers could not be followed.
        $this->assertSame(['App\Jobs\ImportJob'], $result['entryPoints']);
        $this->assertSame('unresolved', $result['coverage']['app/Jobs/ImportJob.php']);
    }

    #[Test]
    public function an_additive_only_job_change_stays_analyzed_even_when_dispatches_are_unfollowable(): void
    {
        // Adding a member to a job seeds nothing on purpose; an unresolved dispatch elsewhere must not
        // flip it to UNRESOLVED, or every "just added a field to a job" PR reads as noise.
        $graph = new CodeGraph([
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\X::run', 'type' => 'job'],
        ], hasUnresolvedDispatches: true);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedAdditive('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob'),
        ]);

        $this->assertSame('analyzed', $result['coverage']['app/Jobs/ImportJob.php']);
    }

    #[Test]
    public function a_changed_job_reads_unresolved_per_file_even_when_a_sibling_reaches_an_entry_point(): void
    {
        // The job reaches no entry point of its own; a sibling change does. The job must still read
        // UNRESOLVED (its own coverage), not be masked by the sibling's entry points.
        $graph = new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Services\X::run', 'type' => 'route-to-controller'],
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\Y::run', 'type' => 'job'],
        ], hasUnresolvedDispatches: true);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedMethod('app/Services/X.php', 'App\Services\X', 'run'),
            $this->changedMethod('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob', 'handle'),
        ]);

        // The sibling's route plus the job's self-listing (its own seeds reached nothing).
        $this->assertSame([self::ROUTE, 'App\Jobs\ImportJob'], $result['entryPoints']);
        $this->assertSame('unresolved', $result['coverage']['app/Jobs/ImportJob.php']);
        $this->assertSame('analyzed', $result['coverage']['app/Services/X.php']);
    }

    #[Test]
    public function a_changed_job_with_all_dispatches_resolved_reads_as_genuinely_empty(): void
    {
        $graph = new CodeGraph([
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\X::run', 'type' => 'job'],
        ]);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedMethod('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob', 'handle'),
        ]);

        $this->assertSame('analyzed', $result['coverage']['app/Jobs/ImportJob.php']);
    }

    #[Test]
    public function symbol_lookup_does_not_over_match_sibling_classes(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Video::class, 'target' => VideoContainer::class, 'type' => 'model-relationship'],
        ]));

        // "App\Models\Video" must seed only Video, not the sibling "App\Models\VideoContainer".
        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertSame(1, $result['changed']['app/Models/Video.php']);
    }

    #[Test]
    public function symbol_lookup_respects_identifier_boundaries_on_both_sides(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Models\SuperVideo', 'target' => VideoContainer::class, 'type' => 'model-relationship'],
        ]));

        // "Video" must match neither "SuperVideo" (left) nor "VideoContainer" (right).
        $result = $analyzer->impact('Video');

        $this->assertSame([], $result['callers']);
        $this->assertSame([], $result['dependencies']);
    }

    #[Test]
    public function impacted_count_is_unique_across_directions(): void
    {
        // A <-> B cycle: B is reachable from A both up- and downstream, but must count once.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Services\A::run', 'target' => 'App\Services\B::run', 'type' => 'action-to-service'],
            ['source' => 'App\Services\B::run', 'target' => 'App\Services\A::run', 'type' => 'action-to-service'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/A.php', 'App\Services\A', 'run'),
        ]);

        $this->assertSame(1, $result['impacted']);
    }

    #[Test]
    public function an_unmatched_symbol_formats_as_a_no_match_message(): void
    {
        $result = $this->analyzer()->impact('App\Models\NonExistent');

        $this->assertStringContainsString('No graph nodes matched', ImpactFormatter::impact($result));
    }

    #[Test]
    public function a_leading_backslash_symbol_still_matches_fqcn_cased_nodes(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Video::class, 'target' => Interaction::class, 'type' => 'model-relationship'],
        ]));

        // A leading-backslash FQCN must resolve the same as one without it.
        $leadingBackslash = $this->nodes($analyzer->impact('\\' . Video::class)['dependencies']);

        $this->assertSame($this->nodes($analyzer->impact(Video::class)['dependencies']), $leadingBackslash);
        $this->assertContains(Interaction::class, $leadingBackslash);
    }

    #[Test]
    public function an_empty_symbol_matches_nothing(): void
    {
        $result = $this->analyzer()->impact('');

        $this->assertSame([], $result['callers']);
        $this->assertSame([], $result['dependencies']);
    }

    #[Test]
    public function fqcn_is_derived_from_an_app_path(): void
    {
        $this->assertSame(Video::class, Fqcn::fromPath('app/Models/Video.php'));
        $this->assertSame('App\Jobs\Video\SomeImportJob', Fqcn::fromPath('app/Jobs/Video/SomeImportJob.php'));
        $this->assertSame(Video::class, Fqcn::fromPath('./app/Models/Video.php'));
    }

    #[Test]
    public function fqcn_does_not_force_non_app_paths_into_the_app_namespace(): void
    {
        // A path that merely contains "app/" deeper down is not App\...
        $this->assertSame('Bar', Fqcn::fromPath('packages/foo/app/Support/Bar.php'));
        $this->assertSame('helpers', Fqcn::fromPath('bootstrap/helpers.php'));
    }

    #[Test]
    public function event_listener_target_keeps_an_explicit_method(): void
    {
        $this->assertSame('App\Listeners\SendNotification::handle', EntryPointTracer::listenerTarget('App\Listeners\SendNotification'));
        $this->assertSame('App\Listeners\SendNotification::onFoo', EntryPointTracer::listenerTarget('App\Listeners\SendNotification@onFoo'));
    }

    #[Test]
    public function a_changed_file_matches_its_fqcn_keyed_deep_call_node(): void
    {
        // A job appears as an FQCN-keyed deep-call node; a change to its file must resolve to it.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Jobs\Video\SomeImportJob::handle', 'target' => 'App\Services\Importer::run', 'type' => 'job'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Jobs/Video/SomeImportJob.php', 'App\Jobs\Video\SomeImportJob', 'handle'),
        ]);

        $this->assertSame(1, $result['changed']['app/Jobs/Video/SomeImportJob.php']);
        $this->assertContains('App\Services\Importer::run', $this->nodes($result['dependencies']));
    }

    #[Test]
    public function a_changed_member_reaches_its_class_callers_through_the_declares_edge(): void
    {
        // The headline HPB-5468 join: callers reference the class node (`$user->can(Policy::X)`),
        // the changed method seeds its member node — the declares edge connects the two.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\UserController::destroy', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\UserController::destroy', 'target' => UserPolicy::class, 'type' => 'authorizes'],
            ['source' => UserPolicy::class, 'target' => 'App\Policies\UserPolicy::forceDelete', 'type' => 'declares'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Policies/UserPolicy.php', UserPolicy::class, 'forceDelete'),
        ]);

        $this->assertSame(['analyzed'], array_values($result['coverage']));
        $this->assertSame([self::ROUTE], $result['entryPoints']);
    }

    #[Test]
    public function a_declares_only_fan_out_is_association_not_risk(): void
    {
        // A class declaring many members must not read as impact by declaration alone —
        // mirrors the model-relationship exclusion.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Video::class, 'target' => Video::class . '::interactions', 'type' => 'declares'],
            ['source' => Video::class, 'target' => Video::class . '::questions', 'type' => 'declares'],
            ['source' => Video::class, 'target' => Video::class . '::publish', 'type' => 'declares'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Video.php', Video::class),
        ]);

        $this->assertSame(0, $result['impacted']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_changed_entry_point_class_with_no_callers_lists_itself_as_the_entry_surface(): void
    {
        // A vendor-fired listener has no app-side caller edge, but it still runs on every event —
        // "Entry points reached: 0" would under-communicate that.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Listeners\Saml\SamlLoginListener::handle', 'target' => 'App\Services\UserProvisioner::provision', 'type' => 'call'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Listeners/Saml/SamlLoginListener.php', 'App\Listeners\Saml\SamlLoginListener', 'handle'),
        ]);

        $this->assertSame(['App\Listeners\Saml\SamlLoginListener'], $result['entryPoints']);
        // Self-listing must not drive risk by entry-point count — the entry-class floor already applies.
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function a_changed_middleware_without_route_edges_self_lists_with_the_entry_class_floor(): void
    {
        // Kernel-registered (global/alias) middleware gets no per-route edge from Brain, yet runs on
        // every request — it must place as its own entry surface, never "no impact".
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Http\Middleware\FirstPartySessionCookie::handle', 'target' => 'App\Services\CookieJar::set', 'type' => 'call'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Http/Middleware/FirstPartySessionCookie.php', 'App\Http\Middleware\FirstPartySessionCookie', 'handle'),
        ]);

        $this->assertSame(['App\Http\Middleware\FirstPartySessionCookie'], $result['entryPoints']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function a_changed_trait_method_reaches_the_using_class_callers(): void
    {
        // uses-trait joins: route → controller → model (class) → uses-trait → trait → declares → method.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\UserController::show', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\UserController::show', 'target' => User::class, 'type' => 'model'],
            ['source' => User::class, 'target' => WithAudits::class, 'type' => 'uses-trait'],
            ['source' => WithAudits::class, 'target' => 'App\Models\Concerns\WithAudits::audits', 'type' => 'declares'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Models/Concerns/WithAudits.php', WithAudits::class, 'audits'),
        ]);

        $this->assertSame(['analyzed'], array_values($result['coverage']));
        $this->assertSame([self::ROUTE], $result['entryPoints']);
    }

    #[Test]
    public function a_route_mapped_middleware_lists_its_route_instead_of_self_listing(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => PlaylistAuthenticate::class, 'type' => 'route-to-middleware'],
            ['source' => PlaylistAuthenticate::class, 'target' => 'App\Http\Middleware\PlaylistAuthenticate::handle', 'type' => 'declares'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Http/Middleware/PlaylistAuthenticate.php', PlaylistAuthenticate::class, 'handle'),
        ]);

        $this->assertSame([self::ROUTE], $result['entryPoints']);
    }

    #[Test]
    public function an_additive_only_entry_point_class_change_does_not_self_list(): void
    {
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols('app/Listeners/Saml/SamlLoginListener.php', 'App\Listeners\Saml\SamlLoginListener', [
                new MemberChange('newHelper', MemberChange::KIND_METHOD, MemberChange::CHANGE_ADDED, resolvable: true),
            ], cosmeticOnly: false),
        ]);

        $this->assertSame([], $result['entryPoints']);
    }

    #[Test]
    public function an_unnormalised_model_node_renders_short_and_dedupes_against_its_fqcn(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => VideoContainer::class, 'target' => Video::class, 'type' => 'model-relationship'],
            ['source' => VideoContainer::class, 'target' => 'model::Video', 'type' => 'model-relationship'],
            ['source' => VideoContainer::class, 'target' => 'model::Playlist', 'type' => 'model-relationship'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/VideoContainer.php', VideoContainer::class),
        ]);

        // `model::Video` collapses into the FQCN label; `model::Playlist` (no FQCN sibling) renders short.
        $this->assertSame([Video::class, 'Playlist'], $result['relatedModels']);
    }

    #[Test]
    public function an_ambiguous_short_model_label_is_kept_when_two_fqcns_share_its_basename(): void
    {
        // App\Models\Theme and App\Models\Playlist\Theme both exist — collapsing `model::Theme`
        // into either would silently claim the wrong model; keep the short label instead.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => VideoContainer::class, 'target' => Theme::class, 'type' => 'model-relationship'],
            ['source' => VideoContainer::class, 'target' => \App\Models\Playlist\Theme::class, 'type' => 'model-relationship'],
            ['source' => VideoContainer::class, 'target' => 'model::Theme', 'type' => 'model-relationship'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/VideoContainer.php', VideoContainer::class),
        ]);

        $this->assertSame([Theme::class, \App\Models\Playlist\Theme::class, 'Theme'], $result['relatedModels']);
    }

    #[Test]
    public function source_findings_are_passed_through_prefixed_with_their_file(): void
    {
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols(
                'app/Exports/X.php',
                'App\Exports\X',
                [new MemberChange('__construct', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true)],
                cosmeticOnly: false,
                findings: ["eager-load string 'interactionsquestions' matches no relation"],
            ),
        ]);

        $this->assertSame(["app/Exports/X.php: eager-load string 'interactionsquestions' matches no relation"], $result['findings']);
    }

    #[Test]
    public function a_change_without_findings_yields_an_empty_findings_list(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Http/Controllers/VideoController.php', 'App\Http\Controllers\VideoController', 'publish'),
        ]);

        $this->assertSame([], $result['findings']);
    }
}
