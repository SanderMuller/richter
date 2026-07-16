<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Tests\TestCase;

final class BenchmarkCaseTest extends TestCase
{
    /**
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<string>  $entryPoints
     * @param  array<string, int>|null  $changed  per-file seed counts; defaults to 1 seed per covered file
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, risk: RiskLevel}
     */
    private function analyzerResult(array $coverage, array $entryPoints, RiskLevel $risk, ?array $changed = null): array
    {
        return [
            'changed' => $changed ?? array_map(static fn (): int => 1, $coverage),
            'coverage' => $coverage,
            'entryPoints' => $entryPoints,
            'risk' => $risk,
        ];
    }

    private function signalCase(): BenchmarkCase
    {
        return new BenchmarkCase('HPB-0001', 'abc123', 'a bug class', expectSignal: true);
    }

    #[Test]
    public function a_signal_case_passes_when_the_change_resolves_and_reaches_an_entry_point(): void
    {
        $result = $this->analyzerResult(['app/Foo.php' => 'analyzed'], ['route::GET /foo'], RiskLevel::Medium);

        $this->assertSame([], $this->signalCase()->evaluate($result));
    }

    #[Test]
    public function a_signal_case_fails_on_an_unresolved_changed_file(): void
    {
        $result = $this->analyzerResult(['app/Foo.php' => 'unresolved'], ['route::GET /foo'], RiskLevel::Medium);

        $failures = $this->signalCase()->evaluate($result);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('app/Foo.php', $failures[0]);
    }

    #[Test]
    public function a_signal_case_fails_when_no_entry_point_is_reached(): void
    {
        $result = $this->analyzerResult(['app/Foo.php' => 'analyzed'], [], RiskLevel::Low);

        $failures = $this->signalCase()->evaluate($result);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('no entry points reached', $failures[0]);
    }

    #[Test]
    public function a_signal_case_reports_every_unresolved_file(): void
    {
        $result = $this->analyzerResult(
            ['app/Foo.php' => 'unresolved', 'app/Bar.php' => 'unresolved'],
            [],
            RiskLevel::Low,
        );

        $this->assertCount(3, $this->signalCase()->evaluate($result));
    }

    #[Test]
    public function a_control_case_passes_when_risk_stays_within_the_cap(): void
    {
        $case = new BenchmarkCase('control', 'abc123', 'benign control', expectSignal: false, maxRisk: RiskLevel::Low);

        $result = $this->analyzerResult(['app/Foo.php' => 'analyzed'], [], RiskLevel::Low);

        $this->assertSame([], $case->evaluate($result));
    }

    #[Test]
    public function a_partially_unresolved_control_still_passes(): void
    {
        // One analyzed file keeps the control meaningful; unresolved siblings (e.g. a job under
        // unfollowable dispatches) are the coverage honesty at work, not fixture drift.
        $case = new BenchmarkCase('control', 'abc123', 'benign control', expectSignal: false, maxRisk: RiskLevel::Medium);

        $result = $this->analyzerResult(['app/Foo.php' => 'analyzed', 'app/Bar.php' => 'unresolved'], [], RiskLevel::Medium);

        $this->assertSame([], $case->evaluate($result));
    }

    #[Test]
    public function a_control_that_resolved_no_graph_node_fails_as_drifted(): void
    {
        $case = new BenchmarkCase('control', 'abc123', 'benign control', expectSignal: false, maxRisk: RiskLevel::Low);

        $failures = $case->evaluate($this->analyzerResult(
            ['app/Foo.php' => 'unresolved'],
            [],
            RiskLevel::Low,
            changed: ['app/Foo.php' => 0],
        ));

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('drifted', $failures[0]);
    }

    #[Test]
    public function an_unresolved_control_with_resolved_seeds_is_not_drift(): void
    {
        // A job whose coverage flips UNRESOLVED (unfollowable dispatch honesty) still seeded and
        // evaluated the cap — only a zero-seed fixture is drift.
        $case = new BenchmarkCase('control', 'abc123', 'benign control', expectSignal: false, maxRisk: RiskLevel::Medium);

        $result = $this->analyzerResult(['app/Foo.php' => 'unresolved'], [], RiskLevel::Medium, changed: ['app/Foo.php' => 6]);

        $this->assertSame([], $case->evaluate($result));
    }

    #[Test]
    public function a_control_case_fails_when_risk_exceeds_the_cap(): void
    {
        $case = new BenchmarkCase('control', 'abc123', 'benign control', expectSignal: false, maxRisk: RiskLevel::Medium);

        $failures = $case->evaluate($this->analyzerResult(['app/Foo.php' => 'analyzed'], [], RiskLevel::High));

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('exceeds the expected maximum', $failures[0]);
    }
}
