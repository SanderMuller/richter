<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\ProviderBindings;
use SanderMuller\Richter\Tests\TestCase;

/**
 * One walk of `app/Providers`, read as `binding` edges and as the container-key map a string facade
 * accessor resolves through. The edges are the shape the graph has always had; the map is the new
 * half, and most of what these cover is what it must refuse to answer.
 */
final class ProviderBindingsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-provider-bindings-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Providers", recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function a_string_key_binding_maps_the_key_to_its_concrete(): void
    {
        $this->provider("\$this->app->bind('reports', \\App\\Support\\ReportBuilder::class);");

        $this->assertSame(['reports' => 'App\\Support\\ReportBuilder'], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function a_string_key_binding_draws_no_edge(): void
    {
        // The alias rule stands: `'reports'` is a lookup value, never a graph node.
        $this->provider("\$this->app->bind('reports', \\App\\Support\\ReportBuilder::class);");

        $this->assertSame([], ProviderBindings::forProject($this->projectRoot)->edges);
    }

    #[Test]
    public function a_class_abstract_is_keyed_under_its_fqcn_as_well_as_drawn_as_an_edge(): void
    {
        // The container files a `::class` binding under the FQCN string, so an accessor returning
        // that spelling resolves through the same map.
        $this->provider('$this->app->singleton(\\App\\Contracts\\Reports::class, \\App\\Support\\ReportBuilder::class);');

        $bindings = ProviderBindings::forProject($this->projectRoot);

        $this->assertSame(['App\\Contracts\\Reports' => 'App\\Support\\ReportBuilder'], $bindings->keys);
        $this->assertSame(
            [['source' => 'App\\Contracts\\Reports', 'target' => 'App\\Support\\ReportBuilder', 'type' => 'binding']],
            $bindings->edges,
        );
    }

    #[Test]
    public function a_key_two_providers_disagree_on_is_dropped(): void
    {
        $this->provider("\$this->app->bind('reports', \\App\\Support\\ReportBuilder::class);", 'FirstServiceProvider');
        $this->provider("\$this->app->bind('reports', \\App\\Support\\ExportTarget::class);", 'SecondServiceProvider');

        $this->assertSame([], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function a_bind_if_followed_by_a_bind_is_dropped_rather_than_resolved_by_precedence(): void
    {
        // Deterministic at runtime, but modelling precedence puts richter one registration shape
        // away from naming the wrong file. An accepted false negative.
        $this->provider(
            "\$this->app->bindIf('reports', \\App\\Support\\ReportBuilder::class);\n"
            . "        \$this->app->bind('reports', \\App\\Support\\ExportTarget::class);",
        );

        $this->assertSame([], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function the_same_key_bound_twice_to_one_concrete_survives(): void
    {
        $this->provider("\$this->app->bind('reports', \\App\\Support\\ReportBuilder::class);", 'FirstServiceProvider');
        $this->provider("\$this->app->singleton('reports', \\App\\Support\\ReportBuilder::class);", 'SecondServiceProvider');

        $this->assertSame(['reports' => 'App\\Support\\ReportBuilder'], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function a_key_containing_a_backslash_is_kept_verbatim(): void
    {
        // A container key is an arbitrary string. A namespace-separator test would file this one as
        // a class name and lose it.
        $this->provider("\$this->app->bind('reports\\\\primary', \\App\\Support\\ReportBuilder::class);");

        $this->assertSame(['reports\\primary' => 'App\\Support\\ReportBuilder'], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function a_closure_concrete_contributes_nothing(): void
    {
        $this->provider("\$this->app->singleton('reports', fn () => new \\App\\Support\\ReportBuilder());");

        $bindings = ProviderBindings::forProject($this->projectRoot);

        $this->assertSame([], $bindings->keys);
        $this->assertSame([], $bindings->edges);
    }

    #[Test]
    public function an_alias_call_is_not_a_binding(): void
    {
        $this->provider("\$this->app->alias('reports', \\App\\Support\\ReportBuilder::class);");

        $this->assertSame([], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function a_contextual_binding_is_not_read(): void
    {
        $this->provider(
            '$this->app->when(\\App\\Support\\ReportBuilder::class)'
            . "->needs('reports')->give(\\App\\Support\\ExportTarget::class);",
        );

        $this->assertSame([], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function a_singletons_property_registers_both_products(): void
    {
        $provider = <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Providers;

            final class AppServiceProvider extends \Illuminate\Support\ServiceProvider
            {
                /** @var array<string, class-string> */
                public array $singletons = [
                    'reports' => \App\Support\ReportBuilder::class,
                    \App\Contracts\Exports::class => \App\Support\ExportTarget::class,
                ];
            }
            PHP;

        file_put_contents("{$this->projectRoot}/app/Providers/AppServiceProvider.php", $provider);

        $bindings = ProviderBindings::forProject($this->projectRoot);

        $this->assertSame([
            'reports' => 'App\\Support\\ReportBuilder',
            'App\\Contracts\\Exports' => 'App\\Support\\ExportTarget',
        ], $bindings->keys);
        $this->assertSame(
            [['source' => 'App\\Contracts\\Exports', 'target' => 'App\\Support\\ExportTarget', 'type' => 'binding']],
            $bindings->edges,
        );
    }

    #[Test]
    public function a_project_without_a_providers_directory_scans_to_nothing(): void
    {
        new Filesystem()->deleteDirectory("{$this->projectRoot}/app/Providers");

        $bindings = ProviderBindings::forProject($this->projectRoot);

        $this->assertSame([], $bindings->edges);
        $this->assertSame([], $bindings->keys);
    }

    #[Test]
    public function a_provider_outside_app_providers_is_not_scanned(): void
    {
        mkdir("{$this->projectRoot}/app/Support", recursive: true);
        file_put_contents(
            "{$this->projectRoot}/app/Support/LateServiceProvider.php",
            "<?php declare(strict_types=1);\n\nnamespace App\\Support;\n\nfinal class LateServiceProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n    public function register(): void\n    {\n        \$this->app->bind('reports', \\App\\Support\\ReportBuilder::class);\n    }\n}\n",
        );

        $this->assertSame([], ProviderBindings::forProject($this->projectRoot)->keys);
    }

    #[Test]
    public function the_none_scan_holds_nothing(): void
    {
        $this->assertSame([], ProviderBindings::none()->edges);
        $this->assertSame([], ProviderBindings::none()->keys);
    }

    private function provider(string $registerBody, string $class = 'AppServiceProvider'): void
    {
        $source = <<<PHP
            <?php declare(strict_types=1);

            namespace App\\Providers;

            final class {$class} extends \\Illuminate\\Support\\ServiceProvider
            {
                public function register(): void
                {
                    {$registerBody}
                }
            }
            PHP;

        file_put_contents("{$this->projectRoot}/app/Providers/{$class}.php", $source);
    }
}
