<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Contracts\PostPublisher;
use App\Contracts\PostTranscoder;
use App\Contracts\ThumbnailRenderer;
use App\Events\PostPublished;
use App\Http\Controllers\Post\ReviewController;
use App\Http\Middleware\Authenticate;
use App\Http\Resources\Api\v2\Post\ReviewPlayerResource;
use App\Http\Resources\Api\v2\Post\ReviewResource;
use App\Jobs\ProcessPostJob;
use App\Listeners\SendPostNotification;
use App\Models\Concerns\WithAudits;
use App\Models\Post;
use App\Models\Review;
use App\Policies\PostPolicy;
use App\Services\FfmpegTranscoder;
use App\Services\GdThumbnailRenderer;
use App\Services\YoutubePublisher;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\EntryPointTracer;

/**
 * End-to-end: builds the real graph (Laravel Brain analysis + every tracer) from the fixture
 * project in tests/Fixtures/project and asserts the edges each layer contributes.
 */
final class CodeGraphBuilderTest extends TestCase
{
    /** Built once per process — the build runs Brain plus every tracer over the fixture tree. */
    private static ?CodeGraph $graph = null;

    private function graph(): CodeGraph
    {
        return self::$graph ??= new CodeGraphBuilder()->build(self::fixtureProjectPath());
    }

    #[Test]
    public function a_filament_resource_traces_as_an_upstream_entry_surface_of_its_model(): void
    {
        // The fixture PostResource (app/Filament, a traced entry-point root) touches Post — a
        // change to the model must surface the resource as a caller, and the analyzer must report
        // it as a user-facing entry point even though no route:: node exists for it.
        $callers = new ImpactAnalyzer($this->graph())->impact(Post::class)['callers'];
        $callerNodes = array_column($callers, 'node');

        $this->assertContains('App\Filament\Resources\PostResource::table', $callerNodes);
    }

    #[Test]
    public function a_route_node_carries_its_defining_file_from_brain(): void
    {
        $location = $this->graph()->locationOf('route::GET::/posts/{post}/edit');

        $this->assertNotNull($location);
        $this->assertStringEndsWith('routes/web.php', $location['file']);
    }

    #[Test]
    public function a_class_only_reached_by_tracer_edges_gets_its_file_from_the_fqcn_fallback(): void
    {
        $location = $this->graph()->locationOf(Post::class);

        $this->assertNotNull($location);
        $this->assertSame('app/Models/Post.php', $location['file']);
    }

    #[Test]
    public function a_pennant_gated_route_carries_its_flags_through_the_full_build(): void
    {
        // End-to-end guard on the ordering constraint: gates are read from the raw
        // `middleware::features:…` edge BEFORE the alias rewrite strips the parameters — if that
        // order ever flips, this is the test that goes red.
        $this->assertSame(['interactive-post'], $this->graph()->gatesOf('route::GET::/posts/{post}/interactive'));
    }

    #[Test]
    public function a_route_node_carries_brains_security_surface(): void
    {
        // The fixture's edit route is guarded by the aliased `auth` middleware — whatever exposure
        // Brain assigns, the surface must be present and shaped.
        $security = $this->graph()->securityOf('route::GET::/posts/{post}/edit');

        $this->assertNotNull($security);
        $this->assertContains($security['exposure'], ['public', 'guest', 'authed', 'admin']);
    }

    #[Test]
    public function a_route_links_to_its_controller(): void
    {
        $this->assertSame([
            'route::GET::/posts/{post}/edit' => 'route-to-controller',
            'route::GET::/posts/{post}/reviews' => 'route-to-controller',
        ], $this->directCallersOf(ReviewController::class));
    }

    #[Test]
    public function an_alias_registered_middleware_resolves_onto_its_fqcn(): void
    {
        // The route registers `->middleware('auth')`; the Kernel fixture aliases it. Without the
        // alias rewrite this edge would target the unjoinable `middleware::auth` node.
        $this->assertSame(['route::GET::/posts/{post}/edit' => 'route-to-middleware'], $this->directCallersOf(Authenticate::class));
    }

    #[Test]
    public function a_dispatched_job_links_back_to_its_dispatching_action(): void
    {
        $this->assertSame('action-to-job', $this->directCallersOf(ProcessPostJob::class . '::handle')[ReviewController::class . '::show'] ?? null);
    }

    #[Test]
    public function a_listen_registered_listener_links_to_its_event(): void
    {
        $this->assertArrayHasKey(PostPublished::class, $this->directCallersOf(SendPostNotification::class . '::handle'));
    }

    #[Test]
    public function a_controller_action_links_its_resource_relation_and_view(): void
    {
        $show = $this->directDependenciesOf(ReviewController::class . '::show');

        $this->assertSame('resource', $show[ReviewResource::class] ?? null);
        $this->assertSame('loads-relation', $show[Post::class . '::reviews'] ?? null);

        $edit = $this->directDependenciesOf(ReviewController::class . '::edit');

        $this->assertSame('authorizes', $edit[PostPolicy::class] ?? null);
        $this->assertSame('action-to-view', $edit['view::blade__posts.show'] ?? null);
    }

