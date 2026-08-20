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
        // The whole point: the key is dynamic, the file is not. The edge says so in its type — the
        // read names no single class, so a surface behind it is context rather than a caller.
        $edges = $this->edges('return config("calculators.{$key}");');

        $this->assertSame([[
            'source' => self::CALLER . '::resolve',
            'target' => self::TARGET,
            'type' => 'config-registry-fanout',
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
            'type' => 'config-registry-fanout',
        ]], $this->edgesForSource($source));
    }

    #[Test]
    public function the_facade_method_name_is_matched_case_insensitively(): void
    {
        // PHP method names are case-insensitive, and the helper branch already lowercases.
        $this->assertCount(1, $this->edges("return \\Illuminate\\Support\\Facades\\Config::GET('calculators.basic');"));
    }

    #[Test]
    public function a_literal_key_whose_value_is_a_scalar_draws_nothing(): void
    {
        // The regression this lane shipped with. `config/settings.php` names an app class under one
        // key, so before the key was looked up, EVERY literal read of that file fanned out into it —
        // `config('app.timezone')` in a real application, drawing edges to whatever `config/app.php`
        // happened to name in its `aliases` map. A fully literal key is knowable, so nothing is
        // guessed: the value is a string, and a string names no class.
        $this->assertSame([], $this->edges("return config('settings.timezone');"));
    }

    #[Test]
    public function a_literal_key_whose_value_is_an_unresolvable_call_draws_nothing(): void
    {
        // `env('SETTINGS_DRIVER')` cannot be evaluated here, but it does not have to be: the key was
        // found, and nothing in its value names a class. That is a determined answer, not an unknown.
        $this->assertSame([], $this->edges("return config('settings.driver');"));
    }

    #[Test]
    public function a_class_named_as_an_env_default_is_still_the_configured_class(): void
    {
        // `env('X', Basic::class)` IS that class unless the environment overrides it, so the edge is
        // a true positive. A value is judged by whether it names a class, not by the expression it
        // happens to be wrapped in.
        $this->assertCount(1, $this->edges("return config('settings.fallback');"));
    }

    #[Test]
    public function a_literal_key_whose_value_is_a_class_draws_that_class(): void
    {
        $this->assertSame([[
            'source' => self::CALLER . '::resolve',
            'target' => self::TARGET,
            'type' => 'config-registry',
        ]], $this->edges("return config('settings.handler');"));
    }

    #[Test]
    public function a_literal_key_whose_value_is_an_array_of_classes_draws_them(): void
    {
        $this->assertCount(1, $this->edges("return config('settings.nested');"));
    }

    #[Test]
    public function a_key_absent_from_a_fully_literal_array_draws_nothing(): void
    {
        // Every key in that array is a plain string literal and nothing is spread in, so a miss is
        // genuinely a miss rather than a key this could not read.
        $this->assertSame([], $this->edges("return config('settings.absent');"));
    }

    #[Test]
    public function a_literal_key_into_a_file_this_cannot_walk_still_uses_the_whole_class_list(): void
    {
        // `config/calculators.php` builds its array in a loop and returns a variable, so no key can
        // be looked up. Over-approximating is the safe direction here: the lane adds reach, so
        // drawing nothing would be the under-report, and this is the shape the lane exists for.
        $this->assertCount(1, $this->edges("return config('calculators.whatever');"));
    }

    #[Test]
    public function a_literal_key_in_a_namespaced_config_file_is_still_looked_up(): void
    {
        // A `namespace` declaration wraps the file in one node, so the `return` is a level deeper.
        // Scanning only the top level made every lookup here read as unwalkable, which fell back to
        // the whole class list — silently restoring the fan-out the lookup exists to prevent, in
        // exactly the file shape this lane documents as its reason for resolving bare class names.
        $this->assertSame([], $this->edges("return config('namespaced.timezone');"));
        $this->assertCount(1, $this->edges("return config('namespaced.handler');"));
    }

    #[Test]
    public function a_repeated_key_resolves_to_the_value_php_would_keep(): void
    {
        // `['handler' => Basic::class, 'handler' => 'none']` is legal, and the array ends up holding
        // the second. Resolving to the first would answer with a value the application never sees.
        $this->assertSame([], $this->edges("return config('duplicated.handler');"));
    }

    #[Test]
    public function a_key_a_later_spread_could_overwrite_falls_back_to_the_whole_class_list(): void
    {
        // `['before' => Basic::class, ...$extra]` — the spread can set 'before' too, so the literal
        // value is not what the array necessarily holds. Answering with it would be confidently
        // wrong, which is worse here than over-approximating.
        $this->assertCount(1, $this->edges("return config('spread.before');"));
    }

    #[Test]
    public function a_key_positioned_after_every_spread_is_still_resolved(): void
    {
        // Nothing after it can overwrite it, so position is what decides — not the mere presence of
        // a spread somewhere in the array.
        $this->assertCount(1, $this->edges("return config('spread.after');"));
    }

    #[Test]
    public function a_key_absent_from_an_array_holding_a_spread_falls_back(): void
    {
        // Absent from the literals is not absent from the array: the spread may well supply it. Only
        // an array whose every entry is readable can turn a miss into a determined "draws nothing".
        $this->assertCount(1, $this->edges("return config('spread.missing');"));
    }

    #[Test]
    public function an_interpolated_key_into_a_walkable_file_still_uses_the_whole_class_list(): void
    {
        // `config/settings.php` IS walkable, but `"settings.{$key}"` names no key to walk to. The
        // completeness of the literal decides, not the readability of the file.
        $this->assertCount(1, $this->edges('return config("settings.{$key}");'));
    }

    #[Test]
    public function two_different_keys_in_one_file_are_both_answered(): void
    {
        // Deduping reads on the file name rather than the (file, key) pair would drop one of these,
        // and which one survived would depend on source order.
        $edges = $this->edges("return [config('settings.timezone'), config('settings.handler')];");

        $this->assertCount(1, $edges);
        $this->assertSame(self::TARGET, $edges[0]['target']);
    }

    #[Test]
    public function a_project_without_a_config_directory_yields_no_registries(): void
    {
        $this->assertSame([], new ConfigRegistryTracer($this->acmeProjectPath() . '/app')->registries());
    }

    #[Test]
    public function a_lookup_inside_an_anonymous_class_is_credited_to_the_method_that_builds_it(): void
    {
        // Same rule as the view lane: an anonymous class cannot be a source, and inventing
        // `CalculatorRegistry::pick` would name a member that does not exist. The builder owns it.
        $source = "<?php\nnamespace Acme\\Support;\n"
            . "class CalculatorRegistry { public function build(): object { return new class { public function pick(): ?string { return config('settings.handler'); } }; } }\n";

        $sources = array_column($this->edgesForSource($source), 'source');

        $this->assertSame(['Acme\\Support\\CalculatorRegistry::build'], $sources);
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

        return new ConfigRegistryTracer($this->acmeProjectPath())->edgesForClassLikes($classLikes);
    }

    private function acmeProjectPath(): string
    {
        return __DIR__ . '/../Fixtures/acme-project';
    }
}
