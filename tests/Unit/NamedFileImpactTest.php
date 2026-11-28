<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Analysis\UnreadFileImpact;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Changed files that are reached by name: a config file through its key, a seeder through its
 * class, any other file through the directory it is in. Over a temp project, because the answer is
 * who names the file.
 */
final class NamedFileImpactTest extends TestCase
{
    private const string READER = 'app/Services/Pricing.php';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = sys_get_temp_dir() . '/richter-named-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    /**
     * @param  list<string>  $changed
     * @param  array<string, string>  $project  path => source
     * @return array{selected: list<string>, vetoed: list<string>, readers: list<string>}
     */
    private function assess(array $changed, array $project): array
    {
        $index = new TestReferenceIndex();

        foreach ($project as $path => $source) {
            File::ensureDirectoryExists(dirname("{$this->projectRoot}/{$path}"));
            File::put("{$this->projectRoot}/{$path}", $source);

            if (str_starts_with($path, 'tests/')) {
                $index->addSource($source, $path);
            }
        }

        // Every app file of the project has one node, so a reader can be analysed.
        $nodes = [];

        foreach (array_keys($project) as $path) {
            if (str_starts_with($path, 'app/') && ! str_contains($path, 'Unplaced')) {
                $nodes['App\\' . str_replace('/', '\\', substr($path, 4, -4)) . '::handle'] = ['file' => $path];
            }
        }

        $graph = new CodeGraph(
            array_map(static fn (string $node): array => ['source' => 'route::GET::/x', 'target' => $node, 'type' => 'call'], array_keys($nodes)),
            hasUnparseableFiles: false,
            nodeMetadata: $nodes,
        );

        $impact = UnreadFileImpact::assess(
            $changed,
            static fn (): TestReferenceIndex => $index,
            static fn (): CodeGraph => $graph,
            static fn (string $file): ?string => null,
            $this->projectRoot,
        );

        return ['selected' => array_keys($impact['selected']), 'vetoed' => $impact['vetoed'], 'readers' => array_keys($impact['readers'])];
    }

