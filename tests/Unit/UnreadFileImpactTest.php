<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\SelectionProvenance;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Analysis\UnreadFileImpact;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tests\TestCase;

final class UnreadFileImpactTest extends TestCase
{
    private const string POST_FACTORY = "<?php\nnamespace Database\\Factories;\nuse Illuminate\\Database\\Eloquent\\Factories\\Factory;\nfinal class PostFactory extends Factory\n{\n    public function definition(): array { return []; }\n}\n";

    /**
     * @param  array<string, string>  $tests  file => source, the `tests/` tree
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, string>  $sources  changed file => its source on disk
     * @param  list<string>  $files
     * @return array{selected: array<string, list<array{file: string, via: string}>>, vetoed: list<string>, readers: array<string, list<array{file: string, via: string}>>}
     */
    private function assess(array $files, array $tests = [], array $edges = [], array $sources = []): array
    {
        $index = new TestReferenceIndex();

        foreach ($tests as $file => $source) {
            $index->addSource($source, $file);
        }

        return UnreadFileImpact::assess(
            $files,
            static fn (): TestReferenceIndex => $index,
            static fn (): CodeGraph => new CodeGraph($edges, hasUnparseableFiles: false),
            static fn (string $file): ?string => $sources[$file] ?? null,
        );
    }

    /** @return Iterator<string, array{string, bool}> */
    public static function filesAndWhetherTheyNeedAnAnswer(): Iterator
    {
        yield 'prose' => ['README.md', false];
        yield 'prose in a subdirectory' => ['docs/guide/intro.md', false];
        yield 'a CI workflow' => ['.github/workflows/tests.yml', false];
        yield 'a static-analysis baseline' => ['phpstan-baseline.neon', false];
        yield 'a frontend lock file' => ['yarn.lock', false];
        yield 'a frontend asset' => ['resources/js/app.js', false];
        yield 'a changed test, selected elsewhere' => ['tests/Feature/PostTest.php', false];
        yield 'a changed frontend spec, selected elsewhere' => ['resources/js/__tests__/format.test.ts', false];
        yield 'a config file' => ['config/services.php', true];
        yield 'the PHP lock file' => ['composer.lock', true];
        yield 'the test runner config' => ['phpunit.xml', true];
        yield 'an environment file' => ['.env.testing', true];
        yield 'a language file' => ['lang/en/validation.php', true];
        yield 'a seeder' => ['database/seeders/PostSeeder.php', true];
        yield 'a fixture a test reads' => ['tests/Fixtures/orders.json', true];
        yield 'an expected-output file under tests' => ['tests/Fixtures/expected.md', true];
        yield 'prose an application can load' => ['resources/skills/orders/SKILL.md', true];
        yield 'the Vite manifest' => ['public/build/manifest.json', true];
        yield 'a support class under tests' => ['tests/Support/BuildsPosts.php', true];
        yield 'a model factory' => ['database/factories/PostFactory.php', true];
        yield 'PHP outside app' => ['helpers/strings.php', true];
        yield 'a path nothing describes' => ['tools/sync-orders.mjs', true];
    }

    #[Test]
    #[DataProvider('filesAndWhetherTheyNeedAnAnswer')]
    public function only_a_file_that_can_change_a_test_result_needs_an_answer(string $file, bool $needsAnswer): void
    {
        $this->assertSame($needsAnswer ? [$file] : [], UnreadFileImpact::candidates([$file], RichterConfig::inertTestPaths()));
    }

    #[Test]
    public function a_project_can_name_more_inert_paths_and_cannot_remove_a_built_in_one(): void
    {
        config()->set('richter.tests.inert_paths', ['tools/*']);

        $this->assertSame(
            ['config/services.php'],
            UnreadFileImpact::candidates(['tools/sync-orders.mjs', 'README.md', 'config/services.php'], RichterConfig::inertTestPaths()),
        );
    }

