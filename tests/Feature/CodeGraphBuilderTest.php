<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Contracts\ThumbnailRenderer;
use App\Contracts\VideoPublisher;
use App\Contracts\VideoTranscoder;
use App\Events\VideoPublished;
use App\Http\Controllers\Video\QuestionController;
use App\Http\Middleware\Authenticate;
use App\Http\Resources\Api\v2\Video\QuestionPlayerResource;
use App\Http\Resources\Api\v2\Video\QuestionResource;
use App\Jobs\ProcessVideoJob;
use App\Listeners\SendVideoNotification;
use App\Models\Concerns\WithAudits;
use App\Models\Question;
use App\Models\Video;
use App\Policies\VideoPolicy;
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
    public function a_route_links_to_its_controller(): void
    {
        $this->assertSame([
            'route::GET::/videos/{video}/edit' => 'route-to-controller',
            'route::GET::/videos/{video}/questions' => 'route-to-controller',
        ], $this->directCallersOf(QuestionController::class));
    }

    #[Test]
    public function an_alias_registered_middleware_resolves_onto_its_fqcn(): void
    {
        // The route registers `->middleware('auth')`; the Kernel fixture aliases it. Without the
        // alias rewrite this edge would target the unjoinable `middleware::auth` node.
        $this->assertSame(['route::GET::/videos/{video}/edit' => 'route-to-middleware'], $this->directCallersOf(Authenticate::class));
    }

    #[Test]
    public function a_dispatched_job_links_back_to_its_dispatching_action(): void
    {
        $this->assertSame('action-to-job', $this->directCallersOf(ProcessVideoJob::class . '::handle')[QuestionController::class . '::show'] ?? null);
    }

    #[Test]
    public function a_listen_registered_listener_links_to_its_event(): void
    {
        $this->assertArrayHasKey(VideoPublished::class, $this->directCallersOf(SendVideoNotification::class . '::handle'));
    }

    #[Test]
    public function a_controller_action_links_its_resource_relation_and_view(): void
    {
        $show = $this->directDependenciesOf(QuestionController::class . '::show');

        $this->assertSame('resource', $show[QuestionResource::class] ?? null);
        $this->assertSame('loads-relation', $show[Video::class . '::questions'] ?? null);

        $edit = $this->directDependenciesOf(QuestionController::class . '::edit');

        $this->assertSame('authorizes', $edit[VideoPolicy::class] ?? null);
        $this->assertSame('action-to-view', $edit['view::blade__videos.show'] ?? null);
    }

    #[Test]
    public function a_nested_resource_links_to_the_resource_it_composes(): void
    {
        $this->assertSame('resource', $this->directDependenciesOf(QuestionResource::class . '::toArray')[QuestionPlayerResource::class] ?? null);
    }

    #[Test]
    public function a_blade_view_links_its_includes_and_the_policy_it_gates_on(): void
    {
        $view = $this->directDependenciesOf('view::blade__videos.show');

        $this->assertSame('view-to-view', $view['view::blade__videos.partials.header'] ?? null);
        $this->assertSame('authorizes', $view[VideoPolicy::class] ?? null);
    }

    #[Test]
    public function a_container_binding_links_the_contract_to_its_concrete(): void
    {
        // AppServiceProvider::register() binds the contract via `$this->app->bind(...)`. The
        // provider opens with declare(strict_types=1) — the case Brain's analyzer silently skips.
        $this->assertSame('binding', $this->directDependenciesOf(VideoTranscoder::class)[FfmpegTranscoder::class] ?? null);
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
        $this->assertSame([Question::class => 'uses-trait'], $this->directCallersOf(WithAudits::class));
    }

    #[Test]
    public function an_app_interface_links_back_to_its_implementors(): void
    {
        // The edge runs implementor → interface, so a caller walk from the interface
        // surfaces the classes that implement it.
        $this->assertSame('implements', $this->directCallersOf(VideoPublisher::class)[YoutubePublisher::class] ?? null);
    }

    #[Test]
    public function impact_of_a_model_walks_up_to_the_route_that_reaches_it(): void
    {
        $result = new ImpactAnalyzer($this->graph())->impact(Video::class);

        $callers = array_column($result['callers'], 'node');

        $this->assertContains(QuestionController::class . '::show', $callers);
        $this->assertContains('route::GET::/videos/{video}/questions', $callers);
    }

    #[Test]
    public function the_entry_point_tracer_traces_without_retained_asts(): void
    {
        // trace() without the builder's retained-AST map — methodsOf() and eventListenerEdges()
        // must fall back to their own parses, not silently lose edges. Pins the job-method edge
        // (only reachable when methodsOf() lists handle()) and the `$listen` event→listener edge.
        $edges = new EntryPointTracer()->trace(self::fixtureProjectPath());

        $this->assertContains(
            ['source' => ProcessVideoJob::class . '::handle', 'target' => Video::class . '::questions', 'type' => 'model'],
            $edges,
        );
        $this->assertContains(
            ['source' => VideoPublished::class, 'target' => SendVideoNotification::class . '::handle', 'type' => 'event-listener'],
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
