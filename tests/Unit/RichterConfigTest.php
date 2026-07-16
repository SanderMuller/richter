<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

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
    public function dispatch_helpers_keeps_only_strings(): void
    {
        config()->set('richter.dispatch_helpers', ['dispatch_with_retries', 42, null, 'dispatch_sync_with_retries']);

        $this->assertSame(['dispatch_with_retries', 'dispatch_sync_with_retries'], RichterConfig::dispatchHelpers());
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
}
