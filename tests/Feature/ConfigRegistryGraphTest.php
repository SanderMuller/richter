<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tests\TestCase;

/**
 * End-to-end over a class the application reaches only by looking it up in a config registry. Every
 * change to such a class reports zero entry points however central it is: nothing statically connects
 * `config("calculators.{$key}")` to the classes `config/calculators.php` names.
 */
final class ConfigRegistryGraphTest extends TestCase
{
    private const string REGISTRY = 'Acme\\Support\\CalculatorRegistry';

    private const string CALCULATOR = 'Acme\\Calculators\\Basic';

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
    public function a_registry_class_gains_the_caller_that_looks_it_up(): void
    {
        $callers = array_column(new ImpactAnalyzer($this->graph())->impact(self::CALCULATOR)['callers'], 'node');

        $this->assertContains(self::REGISTRY . '::resolve', $callers);
    }

    #[Test]
    public function the_lookup_reaches_the_class_downstream_too(): void
    {
        $reached = array_column(new ImpactAnalyzer($this->graph())->impact(self::REGISTRY . '::resolve')['dependencies'], 'node');

        $this->assertContains(self::CALCULATOR, $reached);
    }

    #[Test]
    public function the_fan_out_does_not_count_toward_risk(): void
    {
        // A lookup can return any class the registry names, so the edge is an over-approximation in
        // the same shape as `override`: it must carry reach without letting one edit to the resolver
        // saturate the level on breadth alone.
        $this->assertContains('config-registry', ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES);
    }

    #[Test]
    public function editing_a_registry_file_invalidates_the_graph_cache(): void
    {
        // The lane reads config/, which was not a build input before it existed. Without the
        // fingerprint covering it, adding a class to a registry would serve the previous graph and
        // the new class would keep reporting no callers — the stale answer this cache exists to
        // design out.
        $cache = $this->app?->make(GraphCache::class);
        $this->assertInstanceOf(GraphCache::class, $cache);

        $path = $this->acmeProjectPath() . '/config/calculators.php';
        $before = $cache->fingerprint($this->acmeProjectPath());
        $original = (string) file_get_contents($path);

        try {
            file_put_contents($path, $original . "\n// touched\n");
            clearstatcache();

            $this->assertNotSame($before, $cache->fingerprint($this->acmeProjectPath()));
        } finally {
            file_put_contents($path, $original);
        }
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
