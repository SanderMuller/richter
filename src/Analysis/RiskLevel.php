<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function exceeds(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function atLeast(self $other): bool
    {
        return $this === $other || $this->exceeds($other);
    }

    private function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
        };
    }
}
