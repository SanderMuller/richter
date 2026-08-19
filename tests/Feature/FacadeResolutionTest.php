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
 * End-to-end over a call that goes through an application facade. The facade is an app class, so the
 * static-call edge lands on a member it does not declare and the class behind the accessor was
 * reachable from nothing — a change to it reported no callers while every call site sat in a parsed
 * file. Brain draws this same edge, but only inside a route-reached body.
 */
final class FacadeResolutionTest extends TestCase
{
    private const string MIDDLEWARE = 'Acme\\Http\\Middleware\\EnsureTokenIsValid';

    private const string CALLER = 'Acme\\Support\\ClientFactory';

    private const string FACADE = 'Acme\\Facades\\Reports';

    private const string CONCRETE = 'Acme\\Support\\ReportBuilder';

    private const string VALUE_OBJECT = 'Acme\\Support\\ExportTarget';

    private const string KEYED_FACADE = 'Acme\\Facades\\KeyedReports';

    private const string KEYED_CALLER = 'Acme\\Support\\KeyedClientFactory';

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
    public function the_class_behind_the_accessor_carries_the_facades_callers(): void
    {
        $callers = array_column(new ImpactAnalyzer($this->graph())->impact(self::CONCRETE . '::assemble')['callers'], 'node');

        $this->assertContains(self::FACADE . '::assemble', $callers);
        $this->assertContains(self::CALLER . '::create', $callers);
    }

    #[Test]
    public function the_chain_runs_from_the_concrete_all_the_way_to_the_entry_point(): void
    {
        // The finding that matters to a reviewer: changing the class the accessor names reaches the
        // middleware, through the facade and the static call in front of it.
        $callers = array_column(new ImpactAnalyzer($this->graph())->impact(self::CONCRETE)['callers'], 'node');

        $this->assertContains(self::MIDDLEWARE . '::handle', $callers);
    }

    #[Test]
    public function the_body_behind_the_facade_is_read_for_the_calls_it_makes(): void
    {
        // Asserted on the value object, not on the concrete: `ReportBuilder::assemble` can only reach
        // `ExportTarget` if the second-hop walk read its body, and it is a walk candidate only
        // because a `facade-resolves-to` edge put it there.
        $callers = array_column(new ImpactAnalyzer($this->graph())->impact(self::VALUE_OBJECT)['callers'], 'node');

        $this->assertContains(self::CONCRETE . '::assemble', $callers);
    }

    #[Test]
    public function a_container_key_accessor_carries_its_callers_through_the_provider_binding(): void
    {
        // `KeyedReports::getFacadeAccessor()` returns `'reports'`, which AppServiceProvider binds to
        // the concrete. Without the provider-binding map this call site reaches nothing.
        $callers = array_column(new ImpactAnalyzer($this->graph())->impact(self::CONCRETE . '::assemble')['callers'], 'node');

        $this->assertContains(self::KEYED_FACADE . '::assemble', $callers);
        $this->assertContains(self::KEYED_CALLER . '::create', $callers);
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
