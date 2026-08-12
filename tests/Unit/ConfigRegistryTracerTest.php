<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Foundation\Application;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ConfigRegistryTracer;

/**
 * A class the app only ever reaches through `config('registry.key')` has no caller in the graph, so
 * a change to it reports zero entry points however central it is. These cover the link, and the
 * argument shapes that must draw nothing rather than guess at a file name.
 */
final class ConfigRegistryTracerTest extends TestCase
{
    private const string CALLER = 'Acme\\Support\\CalculatorRegistry';

    private const string TARGET = 'Acme\\Calculators\\Basic';

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->acmeProjectPath());

        AppNamespace::flush();
    }

    #[Test]
    public function it_reads_the_classes_a_config_file_names_through_that_file_own_namespace(): void
    {
        // `Basic::class` is written bare under `namespace Acme\Calculators;`. Without name resolution
        // it would read as the class `Basic`, which is nothing.
        $registries = new ConfigRegistryTracer($this->acmeProjectPath())->registries();

        $this->assertSame([self::TARGET], $registries['calculators'] ?? []);
    }

    #[Test]
    public function a_config_file_naming_only_vendor_classes_is_not_a_registry(): void
    {
        // `config/plain.php` names `Illuminate\\Support\\Str::class`. Every other tracer is app-scoped
        // and this one has more reason than most: a vendor class is reached from everywhere, so
        // linking one would attach the whole framework to any method that reads a config value.
        $registries = new ConfigRegistryTracer($this->acmeProjectPath())->registries();

        $this->assertArrayNotHasKey('plain', $registries);
    }

    #[Test]
    public function an_interpolated_key_still_names_its_file(): void
    {
        // The whole point: the key is dynamic, the file is not.
        $edges = $this->edges('return config("calculators.{$key}");');

        $this->assertSame([[
            'source' => self::CALLER . '::resolve',
            'target' => self::TARGET,
            'type' => 'config-registry',
        ]], $edges);
    }

    #[Test]
    public function a_fully_static_key_links_the_same_way(): void
    {
        $this->assertCount(1, $this->edges("return config('calculators.basic');"));
    }

    #[Test]
    public function the_config_facade_form_is_recognised(): void
    {
        $this->assertCount(1, $this->edges("return \\Illuminate\\Support\\Facades\\Config::get('calculators.basic');"));
    }

    #[Test]
    public function a_fully_dynamic_argument_draws_nothing(): void
    {
        // `config($key)` names no file, and guessing one would point the reader at a whole subsystem
        // that has nothing to do with the change.
        $this->assertSame([], $this->edges('return config($key);'));
    }

    #[Test]
    public function a_lookup_into_a_config_file_that_names_no_app_class_draws_nothing(): void
    {
        $this->assertSame([], $this->edges("return config('app.timezone');"));
    }

    #[Test]
    public function a_lookup_in_a_second_class_in_the_file_is_attributed_to_that_class(): void
    {
        // The flat method bucket would hang the whole registry off the first class in the file, and
        // the report would then name a caller that never reads the config at all.
        $source = "<?php\nnamespace Acme\\Support;\n"
            . "class CalculatorRegistry { public function resolve(): void {} }\n"
            . "class SecondaryRegistry { public function pick(): ?string { return config('calculators.basic'); } }\n";

        $this->assertSame([[
            'source' => 'Acme\\Support\\SecondaryRegistry::pick',
            'target' => self::TARGET,
            'type' => 'config-registry',
        ]], $this->edgesForSource($source));
    }

    #[Test]
    public function the_facade_method_name_is_matched_case_insensitively(): void
    {
        // PHP method names are case-insensitive, and the helper branch already lowercases.
        $this->assertCount(1, $this->edges("return \\Illuminate\\Support\\Facades\\Config::GET('calculators.basic');"));
    }

    #[Test]
    public function a_project_without_a_config_directory_yields_no_registries(): void
    {
        $this->assertSame([], new ConfigRegistryTracer($this->acmeProjectPath() . '/app')->registries());
    }

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body): array
    {
        return $this->edgesForSource("<?php\nnamespace Acme\\Support;\nclass CalculatorRegistry\n{\n    public function resolve(string \$key): ?string\n    {\n        {$body}\n    }\n}\n");
    }

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edgesForSource(string $source): array
    {
        $ast = AppFiles::parseResolved($source);
        $this->assertNotNull($ast);

        /** @var list<ClassLike> $classLikes */
        $classLikes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));

        return new ConfigRegistryTracer($this->acmeProjectPath())->edgesForClassLikes($classLikes, self::CALLER);
    }

    private function acmeProjectPath(): string
    {
        return __DIR__ . '/../Fixtures/acme-project';
    }
}