    #[Test]
    public function a_changed_support_trait_selects_the_tests_that_use_it(): void
    {
        $impact = $this->assess(['tests/Support/BuildsPosts.php'], [
            'tests/Support/BuildsPosts.php' => "<?php\nnamespace Tests\\Support;\ntrait BuildsPosts {}\n",
            'tests/Feature/PostTest.php' => "<?php\nnamespace Tests\\Feature;\nfinal class PostTest\n{\n    use \\Tests\\Support\\BuildsPosts;\n}\n",
            'tests/Feature/CommentTest.php' => "<?php\nnamespace Tests\\Feature;\nuse Tests\\Support\\BuildsPosts;\nfinal class CommentTest\n{\n    use BuildsPosts;\n}\n",
            'tests/Feature/OtherTest.php' => "<?php\nnamespace Tests\\Feature;\nfinal class OtherTest {}\n",
        ]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertEqualsCanonicalizing(['tests/Feature/PostTest.php', 'tests/Feature/CommentTest.php'], array_keys($impact['selected']));
        $this->assertSame([['file' => 'tests/Support/BuildsPosts.php', 'via' => 'support']], $impact['selected']['tests/Feature/PostTest.php']);
    }

    /**
     * The answers a support file gives that are NOT a mapping. Each one read as "these are its users"
     * would select too few tests and call the selection determined.
     *
     * @return Iterator<string, array{string, array<string, string>}>
     */
    public static function supportFilesThatCannotBeMapped(): Iterator
    {
        $user = "<?php\nnamespace Tests\\Feature;\nfinal class PostTest extends \\Tests\\TestCase {}\n";
        $base = "<?php\nnamespace Tests;\nabstract class TestCase {}\n";

        yield 'a file of helper functions declares no class' => ['tests/Support/helpers.php', [
            'tests/Support/helpers.php' => "<?php\nfunction makePost(): array { return []; }\n",
            'tests/Feature/PostTest.php' => "<?php\nit('works', fn () => makePost());\n",
        ]];
        yield 'the Pest bootstrap declares no class' => ['tests/Pest.php', [
            'tests/Pest.php' => "<?php\npest()->extend(Tests\\TestCase::class)->in('Feature');\n",
            'tests/TestCase.php' => $base,
        ]];
        yield 'a class nothing is seen to use' => ['tests/Support/OrderRequestFactory.php', [
            'tests/Support/OrderRequestFactory.php' => "<?php\nnamespace Tests\\Support;\nfinal class OrderRequestFactory {}\n",
            'tests/Feature/PostTest.php' => $user,
        ]];
        yield 'a base case bound by pest()->extend()->in()' => ['tests/TestCase.php', [
            'tests/TestCase.php' => $base,
            'tests/Pest.php' => "<?php\nuse Tests\\TestCase;\npest()->extend(TestCase::class)->in('Feature');\n",
            'tests/Unit/ExplicitTest.php' => "<?php\nnamespace Tests\\Unit;\nfinal class ExplicitTest extends \\Tests\\TestCase {}\n",
        ]];
        yield 'a base case bound without a directory' => ['tests/TestCase.php', [
            'tests/TestCase.php' => $base,
            'tests/Pest.php' => "<?php\npest()->extend(Tests\\TestCase::class);\n",
            'tests/Unit/ExplicitTest.php' => "<?php\nnamespace Tests\\Unit;\nfinal class ExplicitTest extends \\Tests\\TestCase {}\n",
        ]];
        yield 'a trait bound by ->use()' => ['tests/Support/BuildsPosts.php', [
            'tests/Support/BuildsPosts.php' => "<?php\nnamespace Tests\\Support;\ntrait BuildsPosts {}\n",
            'tests/Pest.php' => "<?php\npest()->extend(Tests\\TestCase::class)->use(Tests\\Support\\BuildsPosts::class)->in('Feature');\n",
            'tests/Unit/ExplicitTest.php' => "<?php\nnamespace Tests\\Unit;\nfinal class ExplicitTest\n{\n    use \\Tests\\Support\\BuildsPosts;\n}\n",
        ]];
        yield 'a base case bound by uses()->in()' => ['tests/TestCase.php', [
            'tests/TestCase.php' => $base,
            'tests/Pest.php' => "<?php\nuses(Tests\\TestCase::class)->in('Feature');\n",
            'tests/Unit/ExplicitTest.php' => "<?php\nnamespace Tests\\Unit;\nfinal class ExplicitTest extends \\Tests\\TestCase {}\n",
        ]];
        yield 'a trait the bound base case uses' => ['tests/Support/BuildsPosts.php', [
            'tests/Support/BuildsPosts.php' => "<?php\nnamespace Tests\\Support;\ntrait BuildsPosts {}\n",
            'tests/TestCase.php' => "<?php\nnamespace Tests;\nabstract class TestCase\n{\n    use Support\\BuildsPosts;\n}\n",
            'tests/Pest.php' => "<?php\npest()->extend(Tests\\TestCase::class)->in('Feature');\n",
        ]];
    }

    /** @param  array<string, string>  $tests */
    #[Test]
    #[DataProvider('supportFilesThatCannotBeMapped')]
    public function a_support_file_whose_users_cannot_be_named_withdraws_the_selection(string $file, array $tests): void
    {
        $impact = $this->assess([$file], $tests);

        $this->assertSame([$file], $impact['vetoed']);
        $this->assertSame([], $impact['selected']);
    }

    #[Test]
    public function a_changed_factory_selects_tests_naming_its_model_or_the_factory_class(): void
    {
        $impact = $this->assess(['database/factories/PostFactory.php'], [
            'tests/Feature/PostTest.php' => "<?php\nuse App\\Models\\Post;\n",
            'tests/Feature/FactoryOnlyTest.php' => "<?php\nuse Database\\Factories\\PostFactory;\nPostFactory::new()->create();\n",
            'tests/Feature/GroupedTest.php' => "<?php\nuse Database\\Factories\\{CommentFactory, PostFactory};\n",
            'tests/Feature/QualifiedTest.php' => "<?php\n\\Database\\Factories\\PostFactory::new();\n",
            'tests/Feature/UnrelatedTest.php' => "<?php\nuse App\\Models\\Tag;\n",
            'tests/Support/PostFixtures.php' => "<?php\nuse App\\Models\\Post;\n",
        ], sources: ['database/factories/PostFactory.php' => self::POST_FACTORY]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertEqualsCanonicalizing(
            ['tests/Feature/PostTest.php', 'tests/Feature/FactoryOnlyTest.php', 'tests/Feature/GroupedTest.php', 'tests/Feature/QualifiedTest.php'],
            array_keys($impact['selected']),
        );
        $this->assertSame([['file' => 'database/factories/PostFactory.php', 'via' => 'factory']], $impact['selected']['tests/Feature/PostTest.php']);
    }

    #[Test]
    public function a_support_trait_that_builds_the_model_stands_for_the_tests_that_use_it(): void
    {
        // The trait imports the model and its users do not, so the model's importers alone miss them.
        $impact = $this->assess(['database/factories/PostFactory.php'], [
            'tests/Support/BuildsPosts.php' => "<?php\nnamespace Tests\\Support;\nuse App\\Models\\Post;\ntrait BuildsPosts\n{\n    protected function post(): Post { return Post::factory()->create(); }\n}\n",
            'tests/Feature/UsesTraitTest.php' => "<?php\nnamespace Tests\\Feature;\nfinal class UsesTraitTest\n{\n    use \\Tests\\Support\\BuildsPosts;\n}\n",
        ], sources: ['database/factories/PostFactory.php' => self::POST_FACTORY]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertSame(['tests/Feature/UsesTraitTest.php'], array_keys($impact['selected']));
    }

    #[Test]
    public function a_changed_test_that_other_tests_extend_selects_them(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nnamespace Tests\\Feature;\nabstract class BaseApiTest {}\n", 'tests/Feature/BaseApiTest.php');
        $index->addSource("<?php\nnamespace Tests\\Feature;\nfinal class OrderApiTest extends BaseApiTest {}\n", 'tests/Feature/OrderApiTest.php');
        $index->addSource("<?php\nnamespace Tests\\Feature;\nfinal class PlainTest {}\n", 'tests/Feature/PlainTest.php');

        $impact = UnreadFileImpact::assess(
            [],
            static fn (): TestReferenceIndex => $index,
            static fn (): CodeGraph => new CodeGraph([], hasUnparseableFiles: false),
            static fn (string $file): ?string => null,
            changedTests: ['tests/Feature/BaseApiTest.php', 'tests/Feature/PlainTest.php'],
        );

        $this->assertSame([], $impact['vetoed']);
        $this->assertSame(['tests/Feature/OrderApiTest.php'], array_keys($impact['selected']));
    }

    #[Test]
    public function a_reader_the_diff_also_changed_is_seeded_whole(): void
    {
        // The diff changed one member, and the config read sits in another. Seeding only what the
        // diff changed would leave the reading member out.
        $graph = new CodeGraph(
            [['source' => 'route::GET::/quote', 'target' => 'App\Services\Pricing::quote', 'type' => 'call'], ['source' => 'route::GET::/label', 'target' => 'App\Services\Pricing::label', 'type' => 'call']],
            hasUnparseableFiles: false,
            nodeMetadata: ['App\Services\Pricing::quote' => ['file' => 'app/Services/Pricing.php'], 'App\Services\Pricing::label' => ['file' => 'app/Services/Pricing.php']],
        );
        $diff = new ChangedFileSymbols('app/Services/Pricing.php', 'App\Services\Pricing', [], cosmeticOnly: true, directSeeds: ['App\Services\Pricing::label']);
        $other = new ChangedFileSymbols('app/Models/Post.php', 'App\Models\Post', [], cosmeticOnly: false);

        $changes = UnreadFileImpact::readersAsChanges(
            ['selected' => [], 'vetoed' => [], 'readers' => ['app/Services/Pricing.php' => [['file' => 'config/plans.php', 'via' => 'config']]]],
            [$other, $diff],
            $graph,
        );

        $this->assertCount(2, $changes);
        $this->assertSame($other, $changes[0]);
        $this->assertSame('app/Services/Pricing.php', $changes[1]->file);
        $this->assertFalse($changes[1]->cosmeticOnly);
        $this->assertEqualsCanonicalizing(['App\Services\Pricing::label', 'App\Services\Pricing::quote'], $changes[1]->directSeeds);
    }

    #[Test]
    public function a_changed_factory_reaches_the_tests_of_a_model_whose_factory_builds_it(): void
    {
        // `CommentFactory` sets `'post_id' => Post::factory()`, so a test that creates a comment runs
        // the post factory and need not name a post anywhere.
        $impact = $this->assess(['database/factories/PostFactory.php'], [
            'tests/Feature/CommentTest.php' => "<?php\nuse App\\Models\\Comment;\n",
            'tests/Feature/CommentFactoryTest.php' => "<?php\nuse Database\\Factories\\CommentFactory;\n",
        ], [
            ['source' => 'App\Models\Comment::factory', 'target' => 'App\Models\Post::factory', 'type' => 'factory'],
        ], ['database/factories/PostFactory.php' => self::POST_FACTORY]);

        $this->assertSame([], $impact['vetoed']);
        $this->assertEqualsCanonicalizing(['tests/Feature/CommentTest.php', 'tests/Feature/CommentFactoryTest.php'], array_keys($impact['selected']));
    }

    #[Test]
    public function a_factory_no_test_reaches_adds_nothing_and_withdraws_nothing(): void
    {
        $impact = $this->assess(['database/factories/PostFactory.php'], [
            'tests/Feature/TagTest.php' => "<?php\nuse App\\Models\\Tag;\n",
        ], sources: ['database/factories/PostFactory.php' => self::POST_FACTORY]);

        $this->assertSame(UnreadFileImpact::none(), $impact);
    }

    /** @return Iterator<string, array{string|null, list<array{source: string, target: string, type: string}>}> */
    public static function factoriesThatCannotBeMapped(): Iterator
    {
        yield 'a deleted factory' => [null, []];
        yield 'a factory for a class that is no model with a factory' => ["<?php\nnamespace Database\\Factories;\nfinal class TagFactory {}\n", []];
        yield 'a shared trait under database/factories' => ["<?php\nnamespace Database\\Factories\\Concerns;\ntrait HasPublishedState {}\n", []];
        yield 'a factory outside the default namespace' => ["<?php\nnamespace Tests\\Factories;\nfinal class PostFactory {}\n", []];
        yield 'a factory application code builds models through' => [self::POST_FACTORY, [
            ['source' => 'App\Console\Commands\SeedDemo::handle', 'target' => 'App\Models\Post::factory', 'type' => 'static-call'],
        ]];
    }

    /** @param  list<array{source: string, target: string, type: string}>  $edges */
    #[Test]
    #[DataProvider('factoriesThatCannotBeMapped')]
    public function a_factory_that_cannot_be_tied_to_its_tests_withdraws_the_selection(?string $source, array $edges): void
    {
        $file = 'database/factories/PostFactory.php';
        $impact = $this->assess([$file], ['tests/Feature/PostTest.php' => "<?php\nuse App\\Models\\Post;\n"], $edges, $source === null ? [] : [$file => $source]);

        $this->assertSame([$file], $impact['vetoed']);
        $this->assertSame([], $impact['selected']);
    }

    #[Test]
    public function a_factory_names_its_model_through_the_model_property_too(): void
    {
        $source = "<?php\nnamespace Database\\Factories;\nuse App\\Models\\Post;\nfinal class ArticleFactory\n{\n    protected \$model = Post::class;\n}\n";

        $impact = $this->assess(['database/factories/ArticleFactory.php'], [
            'tests/Feature/PostTest.php' => "<?php\nuse App\\Models\\Post;\n",
        ], sources: ['database/factories/ArticleFactory.php' => $source]);

        $this->assertSame(['tests/Feature/PostTest.php'], array_keys($impact['selected']));
    }

    #[Test]
    public function a_file_with_no_mapping_withdraws_the_selection_whether_it_exists_or_not(): void
    {
        $impact = $this->assess(['config/services.php', 'composer.lock', 'tests/Fixtures/orders.json', 'helpers/strings.php']);

        $this->assertSame(['composer.lock', 'config/services.php', 'helpers/strings.php', 'tests/Fixtures/orders.json'], $impact['vetoed']);
    }

    #[Test]
    public function the_selection_adds_the_mapped_tests_and_names_the_files_that_withdraw_it(): void
    {
        $index = new TestReferenceIndex();
        $provenance = null;

        $selection = AffectedTests::select(
            ['coverage' => [], 'entryPoints' => [], 'lowConfidence' => false],
            [],
            $index,
            unresolvedDispatchSites: [],
            provenance: $provenance,
            unread: [
                'selected' => ['tests/Feature/PostTest.php' => [['file' => 'tests/Support/BuildsPosts.php', 'via' => 'support']]],
                'vetoed' => ['config/services.php', 'composer.lock'],
                'readers' => [],
            ],
        );

        $this->assertFalse($selection['determinable']);
        $this->assertSame(['changed file(s) no analysis reads can change test results: config/services.php, composer.lock'], $selection['reasons']);
        $this->assertSame(['tests/Feature/PostTest.php'], $selection['tests']);
        $this->assertInstanceOf(SelectionProvenance::class, $provenance);
        $this->assertSame(
            [['axis' => SelectionProvenance::AXIS_UNREAD_FILE, 'file' => 'tests/Support/BuildsPosts.php', 'via' => 'support']],
            $provenance->explain('tests/Feature/PostTest.php', determinable: false)['reasons'] ?? null,
        );
    }

    #[Test]
    public function mapped_tests_alone_leave_the_selection_determinable(): void
    {
        $selection = AffectedTests::select(
            ['coverage' => [], 'entryPoints' => [], 'lowConfidence' => false],
            [],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            unread: ['selected' => ['tests/Feature/PostTest.php' => [['file' => 'database/factories/PostFactory.php', 'via' => 'factory']]], 'vetoed' => [], 'readers' => []],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/PostTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_long_list_of_withdrawing_files_is_capped_in_the_reason(): void
    {
        $files = array_map(static fn (int $i): string => "config/file{$i}.php", range(1, 17));

        $selection = AffectedTests::select(
            ['coverage' => [], 'entryPoints' => [], 'lowConfidence' => false],
            [],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            unread: ['selected' => [], 'vetoed' => $files, 'readers' => []],
        );

        $this->assertStringContainsString('config/file15.php, … and 2 more', $selection['reasons'][0]);
        $this->assertStringNotContainsString('config/file16.php', $selection['reasons'][0]);
    }
}
