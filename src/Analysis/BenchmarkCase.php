<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use InvalidArgumentException;

/**
 * One replayable accuracy fixture for the change-impact report: a historical commit whose diff the
 * report is re-run against, plus what a trustworthy report must say about it. Bug fixtures
 * (`expectSignal`) replay defects the report originally missed — they pass only when the changed
 * area resolves and reaches an entry point. Control fixtures cap the risk a harmless change may
 * report, guarding against the over-reporting that trains readers to ignore the check.
 */
final readonly class BenchmarkCase
{
    public function __construct(
        public string $key,
        public string $fixCommit,
        public string $bugClass,
        public bool $expectSignal,
        public RiskLevel $maxRisk = RiskLevel::High,
    ) {}

    /** Builds a case from a `richter.benchmark_cases` config entry, validating its shape. */
    public static function fromArray(mixed $case): self
    {
        if (! is_array($case)
            || ! is_string($case['key'] ?? null)
            || ! is_string($case['fix_commit'] ?? null)
            || ! is_string($case['bug_class'] ?? null)
            || ! is_bool($case['expect_signal'] ?? null)) {
            throw new InvalidArgumentException('A richter.benchmark_cases entry needs string key, fix_commit and bug_class values plus a bool expect_signal.');
        }

        $maxRisk = $case['max_risk'] ?? null;

        return new self(
            key: $case['key'],
            fixCommit: $case['fix_commit'],
            bugClass: $case['bug_class'],
            expectSignal: $case['expect_signal'],
            maxRisk: is_string($maxRisk) ? RiskLevel::from($maxRisk) : RiskLevel::High,
        );
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, risk: RiskLevel, ...}  $result  a {@see ImpactAnalyzer::detectChanges()} result
     * @return list<string> failure reasons; empty means the case passed
     */
    public function evaluate(array $result): array
    {
        $failures = [];

        if ($this->expectSignal) {
            foreach ($result['coverage'] as $file => $coverage) {
                if ($coverage === 'unresolved') {
                    $failures[] = "changed area could not be placed (UNRESOLVED): {$file}";
                }
            }

            if ($result['entryPoints'] === []) {
                $failures[] = 'no entry points reached — the report reads as "no impact" for a change that caused a real bug';
            }
        } elseif ($result['coverage'] !== []
            && ! in_array('analyzed', $result['coverage'], strict: true)
            && array_sum($result['changed']) === 0) {
            // A control that resolved no graph node at all exercised nothing — its green would claim
            // the over-reporting cap held without ever testing it (fixture drift, e.g. the changed
            // area renamed away). A file with seeds whose coverage flips UNRESOLVED (unfollowable
            // dispatch honesty) still evaluated the cap and is not drift.
            $failures[] = 'control fixture resolved no graph node — the fixture has drifted and no longer exercises the cap';
        }

        if ($result['risk']->exceeds($this->maxRisk)) {
            $failures[] = "risk {$result['risk']->value} exceeds the expected maximum of {$this->maxRisk->value} for this change";
        }

        return $failures;
    }
}
