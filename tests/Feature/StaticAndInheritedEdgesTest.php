<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tests\TestCase;

/**
 * End-to-end over a four-link chain both missing edge types used to hide: an entry-point class calls a
 * service, the service inherits the method that does the work, and that inherited method reaches its
 * collaborator through a static call. Two missing edge types broke it in two places, so each link had
 * to be restored for the chain to be walkable — which is why this is one test rather than two.
 */
final class StaticAndInheritedEdgesTest extends TestCase
{
    private const string MIDDLEWARE = 'Acme\\Http\\Middleware\\EnsureTokenIsValid';

    private const string SUBCLASS = 'Acme\\Services\\ReportMappingService';

    private const string PARENT = 'Acme\\Services\\ReportApiService';

    private const string FACTORY = 'Acme\\Support\\ClientFactory';

    private static ?CodeGraph $graph = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->acmeProjectPath());

        AppNamespace::flush();
    }

    #[Test]
    public function a_class_reached_only_through_a_static_call_is_in_the_graph(): void
    {
        // Brain draws no hop for `ClientFactory::create(...)`, so before the static-call tracer this
        // class had no node at all and `impact` answered "no graph nodes matched".
        $result = new ImpactAnalyzer($this->graph())->impact(self::FACTORY . '::create');

        $this->assertNotSame([], $result['callers'], 'the static callee should have callers');
        $this->assertContains(self::PARENT . '::build', array_column($result['callers'], 'node'));
    }

    #[Test]
    public function an_inherited_method_carries_its_subclass_callers_up_to_the_parent(): void
    {
        // Every real call lands on the subclass node; the parent declares the method. Without the
        // inherits edge the parent reports no callers at all.
        $callers = array_column(new ImpactAnalyzer($this->graph())->impact(self::PARENT . '::build')['callers'], 'node');

        $this->assertContains(self::SUBCLASS . '::build', $callers);
        $this->assertContains(self::MIDDLEWARE . '::handle', $callers);
    }

    #[Test]
    public function the_whole_chain_is_walkable_from_the_static_callee_to_the_entry_point(): void
    {
        // The finding that matters to a reviewer: changing the factory reaches the middleware, four
        // links away, through both new edge types.
        $callers = array_column(new ImpactAnalyzer($this->graph())->impact(self::FACTORY)['callers'], 'node');

        $this->assertContains(self::MIDDLEWARE . '::handle', $callers);
    }

    #[Test]
    public function the_static_callee_no_longer_reads_as_referenced_by_nothing(): void
    {
        // The inverse of the consumer's complaint: `detect-changes` said nothing referenced a class
        // two graphed callers call. Seeds resolving proves the finding can no longer fire for it.
        $this->assertNotSame([], $this->graph()->nodesContaining(self::FACTORY));
    }

    /** Built once per process: the build runs Brain plus every tracer over the fixture tree. */
    private function graph(): CodeGraph
    {
        return self::$graph ??= new CodeGraphBuilder()->build($this->acmeProjectPath());
    }

    private function acmeProjectPath(): string
    {
        return __DIR__ . '/../Fixtures/acme-project';
    }
}