    #[Test]
    public function a_nested_resource_links_to_the_resource_it_composes(): void
    {
        $this->assertSame('resource', $this->directDependenciesOf(ReviewResource::class . '::toArray')[ReviewPlayerResource::class] ?? null);
    }

    #[Test]
    public function a_blade_view_links_its_includes_and_the_policy_it_gates_on(): void
    {
        $view = $this->directDependenciesOf('view::blade__posts.show');

        $this->assertSame('view-to-view', $view['view::blade__posts.partials.header'] ?? null);
        $this->assertSame('authorizes', $view[PostPolicy::class] ?? null);
    }

    #[Test]
    public function a_container_binding_links_the_contract_to_its_concrete(): void
    {
        // AppServiceProvider::register() binds the contract via `$this->app->bind(...)`. The
        // provider opens with declare(strict_types=1) — the case Brain's analyzer silently skips.
        $this->assertSame('binding', $this->directDependenciesOf(PostTranscoder::class)[FfmpegTranscoder::class] ?? null);
    }

    #[Test]
    public function a_singletons_property_links_the_contract_to_its_concrete(): void
    {
        // Registered declaratively via AppServiceProvider's `$singletons` property, not register().
        $this->assertSame('binding', $this->directDependenciesOf(ThumbnailRenderer::class)[GdThumbnailRenderer::class] ?? null);
    }

    #[Test]
    public function a_trait_links_back_to_its_using_class(): void
    {
        $this->assertSame([Review::class => 'uses-trait'], $this->directCallersOf(WithAudits::class));
    }

    #[Test]
    public function an_app_interface_links_back_to_its_implementors(): void
    {
        // The edge runs implementor → interface, so a caller walk from the interface
        // surfaces the classes that implement it.
        $this->assertSame('implements', $this->directCallersOf(PostPublisher::class)[YoutubePublisher::class] ?? null);
    }

    #[Test]
    public function impact_of_a_model_walks_up_to_the_route_that_reaches_it(): void
    {
        $result = new ImpactAnalyzer($this->graph())->impact(Post::class);

        $callers = array_column($result['callers'], 'node');

        $this->assertContains(ReviewController::class . '::show', $callers);
        $this->assertContains('route::GET::/posts/{post}/reviews', $callers);
    }

    #[Test]
    public function the_entry_point_tracer_traces_without_retained_asts(): void
    {
        // trace() without the builder's retained-AST map — methodsOf() and eventListenerEdges()
        // must fall back to their own parses, not silently lose edges. Pins the job-method edge
        // (only reachable when methodsOf() lists handle()) and the `$listen` event→listener edge.
        $edges = new EntryPointTracer()->trace(self::fixtureProjectPath());

        $this->assertContains(
            ['source' => ProcessPostJob::class . '::handle', 'target' => Post::class . '::reviews', 'type' => 'model'],
            $edges,
        );
        $this->assertContains(
            ['source' => PostPublished::class, 'target' => SendPostNotification::class . '::handle', 'type' => 'event-listener'],
            $edges,
        );
    }

    #[Test]
    public function a_build_restores_the_host_apps_brain_config(): void
    {
        config()->set('laravel-brain.route_paths', ['host/sentinel/*.php']);

        new CodeGraphBuilder()->build(self::fixtureProjectPath());

        $this->assertSame(['host/sentinel/*.php'], config('laravel-brain.route_paths'));
    }

    #[Test]
    public function the_build_reports_its_phase_timings_through_the_progress_callback(): void
    {
        // Brain's own progress events flow through the same callback — filtering on the
        // richter:phase event name is what isolates the six phase-timing events from those.
        $events = [];

        new CodeGraphBuilder()->build(
            self::fixtureProjectPath(),
            function (string $event, array $data) use (&$events): void {
                if ($event === 'richter:phase') {
                    $events[] = $data;
                }
            },
        );

        $this->assertSame([
            'brain-analyze',
            'canonicalize-metadata',
            'consolidated-tracers',
            'entry-point-tracer',
            'blade-tracers',
            'rewrites-and-members',
        ], array_column($events, 'phase'));

        foreach ($events as $event) {
            $this->assertIsFloat($event['seconds']);
            $this->assertGreaterThanOrEqual(0.0, $event['seconds']);
        }
    }

    /** @return array<string, string> caller node → edge type, sorted by node */
    private function directCallersOf(string $node): array
    {
        return $this->hopsByNode($this->graph()->callersOf([$node], 1));
    }

    /** @return array<string, string> dependency node → edge type, sorted by node */
    private function directDependenciesOf(string $node): array
    {
        return $this->hopsByNode($this->graph()->dependenciesOf([$node], 1));
    }

    /**
     * @param  list<array{depth: int, node: string, via: string}>  $hops
     * @return array<string, string>
     */
    private function hopsByNode(array $hops): array
    {
        $byNode = [];

        foreach ($hops as $hop) {
            $byNode[$hop['node']] = $hop['via'];
        }

        ksort($byNode);

        return $byNode;
    }
}
