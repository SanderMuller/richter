<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tests\TestCase;

final class RichterConfigTest extends TestCase
{
    #[Test]
    public function an_explicit_base_option_wins_over_the_config_value(): void
    {
        config()->set('richter.default_base', 'origin/develop');

        $this->assertSame('origin/feature', RichterConfig::baseRef('origin/feature'));
    }

    #[Test]
    public function the_configured_base_is_used_when_no_option_is_passed(): void
    {
        config()->set('richter.default_base', 'origin/develop');

        $this->assertSame('origin/develop', RichterConfig::baseRef());
    }

    #[Test]
    public function the_base_falls_back_to_origin_main_without_config(): void
    {
        config()->set('richter.default_base');

        $this->assertSame('origin/main', RichterConfig::baseRef());
    }

    #[Test]
    public function configured_dispatch_helpers_are_returned_as_a_list(): void
    {
        config()->set('richter.dispatch_helpers', ['dispatch_with_retries', 'dispatch_sync_with_retries']);

        $this->assertSame(['dispatch_with_retries', 'dispatch_sync_with_retries'], RichterConfig::dispatchHelpers());
    }

    #[Test]
    public function a_non_string_dispatch_helper_entry_throws(): void
    {
        config()->set('richter.dispatch_helpers', ['dispatch_with_retries', 42]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('richter.dispatch_helpers');

        RichterConfig::dispatchHelpers();
    }

    #[Test]
    public function a_non_array_entry_point_roots_value_throws(): void
    {
        config()->set('richter.entry_point_roots', 'Jobs');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('richter.entry_point_roots');

        RichterConfig::entryPointRoots();
    }

    #[Test]
    public function a_dash_prefixed_base_ref_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RichterConfig::baseRef('--upload-pack=evil');
    }

    #[Test]
    public function the_cache_is_enabled_by_default_and_a_non_boolean_value_throws(): void
    {
        config()->set('richter.cache.enabled');
        $this->assertTrue(RichterConfig::cacheEnabled());

        config()->set('richter.cache.enabled', 'yes');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('richter.cache.enabled');

        RichterConfig::cacheEnabled();
    }

    #[Test]
    public function the_cache_directory_defaults_to_storage_and_honours_an_override(): void
    {
        config()->set('richter.cache.directory');
        $this->assertSame(storage_path('framework/cache/richter'), RichterConfig::cacheDirectory());

        config()->set('richter.cache.directory', '');
        $this->assertSame(storage_path('framework/cache/richter'), RichterConfig::cacheDirectory());

        config()->set('richter.cache.directory', '/tmp/custom-richter');
        $this->assertSame('/tmp/custom-richter', RichterConfig::cacheDirectory());
    }

    #[Test]
    public function a_non_string_cache_directory_throws(): void
    {
        config()->set('richter.cache.directory', ['nope']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('richter.cache.directory');

        RichterConfig::cacheDirectory();
    }

    #[Test]
    public function a_non_array_benchmark_cases_value_throws(): void
    {
        config()->set('richter.benchmark_cases', 'abc1234');

        $this->expectException(InvalidArgumentException::class);

        RichterConfig::benchmarkCases();
    }

    #[Test]
    public function a_malformed_benchmark_case_throws_naming_its_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"CASE-BROKEN"');

        BenchmarkCase::fromArray([
            'key' => 'CASE-BROKEN',
            'fix_commit' => 'abc1234',
            'bug_class' => 'background-job change',
            // expect_signal missing
        ]);
    }

    #[Test]
    public function an_invalid_max_risk_string_throws_naming_its_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"CASE-3"');

        BenchmarkCase::fromArray([
            'key' => 'CASE-3',
            'fix_commit' => 'abc1234',
            'bug_class' => 'benign control',
            'expect_signal' => false,
            'max_risk' => 'critical',
        ]);
    }

    #[Test]
    public function a_risk_level_instance_is_accepted_as_max_risk(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'CASE-4',
            'fix_commit' => 'abc1234',
            'bug_class' => 'benign control',
            'expect_signal' => false,
            'max_risk' => RiskLevel::Low,
        ]);

        $this->assertSame(RiskLevel::Low, $case->maxRisk);
    }

    #[Test]
    public function unconfigured_entry_point_roots_stay_null_so_the_tracer_default_applies(): void
    {
        config()->set('richter.entry_point_roots');

        $this->assertNull(RichterConfig::entryPointRoots());
    }

    #[Test]
    public function configured_entry_point_roots_are_returned_as_a_list(): void
    {
        config()->set('richter.entry_point_roots', ['Jobs', 'Domain/Listeners']);

        $this->assertSame(['Jobs', 'Domain/Listeners'], RichterConfig::entryPointRoots());
    }

    #[Test]
    public function benchmark_cases_map_onto_benchmark_case_objects(): void
    {
        config()->set('richter.benchmark_cases', [
            [
                'key' => 'CASE-1',
                'fix_commit' => 'abc1234',
                'bug_class' => 'background-job change',
                'expect_signal' => true,
                'max_risk' => 'medium',
            ],
        ]);

        $cases = RichterConfig::benchmarkCases();

        $this->assertCount(1, $cases);
        $this->assertSame('CASE-1', $cases[0]->key);
        $this->assertSame('abc1234', $cases[0]->fixCommit);
        $this->assertTrue($cases[0]->expectSignal);
        $this->assertSame(RiskLevel::Medium, $cases[0]->maxRisk);
    }

    #[Test]
    public function no_configured_benchmark_cases_yields_an_empty_list(): void
    {
        config()->set('richter.benchmark_cases');

        $this->assertSame([], RichterConfig::benchmarkCases());
    }

    #[Test]
    public function a_case_without_max_risk_defaults_to_high(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'CASE-2',
            'fix_commit' => 'def5678',
            'bug_class' => 'benign control',
            'expect_signal' => false,
        ]);

        $this->assertSame(RiskLevel::High, $case->maxRisk);
    }

    #[Test]
    public function frontend_roots_default_to_off(): void
    {
        config()->offsetUnset('richter.frontend');

        $this->assertSame([], RichterConfig::frontendRoots());
    }

    #[Test]
    public function configured_frontend_roots_and_generated_paths_pass_through(): void
    {
        config()->set('richter.frontend.roots', ['resources/js']);
        config()->set('richter.frontend.generated_paths', ['generated']);

        $this->assertSame(['resources/js'], RichterConfig::frontendRoots());
        $this->assertSame(['generated'], RichterConfig::frontendGeneratedPaths());
    }

    #[Test]
    public function unset_generated_paths_default_to_the_wayfinder_trees(): void
    {
        config()->offsetUnset('richter.frontend');

        $this->assertSame(['actions', 'routes', 'wayfinder'], RichterConfig::frontendGeneratedPaths());
    }
}
