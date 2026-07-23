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
        public ?string $expectFinding = null,
    ) {}

    public static function fromArray(mixed $case): self
    {
        if (! is_array($case)
            || ! is_string($case['key'] ?? null)
            || ! is_string($case['fix_commit'] ?? null)
            || ! is_string($case['bug_class'] ?? null)
            || ! is_bool($case['expect_signal'] ?? null)) {
            $key = is_array($case) && is_string($case['key'] ?? null) ? " \"{$case['key']}\"" : '';

            throw new InvalidArgumentException("A richter.benchmark_cases entry{$key} needs string key, fix_commit and bug_class values plus a bool expect_signal.");
        }

        return new self(
            key: $case['key'],
            fixCommit: $case['fix_commit'],
            bugClass: $case['bug_class'],
            expectSignal: $case['expect_signal'],
            maxRisk: self::maxRisk($case['key'], $case['max_risk'] ?? null),
            expectFinding: self::expectFinding($case['key'], $case['expect_finding'] ?? null),
        );
    }

    /** A non-string, non-null `expect_finding` throws — silently ignoring it would make the assertion unsatisfiable without ever testing it. */
    private static function expectFinding(string $key, mixed $expectFinding): ?string
    {
        if ($expectFinding === null) {
            return null;
        }

        if (! is_string($expectFinding)) {
            throw new InvalidArgumentException("Benchmark case \"{$key}\" has an invalid expect_finding — it must be a string.");
        }

        return $expectFinding;
    }

    /**
     * An unrecognised `max_risk` must throw, not default: silently falling back to High makes a
     * control fixture's over-reporting cap unsatisfiable, so the benchmark would report green
     * without ever testing it.
     */
    private static function maxRisk(string $key, mixed $maxRisk): RiskLevel
    {
        if ($maxRisk === null) {
            return RiskLevel::High;
        }

        if ($maxRisk instanceof RiskLevel) {
            return $maxRisk;
        }

        $level = is_string($maxRisk) ? RiskLevel::tryFrom($maxRisk) : null;

        if ($level === null) {
            throw new InvalidArgumentException("Benchmark case \"{$key}\" has an invalid max_risk — use 'low', 'medium' or 'high'.");
        }

        return $level;
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, risk: RiskLevel, findings: list<string>, ...}  $result  a {@see ImpactAnalyzer::detectChanges()} result
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

        if ($this->expectFinding !== null && ! array_any($result['findings'], fn (string $finding): bool => str_contains($finding, $this->expectFinding))) {
            $failures[] = "no finding contains \"{$this->expectFinding}\"";
        }

        return $failures;
    }
}
