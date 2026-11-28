<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A file of helper functions under `tests/` that names an entry point. Its code runs only when a
 * test calls one of its functions, so the tests that call them, directly or through another helper,
 * are the tests that reach the entry point.
 */
final class TestSupportFunctionsTest extends TestCase
{
    private const string ENTRY = 'command::demo:maintain';

    private const string HELPER = "<?php\nuse Illuminate\\Support\\Facades\\Artisan;\n\nfunction runMaintenance(): void\n{\n    expect(Artisan::call('demo:maintain'))->toBe(0);\n}\n";

    /**
     * @param  array<string, string>  $sources  file => source, beside the helper in `tests/Support/Maintenance.php`
     * @return array{determinable: bool, reasons: list<string>, tests: list<string>, ...}
     */
    private function select(array $sources, string $helper = self::HELPER): array
    {
        $index = new TestReferenceIndex();
        $index->addSource($helper, 'tests/Support/Maintenance.php');

        foreach ($sources as $file => $source) {
            $index->addSource($source, $file);
        }

        return AffectedTests::select(['coverage' => [], 'entryPoints' => [self::ENTRY], 'lowConfidence' => false], [], $index, unresolvedDispatchSites: []);
    }

    #[Test]
    public function a_test_that_includes_the_helper_and_calls_it_is_selected(): void
    {
        $selection = $this->select([
            'tests/Feature/MaintenanceTest.php' => "<?php\nrequire_once __DIR__ . '/../Support/Maintenance.php';\nit('runs maintenance', function () { runMaintenance(); });\n",
            'tests/Feature/OtherTest.php' => "<?php\nit('runs', fn () => expect(true)->toBeTrue());\n",
        ]);

        $this->assertSame([], $selection['reasons']);
        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/MaintenanceTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_helper_loaded_for_every_test_selects_only_its_callers(): void
    {
        $selection = $this->select([
            'tests/Pest.php' => "<?php\nrequire_once __DIR__ . '/Support/Maintenance.php';\n",
            'tests/Feature/MaintenanceTest.php' => "<?php\nit('runs maintenance', fn () => runMaintenance());\n",
            'tests/Feature/OtherTest.php' => "<?php\nit('runs', fn () => expect(true)->toBeTrue());\n",
        ]);

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/MaintenanceTest.php'], $selection['tests']);
    }

    #[Test]
    public function callers_are_followed_through_another_helper_and_a_support_trait(): void
    {
        $selection = $this->select([
            'tests/Support/Nightly.php' => "<?php\nfunction runNightly(): void\n{\n    runMaintenance();\n}\n",
            'tests/Support/RunsJobs.php' => "<?php\nnamespace Tests\\Support;\ntrait RunsJobs\n{\n    public function runAll(): void\n    {\n        runNightly();\n    }\n}\n",
            'tests/Feature/NightlyTest.php' => "<?php\nit('runs nightly', fn () => runNightly());\n",
            'tests/Feature/JobsTest.php' => "<?php\nuses(Tests\\Support\\RunsJobs::class);\nit('runs all', function () { \$this->runAll(); });\n",
            'tests/Feature/OtherTest.php' => "<?php\nit('runs', fn () => expect(true)->toBeTrue());\n",
        ]);

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/JobsTest.php', 'tests/Feature/NightlyTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_method_with_the_same_name_is_no_call(): void
    {
        $selection = $this->select([
            'tests/Feature/MaintenanceTest.php' => "<?php\nit('runs maintenance', fn () => runMaintenance());\n",
            'tests/Feature/MethodTest.php' => "<?php\nit('calls a method', fn () => \$this->runMaintenance());\n",
        ]);

        $this->assertSame(['tests/Feature/MaintenanceTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_caller_that_cannot_be_followed_keeps_the_veto(): void
    {
        $selection = $this->select([
            'tests/Datasets/Maintenance.php' => "<?php\ndataset('maintained', fn () => [runMaintenance()]);\n",
            'tests/Feature/MaintenanceTest.php' => "<?php\nit('runs maintenance', fn () => runMaintenance());\n",
        ]);

        $this->assertFalse($selection['determinable']);
        $this->assertSame(['tests referencing "' . self::ENTRY . '" live only in non-test support files — cannot map them to runnable tests'], $selection['reasons']);
    }

    /** Pest loads `tests/Helpers.php` and `tests/Datasets/` itself, so an include is no list of where the code runs. */
    #[Test]
    public function a_helper_with_top_level_code_keeps_the_veto(): void
    {
        $selection = $this->select([
            'tests/Feature/MaintenanceTest.php' => "<?php\nrequire_once __DIR__ . '/../Support/Maintenance.php';\nit('runs maintenance', fn () => runMaintenance());\n",
        ], self::HELPER . "\nArtisan::call('demo:maintain');\n");

        $this->assertFalse($selection['determinable']);
    }

    #[Test]
    public function a_function_wrapped_in_a_function_exists_guard_is_followed(): void
    {
        $helper = "<?php\nuse Illuminate\\Support\\Facades\\Artisan;\n\nif (! function_exists('runMaintenance')) {\n    function runMaintenance(): void\n    {\n        Artisan::call('demo:maintain');\n    }\n}\n";

        $selection = $this->select([
            'tests/Pest.php' => "<?php\nrequire_once __DIR__ . '/Support/Maintenance.php';\n",
            'tests/Feature/MaintenanceTest.php' => "<?php\nit('runs maintenance', fn () => runMaintenance());\n",
        ], $helper);

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/MaintenanceTest.php'], $selection['tests']);
    }
}
