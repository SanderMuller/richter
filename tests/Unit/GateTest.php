<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Gate;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Tests\TestCase;

final class GateTest extends TestCase
{
    #[Test]
    public function risk_gate_trips_at_or_above_the_threshold(): void
    {
        $this->assertTrue(Gate::evaluate(RiskLevel::High, 0, RiskLevel::Medium, false)['tripped']);
        $this->assertTrue(Gate::evaluate(RiskLevel::Medium, 0, RiskLevel::Medium, false)['tripped']);
        $this->assertSame(['risk high ≥ medium'], Gate::evaluate(RiskLevel::High, 0, RiskLevel::Medium, false)['reasons']);
    }

    #[Test]
    public function risk_gate_does_not_trip_below_the_threshold(): void
    {
        $gate = Gate::evaluate(RiskLevel::Low, 0, RiskLevel::Medium, false);

        $this->assertFalse($gate['tripped']);
        $this->assertSame([], $gate['reasons']);
    }

    #[Test]
    public function unresolved_gate_trips_independently_of_risk(): void
    {
        $gate = Gate::evaluate(RiskLevel::Low, 2, null, true);

        $this->assertTrue($gate['tripped']);
        $this->assertSame(['2 changed files UNRESOLVED'], $gate['reasons']);
    }

    #[Test]
    public function unresolved_gate_uses_singular_wording_for_one_file(): void
    {
        $this->assertSame(['1 changed file UNRESOLVED'], Gate::evaluate(RiskLevel::Low, 1, null, true)['reasons']);
    }

    #[Test]
    public function unresolved_gate_ignores_zero_unresolved_files(): void
    {
        $this->assertFalse(Gate::evaluate(RiskLevel::Low, 0, null, true)['tripped']);
    }

    #[Test]
    public function both_gates_can_trip_and_both_reasons_are_listed(): void
    {
        $gate = Gate::evaluate(RiskLevel::High, 1, RiskLevel::Medium, true);

        $this->assertTrue($gate['tripped']);
        $this->assertSame(['risk high ≥ medium', '1 changed file UNRESOLVED'], $gate['reasons']);
    }

    #[Test]
    public function no_flags_never_trips(): void
    {
        // Even a high-risk, all-unresolved change passes when neither gate flag is set.
        $this->assertFalse(Gate::evaluate(RiskLevel::High, 5, null, false)['tripped']);
    }
}
