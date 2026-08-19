<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Foundation\Application;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\ProviderBindings;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\FacadeEdgeTracer;

/**
 * A call through an application facade lands on the facade's own member node, which the facade does
 * not declare. These cover the hop that carries it to the class the accessor names — and, just as
 * load-bearing, the accessor shapes that must draw nothing rather than guess.
 *
 * Runs against the `Acme` fixture rather than a synthetic namespace: the tracer decides facade-ness
 * with `is_subclass_of()` and checks the concrete with `method_exists()`, so the classes it names
 * have to be real and autoloadable.
 */
final class FacadeEdgeTracerTest extends TestCase
{
    private const string FACADE = 'Acme\\Facades\\Reports';

    private const string CONCRETE = 'Acme\\Support\\ReportBuilder';

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(__DIR__ . '/../Fixtures/acme-project');

        AppNamespace::flush();
    }

    #[Test]
    public function it_carries_a_facade_member_over_to_the_concrete(): void
    {
        $edges = $this->resolve(
            [$this->facade('return \\Acme\\Support\\ReportBuilder::class;')],
            [$this->staticCall(self::FACADE . '::assemble')],
        );

        $this->assertSame([[
            'source' => self::FACADE . '::assemble',
            'target' => self::CONCRETE . '::assemble',
            'type' => 'facade-resolves-to',
        ]], $edges);
    }

    #[Test]
    public function it_draws_nothing_for_a_container_key_no_provider_binds(): void
    {
        // A key the provider scan never saw resolves to nothing. Silence beats a guess: the wrong
        // concrete would send a reviewer to the wrong file.
        $edges = $this->resolve(
            [$this->facade("return 'reports';")],
            [$this->staticCall(self::FACADE . '::assemble')],
        );

        $this->assertSame([], $edges);
    }

    #[Test]
    public function it_carries_a_container_key_accessor_over_through_the_binding_map(): void
    {
        $edges = $this->resolve(
            [$this->facade("return 'reports';")],
            [$this->staticCall(self::FACADE . '::assemble')],
            ['reports' => self::CONCRETE],
        );

        $this->assertSame([[
            'source' => self::FACADE . '::assemble',
            'target' => self::CONCRETE . '::assemble',
            'type' => 'facade-resolves-to',
        ]], $edges);
    }

    #[Test]
    public function a_container_key_containing_a_backslash_is_looked_up_as_a_key(): void
    {
        // Not sniffed as a class name: the kind is recorded when the accessor is read, so a key that
        // looks class-like is still a key.
        $edges = $this->resolve(
            [$this->facade("return 'reports\\primary';")],
            [$this->staticCall(self::FACADE . '::assemble')],
            ['reports\\primary' => self::CONCRETE],
        );

        $this->assertSame([[
            'source' => self::FACADE . '::assemble',
            'target' => self::CONCRETE . '::assemble',
            'type' => 'facade-resolves-to',
        ]], $edges);
    }

    #[Test]
    public function an_accessor_naming_a_class_as_a_string_resolves_through_the_class_keyed_binding(): void
    {
        // `bind(Reports::class, ReportBuilder::class)` files the binding under the FQCN string, which
        // is exactly what this accessor returns.
        $edges = $this->resolve(
            [$this->facade("return 'Acme\\Contracts\\Reports';")],
            [$this->staticCall(self::FACADE . '::assemble')],
            ['Acme\\Contracts\\Reports' => self::CONCRETE],
        );

        $this->assertSame([[
            'source' => self::FACADE . '::assemble',
            'target' => self::CONCRETE . '::assemble',
            'type' => 'facade-resolves-to',
        ]], $edges);
    }

    #[Test]
    public function a_container_key_bound_to_a_vendor_class_draws_nothing(): void
    {
        $edges = $this->resolve(
            [$this->facade("return 'strings';")],
            [$this->staticCall(self::FACADE . '::title')],
            ['strings' => 'Illuminate\\Support\\Str'],
        );

        $this->assertSame([], $edges);
    }

    #[Test]
    public function a_container_key_whose_concrete_lacks_the_method_draws_nothing(): void
    {
        $edges = $this->resolve(
            [$this->facade("return 'reports';")],
            [$this->staticCall(self::FACADE . '::renderPdf')],
            ['reports' => self::CONCRETE],
        );

        $this->assertSame([], $edges);
    }

    #[Test]
    public function an_accessor_inherited_from_an_unscanned_base_draws_nothing(): void
    {
        // The parent chain stops at the first class richter did not collect, so a vendor base's
        // accessor is out of reach — and a key map cannot supply what was never read.
        $subclass = <<<'PHP'
            <?php
            namespace Acme\Facades;
            final class Reports extends \Illuminate\Support\Facades\Facade {}
            PHP;

        $edges = $this->resolve([$subclass], [$this->staticCall(self::FACADE . '::assemble')], ['reports' => self::CONCRETE]);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function it_finds_an_accessor_declared_on_an_app_side_base_facade(): void
    {
        $base = <<<'PHP'
            <?php
            namespace Acme\Facades;
            abstract class BaseReports extends \Illuminate\Support\Facades\Facade
            {
                protected static function getFacadeAccessor(): string
                {
                    return \Acme\Support\ReportBuilder::class;
                }
            }
            PHP;

        $subclass = <<<'PHP'
            <?php
            namespace Acme\Facades;
            final class Reports extends BaseReports {}
            PHP;

        $edges = $this->resolve([$base, $subclass], [$this->staticCall(self::FACADE . '::assemble')]);

        $this->assertSame([[
            'source' => self::FACADE . '::assemble',
            'target' => self::CONCRETE . '::assemble',
            'type' => 'facade-resolves-to',
        ]], $edges);
    }

    #[Test]
    public function it_draws_nothing_for_a_class_that_is_not_a_facade(): void
    {
        // The method name is not the test — a class can declare `getFacadeAccessor()` without being
        // a facade, and bridging its members would reroute calls that never went through one. The
        // accessor here names a class that really does have `assemble`, so only the `is_subclass_of`
        // gate can be what stops the edge.
        $notAFacade = <<<'PHP'
            <?php
            namespace Acme\Support;
            final class ClientFactory
            {
                protected static function getFacadeAccessor(): string
                {
                    return \Acme\Support\ReportBuilder::class;
                }
            }
            PHP;

        $edges = $this->resolve([$notAFacade], [$this->staticCall('Acme\\Support\\ClientFactory::assemble')]);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function it_draws_nothing_when_the_accessor_can_return_two_different_classes(): void
    {
        // The concrete is picked at runtime from state this cannot see. Taking the first return
        // would send the reader to one of two files with no hint the other exists.
        $edges = $this->resolve(
            [$this->facade('if ($x) { return \\Acme\\Support\\ReportBuilder::class; } return \\Acme\\Support\\ExportTarget::class;')],
            [$this->staticCall(self::FACADE . '::assemble')],
        );

        $this->assertSame([], $edges);
    }

    #[Test]
    public function it_draws_nothing_when_one_of_several_returns_is_not_resolvable(): void
    {
        // A resolvable sibling return must not speak for the branch that returns a container key.
        $edges = $this->resolve(
            [$this->facade("if (\$x) { return 'reports'; } return \\Acme\\Support\\ReportBuilder::class;")],
            [$this->staticCall(self::FACADE . '::assemble')],
        );

        $this->assertSame([], $edges);
    }

    #[Test]
    public function it_draws_nothing_when_the_accessor_names_a_vendor_class(): void
    {
        $edges = $this->resolve(
            [$this->facade('return \\Illuminate\\Support\\Str::class;')],
            [$this->staticCall(self::FACADE . '::title')],
        );

        $this->assertSame([], $edges);
    }

    #[Test]
    public function it_draws_nothing_when_the_concrete_has_no_such_method(): void
    {
        // A `__call`-backed facade method has no statically known target, and a member node for a
        // method the concrete does not have would be a phantom.
        $edges = $this->resolve(
            [$this->facade('return \\Acme\\Support\\ReportBuilder::class;')],
            [$this->staticCall(self::FACADE . '::renderPdf')],
        );

        $this->assertSame([], $edges);
    }

    #[Test]
    public function it_ignores_an_edge_that_is_not_a_static_call(): void
    {
        $edges = $this->resolve(
            [$this->facade('return \\Acme\\Support\\ReportBuilder::class;')],
            [['source' => 'Acme\\Support\\ClientFactory', 'target' => self::FACADE . '::assemble', 'type' => 'references']],
        );

        $this->assertSame([], $edges);
    }

    private function facade(string $accessorBody): string
    {
        return <<<PHP
            <?php
            namespace Acme\\Facades;
            final class Reports extends \\Illuminate\\Support\\Facades\\Facade
            {
                protected static function getFacadeAccessor(): string
                {
                    {$accessorBody}
                }
            }
            PHP;
    }

    /** @return array{source: string, target: string, type: string} */
    private function staticCall(string $target): array
    {
        return ['source' => 'Acme\\Support\\ClientFactory::create', 'target' => $target, 'type' => 'static-call'];
    }

    /**
     * @param  list<string>  $sources  the class-likes the consolidated pass would have collected
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, string>  $containerKeys  the map {@see ProviderBindings} would have built
     * @return list<array{source: string, target: string, type: string}>
     */
    private function resolve(array $sources, array $edges, array $containerKeys = []): array
    {
        $tracer = new FacadeEdgeTracer($containerKeys);

        foreach ($sources as $source) {
            $ast = AppFiles::parseResolved($source);
            $this->assertNotNull($ast);
            $tracer->collect(array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class)));
        }

        return $tracer->resolutionEdges($edges);
    }
}
