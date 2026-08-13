<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Enums\ExperimentFlag;
use App\Http\Middleware\CategoryAuthenticate;
use App\Models\Comment;
use App\Models\Concerns\WithAudits;
use App\Models\Post;
use App\Models\PostContainer;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use App\Policies\PostPolicy;
use App\Policies\UserPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\Fqcn;
use SanderMuller\Richter\Tests\TestCase;

final class ImpactAnalyzerTest extends TestCase
{
    private const string ROUTE = 'route::POST::/posts/{post}/publish';

    private function analyzer(): ImpactAnalyzer
    {
        // Models a request path: route → controller → action → service → event,
        // plus an Eloquent relationship edge between two models.
        return new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\PostController', 'target' => 'App\Http\Controllers\PostController::publish', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\PostController::publish', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'action-to-service'],
            ['source' => 'App\Services\PostPublisher::publish', 'target' => 'App\Events\PostPublished', 'type' => 'action-to-event'],
            ['source' => Post::class, 'target' => Comment::class, 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));
    }

    /**
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $hops
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

    /**
     * A brand-new file: every member reads CHANGE_ADDED because there is no base side to diff against,
     * so only `isNewFile` distinguishes it from a member added to an existing class.
     */
    private function changedNewFile(string $file, string $fqcn): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange('handle', MemberChange::KIND_METHOD, MemberChange::CHANGE_ADDED, resolvable: true),
        ], cosmeticOnly: false, isNewFile: true);
    }

    #[Test]
    public function impact_reports_callers_up_to_the_entry_point_and_dependencies(): void
    {
        $result = $this->analyzer()->impact('PostPublisher::publish');

        $this->assertContains(self::ROUTE, $this->nodes($result['callers']));
        $this->assertContains('App\Http\Controllers\PostController::publish', $this->nodes($result['callers']));
        $this->assertContains('App\Events\PostPublished', $this->nodes($result['dependencies']));
    }

    #[Test]
    public function detect_changes_resolves_the_http_entry_point_for_a_service_change(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame([self::ROUTE], $result['entryPoints']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function detect_changes_explains_the_chain_from_the_entry_point_to_the_changed_member(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame([
            ['node' => self::ROUTE, 'via' => 'route-to-controller'],
            ['node' => 'App\Http\Controllers\PostController', 'via' => 'controller-to-action'],
            ['node' => 'App\Http\Controllers\PostController::publish', 'via' => 'action-to-service'],
            ['node' => 'App\Services\PostPublisher::publish', 'via' => ''],
        ], $result['entryPointPaths'][self::ROUTE]);
    }

    #[Test]
    public function detect_changes_annotates_reached_entry_points_with_location_and_security(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Services\PostPublisher::publish', 'type' => 'route-to-controller'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            self::ROUTE => [
                'file' => 'routes/web.php',
                'line' => 8,
                'security' => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                    ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware'],
                ]],
            ],
            'App\Services\PostPublisher::publish' => ['file' => 'app/Services/PostPublisher.php', 'line' => 30],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame(['file' => 'routes/web.php', 'line' => 8], $result['entryPointLocations'][self::ROUTE]);
        $this->assertSame('public', $result['entryPointSecurity'][self::ROUTE]['exposure']);
        // The explain chain carries each hop's location too.
        $this->assertSame([
            ['node' => self::ROUTE, 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 8],
            ['node' => 'App\Services\PostPublisher::publish', 'via' => '', 'file' => 'app/Services/PostPublisher.php', 'line' => 30],
        ], $result['entryPointPaths'][self::ROUTE]);
    }

    #[Test]
    public function a_reached_route_carries_security_while_a_co_reached_livewire_component_carries_none(): void
    {
        // Intentional route-only contract, not a gap to "fix" by fabricating exposure: security
        // is a route classification (Brain classifies nothing else — ImpactAnalyzer::entryPointAnnotations()).
        // A Livewire component's real exposure comes from its mount-time authorize()/middleware/route
        // placement, which the graph doesn't model — so it gets no entryPointSecurity key at all,
        // never a fabricated one. Absence here means "not classified," never "public".
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'route::GET::/posts/{post}', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'route-to-controller'],
            ['source' => 'App\Livewire\StatusPanel::render', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            'route::GET::/posts/{post}' => [
                'security' => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => []],
            ],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertContains('route::GET::/posts/{post}', $result['entryPoints']);
        $this->assertContains('App\Livewire\StatusPanel', $result['entryPoints']);
        $this->assertSame('public', $result['entryPointSecurity']['route::GET::/posts/{post}']['exposure']);
        $this->assertArrayNotHasKey('App\Livewire\StatusPanel', $result['entryPointSecurity']);
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $extraEdges
     * @param  list<array{type: string, severity: string, message: string}>  $issues
     */
    private function publicWriteGraph(array $extraEdges, array $issues): ImpactAnalyzer
    {
        return new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController::publish', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\PostController::publish', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
            ...$extraEdges,
        ], hasUnparseableFiles: false, nodeMetadata: [
            self::ROUTE => ['security' => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => $issues]],
        ]));
    }

    #[Test]
    public function a_public_write_route_whose_reach_authorizes_a_policy_records_the_gate(): void
    {
        // Brain flags the route PUBLIC_WRITE, but its handler authorizes a policy — richter's own
        // `authorizes` edge contradicts Brain's "no authentication". The gate is recorded as evidence.
        $analyzer = $this->publicWriteGraph(
            [['source' => 'App\Http\Controllers\PostController::publish', 'target' => PostPolicy::class, 'type' => 'authorizes']],
            [['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware']],
        );

        $result = $analyzer->detectChanges([$this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish')]);

        $this->assertSame([PostPolicy::class], $result['entryPointAuthGates'][self::ROUTE]);
    }

    #[Test]
    public function a_public_write_route_with_no_authorization_in_reach_records_no_gate(): void
    {
        // A genuinely-ungated public write: no `authorizes` edge, so nothing contradicts Brain and the
        // finding is left to stand (never softened).
        $analyzer = $this->publicWriteGraph(
            [],
            [['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware']],
        );

        $result = $analyzer->detectChanges([$this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish')]);

        $this->assertArrayNotHasKey(self::ROUTE, $result['entryPointAuthGates']);
    }

    #[Test]
    public function a_public_route_without_a_public_write_issue_is_not_cross_checked(): void
    {
        // The trigger is the PUBLIC_WRITE issue, not the `public` exposure — a public-by-design route
        // with an `authorizes` edge in reach must not be annotated (no false "verify this" noise).
        $analyzer = $this->publicWriteGraph(
            [['source' => 'App\Http\Controllers\PostController::publish', 'target' => PostPolicy::class, 'type' => 'authorizes']],
            [],
        );

        $result = $analyzer->detectChanges([$this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish')]);

        $this->assertArrayNotHasKey(self::ROUTE, $result['entryPointAuthGates']);
    }

    #[Test]
    public function an_authorizes_edge_is_found_even_when_the_policy_is_also_reached_another_way(): void
    {
        // Guards the BFS-tree hazard: the policy node is ALSO reached via a non-`authorizes` edge. A
        // dependencyEdgesOf()-filter would keep only the first edge into the policy and could drop the
        // `authorizes` one; reading the adjacency by type finds it regardless of traversal order.
        $analyzer = $this->publicWriteGraph(
            [
                ['source' => 'App\Services\PostPublisher::publish', 'target' => PostPolicy::class, 'type' => 'references'],
                ['source' => 'App\Http\Controllers\PostController::publish', 'target' => PostPolicy::class, 'type' => 'authorizes'],
            ],
            [['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware']],
        );

        $result = $analyzer->detectChanges([$this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish')]);

        $this->assertSame([PostPolicy::class], $result['entryPointAuthGates'][self::ROUTE]);
    }

    #[Test]
    public function detect_changes_annotates_a_gated_route_with_its_feature_flags(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Services\PostPublisher::publish', 'type' => 'route-to-controller'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            self::ROUTE => ['gates' => ['ai-coach']],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame(['ai-coach'], $result['entryPointGates'][self::ROUTE]);
        // Annotation only: the gate must not soften or raise the risk level.
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function a_self_listed_entry_class_gets_a_location_but_never_security(): void
    {
        // Security is a route classification — a job or listener has no exposure level, and a
        // location on the self-listing still gives the reviewer a place to click through to.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Listeners\Saml\SamlLoginListener::handle', 'target' => 'App\Services\UserProvisioner::provision', 'type' => 'call'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            'App\Listeners\Saml\SamlLoginListener' => ['file' => 'app/Listeners/Saml/SamlLoginListener.php'],
        ]));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Listeners/Saml/SamlLoginListener.php', 'App\Listeners\Saml\SamlLoginListener', 'handle'),
        ]);

        $this->assertSame(['App\Listeners\Saml\SamlLoginListener'], $result['entryPoints']);
        $this->assertSame(
            ['file' => 'app/Listeners/Saml/SamlLoginListener.php'],
            $result['entryPointLocations']['App\Listeners\Saml\SamlLoginListener'],
        );
        $this->assertSame([], $result['entryPointSecurity']);
    }

    #[Test]
    public function impact_hops_carry_their_defining_locations(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'route::GET::/r', 'target' => 'App\Services\S::run', 'type' => 'route-to-controller'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            'route::GET::/r' => ['file' => 'routes/web.php', 'line' => 4],
        ]));

        $result = $analyzer->impact('App\Services\S::run');

        $this->assertSame(
            [['depth' => 1, 'node' => 'route::GET::/r', 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 4]],
            $result['callers'],
        );
    }

    #[Test]
    public function an_upstream_livewire_component_counts_as_one_entry_surface_across_its_members(): void
    {
        // Blade-mounted components have no route:: node — reached upstream, the component IS the
        // user-facing entry surface, and two of its members must collapse onto one class entry.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Livewire\Settings::save', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
            ['source' => 'App\Livewire\Settings::render', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame(['App\Livewire\Settings'], $result['entryPoints']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
        // The entry is class-normalised but the walk reached members — the shallowest member's
        // chain stands in, so --explain still shows how the surface reaches the change.
        $this->assertSame([
            ['node' => 'App\Livewire\Settings::render', 'via' => 'call'],
            ['node' => 'App\Services\PostPublisher::publish', 'via' => ''],
        ], $result['entryPointPaths']['App\Livewire\Settings']);
    }

    #[Test]
    public function an_upstream_filament_resource_counts_as_an_entry_surface(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Filament\Resources\PostResource::table', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame(['App\Filament\Resources\PostResource'], $result['entryPoints']);
    }

    #[Test]
    public function impact_normalises_ui_component_callers_onto_the_class_entry_surface(): void
    {
        // impact() reuses detectChanges' composition: two Livewire members among the
        // callers are ONE class-normalised entry surface, and the shallowest member's
        // chain stands in for the class node the walk never visited.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Livewire\Settings::save', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
            ['source' => 'App\Livewire\Settings::render', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->impact('App\Services\PostPublisher::publish');

        $this->assertSame(['App\Livewire\Settings'], $result['entryPoints']);
        $this->assertSame([
            ['node' => 'App\Livewire\Settings::render', 'via' => 'call'],
            ['node' => 'App\Services\PostPublisher::publish', 'via' => ''],
        ], $result['entryPointPaths']['App\Livewire\Settings']);
    }

    #[Test]
    public function a_class_merely_named_after_a_ui_framework_is_not_an_entry_surface(): void
    {
        // `Filament`/`Livewire` must match as a namespace segment, never as a name substring, and
        // vendor classes outside App\ never qualify.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Services\FilamentExport::run', 'target' => 'App\Services\X::run', 'type' => 'call'],
            ['source' => 'App\Services\Filament', 'target' => 'App\Services\X::run', 'type' => 'call'],
            ['source' => 'Filament\Pages\Dashboard', 'target' => 'App\Services\X::run', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/X.php', 'App\Services\X', 'run'),
        ]);

        $this->assertSame([], $result['entryPoints']);
    }

    #[Test]
    public function a_changed_filament_class_gets_the_entry_class_floor_and_self_lists(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Filament\Resources\PostResource::table', 'target' => 'App\Services\X::run', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Filament/Resources/PostResource.php', 'App\Filament\Resources\PostResource', 'table'),
        ]);

        $this->assertSame(['App\Filament\Resources\PostResource'], $result['entryPoints']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function a_changed_nova_resource_gets_the_entry_class_floor_and_self_lists(): void
    {
        // A Nova resource is the same kind of thing as a Filament one: an admin surface the panel
        // routes to, with no route in the application's own files. Without the namespace in the
        // vocabulary a change to one reads as reaching nothing at all, which on an app whose whole
        // admin is Nova means every admin change reports no surface.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Nova\PostResource::fields', 'target' => 'App\Services\X::run', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Nova/PostResource.php', 'App\Nova\PostResource', 'fields'),
        ]);

        $this->assertSame(['App\Nova\PostResource'], $result['entryPoints']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function an_upstream_nova_resource_counts_as_an_entry_surface(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Nova\PostResource::fields', 'target' => 'App\Services\PostPublisher::publish', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame(['App\Nova\PostResource'], $result['entryPoints']);
    }

    #[Test]
    public function a_changed_component_reached_by_a_sibling_change_is_listed_once(): void
    {
        // The component is both a changed entry class AND an upstream caller of the sibling change —
        // the self-listing guard must not append a duplicate.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Livewire\Settings::save', 'target' => 'App\Services\X::run', 'type' => 'call'],
            ['source' => 'App\Livewire\Settings', 'target' => 'App\Livewire\Settings::save', 'type' => 'declares'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Services/X.php', 'App\Services\X', 'run'),
            $this->changedMethod('app/Livewire/Settings.php', 'App\Livewire\Settings', 'save'),
        ]);

        $this->assertSame(['App\Livewire\Settings'], $result['entryPoints']);
    }

    #[Test]
    public function a_self_listed_entry_class_carries_no_explain_chain(): void
    {
        // The listener IS the entry surface — it is not reached from the change, so a chain would
        // be fiction; its absence is what tells a consumer apart "reached" from "self-listed".
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Listeners\Saml\SamlLoginListener::handle', 'target' => 'App\Services\UserProvisioner::provision', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Listeners/Saml/SamlLoginListener.php', 'App\Listeners\Saml\SamlLoginListener', 'handle'),
        ]);

        $this->assertSame(['App\Listeners\Saml\SamlLoginListener'], $result['entryPoints']);
        $this->assertSame([], $result['entryPointPaths']);
    }

    #[Test]
    public function detect_changes_seeds_a_changed_blade_view_and_reaches_its_entry_point_and_policy(): void
    {
        // route → controller → post-item view → action-buttons component → PostPolicy. A change to
        // the component Blade must walk up to the route and surface the policy it gates on.
        $component = 'view::blade__components.post_dashboard.post_action_buttons';
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\PostController', 'target' => 'App\Http\Controllers\PostController::index', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\PostController::index', 'target' => 'view::blade__dashboard.home.post_item', 'type' => 'action-to-view'],
            ['source' => 'view::blade__dashboard.home.post_item', 'target' => $component, 'type' => 'view-to-view'],
            ['source' => $component, 'target' => PostPolicy::class, 'type' => 'authorizes'],
        ], hasUnparseableFiles: false));

        $file = 'resources/views/components/post-dashboard/post-action-buttons.blade.php';
        $result = $analyzer->detectChanges([
            new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: [$component]),
        ]);

        $this->assertSame([self::ROUTE], $result['entryPoints']);
        $this->assertSame('analyzed', $result['coverage'][$file]);
        $this->assertFalse($result['lowConfidence']);
        $this->assertContains(PostPolicy::class, $this->nodes($result['dependencies']));
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
    public function a_changed_view_never_seeds_a_nested_sibling_view(): void
    {
        // `components.card` is a boundary-clean substring of `components.card.header` — a changed
        // card must never drag its unrelated header sibling's entry point along for the ride.
        $card = 'view::blade__components.card';
        $cardHeader = 'view::blade__components.card.header';
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'route::GET::/card', 'target' => 'App\Http\Controllers\CardController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\CardController', 'target' => $card, 'type' => 'action-to-view'],
            ['source' => 'route::GET::/card-header', 'target' => 'App\Http\Controllers\CardHeaderController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\CardHeaderController', 'target' => $cardHeader, 'type' => 'action-to-view'],
        ], hasUnparseableFiles: false));

        $file = 'resources/views/components/card.blade.php';
        $result = $analyzer->detectChanges([
            new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: [$card]),
        ]);

        $this->assertContains('route::GET::/card', $result['entryPoints']);
        $this->assertNotContains('route::GET::/card-header', $result['entryPoints']);
        $this->assertSame('analyzed', $result['coverage'][$file]);
    }

    #[Test]
    public function a_pure_rename_reaches_the_old_fqcns_callers(): void
    {
        // A pure rename seeds the vanished OLD FQCN directly — head-tree callers still reference
        // it, so the blast radius must walk up from the old name to its entry points.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'route::GET::/r', 'target' => 'App\Services\Old', 'type' => 'references'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            new ChangedFileSymbols('app/Services/New.php', 'App\Services\New', [
                new MemberChange('', MemberChange::KIND_CLASS, MemberChange::CHANGE_MODIFIED, resolvable: false),
            ], cosmeticOnly: false, directSeeds: ['App\Services\Old']),
        ]);

        $this->assertContains('route::GET::/r', $result['entryPoints']);
        $this->assertSame('analyzed', $result['coverage']['app/Services/New.php']);
    }

    #[Test]
    public function detect_changes_follows_eloquent_relationship_edges(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertContains(Comment::class, $this->nodes($result['dependencies']));
        $this->assertSame([], $result['entryPoints']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_real_change_to_an_uncharted_entry_point_class_is_at_least_medium(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Jobs/Post/SomeImportJob.php', 'App\Jobs\Post\SomeImportJob', 'handle'),
        ]);

        $this->assertSame(0, $result['changed']['app/Jobs/Post/SomeImportJob.php']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function a_new_file_seeds_its_class_node_and_reports_its_reach(): void
    {
        // The reported false negative: a brand-new class whose nodes ARE in the graph used to report
        // 0 nodes and LOW, because every member of a new file classifies as added.
        $result = $this->analyzer()->detectChanges([
            $this->changedNewFile('app/Services/PostPublisher.php', 'App\Services\PostPublisher'),
        ]);

        $this->assertGreaterThan(0, $result['changed']['app/Services/PostPublisher.php']);
        $this->assertSame('analyzed', $result['coverage']['app/Services/PostPublisher.php']);
        $this->assertSame([self::ROUTE], $result['entryPoints']);
        // Its reach into existing code counts toward the impacted total.
        $this->assertGreaterThan(0, $result['impacted']);
        // Precise, not coarse: no low-confidence flag and no capped HIGH.
        $this->assertFalse($result['lowConfidence']);
        $this->assertFalse($result['coarseCapApplied']);
    }

    #[Test]
    public function a_new_command_file_classified_from_a_real_diff_reaches_its_entry_point(): void
    {
        // End to end over the reported reproduction: a brand-new console command, classified by the
        // real classifier from an all-added hunk, used to report 0 nodes / LOW while richter:impact on
        // the same class in the same graph returned a populated result.
        $head = "<?php\nnamespace App\\Console\\Commands;\nclass ImportCommand\n{\n    public function handle(): void\n    {\n        run();\n    }\n}\n";
        $added = [];

        foreach (explode("\n", $head) as $index => $text) {
            $added[] = ['line' => $index + 1, 'text' => $text];
        }

        $graph = new CodeGraph([
            ['source' => 'command::app:import', 'target' => 'App\\Console\\Commands\\ImportCommand::handle', 'type' => 'command-to-action'],
            ['source' => 'App\\Console\\Commands\\ImportCommand::handle', 'target' => 'App\\Services\\Importer::run', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            ChangedSymbols::classifyFile('app/Console/Commands/ImportCommand.php', $head, baseSrc: null, hunk: ['added' => $added, 'removed' => []], isNew: true),
        ]);

        $this->assertGreaterThan(0, $result['changed']['app/Console/Commands/ImportCommand.php']);
        $this->assertSame('analyzed', $result['coverage']['app/Console/Commands/ImportCommand.php']);
        $this->assertSame(['command::app:import'], $result['entryPoints']);
        $this->assertSame(['app/Console/Commands/ImportCommand.php'], $result['newFiles']);
    }

    #[Test]
    public function a_new_entry_point_class_file_is_at_least_medium_and_self_lists(): void
    {
        // A new job has no caller in the graph, so it is its own entry surface — and the entry-class
        // floor applies, which the additive short-circuit used to skip entirely.
        $result = $this->analyzer()->detectChanges([
            $this->changedNewFile('app/Jobs/Post/SomeImportJob.php', 'App\Jobs\Post\SomeImportJob'),
        ]);

        $this->assertSame(['App\Jobs\Post\SomeImportJob'], $result['entryPoints']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function a_new_file_no_graph_node_references_stays_analyzed_with_a_finding(): void
    {
        // Nothing references the class yet — a determined answer, not an unknown, and a new class
        // cannot break an existing caller. UNRESOLVED here would fail every --fail-on-unresolved
        // build that adds a not-yet-wired class.
        $result = $this->analyzer()->detectChanges([
            $this->changedNewFile('app/Enums/NewStatus.php', 'App\Enums\NewStatus'),
        ]);

        $this->assertSame(0, $result['changed']['app/Enums/NewStatus.php']);
        $this->assertSame('analyzed', $result['coverage']['app/Enums/NewStatus.php']);
        // Graph-scoped by construction, and worded so it cannot read as a claim about the codebase:
        // a consumer hit exactly that misreading when a static call left a called class edgeless.
        $this->assertContains('app/Enums/NewStatus.php is new and no traced edge reaches it — either nothing calls it yet, or the call shape is one richter does not trace', $result['findings']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function reach_through_the_new_edge_types_counts_toward_risk(): void
    {
        // Decided with the spec: a static call is an invocation and an inherited method is the code
        // that runs, so both count — unlike `override`, which is excluded because an interface with
        // many implementors fans out widely.
        $graph = new CodeGraph([
            ['source' => 'App\\Services\\Caller::run', 'target' => 'App\\Support\\Registry::all', 'type' => 'static-call'],
            ['source' => 'App\\Services\\Sub::build', 'target' => 'App\\Services\\Base::build', 'type' => 'inherits'],
        ], hasUnparseableFiles: false);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedMethod('app/Services/Caller.php', 'App\\Services\\Caller', 'run'),
            $this->changedMethod('app/Services/Sub.php', 'App\\Services\\Sub', 'build'),
        ]);

        $this->assertSame(2, $result['impacted']);
    }

    #[Test]
    public function an_additive_only_change_to_a_job_is_low_not_medium(): void
    {
        // A new method on a job has no callers; the entry-class floor must not fire.
        $result = $this->analyzer()->detectChanges([
            $this->changedAdditive('app/Jobs/Post/SomeImportJob.php', 'App\Jobs\Post\SomeImportJob'),
        ]);

        $this->assertSame(0, $result['changed']['app/Jobs/Post/SomeImportJob.php']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function an_additive_enum_case_seeds_nothing(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedAdditive('app/Enums/ExperimentFlag.php', ExperimentFlag::class),
        ]);

        $this->assertSame(0, $result['changed']['app/Enums/ExperimentFlag.php']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_coarse_hub_change_is_capped_at_medium_not_high(): void
    {
        // 25 controllers load Post — without the cap a coarse $fillable seed would saturate to HIGH.
        $edges = [];
        for ($i = 0; $i < 25; ++$i) {
            $edges[] = ['source' => "App\\Http\\Controllers\\C{$i}::index", 'target' => Post::class, 'type' => 'action-to-model'];
        }

        $result = new ImpactAnalyzer(new CodeGraph($edges, hasUnparseableFiles: false))->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertTrue($result['lowConfidence']);
        $this->assertGreaterThanOrEqual(20, $result['impacted']);
        $this->assertSame(RiskLevel::Medium, $result['risk']);
        // The cap genuinely bound the result here (coarse fan-out would otherwise be HIGH).
        $this->assertTrue($result['coarseCapApplied']);
        // MEDIUM was decided against the precisely-seeded subset, which seeds nothing here — so the
        // printed impacted count is not the number the level was measured against, and calibrating
        // `risk_thresholds` on it (as the guidance says) would set the bar against the wrong scale.
        $this->assertSame(0, $result['scoredImpacted']);
        $this->assertNotSame($result['impacted'], $result['scoredImpacted']);
    }

    #[Test]
    public function an_addition_only_column_config_change_to_a_hub_model_reads_low(): void
    {
        // Same 25-controller hub as the coarse test, but here the $fillable edit only ADDS an element.
        // ChangedSymbols reclassifies an addition-only config edit as additive, so it seeds nothing and stays LOW
        // instead of the coarse MEDIUM low-confidence estimate.
        $edges = [];
        for ($i = 0; $i < 25; ++$i) {
            $edges[] = ['source' => "App\\Http\\Controllers\\C{$i}::index", 'target' => Post::class, 'type' => 'action-to-model'];
        }

        $head = "<?php\nclass Post\n{\n    protected array \$fillable = ['a', 'b'];\n}\n";
        $base = "<?php\nclass Post\n{\n    protected array \$fillable = ['a'];\n}\n";
        $hunk = [
            'added' => [['line' => 4, 'text' => "    protected array \$fillable = ['a', 'b'];"]],
            'removed' => [['line' => 4, 'text' => "    protected array \$fillable = ['a'];"]],
        ];

        $result = new ImpactAnalyzer(new CodeGraph($edges, hasUnparseableFiles: false))->detectChanges([
            ChangedSymbols::classifyFile('app/Models/Post.php', $head, $base, $hunk),
        ]);

        $this->assertSame(0, $result['changed']['app/Models/Post.php']);
        $this->assertFalse($result['lowConfidence']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function raising_the_risk_thresholds_lets_a_large_repo_get_a_level_that_discriminates(): void
    {
        // On a big codebase the defaults report HIGH for everything, and a level that never varies
        // is one reviewers learn to skip. The same change reads HIGH by default and MEDIUM once the
        // repo sets a floor that matches its own scale.
        $edges = [];
        for ($i = 0; $i < 30; ++$i) {
            $edges[] = ['source' => "App\\Services\\Caller{$i}::run", 'target' => 'App\\Services\\Big::run', 'type' => 'call'];
        }

        $analyzer = new ImpactAnalyzer(new CodeGraph($edges, hasUnparseableFiles: false));
        $changed = [$this->changedMethod('app/Services/Big.php', 'App\\Services\\Big', 'run')];

        $this->assertSame(RiskLevel::High, $analyzer->detectChanges($changed)['risk']);

        config()->set('richter.risk_thresholds.high.impacted', 500);
        config()->set('richter.risk_thresholds.medium.impacted', 25);

        $this->assertSame(RiskLevel::Medium, $analyzer->detectChanges($changed)['risk']);
    }

    #[Test]
    public function a_risk_threshold_below_one_is_rejected_rather_than_making_every_diff_high(): void
    {
        config()->set('richter.risk_thresholds.high.impacted', 0);

        $this->expectException(InvalidArgumentException::class);

        new ImpactAnalyzer(new CodeGraph([], hasUnparseableFiles: false))->detectChanges([
            $this->changedMethod('app/Services/Big.php', 'App\\Services\\Big', 'run'),
        ]);
    }

    #[Test]
    public function an_entry_surface_reached_only_through_a_model_relation_is_not_a_caller(): void
    {
        // The shape that made a report name six admin screens as reached surfaces while the routes
        // that actually run the changed code reported no path at all. A Filament resource that
        // touches a related model is associated with the change, not a caller of it. It stays in the
        // report, in its own section, and out of the count that drives risk.
        $result = new ImpactAnalyzer(new CodeGraph([
            ['source' => Comment::class, 'target' => Post::class, 'type' => 'model-relationship'],
            ['source' => 'App\Filament\Resources\CommentResource', 'target' => Comment::class, 'type' => 'call'],
            ['source' => 'route::GET::/posts', 'target' => 'App\Services\Publisher::run', 'type' => 'route-to-controller'],
            ['source' => 'App\Services\Publisher::run', 'target' => Post::class, 'type' => 'call'],
        ], hasUnparseableFiles: false))->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertSame(['route::GET::/posts'], $result['entryPoints']);
        $this->assertSame(['App\Filament\Resources\CommentResource'], $result['associationEntryPoints']);
    }

    #[Test]
    public function the_coarse_cap_does_not_rescore_against_association_reach(): void
    {
        // 0.26.0 took association reach out of the entry-point count and left one path uncovered:
        // the cap's second scoring pass still walked relations. So screens that merely sit beside the
        // changed model could hold a coarse change at HIGH — the exact regression that split was for —
        // and the scored count could come out ABOVE the printed one, inverting what the report says
        // that number is.
        $edges = [
            // Nothing calls the changed method: the three routes reach it through Comment's relation.
            ['source' => Comment::class, 'target' => Review::class . '::publish', 'type' => 'model-relationship'],
        ];

        for ($i = 0; $i < 3; ++$i) {
            $edges[] = ['source' => "route::GET::/c{$i}", 'target' => Comment::class, 'type' => 'route-to-controller'];
        }

        // The co-touched $fillable is what makes the report coarse, and its downstream fan-out is what
        // rates the whole diff HIGH — so the cap is armed and its rescore decides the level.
        for ($i = 0; $i < 25; ++$i) {
            $edges[] = ['source' => Post::class, 'target' => "App\\Services\\S{$i}::run", 'type' => 'call'];
        }

        $result = new ImpactAnalyzer(new CodeGraph($edges, hasUnparseableFiles: false))->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
            $this->changedMethod('app/Models/Review.php', Review::class, 'publish'),
        ]);

        $this->assertSame([], $result['entryPoints']);
        $this->assertSame(0, $result['scoredEntryPoints']);
        // The rescore finds no caller, so the coarse HIGH is capped instead of being propped up.
        $this->assertSame(RiskLevel::Medium, $result['risk']);
        $this->assertTrue($result['coarseCapApplied']);
    }

    #[Test]
    public function the_scored_entry_point_count_never_exceeds_the_printed_list(): void
    {
        // Argued in 0.29.0's notes as holding "by construction"; asserted here instead, because an
        // invariant a release states and nothing checks is one a consumer gets to disprove — and one
        // has been reported, on a shape this codebase is not currently able to produce (see the probe
        // note in internal/). These pin the derivation; they do not prove it globally. Both the
        // paths that set the scored count are exercised: the plain one, and the low-confidence rescore
        // that runs on a narrower seed set. A report where the printed list ALSO grows afterwards (a
        // self-listed entry class) is the case that makes the two counts diverge the other way.
        $edges = [
            ['source' => 'route::GET::/posts', 'target' => 'App\Services\Publisher::run', 'type' => 'route-to-controller'],
            ['source' => 'App\Services\Publisher::run', 'target' => Post::class, 'type' => 'call'],
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => Post::class, 'type' => 'call'],
        ];

        foreach ([
            [$this->changedMethod('app/Models/Post.php', Post::class, 'publish')],
            [$this->changedCoarse('app/Models/Post.php', Post::class)],
            [$this->changedCoarse('app/Models/Post.php', Post::class), $this->changedMethod('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob', 'handle')],
        ] as $changed) {
            $result = new ImpactAnalyzer(new CodeGraph($edges, hasUnparseableFiles: false))->detectChanges($changed);

            $this->assertLessThanOrEqual(
                count($result['entryPoints']),
                $result['scoredEntryPoints'],
                'scored counts describe the narrower set the level was measured on, so they can never exceed the printed list',
            );
        }
    }

    #[Test]
    public function an_over_approximated_call_edge_still_yields_a_real_entry_point(): void
    {
        // `override` and `config-registry` fan out, but the dispatch behind them is real, so a surface
        // behind one does run the changed code. Only relations are demoted, not everything that is
        // excluded from the risk count.
        $result = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Services\Base::run', 'target' => 'App\Services\Concrete::run', 'type' => 'override'],
            ['source' => 'route::GET::/run', 'target' => 'App\Services\Base::run', 'type' => 'route-to-controller'],
        ], hasUnparseableFiles: false))->detectChanges([
            $this->changedMethod('app/Services/Concrete.php', 'App\Services\Concrete', 'run'),
        ]);

        $this->assertSame(['route::GET::/run'], $result['entryPoints']);
        $this->assertSame([], $result['associationEntryPoints']);
    }

    #[Test]
    public function a_precise_high_impact_change_is_not_capped_by_an_unrelated_coarse_change(): void
    {
        // A genuine HIGH (25 routes reach the changed service) must survive even when the diff also
        // touches a $fillable elsewhere — the coarse cap only applies to coarse-driven HIGH.
        $edges = [['source' => Post::class, 'target' => Comment::class, 'type' => 'model-relationship']];
        for ($i = 0; $i < 25; ++$i) {
            $edges[] = ['source' => "route::GET::/r{$i}", 'target' => 'App\Services\Big::run', 'type' => 'route-to-controller'];
        }

        $result = new ImpactAnalyzer(new CodeGraph($edges, hasUnparseableFiles: false))->detectChanges([
            $this->changedMethod('app/Services/Big.php', 'App\Services\Big', 'run'),
            $this->changedCoarse('app/Models/Post.php', Post::class),
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
        ], hasUnparseableFiles: false));

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
        ], hasUnparseableFiles: false));

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
            ['source' => Post::class, 'target' => Comment::class, 'type' => 'model-relationship'],
            ['source' => Post::class, 'target' => Review::class, 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertSame(0, $result['impacted']);
        // Related models render as readable FQCNs.
        $this->assertContains(Comment::class, $result['relatedModels']);
        $this->assertContains(Review::class, $result['relatedModels']);
    }

    #[Test]
    public function a_model_reached_by_two_edges_collapses_to_one_label(): void
    {
        // Two relationship edges to the same model must not list it twice (which would inflate the count).
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Post::class, 'target' => Comment::class, 'type' => 'model-relationship'],
            ['source' => Post::class, 'target' => Comment::class, 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertSame([Comment::class], $result['relatedModels']);
    }

    #[Test]
    public function a_node_reachable_by_both_a_relation_and_a_call_edge_counts_toward_risk(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Post::class, 'target' => 'App\Services\X::run', 'type' => 'model-relationship'],
            ['source' => Post::class, 'target' => 'App\Services\X::run', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertSame(1, $result['impacted']);
        $this->assertSame([], $result['relatedModels']);
    }

    #[Test]
    public function detect_changes_returns_the_seed_set_and_the_reach_map(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        $this->assertSame(['App\Services\PostPublisher::publish'], $result['seeds']);
        // The reach map keys every node the change touches in either direction, carrying the SET of
        // edge types that reached it — this is what the report classifies nodes by.
        $this->assertSame(['action-to-event' => true], $result['reach']['App\Events\PostPublished']);
        $this->assertSame(['action-to-service' => true], $result['reach']['App\Http\Controllers\PostController::publish']);
        // A seed is not its own reach.
        $this->assertArrayNotHasKey('App\Services\PostPublisher::publish', $result['reach']);
    }

    #[Test]
    public function detect_changes_returns_the_merged_caller_and_dependency_edges(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
        ]);

        // One edge from each direction, both in graph orientation (caller is always the source).
        $this->assertContains([
            'source' => 'App\Http\Controllers\PostController::publish',
            'target' => 'App\Services\PostPublisher::publish',
            'via' => 'action-to-service',
            'depth' => 1,
        ], $result['edges']);
        $this->assertContains([
            'source' => 'App\Services\PostPublisher::publish',
            'target' => 'App\Events\PostPublished',
            'via' => 'action-to-event',
            'depth' => 1,
        ], $result['edges']);
    }

    #[Test]
    public function the_reach_map_classifies_a_relationship_only_node_outside_impact(): void
    {
        // The agreement invariant the HTML report rests on: classifying reach entries with the
        // analyzer's own risk predicate must reproduce `impacted` exactly. If it ever stops doing
        // so, the diagram's node count and the Impacted tile would silently disagree.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Post::class, 'target' => Comment::class, 'type' => 'model-relationship'],
            ['source' => Post::class, 'target' => 'App\Services\X::run', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $isRiskBearing = new ReflectionMethod(ImpactAnalyzer::class, 'isRiskBearing');
        $riskBearing = array_filter(
            $result['reach'],
            // Reflection hands back mixed; the predicate is documented to return bool, so compare
            // strictly rather than coercing — a non-bool return should fail this test, not pass it.
            static fn (array $types): bool => $isRiskBearing->invoke($analyzer, $types) === true,
        );

        $this->assertArrayHasKey(Comment::class, $result['reach']);
        $this->assertArrayNotHasKey(Comment::class, $riskBearing);
        $this->assertCount($result['impacted'], $riskBearing);
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
        ], hasUnparseableFiles: false));

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
        ], hasUnparseableFiles: false, hasUnresolvedDispatches: true);

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
        ], hasUnparseableFiles: false, hasUnresolvedDispatches: true);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedAdditive('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob'),
        ]);

        $this->assertSame('analyzed', $result['coverage']['app/Jobs/ImportJob.php']);
    }

    #[Test]
    public function a_new_job_file_reads_unresolved_when_dispatches_are_unfollowable(): void
    {
        // A new job IS seeded now, so the unfollowable-dispatch flip applies to it: its dispatchers
        // are genuinely unknown, which is the honest answer, not "analyzed".
        $graph = new CodeGraph([
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\X::run', 'type' => 'job'],
        ], hasUnparseableFiles: false, hasUnresolvedDispatches: true);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedNewFile('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob'),
        ]);

        $this->assertSame(['App\Jobs\ImportJob'], $result['entryPoints']);
        $this->assertSame('unresolved', $result['coverage']['app/Jobs/ImportJob.php']);
    }

    #[Test]
    public function a_changed_job_reads_unresolved_per_file_even_when_a_sibling_reaches_an_entry_point(): void
    {
        // The job reaches no entry point of its own; a sibling change does. The job must still read
        // UNRESOLVED (its own coverage), not be masked by the sibling's entry points.
        $graph = new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Services\X::run', 'type' => 'route-to-controller'],
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\Y::run', 'type' => 'job'],
        ], hasUnparseableFiles: false, hasUnresolvedDispatches: true);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedMethod('app/Services/X.php', 'App\Services\X', 'run'),
            $this->changedMethod('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob', 'handle'),
        ]);

        // The sibling's route plus the job's self-listing (its own seeds reached nothing).
        $this->assertSame([self::ROUTE, 'App\Jobs\ImportJob'], $result['entryPoints']);
        // Self-listed surfaces join the list *after* the level is scored, so the printed count is one
        // higher than the number the thresholds were compared against. Nothing about this report is
        // low-confidence — the two counts come apart here for a second, independent reason.
        $this->assertFalse($result['lowConfidence']);
        $this->assertSame(1, $result['scoredEntryPoints']);
        $this->assertSame('unresolved', $result['coverage']['app/Jobs/ImportJob.php']);
        $this->assertSame('analyzed', $result['coverage']['app/Services/X.php']);
    }

    #[Test]
    public function a_changed_job_with_all_dispatches_resolved_reads_as_genuinely_empty(): void
    {
        $graph = new CodeGraph([
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\X::run', 'type' => 'job'],
        ], hasUnparseableFiles: false);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedMethod('app/Jobs/ImportJob.php', 'App\Jobs\ImportJob', 'handle'),
        ]);

        $this->assertSame('analyzed', $result['coverage']['app/Jobs/ImportJob.php']);
    }

    #[Test]
    public function two_changed_job_files_with_identical_per_file_seeds_each_flip_independently(): void
    {
        // Both files change the same job member, so their per-file seed sets are identical
        // (`App\Jobs\ImportJob::handle`) — a memoized riskInputs() must still evaluate each
        // file's own coverage on its own key, never collapse the two.
        $graph = new CodeGraph([
            ['source' => 'App\Jobs\ImportJob::handle', 'target' => 'App\Services\X::run', 'type' => 'job'],
        ], hasUnparseableFiles: false, hasUnresolvedDispatches: true);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            $this->changedMethod('app/Jobs/ImportJobCopyOne.php', 'App\Jobs\ImportJob', 'handle'),
            $this->changedMethod('app/Jobs/ImportJobCopyTwo.php', 'App\Jobs\ImportJob', 'handle'),
        ]);

        $this->assertSame('unresolved', $result['coverage']['app/Jobs/ImportJobCopyOne.php']);
        $this->assertSame('unresolved', $result['coverage']['app/Jobs/ImportJobCopyTwo.php']);
        $this->assertSame(['App\Jobs\ImportJob'], $result['entryPoints']);
    }

    #[Test]
    public function riskinputs_returns_identical_tuples_for_identical_seed_sets(): void
    {
        // White-box check on the memoized method itself: the same seed set (same order, same
        // maxDepth) must yield the exact same tuple both times — the safety net the full suite
        // above already exercises through detectChanges().
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController::publish', 'type' => 'route-to-controller'],
        ], hasUnparseableFiles: false));

        $riskInputs = new ReflectionMethod($analyzer, 'riskInputs');
        $memo = [];
        $freshMemo = [];

        $first = $riskInputs->invokeArgs($analyzer, [['App\Http\Controllers\PostController::publish'], 6, &$memo]);
        // Cache hit on the shared memo…
        $memoized = $riskInputs->invokeArgs($analyzer, [['App\Http\Controllers\PostController::publish'], 6, &$memo]);
        // …compared against a genuinely fresh walk (separate empty memo), so a stale or corrupted
        // cached tuple cannot hide behind comparing the memo with itself.
        $fresh = $riskInputs->invokeArgs($analyzer, [['App\Http\Controllers\PostController::publish'], 6, &$freshMemo]);

        // Reflection erases riskInputs()'s real return type, so narrow it back explicitly rather
        // than indexing into `mixed`.
        $this->assertIsArray($first);
        $this->assertIsArray($memoized);
        $this->assertIsArray($fresh);
        $this->assertSame($fresh, $memoized);
        $this->assertSame($first, $memoized);
        $this->assertSame(1, $first[0]);
    }

    #[Test]
    public function symbol_lookup_does_not_over_match_sibling_classes(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => Post::class, 'target' => PostContainer::class, 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));

        // "App\Models\Post" must seed only Post, not the sibling "App\Models\PostContainer".
        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertSame(1, $result['changed']['app/Models/Post.php']);
    }

    #[Test]
    public function symbol_lookup_respects_identifier_boundaries_on_both_sides(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Models\SuperPost', 'target' => PostContainer::class, 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));

        // "Post" must match neither "SuperPost" (left) nor "PostContainer" (right).
        $result = $analyzer->impact('Post');

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
        ], hasUnparseableFiles: false));

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
            ['source' => Post::class, 'target' => Comment::class, 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));

        // A leading-backslash FQCN must resolve the same as one without it.
        $leadingBackslash = $this->nodes($analyzer->impact('\\' . Post::class)['dependencies']);

        $this->assertSame($this->nodes($analyzer->impact(Post::class)['dependencies']), $leadingBackslash);
        $this->assertContains(Comment::class, $leadingBackslash);
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
        $this->assertSame(Post::class, Fqcn::fromPath('app/Models/Post.php'));
        $this->assertSame('App\Jobs\Post\SomeImportJob', Fqcn::fromPath('app/Jobs/Post/SomeImportJob.php'));
        $this->assertSame(Post::class, Fqcn::fromPath('./app/Models/Post.php'));
    }

    #[Test]
    public function fqcn_does_not_force_non_app_paths_into_the_app_namespace(): void
    {
        // A path that merely contains "app/" deeper down is not App\...
        $this->assertSame('Bar', Fqcn::fromPath('packages/foo/app/Support/Bar.php'));
        $this->assertSame('helpers', Fqcn::fromPath('bootstrap/helpers.php'));
    }

    #[Test]
    public function a_changed_file_matches_its_fqcn_keyed_deep_call_node(): void
    {
        // A job appears as an FQCN-keyed deep-call node; a change to its file must resolve to it.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Jobs\Post\SomeImportJob::handle', 'target' => 'App\Services\Importer::run', 'type' => 'job'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Jobs/Post/SomeImportJob.php', 'App\Jobs\Post\SomeImportJob', 'handle'),
        ]);

        $this->assertSame(1, $result['changed']['app/Jobs/Post/SomeImportJob.php']);
        $this->assertContains('App\Services\Importer::run', $this->nodes($result['dependencies']));
    }

    #[Test]
    public function a_changed_member_reaches_its_class_callers_through_the_declares_edge(): void
    {
        // The join this lane exists for: callers reference the class node (`$user->can(Policy::X)`),
        // the changed method seeds its member node — the declares edge connects the two.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\UserController::destroy', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\UserController::destroy', 'target' => UserPolicy::class, 'type' => 'authorizes'],
            ['source' => UserPolicy::class, 'target' => 'App\Policies\UserPolicy::forceDelete', 'type' => 'declares'],
        ], hasUnparseableFiles: false));

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
            ['source' => Post::class, 'target' => Post::class . '::comments', 'type' => 'declares'],
            ['source' => Post::class, 'target' => Post::class . '::reviews', 'type' => 'declares'],
            ['source' => Post::class, 'target' => Post::class . '::publish', 'type' => 'declares'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/Post.php', Post::class),
        ]);

        $this->assertSame(0, $result['impacted']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function an_override_fan_out_is_reachability_not_risk(): void
    {
        // CHA links an abstract method to its concrete overrides for reachability, but the
        // over-approximated ancestor→concrete edge must not inflate risk — a change to a
        // widely-implemented interface method would otherwise saturate the impacted count via the
        // fan-out. Mirrors the model-relationship / declares / uses-trait exclusions (plan cha-risk).
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Reports\ReportExporter::body', 'target' => 'App\Reports\CsvExporter::body', 'type' => 'override'],
            ['source' => 'App\Reports\ReportExporter::body', 'target' => 'App\Reports\PdfExporter::body', 'type' => 'override'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Reports/ReportExporter.php', 'App\Reports\ReportExporter', 'body'),
        ]);

        $this->assertSame(0, $result['impacted']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_constant_change_seeds_its_readers_precisely_without_low_confidence(): void
    {
        // route → controller → withTax → (references-constant) Pricing::VAT. A change to VAT walks up
        // from the constant to its reader and on to the entry point — precise, no coarse fallback,
        // where a non-resolvable member would coarse-seed the whole class and flag low confidence.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'route::GET::/price', 'target' => 'App\Http\Controllers\PriceController::show', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\PriceController::show', 'target' => 'App\Money\Pricing::withTax', 'type' => 'call'],
            ['source' => 'App\Money\Pricing::withTax', 'target' => 'App\Money\Pricing::VAT', 'type' => 'references-constant'],
            ['source' => 'App\Money\Pricing', 'target' => 'App\Money\Pricing::VAT', 'type' => 'declares'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            new ChangedFileSymbols('app/Money/Pricing.php', 'App\Money\Pricing', [
                new MemberChange('VAT', MemberChange::KIND_CONSTANT, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false),
        ]);

        $this->assertFalse($result['lowConfidence'], 'a resolvable constant change must not be low-confidence');
        $this->assertContains('route::GET::/price', $result['entryPoints']);
        $this->assertSame('analyzed', $result['coverage']['app/Money/Pricing.php']);
    }

    #[Test]
    public function a_constant_change_with_no_graph_node_reads_unresolved_not_no_impact(): void
    {
        // A constant whose node the graph never captured (a dynamic/unresolvable read) must read
        // UNRESOLVED — never a falsely-reassuring "no impact" — even though it is now resolvable-kind.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Other::a', 'target' => 'App\Other::b', 'type' => 'call'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            new ChangedFileSymbols('app/Money/Ghost.php', 'App\Money\Ghost', [
                new MemberChange('MISSING', MemberChange::KIND_CONSTANT, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false),
        ]);

        $this->assertSame('unresolved', $result['coverage']['app/Money/Ghost.php']);
    }

    #[Test]
    public function a_changed_entry_point_class_with_no_callers_lists_itself_as_the_entry_surface(): void
    {
        // A vendor-fired listener has no app-side caller edge, but it still runs on every event —
        // "Entry points reached: 0" would under-communicate that.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Listeners\Saml\SamlLoginListener::handle', 'target' => 'App\Services\UserProvisioner::provision', 'type' => 'call'],
        ], hasUnparseableFiles: false));

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
        ], hasUnparseableFiles: false));

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
        ], hasUnparseableFiles: false));

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
            ['source' => self::ROUTE, 'target' => CategoryAuthenticate::class, 'type' => 'route-to-middleware'],
            ['source' => CategoryAuthenticate::class, 'target' => 'App\Http\Middleware\CategoryAuthenticate::handle', 'type' => 'declares'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Http/Middleware/CategoryAuthenticate.php', CategoryAuthenticate::class, 'handle'),
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
            ['source' => PostContainer::class, 'target' => Post::class, 'type' => 'model-relationship'],
            ['source' => PostContainer::class, 'target' => 'model::Post', 'type' => 'model-relationship'],
            ['source' => PostContainer::class, 'target' => 'model::Category', 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/PostContainer.php', PostContainer::class),
        ]);

        // `model::Post` collapses into the FQCN label; `model::Category` (no FQCN sibling) renders short.
        $this->assertSame([Post::class, 'Category'], $result['relatedModels']);
    }

    #[Test]
    public function an_ambiguous_short_model_label_is_kept_when_two_fqcns_share_its_basename(): void
    {
        // App\Models\Tag and App\Models\Category\Tag both exist — collapsing `model::Tag`
        // into either would silently claim the wrong model; keep the short label instead.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => PostContainer::class, 'target' => Tag::class, 'type' => 'model-relationship'],
            ['source' => PostContainer::class, 'target' => \App\Models\Category\Tag::class, 'type' => 'model-relationship'],
            ['source' => PostContainer::class, 'target' => 'model::Tag', 'type' => 'model-relationship'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Models/PostContainer.php', PostContainer::class),
        ]);

        $this->assertSame([\App\Models\Category\Tag::class, Tag::class, 'Tag'], $result['relatedModels']);
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
                findings: ["eager-load string 'commentsreviews' matches no relation"],
            ),
        ]);

        $this->assertSame(["app/Exports/X.php: eager-load string 'commentsreviews' matches no relation"], $result['findings']);
    }

    /** A graph wiring Post::reviews to a controller method that in turn references a resource. */
    private function payloadParityGraph(string $resourceFqcn, string $resourceFile): CodeGraph
    {
        return new CodeGraph([
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => Post::class . '::reviews', 'type' => 'loads-relation'],
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => $resourceFqcn, 'type' => 'resource'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            $resourceFqcn => ['file' => $resourceFile],
        ]);
    }

    /**
     * @param  list<string>  $fieldSet
     * @param  list<string>  $addedFields
     */
    private function changedPost(array $fieldSet, array $addedFields): ChangedFileSymbols
    {
        return new ChangedFileSymbols('app/Models/Post.php', Post::class, [], cosmeticOnly: false, modelFieldSet: $fieldSet, addedModelFields: $addedFields);
    }

    #[Test]
    public function payload_parity_flags_a_wired_mirror_resource_missing_an_added_field(): void
    {
        // Real fixture files, read from base_path() like production — {@see ReviewResource} mirrors
        // title/slug but not 'status'.
        $originalBasePath = base_path();
        app()->setBasePath(self::fixtureProjectPath());

        try {
            $analyzer = new ImpactAnalyzer($this->payloadParityGraph(
                'App\Http\Resources\Api\v2\Post\ReviewResource',
                'app/Http/Resources/Api/v2/Post/ReviewResource.php',
            ));

            $result = $analyzer->detectChanges([$this->changedPost(['title', 'slug', 'status'], ['status'])]);

            $this->assertCount(1, $result['findings']);
            $this->assertStringContainsString('status', $result['findings'][0]);
            $this->assertStringContainsString('ReviewResource.php', $result['findings'][0]);
            // No model-file prefix — the note names the resource, not app/Models/Post.php.
            $this->assertStringNotContainsString('app/Models/Post.php:', $result['findings'][0]);
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    #[Test]
    public function payload_parity_stays_silent_for_a_control_resource_that_does_not_mirror(): void
    {
        $originalBasePath = base_path();
        app()->setBasePath(self::fixtureProjectPath());

        try {
            // ReviewPlayerResource's toArray() is empty — shares nothing with Post's fields.
            $analyzer = new ImpactAnalyzer($this->payloadParityGraph(
                'App\Http\Resources\Api\v2\Post\ReviewPlayerResource',
                'app/Http/Resources/Api/v2/Post/ReviewPlayerResource.php',
            ));

            $result = $analyzer->detectChanges([$this->changedPost(['title', 'slug', 'status'], ['status'])]);

            $this->assertSame([], $result['findings']);
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    #[Test]
    public function payload_parity_is_suppressed_when_explicitly_disabled(): void
    {
        $originalBasePath = base_path();
        app()->setBasePath(self::fixtureProjectPath());

        try {
            $analyzer = new ImpactAnalyzer($this->payloadParityGraph(
                'App\Http\Resources\Api\v2\Post\ReviewResource',
                'app/Http/Resources/Api/v2/Post/ReviewResource.php',
            ));

            $enabled = $analyzer->detectChanges([$this->changedPost(['title', 'slug', 'status'], ['status'])]);
            $disabled = $analyzer->detectChanges([$this->changedPost(['title', 'slug', 'status'], ['status'])], payloadParityEnabled: false);

            $this->assertCount(1, $enabled['findings']);
            $this->assertSame([], $disabled['findings']);
            // Disabling the lane must never move anything but findings.
            $this->assertSame($enabled['risk'], $disabled['risk']);
            $this->assertSame($enabled['entryPoints'], $disabled['entryPoints']);
            $this->assertSame($enabled['impacted'], $disabled['impacted']);
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    #[Test]
    public function payload_parity_is_suppressed_via_config(): void
    {
        $originalBasePath = base_path();
        app()->setBasePath(self::fixtureProjectPath());
        config()->set('richter.payload_parity.enabled', false);

        try {
            $analyzer = new ImpactAnalyzer($this->payloadParityGraph(
                'App\Http\Resources\Api\v2\Post\ReviewResource',
                'app/Http/Resources/Api/v2/Post/ReviewResource.php',
            ));

            $result = $analyzer->detectChanges([$this->changedPost(['title', 'slug', 'status'], ['status'])]);

            $this->assertSame([], $result['findings']);
        } finally {
            app()->setBasePath($originalBasePath);
        }
    }

    #[Test]
    public function a_change_without_findings_yields_an_empty_findings_list(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Http/Controllers/PostController.php', 'App\Http\Controllers\PostController', 'publish'),
        ]);

        $this->assertSame([], $result['findings']);
    }

    /**
     * A frontend file whose endpoint references were pre-mapped to route node ids.
     *
     * @param  list<string>  $routeSeeds
     */
    private function changedFrontend(string $file, array $routeSeeds, bool $unresolved = false): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: $routeSeeds, unresolvedFrontendReferences: $unresolved);
    }

    #[Test]
    public function a_frontend_referenced_route_is_an_entry_point_but_never_a_risk_input(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedFrontend('resources/js/Pages/Posts.vue', [self::ROUTE]),
        ]);

        // The touched route is listed (with its annotations available to formatters)…
        $this->assertSame([self::ROUTE], $result['entryPoints']);
        $this->assertSame(['resources/js/Pages/Posts.vue' => 'analyzed'], $result['coverage']);
        $this->assertSame(['resources/js/Pages/Posts.vue' => 1], $result['changed']);
        // …but the backend behaviour behind it did not change: no walk, no risk.
        $this->assertSame(RiskLevel::Low, $result['risk']);
        $this->assertSame(0, $result['impacted']);
        $this->assertSame([], $result['callers']);
    }

    #[Test]
    public function a_frontend_route_already_reached_from_a_php_change_is_not_double_listed(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedMethod('app/Services/PostPublisher.php', 'App\Services\PostPublisher', 'publish'),
            $this->changedFrontend('resources/js/Pages/Posts.vue', [self::ROUTE]),
        ]);

        $this->assertSame([self::ROUTE], $result['entryPoints']);
        // Risk comes from the PHP lane alone — one reached entry point → MEDIUM, unchanged by the frontend file.
        $this->assertSame(RiskLevel::Medium, $result['risk']);
    }

    #[Test]
    public function an_unresolved_frontend_reference_reads_as_unresolved_coverage(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedFrontend('resources/js/Pages/Posts.vue', [self::ROUTE], unresolved: true),
        ]);

        // The mapped route still lists — partial context — but the file's coverage is honest.
        $this->assertSame([self::ROUTE], $result['entryPoints']);
        $this->assertSame(['resources/js/Pages/Posts.vue' => 'unresolved'], $result['coverage']);
    }

    #[Test]
    public function a_frontend_route_the_graph_does_not_know_reads_as_unresolved(): void
    {
        $result = $this->analyzer()->detectChanges([
            $this->changedFrontend('resources/js/Pages/Posts.vue', ['route::GET::/not-in-graph']),
        ]);

        $this->assertSame([], $result['entryPoints']);
        $this->assertSame(['resources/js/Pages/Posts.vue' => 'unresolved'], $result['coverage']);
    }

    #[Test]
    public function a_frontend_seed_matches_route_nodes_exactly_never_by_prefix(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'route::GET::/posts', 'target' => 'App\Http\Controllers\PostController::index', 'type' => 'route-to-action'],
            ['source' => 'route::GET::/posts/{post}', 'target' => 'App\Http\Controllers\PostController::show', 'type' => 'route-to-action'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedFrontend('resources/js/Pages/Index.vue', ['route::GET::/posts']),
        ]);

        $this->assertSame(['route::GET::/posts'], $result['entryPoints']);
    }

    #[Test]
    public function a_blade_views_inline_fetch_route_is_a_touched_entry_point_not_a_walk_seed(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController', 'type' => 'route-to-controller'],
            ['source' => 'route::GET::/errors/log', 'target' => 'App\Http\Controllers\ErrorController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\PostController::publish', 'target' => 'view::blade__posts.show', 'type' => 'action-to-view'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            new ChangedFileSymbols('resources/views/posts/show.blade.php', '', [], cosmeticOnly: false, directSeeds: ['view::blade__posts.show', 'route::GET::/errors/log']),
        ]);

        // The inline-fetch route lists as touched surface; the view node still walks normally.
        $this->assertContains('route::GET::/errors/log', $result['entryPoints']);
        $this->assertContains('App\Http\Controllers\PostController::publish', $this->nodes($result['callers']));
        // The fetched route contributed no callers walk of its own — it is annotation, not reach.
        $this->assertNotContains('App\Http\Controllers\ErrorController', $this->nodes($result['dependencies']));
    }

    #[Test]
    public function frontend_entry_points_carry_their_gate_and_location_annotations(): void
    {
        $analyzer = new ImpactAnalyzer(new CodeGraph(
            [['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController', 'type' => 'route-to-controller']],
            hasUnparseableFiles: false,
            hasUnresolvedDispatches: false,
            nodeMetadata: [self::ROUTE => ['file' => 'routes/web.php', 'line' => 12, 'gates' => ['interactive-post']]],
        ));

        $result = $analyzer->detectChanges([
            $this->changedFrontend('resources/js/Pages/Posts.vue', [self::ROUTE]),
        ]);

        $this->assertSame(['interactive-post'], $result['entryPointGates'][self::ROUTE] ?? null);
        $this->assertSame(['file' => 'routes/web.php', 'line' => 12], $result['entryPointLocations'][self::ROUTE] ?? null);
    }

    /**
     * The graph a legacy Console Kernel produces: Brain ids the schedule entry by a hash of its
     * target and frequency, so `Acme\Console\Kernel` matches no node and every other seeding lane
     * comes up empty. Only the file the node is pinned to connects the two.
     */
    private function scheduleAnalyzer(): ImpactAnalyzer
    {
        return new ImpactAnalyzer(new CodeGraph(
            [
                ['source' => 'schedule::7b1c', 'target' => 'command::reports:sync {--force}', 'type' => 'schedule-to-command'],
                ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController', 'type' => 'route-to-controller'],
            ],
            hasUnparseableFiles: false,
            hasUnresolvedDispatches: false,
            nodeMetadata: [
                'schedule::7b1c' => ['file' => 'app/Console/Kernel.php', 'line' => 31],
                'command::reports:sync {--force}' => ['file' => 'app/Console/Commands/SyncReports.php'],
                self::ROUTE => ['file' => 'routes/web.php'],
            ],
        ));
    }

    #[Test]
    public function a_governed_model_is_reach_but_not_impact_for_a_policy_change(): void
    {
        // Brain's `model-to-policy` (v2.4.0) says which policy governs a model. It is a
        // governs-relation, not a call: changing the policy leaves the model working, so the model
        // must not count toward the impacted total — the same treatment `model-relationship` gets,
        // and the reason the v2.4.0 bump does not quietly raise the risk of every policy edit.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\\Models\\Post', 'target' => 'App\\Policies\\PostPolicy', 'type' => 'model-to-policy'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Policies/PostPolicy.php', 'App\\Policies\\PostPolicy'),
        ]);

        $this->assertContains('App\\Models\\Post', $this->nodes($result['callers']));
        $this->assertSame(0, $result['impacted']);
    }

    #[Test]
    public function a_node_behind_a_governed_model_still_counts_on_its_own_edge(): void
    {
        // The limit every exclusion shares, pinned so nobody reads the one above as more than it is:
        // exclusion is judged per reached node from the edge it arrived by, so the controller here
        // counts through `action-to-model` even though the policy→model hop before it does not.
        $analyzer = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\\Models\\Post', 'target' => 'App\\Policies\\PostPolicy', 'type' => 'model-to-policy'],
            ['source' => 'App\\Http\\Controllers\\PostController::show', 'target' => 'App\\Models\\Post', 'type' => 'action-to-model'],
        ], hasUnparseableFiles: false));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Policies/PostPolicy.php', 'App\\Policies\\PostPolicy'),
        ]);

        $this->assertSame(1, $result['impacted']);
    }

    #[Test]
    public function a_file_matching_no_symbol_is_placed_by_the_nodes_it_defines_instead_of_reading_unresolved(): void
    {
        $result = $this->scheduleAnalyzer()->detectChanges([
            $this->changedCoarse('app/Console/Kernel.php', 'Acme\Console\Kernel'),
        ]);

        $this->assertSame('analyzed', $result['coverage']['app/Console/Kernel.php']);
        $this->assertSame(1, $result['changed']['app/Console/Kernel.php']);
    }

    #[Test]
    public function a_declared_surface_is_never_walked(): void
    {
        // The registry case: a `$commands` entry or a `schedule()` call declares a surface, it does
        // not call into it. Walking it rates a one-line registration edit by everything its
        // neighbours in the file reach — which is how a Console Kernel edit reported HIGH.
        $result = $this->scheduleAnalyzer()->detectChanges([
            $this->changedCoarse('app/Console/Kernel.php', 'Acme\Console\Kernel'),
        ]);

        $this->assertNotContains('command::reports:sync {--force}', $this->nodes($result['dependencies']));
        $this->assertSame(0, $result['impacted']);
        $this->assertSame(RiskLevel::Low, $result['risk']);
    }

    #[Test]
    public function a_new_file_that_declares_surfaces_does_not_read_as_referenced_by_nothing(): void
    {
        // A brand-new routes file declares its routes; saying "no traced edge reaches it" would be
        // false — the surfaces ARE the placement, they are simply not walked.
        $analyzer = new ImpactAnalyzer(new CodeGraph(
            [['source' => 'route::GET::/reports', 'target' => 'Acme\\Http\\Controllers\\ReportController', 'type' => 'route-to-controller']],
            hasUnparseableFiles: false,
            hasUnresolvedDispatches: false,
            nodeMetadata: ['route::GET::/reports' => ['file' => 'routes/reports.php']],
        ));

        $result = $analyzer->detectChanges([
            new ChangedFileSymbols('routes/reports.php', '', [], cosmeticOnly: false, isNewFile: true),
        ]);

        $this->assertSame([], $result['findings']);
        $this->assertSame('analyzed', $result['coverage']['routes/reports.php']);
    }

    #[Test]
    public function a_defined_node_that_is_not_a_surface_stays_a_walk_seed(): void
    {
        // The other half of the split, and the one that would regress silently. The changed file is
        // a legacy HTTP Kernel — its own FQCN matches no node, so the lane runs — and the node it
        // defines is a `middleware::`, which is code the change touches rather than a surface it
        // declares. Its reach is real reach and must still be walked.
        $analyzer = new ImpactAnalyzer(new CodeGraph(
            [['source' => 'middleware::Acme\\Http\\Middleware\\Throttle', 'target' => 'Acme\\Services\\RateLimiter', 'type' => 'service']],
            hasUnparseableFiles: false,
            hasUnresolvedDispatches: false,
            nodeMetadata: ['middleware::Acme\\Http\\Middleware\\Throttle' => ['file' => 'app/Http/Kernel.php']],
        ));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Http/Kernel.php', 'Acme\\Http\\Kernel'),
        ]);

        $this->assertContains('Acme\\Services\\RateLimiter', $this->nodes($result['dependencies']));
        $this->assertSame('analyzed', $result['coverage']['app/Http/Kernel.php']);
    }

    #[Test]
    public function a_registry_file_declaring_many_surfaces_does_not_reach_high(): void
    {
        // The consumer's exact shape at scale: one added line in a Console Kernel's $commands array,
        // ten commands and six schedules declared in the same file. Three entry points alone push
        // risk to HIGH, and `--fail-on=high` exits 1 — over an edit that cannot break any of them.
        $edges = [];
        $metadata = [];

        for ($i = 1; $i <= 16; ++$i) {
            $edges[] = ['source' => "command::app:task{$i}", 'target' => "Acme\\Console\\Commands\\Task{$i}::handle", 'type' => 'command-to-service'];
            $edges[] = ['source' => "Acme\\Console\\Commands\\Task{$i}::handle", 'target' => "Acme\\Services\\Worker{$i}", 'type' => 'service'];
            $metadata["command::app:task{$i}"] = ['file' => 'app/Console/Kernel.php'];
        }

        $analyzer = new ImpactAnalyzer(new CodeGraph($edges, hasUnparseableFiles: false, hasUnresolvedDispatches: false, nodeMetadata: $metadata));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Console/Kernel.php', 'Acme\\Console\\Kernel'),
        ]);

        $this->assertSame(RiskLevel::Low, $result['risk']);
        $this->assertSame(0, $result['impacted']);
        // The breadth is still reported — it is just breadth, not consequence.
        $this->assertCount(16, $result['entryPoints']);
        $this->assertSame('analyzed', $result['coverage']['app/Console/Kernel.php']);
    }

    #[Test]
    public function a_defined_entry_surface_is_listed_as_touched(): void
    {
        $result = $this->scheduleAnalyzer()->detectChanges([
            $this->changedCoarse('app/Console/Kernel.php', 'Acme\Console\Kernel'),
        ]);

        // The Kernel declares the schedule rather than calling into it, so it can never appear
        // among the callers walked from the seed — it has to be annotated, like a fetched route.
        $this->assertContains('schedule::7b1c', $result['entryPoints']);
    }

    #[Test]
    public function the_defined_node_lane_never_runs_for_a_file_that_already_resolves(): void
    {
        // The gate that keeps member-level precision intact: a one-method controller change must
        // not pick up the controller-level node its own file also defines.
        $analyzer = new ImpactAnalyzer(new CodeGraph(
            [
                ['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController', 'type' => 'route-to-controller'],
                ['source' => 'App\Http\Controllers\PostController', 'target' => 'App\Http\Controllers\PostController::publish', 'type' => 'controller-to-action'],
                // The sibling method the class node reaches but the changed method does not.
                ['source' => 'App\Http\Controllers\PostController', 'target' => 'App\Http\Controllers\PostController::draft', 'type' => 'controller-to-action'],
                ['source' => 'App\Http\Controllers\PostController::draft', 'target' => 'App\Services\Drafts', 'type' => 'action-to-service'],
            ],
            hasUnparseableFiles: false,
            hasUnresolvedDispatches: false,
            nodeMetadata: ['App\Http\Controllers\PostController' => ['file' => 'app/Http/Controllers/PostController.php']],
        ));

        $result = $analyzer->detectChanges([
            $this->changedMethod('app/Http/Controllers/PostController.php', 'App\Http\Controllers\PostController', 'publish'),
        ]);

        $this->assertNotContains('App\Services\Drafts', $this->nodes($result['dependencies']));
    }

    #[Test]
    public function an_edgeless_defined_node_leaves_the_file_unresolved(): void
    {
        // Metadata proves Brain saw a definition; it does not prove the graph can traverse it.
        // Seeding it anyway would turn "couldn't place this" into "placed, reaches nothing".
        $analyzer = new ImpactAnalyzer(new CodeGraph(
            [['source' => self::ROUTE, 'target' => 'App\Http\Controllers\PostController', 'type' => 'route-to-controller']],
            hasUnparseableFiles: false,
            hasUnresolvedDispatches: false,
            nodeMetadata: ['schedule::0d0d' => ['file' => 'app/Console/Kernel.php']],
        ));

        $result = $analyzer->detectChanges([
            $this->changedCoarse('app/Console/Kernel.php', 'Acme\Console\Kernel'),
        ]);

        $this->assertSame('unresolved', $result['coverage']['app/Console/Kernel.php']);
    }
}
