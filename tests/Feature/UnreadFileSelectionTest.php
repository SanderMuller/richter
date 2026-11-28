<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\Support\FakesGit;
use SanderMuller\Richter\Tests\TestCase;

/**
 * `richter:affected-tests` on a diff that holds no file an analysis lane reads. That path returns
 * before the graph is built, and it is where a config-only or support-only diff used to come back
 * as a determined selection of nothing.
 */
final class UnreadFileSelectionTest extends TestCase
{
    use FakesGit;

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            File::delete(base_path($file));
        }

        parent::tearDown();
    }

    private function write(string $file, string $source): void
    {
        File::ensureDirectoryExists(dirname(base_path($file)));
        File::put(base_path($file), $source);
        $this->written[] = $file;
    }

    /**
     * @param  list<string>  $files  changed paths
     * @param  list<string>  $deleted  deleted paths
     */
    private function fakeDiffOf(array $files, array $deleted = []): void
    {
        $diff = '';

        foreach ($files as $file) {
            $diff .= "diff --git a/{$file} b/{$file}\n--- a/{$file}\n+++ b/{$file}\n@@ -1,1 +1,1 @@\n-old\n+new\n";
        }

        foreach ($deleted as $file) {
            $diff .= "diff --git a/{$file} b/{$file}\ndeleted file mode 100644\n--- a/{$file}\n+++ /dev/null\n@@ -1,1 +0,0 @@\n-gone\n";
        }

        $this->fakeGit([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{exit: int, document: array{determinable: bool, reasons: list<string>, tests: list<string>, testsExcluded: int}}
     */
    private function select(array $options = []): array
    {
        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--json' => true] + $options);
        $output = Artisan::output();
        $document = json_decode(substr($output, (int) strpos($output, '{')), associative: true);
        $this->assertIsArray($document);
        $this->assertIsBool($document['determinable'] ?? null);
        $this->assertIsInt($document['testsExcluded'] ?? null);

        return ['exit' => $exit, 'document' => [
            'determinable' => $document['determinable'],
            'reasons' => $this->strings($document['reasons'] ?? null),
            'tests' => $this->strings($document['tests'] ?? null),
            'testsExcluded' => $document['testsExcluded'],
        ]];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        $this->assertIsArray($value);

        return array_values(array_filter($value, is_string(...)));
    }

    #[Test]
    public function a_diff_of_only_inert_files_is_a_determined_empty_selection(): void
    {
        $this->fakeDiffOf(['README.md', '.github/workflows/tests.yml', 'phpstan-baseline.neon']);

        $result = $this->select();

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['document']['determinable']);
        $this->assertSame([], $result['document']['tests']);
    }

    #[Test]
    public function a_diff_of_only_a_config_file_is_not_determinable_and_names_the_file(): void
    {
        $this->fakeDiffOf(['config/services.php']);

        $result = $this->select();

        $this->assertSame(2, $result['exit']);
        $this->assertFalse($result['document']['determinable']);
        $this->assertSame(['changed file(s) no analysis reads can change test results: config/services.php'], $result['document']['reasons']);
    }

    #[Test]
    public function the_plain_output_stays_empty_when_an_unread_file_withdraws_the_selection(): void
    {
        $this->write('tests/Feature/TouchedTest.php', "<?php\n");
        $this->fakeDiffOf(['tests/Feature/TouchedTest.php', 'composer.lock']);

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--plain' => true]);

        $this->assertSame(2, $exit);
        $this->assertSame('', trim(Artisan::output()));
    }

    #[Test]
    public function a_deleted_unread_file_withdraws_the_selection_like_a_changed_one(): void
    {
        $this->fakeDiffOf([], deleted: ['config/legacy.php']);

        $result = $this->select();

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('config/legacy.php', $result['document']['reasons'][0]);
    }

    #[Test]
    public function a_changed_test_stays_named_as_the_floor_beside_a_withdrawing_file(): void
    {
        $this->write('tests/Feature/TouchedTest.php', "<?php\n");
        $this->fakeDiffOf(['tests/Feature/TouchedTest.php', 'config/services.php']);

        $result = $this->select();

        $this->assertSame(2, $result['exit']);
        $this->assertSame(['tests/Feature/TouchedTest.php'], $result['document']['tests']);
    }

    #[Test]
    public function a_changed_support_trait_selects_its_users_and_explains_why(): void
    {
        $this->write('tests/Support/BuildsPosts.php', "<?php\nnamespace Tests\\Support;\ntrait BuildsPosts {}\n");
        $this->write('tests/Feature/UsesTraitTest.php', "<?php\nnamespace Tests\\Feature;\nfinal class UsesTraitTest\n{\n    use \\Tests\\Support\\BuildsPosts;\n}\n");
        $this->write('tests/Feature/IgnoresTraitTest.php', "<?php\nnamespace Tests\\Feature;\nfinal class IgnoresTraitTest {}\n");
        $this->fakeDiffOf(['tests/Support/BuildsPosts.php']);

        $result = $this->select();

        $this->assertSame(0, $result['exit']);
        $this->assertSame(['tests/Feature/UsesTraitTest.php'], $result['document']['tests']);

        $this->fakeDiffOf(['tests/Support/BuildsPosts.php']);
        Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--explain' => 'tests/Feature/UsesTraitTest.php']);

        $this->assertStringContainsString('it uses the changed support file tests/Support/BuildsPosts.php', Artisan::output());
    }

    #[Test]
    public function a_directory_bound_base_test_case_withdraws_the_selection(): void
    {
        // The measured failure: a base case that `pest()->extend()->in()` applies to a directory
        // mapped to the few tests that extend it by name, and the rest were dropped silently.
        $this->write('tests/TestCase.php', "<?php\nnamespace Tests;\nabstract class TestCase {}\n");
        $this->write('tests/Pest.php', "<?php\npest()->extend(Tests\\TestCase::class)->in('Feature');\n");
        $this->write('tests/Unit/ExplicitTest.php', "<?php\nnamespace Tests\\Unit;\nfinal class ExplicitTest extends \\Tests\\TestCase {}\n");
        $this->fakeDiffOf(['tests/TestCase.php']);

        $result = $this->select();

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('tests/TestCase.php', $result['document']['reasons'][0]);
    }

    #[Test]
    public function a_mapped_test_the_runner_cannot_execute_is_excluded_like_any_other(): void
    {
        config()->set('richter.tests.unrunnable_paths', ['tests/Browser/*']);
        $this->write('tests/Support/BuildsPosts.php', "<?php\nnamespace Tests\\Support;\ntrait BuildsPosts {}\n");
        $this->write('tests/Browser/PostBrowserTest.php', "<?php\nnamespace Tests\\Browser;\nfinal class PostBrowserTest\n{\n    use \\Tests\\Support\\BuildsPosts;\n}\n");
        $this->write('tests/Feature/PostTest.php', "<?php\nnamespace Tests\\Feature;\nfinal class PostTest\n{\n    use \\Tests\\Support\\BuildsPosts;\n}\n");
        $this->fakeDiffOf(['tests/Support/BuildsPosts.php']);

        $result = $this->select();

        $this->assertSame(0, $result['exit']);
        $this->assertSame(['tests/Feature/PostTest.php'], $result['document']['tests']);
        $this->assertSame(1, $result['document']['testsExcluded']);
    }

    #[Test]
    public function a_configured_inert_path_stops_withdrawing_the_selection(): void
    {
        $this->fakeDiffOf(['lang/vendor/backup/en/notifications.php']);
        $this->assertSame(2, $this->select()['exit']);

        config()->set('richter.tests.inert_paths', ['lang/vendor/*']);
        $this->fakeDiffOf(['lang/vendor/backup/en/notifications.php']);

        $this->assertSame(0, $this->select()['exit']);
    }

    #[Test]
    public function a_file_outside_the_application_that_no_code_names_is_inert(): void
    {
        // The other services of a monorepo, a Dockerfile, a script: no PHP test reads them.
        $this->fakeDiffOf(['services/worker/main.go', 'runtimes/node/Dockerfile', '.env.example', 'tools/sync-orders.mjs']);

        $result = $this->select();

        $this->assertSame(0, $result['exit']);
        $this->assertSame([], $result['document']['reasons']);
    }

    #[Test]
    public function a_file_outside_the_application_that_a_test_names_withdraws_the_selection(): void
    {
        $this->write('tests/Feature/StarterKitTest.php', "<?php\n\$kit = file_get_contents(base_path('starter-kits/basic/manifest.json'));\n");
        $this->fakeDiffOf(['starter-kits/basic/manifest.json', 'services/worker/main.go']);

        $result = $this->select();

        $this->assertSame(2, $result['exit']);
        $this->assertSame(['changed file(s) no analysis reads can change test results: starter-kits/basic/manifest.json'], $result['document']['reasons']);
    }
}
