<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Opt-in CI gate decision for `richter:detect-changes`. Advisory is the default; a gate only exists
 * when `--fail-on`, `--fail-on-hazard` and/or `--fail-on-unresolved` are set. The three concerns are
 * orthogonal — the level, the hazards, and coverage — and any one of them tripping fails the build.
 * Coverage in particular never floors the level: an UNRESOLVED file is its own gate, so a
 * test-referenced change whose dispatcher could not be followed still reports the level it earned.
 * Never evaluated on an empty diff (nothing to assess), which is why a bare `--fail-on=low` does not
 * trip on zero changes.
 */
final class Gate
{
    /**
     * @param  list<Hazard>  $hazards
     * @param  int|null  $failOnHazard  the lowest tier that blocks, from `--fail-on-hazard`
     * @return array{tripped: bool, reasons: list<string>}
     */
    public static function evaluate(RiskLevel $risk, int $unresolvedCount, ?RiskLevel $failOn, bool $failOnUnresolved, array $hazards = [], ?int $failOnHazard = null): array
    {
        $reasons = [];

        if ($failOn instanceof RiskLevel && $risk->atLeast($failOn)) {
            $reasons[] = "risk {$risk->value} ≥ {$failOn->value}";
        }

        // Blocking a removed guard and blocking a missing test are different policies, so they get a
        // flag each. A team that will not gate on "nothing references this route" may still refuse to
        // merge an authorization removal.
        if ($failOnHazard !== null) {
            $blocking = array_values(array_filter($hazards, static fn (Hazard $hazard): bool => $hazard->tier >= $failOnHazard));

            if ($blocking !== []) {
                $count = count($blocking);
                $noun = $count === 1 ? 'hazard' : 'hazards';
                $reasons[] = "{$count} {$noun} at tier {$failOnHazard} or above";
            }
        }

        if ($failOnUnresolved && $unresolvedCount > 0) {
            $noun = $unresolvedCount === 1 ? 'file' : 'files';
            $reasons[] = "{$unresolvedCount} changed {$noun} UNRESOLVED";
        }

        return ['tripped' => $reasons !== [], 'reasons' => $reasons];
    }
}
