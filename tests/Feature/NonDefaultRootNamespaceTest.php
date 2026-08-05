<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tests\TestCase;

/**
 * End-to-end over a fixture app whose only PSR-4 root for `app/` is `Acme\`, the layout that used to
 * degrade to "no graph nodes matched" for every symbol: path → FQCN produced `App\…` names no class
 * in the app carried, so the source tracer found nothing to trace and every reachability gate
 * rejected what it did find. The middleware here is reached only through the source tracer
 * (`Http/Middleware` is an `entry_point_roots` default), which is exactly the surface the consumer
 * report saw come back empty.
 */
final class NonDefaultRootNamespaceTest extends TestCase
{
    private const string MIDDLEWARE = 'Acme\\Http\\Middleware\\EnsureTokenIsValid';

    private const string SERVICE = 'Acme\\Services\\TokenInspector';

    private static ?CodeGraph $graph = null;

    protected function setUp(): void
    {
        parent::setUp();

        // base_path() is where the root namespace is read from, so point it at the fixture app too —
        // in a real consumer the traced project and the host app are the same directory.
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->acmeProjectPath());

        AppNamespace::flush();
    }

    #[Test]
    public function it_derives_the_fixture_apps_root_namespace(): void
    {
        $this->assertSame('Acme\\', AppNamespace::root());
        $this->assertNull(AppNamespace::unmatchedRootNote());
    }

    #[Test]
    public function the_source_tracer_traces_a_middleware_under_the_derived_root(): void
    {
        $callers = new ImpactAnalyzer($this->graph())->impact(self::SERVICE)['callers'];

        $this->assertContains(self::MIDDLEWARE . '::handle', array_column($callers, 'node'));
    }

    #[Test]
    public function a_middleware_under_the_derived_root_resolves_to_graph_nodes(): void
    {
        $result = new ImpactAnalyzer($this->graph())->impact(self::MIDDLEWARE);

        $this->assertNotSame([], $result['dependencies']);
        $this->assertContains(self::SERVICE . '::inspect', array_column($result['dependencies'], 'node'));
    }

    #[Test]
    public function a_lookup_under_the_wrong_root_namespace_points_at_the_real_node(): void
    {
        // The whole consumer investigation in one line: a symbol looked up under `App\` in an
        // `Acme\`-rooted app matched nothing, and the message gave no thread to pull.
        $result = new ImpactAnalyzer($this->graph())->impact('App\\Services\\TokenInspector');

        $this->assertSame([], $result['callers']);
        $this->assertContains(self::SERVICE, $result['suggestions']);
        $this->assertStringContainsString('Nearest graph nodes: ' . self::SERVICE, ImpactFormatter::impact($result));
    }

    #[Test]
    public function a_traced_class_gets_its_file_from_the_derived_root(): void
    {
        $location = $this->graph()->locationOf(self::MIDDLEWARE);

        $this->assertNotNull($location);
        $this->assertSame('app/Http/Middleware/EnsureTokenIsValid.php', $location['file']);
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
