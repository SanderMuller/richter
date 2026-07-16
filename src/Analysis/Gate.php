<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Opt-in CI gate decision for `richter:detect-changes`. Advisory is the default; a gate only exists
 * when `--fail-on` and/or `--fail-on-unresolved` are set. The risk threshold and the unresolved-coverage
 * concern are orthogonal — either tripping fails the build. Never evaluated on an empty diff (nothing to
 * assess), which is why a bare `--fail-on=low` does not trip on zero changes.
 */
final class Gate
{
    /** @return array{tripped: bool, reasons: list<string>} */
    public static function evaluate(RiskLevel $risk, int $unresolvedCount, ?RiskLevel $failOn, bool $failOnUnresolved): array
    {
        $reasons = [];

        if ($failOn instanceof RiskLevel && $risk->atLeast($failOn)) {
            $reasons[] = "risk {$risk->value} ≥ {$failOn->value}";
        }

        if ($failOnUnresolved && $unresolvedCount > 0) {
            $noun = $unresolvedCount === 1 ? 'file' : 'files';
            $reasons[] = "{$unresolvedCount} changed {$noun} UNRESOLVED";
        }

        return ['tripped' => $reasons !== [], 'reasons' => $reasons];
    }
}