    #[Test]
    public function a_config_file_the_application_owns_reaches_the_code_and_the_tests_that_name_its_key(): void
    {
        $impact = $this->assess(['config/plans.php'], [
            'config/plans.php' => "<?php\nreturn ['basic' => ['price' => 10]];\n",
            self::READER => "<?php\nnamespace App\\Services;\nfinal class Pricing\n{\n    public function handle(): int { return config('plans.basic.price'); }\n}\n",
            'app/Services/Typed.php' => "<?php\nnamespace App\\Services;\nfinal class Typed\n{\n    public function handle(): int { return config()->integer(\"plans.{\$this->plan}.price\"); }\n}\n",
            'app/Services/Other.php' => "<?php\nnamespace App\\Services;\nfinal class Other\n{\n    public function handle(): string { return config('planning.mode'); }\n}\n",
            'tests/Feature/PlanTest.php' => "<?php\nconfig(['plans.basic.price' => 5]);\n",
            'tests/Feature/WholeFileTest.php' => "<?php\n\$all = config('plans');\n",
            'tests/Feature/UnrelatedTest.php' => "<?php\nconfig(['planning.mode' => 'x']);\n",
        ]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertEqualsCanonicalizing(['tests/Feature/PlanTest.php', 'tests/Feature/WholeFileTest.php'], $impact['selected']);
        $this->assertEqualsCanonicalizing([self::READER, 'app/Services/Typed.php'], $impact['readers']);
    }

    #[Test]
    public function a_nested_config_file_is_read_by_its_dotted_key(): void
    {
        $impact = $this->assess(['config/sso/acme.php'], [
            self::READER => "<?php\nnamespace App\\Services;\nfinal class Pricing\n{\n    public function handle(): string { return config('sso.acme.entity'); }\n}\n",
        ]);

        $this->assertSame([self::READER], $impact['readers']);
    }

    /** @return Iterator<string, array{array<string, string>}> */
    public static function configFilesThatCannotBeMapped(): Iterator
    {
        $reader = "<?php\nnamespace App\\Services;\nfinal class Pricing\n{\n    public function handle(): int { return config('plans.basic'); }\n}\n";

        yield 'a package ships a config file of that name' => [[
            'vendor/acme/billing/config/plans.php' => "<?php\nreturn [];\n",
            self::READER => $reader,
        ]];
        yield 'nothing names the key' => [[
            self::READER => "<?php\nnamespace App\\Services;\nfinal class Pricing\n{\n    public function handle(): mixed { return config(\$this->key); }\n}\n",
        ]];
        yield 'a route file names the key' => [[
            self::READER => $reader,
            'routes/web.php' => "<?php\nRoute::get('/plans', fn () => config('plans.basic'));\n",
        ]];
        yield 'a view names the key' => [[
            self::READER => $reader,
            'resources/views/plans.blade.php' => "<p>{{ config('plans.basic') }}</p>\n",
        ]];
        yield 'another config file names the key' => [[
            self::READER => $reader,
            'config/billing.php' => "<?php\nreturn ['default' => config('plans.basic')];\n",
        ]];
        yield 'a test support file with no user names the key' => [[
            self::READER => $reader,
            'tests/Support/PlanHelpers.php' => "<?php\nfunction basicPlan(): array { return config('plans.basic'); }\n",
        ]];
        yield 'a reader the graph has no node for' => [[
            'app/Services/Unplaced.php' => $reader,
        ]];
    }

    /** @param  array<string, string>  $project */
    #[Test]
    #[DataProvider('configFilesThatCannotBeMapped')]
    public function a_config_file_whose_readers_cannot_all_be_named_withdraws_the_selection(array $project): void
    {
        $impact = $this->assess(['config/plans.php'], $project);

        $this->assertSame(['config/plans.php'], $impact['vetoed']);
        $this->assertSame([], $impact['selected']);
        $this->assertSame([], $impact['readers']);
    }

    #[Test]
    public function a_config_key_a_support_trait_names_reaches_the_tests_that_use_the_trait(): void
    {
        $impact = $this->assess(['config/plans.php'], [
            'tests/Support/UsesPlans.php' => "<?php\nnamespace Tests\\Support;\ntrait UsesPlans\n{\n    protected function basic(): array { return config('plans.basic'); }\n}\n",
            'tests/Feature/PlanTest.php' => "<?php\nnamespace Tests\\Feature;\nfinal class PlanTest\n{\n    use \\Tests\\Support\\UsesPlans;\n}\n",
        ]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertSame(['tests/Feature/PlanTest.php'], $impact['selected']);
    }

    #[Test]
    public function a_seeder_reaches_the_tests_and_the_code_that_name_it(): void
    {
        $impact = $this->assess(['database/seeders/PlanSeeder.php'], [
            'database/seeders/PlanSeeder.php' => "<?php\nnamespace Database\\Seeders;\nfinal class PlanSeeder {}\n",
            'tests/Feature/PlanTest.php' => "<?php\nuse Database\\Seeders\\PlanSeeder;\n\$this->seed(PlanSeeder::class);\n",
            'tests/Feature/OtherTest.php' => "<?php\n\$this->seed(\\Database\\Seeders\\TagSeeder::class);\n",
            self::READER => "<?php\nnamespace App\\Services;\nfinal class Pricing\n{\n    public function handle(): void { \\Artisan::call('db:seed', ['--class' => \\Database\\Seeders\\PlanSeeder::class]); }\n}\n",
        ]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertSame(['tests/Feature/PlanTest.php'], $impact['selected']);
        $this->assertSame([self::READER], $impact['readers']);
    }

    #[Test]
    public function a_seeder_the_default_seeder_calls_reaches_the_tests_that_seed_by_default(): void
    {
        $impact = $this->assess(['database/seeders/PlanSeeder.php'], [
            'database/seeders/PlanSeeder.php' => "<?php\nnamespace Database\\Seeders;\nfinal class PlanSeeder {}\n",
            'database/seeders/CatalogSeeder.php' => "<?php\nnamespace Database\\Seeders;\nfinal class CatalogSeeder\n{\n    public function run(): void { \$this->call(PlanSeeder::class); }\n}\n",
            'database/seeders/DatabaseSeeder.php' => "<?php\nnamespace Database\\Seeders;\nfinal class DatabaseSeeder\n{\n    public function run(): void { \$this->call([CatalogSeeder::class]); }\n}\n",
            'tests/Feature/SeededTest.php' => "<?php\nfinal class SeededTest\n{\n    protected \$seed = true;\n}\n",
            'tests/Feature/CallsSeedTest.php' => "<?php\n\$this->seed();\n",
            'tests/Feature/CatalogTest.php' => "<?php\n\$this->seed(\\Database\\Seeders\\CatalogSeeder::class);\n",
            'tests/Feature/PlainTest.php' => "<?php\n\$this->get('/');\n",
        ]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertEqualsCanonicalizing(['tests/Feature/SeededTest.php', 'tests/Feature/CallsSeedTest.php', 'tests/Feature/CatalogTest.php'], $impact['selected']);
    }

    #[Test]
    public function a_seeder_nothing_runs_adds_nothing_and_withdraws_nothing(): void
    {
        $impact = $this->assess(['database/seeders/DemoSeeder.php'], [
            'database/seeders/DemoSeeder.php' => "<?php\nnamespace Database\\Seeders;\nfinal class DemoSeeder {}\n",
            'tests/Feature/PlainTest.php' => "<?php\n\$this->get('/');\n",
        ]);

        $this->assertSame(['selected' => [], 'vetoed' => [], 'readers' => []], $impact);
    }

    /** @return Iterator<string, array{array<string, string>}> */
    public static function seedersThatCannotBeMapped(): Iterator
    {
        $default = "<?php\nnamespace Database\\Seeders;\nfinal class DatabaseSeeder\n{\n    public function run(): void { \$this->call(PlanSeeder::class); }\n}\n";

        yield 'a route file names it' => [['routes/console.php' => "<?php\nArtisan::command('demo', fn () => \$this->call(\\Database\\Seeders\\PlanSeeder::class));\n"]];
        yield 'a base test case seeds by default' => [[
            'database/seeders/DatabaseSeeder.php' => $default,
            'tests/TestCase.php' => "<?php\nnamespace Tests;\nabstract class TestCase\n{\n    protected \$seed = true;\n}\n",
        ]];
    }

    /** @param  array<string, string>  $project */
    #[Test]
    #[DataProvider('seedersThatCannotBeMapped')]
    public function a_seeder_whose_runs_cannot_all_be_named_withdraws_the_selection(array $project): void
    {
        $impact = $this->assess(['database/seeders/PlanSeeder.php'], $project + [
            'database/seeders/PlanSeeder.php' => "<?php\nnamespace Database\\Seeders;\nfinal class PlanSeeder {}\n",
        ]);

        $this->assertSame(['database/seeders/PlanSeeder.php'], $impact['vetoed']);
    }

    /** @return Iterator<string, array{string, array<string, string>, bool}> */
    public static function filesOutsideTheApplication(): Iterator
    {
        yield 'another service of a monorepo' => ['services/worker/main.go', [], false];
        yield 'a Dockerfile in the root' => ['Dockerfile', [], false];
        yield 'the example environment file' => ['.env.example', [], false];
        yield 'a tool config that is PHP' => ['ecs.php', [], false];
        yield 'a directory under resources nothing names' => ['resources/api-docs/openapi.json', [], false];
        yield 'a directory a test reads' => ['starter-kits/basic/kit.json', ['tests/Feature/KitTest.php' => "<?php\nfile_get_contents(base_path('starter-kits/basic/kit.json'));\n"], true];
        yield 'a directory application code reads' => ['stubs/report.stub', ['app/Services/Pricing.php' => "<?php\nnamespace App\\Services;\nfinal class Pricing\n{\n    public function handle(): string { return file_get_contents(base_path('stubs/report.stub')); }\n}\n"], true];
        yield 'a directory under resources that code names' => ['resources/api-docs/openapi.json', ['routes/web.php' => "<?php\nRoute::get('/docs', fn () => response()->file(resource_path('api-docs/openapi.json')));\n"], true];
        yield 'a root file code names' => ['VERSION', ['config/app.php' => "<?php\nreturn ['version' => trim(file_get_contents(base_path('VERSION')))];\n"], true];
        yield 'PHP under a Composer autoload path' => ['helpers/strings.php', ['composer.json' => '{"autoload": {"files": ["helpers/strings.php"], "psr-4": {"App\\\\": "app/"}}}'], true];
        yield 'PHP under a PSR-4 path outside app' => ['modules/Billing/Invoice.php', ['composer.json' => '{"autoload": {"psr-4": {"Modules\\\\": "modules/"}}}'], true];
        yield 'the Composer lock file' => ['composer.lock', [], true];
        yield 'the test runner config' => ['phpunit.xml', [], true];
        yield 'an environment file tests load' => ['.env.testing', [], true];
        yield 'a language file' => ['lang/nl.json', [], true];
        yield 'a config file that is not PHP' => ['config/routes.yaml', [], true];
        yield 'a bootstrap file' => ['bootstrap/providers.php', [], true];
        yield 'a schema dump' => ['database/schema/mysql-schema.sql', [], true];
    }

    /** @param  array<string, string>  $project */
    #[Test]
    #[DataProvider('filesOutsideTheApplication')]
    public function a_file_withdraws_the_selection_only_when_something_loads_or_names_it(string $file, array $project, bool $withdraws): void
    {
        $impact = $this->assess([$file], $project);

        $this->assertSame($withdraws ? [$file] : [], $impact['vetoed']);
        $this->assertSame([], $impact['selected']);
    }

    #[Test]
    public function a_reader_is_analysed_as_changed_and_its_tests_are_named_as_such(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\\Services\\Pricing;\n", 'tests/Unit/PricingTest.php');

        $provenance = null;

        $selection = AffectedTests::select(
            ['coverage' => [self::READER => 'analyzed'], 'entryPoints' => [], 'lowConfidence' => false],
            [new ChangedFileSymbols(self::READER, 'App\Services\Pricing', [], cosmeticOnly: false, directSeeds: ['App\Services\Pricing::handle'])],
            $index,
            unresolvedDispatchSites: [],
            provenance: $provenance,
            unread: ['selected' => [], 'vetoed' => [], 'readers' => [self::READER => [['file' => 'config/plans.php', 'via' => 'config']]]],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Unit/PricingTest.php'], $selection['tests']);
        $this->assertSame('reader', $provenance?->explain('tests/Unit/PricingTest.php', determinable: true)['reasons'][0]['origin'] ?? null);
    }
}
