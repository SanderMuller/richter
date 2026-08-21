<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Tests\TestCase;

final class BenchmarkCaseTest extends TestCase
{
    /**
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<string>  $entryPoints
     * @param  array<string, int>|null  $changed  per-file seed counts; defaults to 1 seed per covered file
     * @param  list<string>  $findings
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, risk: RiskLevel, findings: list<string>}
     */
    private function analyzerResult(array $coverage, array $entryPoints, RiskLevel $risk, ?array $changed = null, array $findings = []): array
    {
        return [
            'changed' => $changed ?? array_map(static fn (): int => 1, $coverage),
            'coverage' => $coverage,
            'entryPoints' => $entryPoints,
            'risk' => $risk,
            'findings' => $findings,
        ];
    }

    private function signalCase(): BenchmarkCase
    {
        return new BenchmarkCase('BUG-0001', 'abc123', 'a bug class', expectSignal: true);
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

    #[Test]
    public function absent_expect_finding_behaves_exactly_as_before(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'BUG-0002',
            'fix_commit' => 'abc123',
            'bug_class' => 'a bug class',
            'expect_signal' => true,
        ]);

        $this->assertNull($case->expectFinding);
        $this->assertSame([], $case->evaluate($this->analyzerResult(['app/Foo.php' => 'analyzed'], ['route::GET /foo'], RiskLevel::Medium)));
    }

    #[Test]
    public function a_matching_finding_passes(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'BUG-0003',
            'fix_commit' => 'abc123',
            'bug_class' => 'a bug class',
            'expect_signal' => true,
            'expect_finding' => 'layout',
        ]);

        $result = $this->analyzerResult(
            ['app/Foo.php' => 'analyzed'],
            ['route::GET /foo'],
            RiskLevel::Medium,
            findings: ['app/Http/Resources/FooResource.php mirrors App\\Models\\Foo but does not expose layout added to App\\Models\\Foo'],
        );

        $this->assertSame([], $case->evaluate($result));
    }

    #[Test]
    public function a_non_matching_finding_fails_with_a_readable_reason(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'BUG-0004',
            'fix_commit' => 'abc123',
            'bug_class' => 'a bug class',
            'expect_signal' => true,
            'expect_finding' => 'layout',
        ]);

        $result = $this->analyzerResult(['app/Foo.php' => 'analyzed'], ['route::GET /foo'], RiskLevel::Medium);

        $failures = $case->evaluate($result);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('layout', $failures[0]);
    }

    #[Test]
    public function a_hazard_above_the_cap_fails_and_names_itself(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'CAP-1', 'fix_commit' => 'abc1234', 'bug_class' => 'a refactor that should carry nothing worse than tier 1',
            'expect_signal' => true, 'max_hazard_tier' => 1,
        ]);

        $failures = $case->evaluate($this->reportResult(hazards: [
            new Hazard('auth', 3, 'CWE-862', 'App\Http\Controllers\PostController::update', 'the guard is gone'),
        ]));

        $this->assertSame(
            ['tier 3 `auth` hazard on App\Http\Controllers\PostController::update exceeds the expected maximum tier of 1'],
            $failures,
        );
    }

    #[Test]
    public function a_hazard_at_the_cap_passes(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'CAP-2', 'fix_commit' => 'abc1234', 'bug_class' => 'a signature change',
            'expect_signal' => true, 'max_hazard_tier' => 1,
        ]);

        $this->assertSame([], $case->evaluate($this->reportResult(hazards: [
            new Hazard('contract', 1, null, 'App\Services\Publisher::publish', 'the parameter list changed'),
        ])));
    }

    #[Test]
    public function a_cap_of_zero_allows_no_hazard_at_all(): void
    {
        // What a control usually wants to say, and says far more precisely than a level cap.
        $case = BenchmarkCase::fromArray([
            'key' => 'CAP-3', 'fix_commit' => 'abc1234', 'bug_class' => 'a harmless additive change',
            'expect_signal' => false, 'max_hazard_tier' => 0,
        ]);

        $this->assertSame([], $case->evaluate($this->reportResult()));
        $this->assertCount(1, $case->evaluate($this->reportResult(hazards: [
            new Hazard('model', 2, 'CWE-915', 'App\Models\Post::$fillable', 'gained status'),
        ])));
    }

    #[Test]
    public function the_cap_catches_what_a_level_cap_cannot(): void
    {
        // The matrix maps a tier-2 at `gated`, a tier-1 at `public-write` and a hazard-free change
        // with unverified reach all onto MEDIUM. A case sitting honestly at MEDIUM therefore stays
        // green under `max_risk: medium` while a false tier-2 appears beneath it.
        $result = $this->reportResult(hazards: [
            new Hazard('parity', 2, null, 'App\Models\Post', 'a field never reached its resource', reach: Hazard::REACH_GATED),
        ]);
        $result['risk'] = RiskLevel::Medium;

        $levelCapped = BenchmarkCase::fromArray([
            'key' => 'CAP-4', 'fix_commit' => 'abc1234', 'bug_class' => 'x', 'expect_signal' => true, 'max_risk' => 'medium',
        ]);
        $tierCapped = BenchmarkCase::fromArray([
            'key' => 'CAP-5', 'fix_commit' => 'abc1234', 'bug_class' => 'x', 'expect_signal' => true, 'max_risk' => 'medium', 'max_hazard_tier' => 1,
        ]);

        $this->assertSame([], $levelCapped->evaluate($result), 'the level cap is blind to this');
        $this->assertCount(1, $tierCapped->evaluate($result));
    }

    #[Test]
    public function an_absent_cap_constrains_nothing(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'CAP-6', 'fix_commit' => 'abc1234', 'bug_class' => 'x', 'expect_signal' => true,
        ]);

        $this->assertSame(3, $case->maxHazardTier);
        $this->assertSame([], $case->evaluate($this->reportResult(hazards: [
            new Hazard('auth', 3, 'CWE-862', 'App\Http\Controllers\PostController::update', 'the guard is gone'),
        ])));
    }

    #[Test]
    public function an_out_of_range_max_hazard_tier_throws_naming_its_key(): void
    {
        // Silently defaulting would make the cap unsatisfiable without ever testing it — the same
        // reason an unrecognised max_risk throws.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Benchmark case "CAP-7" has an invalid max_hazard_tier');

        BenchmarkCase::fromArray([
            'key' => 'CAP-7', 'fix_commit' => 'abc1234', 'bug_class' => 'x', 'expect_signal' => true, 'max_hazard_tier' => 4,
        ]);
    }

    #[Test]
    public function expect_finding_matches_a_hazard_as_well_as_a_finding(): void
    {
        // The three payload-parity checks were findings until 0.40 made them tier-2 hazards. A fixture
        // that pinned one by its message would otherwise have gone unmatchable overnight and failed
        // with "no finding contains …" — which reads as lost coverage, not a relocation.
        $case = BenchmarkCase::fromArray([
            'key' => 'PARITY-1',
            'fix_commit' => 'abc1234',
            'bug_class' => 'a resource key a consumer still reads',
            'expect_signal' => false,
            'expect_finding' => 'still reads',
        ]);

        $result = $this->reportResult(hazards: [
            new Hazard('parity', 2, null, 'App\Http\Resources\PostResource', "a consumer still reads 'published_at'"),
        ]);

        $this->assertSame([], $case->evaluate($result));
    }

    #[Test]
    public function expect_finding_still_fails_when_the_report_says_it_nowhere(): void
    {
        $case = BenchmarkCase::fromArray([
            'key' => 'PARITY-2',
            'fix_commit' => 'abc1234',
            'bug_class' => 'a resource key a consumer still reads',
            'expect_signal' => false,
            'expect_finding' => 'still reads',
        ]);

        $this->assertSame(
            ['no finding or hazard contains "still reads"'],
            $case->evaluate($this->reportResult()),
        );
    }

    /**
     * @param  list<Hazard>  $hazards
     * @param  list<string>  $findings
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, risk: RiskLevel, findings: list<string>, hazards: list<Hazard>}
     */
    private function reportResult(array $hazards = [], array $findings = []): array
    {
        return [
            'changed' => ['app/Models/Post.php' => 1],
            'coverage' => ['app/Models/Post.php' => 'analyzed'],
            'entryPoints' => ['route::GET::/posts'],
            'risk' => RiskLevel::Low,
            'findings' => $findings,
            'hazards' => $hazards,
        ];
    }

    #[Test]
    public function a_non_string_expect_finding_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"BUG-0005"');

        BenchmarkCase::fromArray([
            'key' => 'BUG-0005',
            'fix_commit' => 'abc123',
            'bug_class' => 'a bug class',
            'expect_signal' => true,
            'expect_finding' => 42,
        ]);
    }
}
