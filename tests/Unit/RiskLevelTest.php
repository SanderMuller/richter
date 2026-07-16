<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Tests\TestCase;

final class RiskLevelTest extends TestCase
{
    #[Test]
    public function at_least_is_true_at_or_above_the_threshold(): void
    {
        // The --fail-on comparison: risk trips the gate when it is at least the configured level.
        $this->assertTrue(RiskLevel::High->atLeast(RiskLevel::Medium));
        $this->assertTrue(RiskLevel::Medium->atLeast(RiskLevel::Medium));
        $this->assertTrue(RiskLevel::Low->atLeast(RiskLevel::Low));
    }

    #[Test]
    public function at_least_is_false_below_the_threshold(): void
    {
        $this->assertFalse(RiskLevel::Low->atLeast(RiskLevel::Medium));
        $this->assertFalse(RiskLevel::Medium->atLeast(RiskLevel::High));
    }
}
