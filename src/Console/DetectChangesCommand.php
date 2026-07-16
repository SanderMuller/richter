<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use SanderMuller\Richter\Analysis\Gate;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\RichterConfig;
use Throwable;

/**
 * Advisory change-impact report for the current diff. Prints which entry points and flows the
 * changed files can reach plus a coarse risk level. Advisory by default (exit 0, never a gate);
 * `--fail-on` / `--fail-on-unresolved` opt into a non-zero exit for CI.
 */
final class DetectChangesCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:detect-changes
        {--base= : Git ref to diff the current branch against (defaults to the richter.default_base config value)}
        {--json : Emit the report as JSON on stdout}
        {--fail-on= : Exit non-zero when risk is at least this level (low|medium|high); advisory by default}
        {--fail-on-unresolved : Exit non-zero when any changed PHP file is UNRESOLVED}';

    /** @var string */
    protected $description = 'Report the advisory blast radius of the current branch diff (entry points reached + risk)';

    public function handle(CodeGraphBuilder $builder): int
    {
        $json = (bool) $this->option('json');

        $failOnRaw = $this->option('fail-on');
        $failOn = null;

        if ($failOnRaw !== null) {
            // An explicit but unparseable threshold (`--fail-on=`, `--fail-on=bogus`) is a usage error,
            // not a silently-disabled gate — failing open on a build-gating flag would be the exact
            // false reassurance this tool exists to avoid.
            $failOn = RiskLevel::tryFrom($failOnRaw);

            if (! $failOn instanceof RiskLevel) {
                return $this->emitFailure($json, "Invalid --fail-on value \"{$failOnRaw}\"; expected one of: low, medium, high.");
            }
        } elseif ($this->input->hasParameterOption('--fail-on')) {
            // Bare `--fail-on` with no value returns null, same as absent — but the user asked for a
            // gate, so fail closed rather than run ungated. Only a truly absent flag leaves it off.
            return $this->emitFailure($json, 'The --fail-on option requires a value: low, medium, or high.');
        }

        $failOnUnresolved = (bool) $this->option('fail-on-unresolved');
        $gateActive = $failOn instanceof RiskLevel || $failOnUnresolved;

        return $json
            ? $this->handleJson($builder, $failOn, $failOnUnresolved, $gateActive)
            : $this->handleText($builder, $failOn, $failOnUnresolved, $gateActive);
    }

    private function handleText(CodeGraphBuilder $builder, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
    {
        try {
            $base = RichterConfig::baseRef($this->option('base'));
            $changed = ChangedSymbols::resolve($base);
        } catch (RuntimeException $runtimeException) {
            // A broken diff can't be assessed: advisory still exits 0, but under a gate that reads as failure.
            $this->warn($runtimeException->getMessage());

            return $gateActive ? self::FAILURE : self::SUCCESS;
        } catch (InvalidArgumentException $invalidArgumentException) {
            // An option-shaped/invalid ref: preserve the uncaught-propagation contract when advisory.
            if ($gateActive) {
                $this->error($invalidArgumentException->getMessage());

                return self::FAILURE;
            }

            throw $invalidArgumentException;
        }

        if ($changed === []) {
            $this->info("No changed PHP files under app/ against {$base}.");

            return self::SUCCESS;
        }

        $result = new ImpactAnalyzer($builder->build())->detectChanges($changed);

        $this->line(ImpactFormatter::detectChanges($result, TestReferenceIndex::fromTests(base_path('tests')), $gateActive));

        if (! $gateActive) {
            return self::SUCCESS;
        }

        $gate = Gate::evaluate($result['risk'], $this->unresolvedCount($result['coverage']), $failOn, $failOnUnresolved);

        $this->line($gate['tripped'] ? 'Gate: FAIL — ' . implode('; ', $gate['reasons']) : 'Gate: PASS');

        return $gate['tripped'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout so the output is always a single
     * parseable value. Only ref/diff resolution is advisory (a bad/option-shaped ref or broken diff
     * stays exit 0 unless a gate flag flips it); everything downstream — including an unexpected
     * graph-build or analyze error — reaches the failure backstop rather than being read as "advisory".
     */
    private function handleJson(CodeGraphBuilder $builder, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
    {
        try {
            $base = RichterConfig::baseRef($this->option('base'));
            $changed = ChangedSymbols::resolve($base);
        } catch (InvalidArgumentException|RuntimeException $expected) {
            return $this->jsonError($expected->getMessage(), $gateActive ? self::FAILURE : self::SUCCESS);
        } catch (Throwable $unexpected) {
            return $this->jsonError($unexpected->getMessage(), self::FAILURE);
        }

        try {
            return $this->emitJson($builder, $base, $changed, $failOn, $failOnUnresolved, $gateActive);
        } catch (Throwable $unexpected) {
            // Backstop: a graph-build/analyze failure is not "no impact" — fail, but keep stdout one JSON doc.
            return $this->jsonError($unexpected->getMessage(), self::FAILURE);
        }
    }

    /**
     * @param  list<ChangedFileSymbols>  $changed
     */
    private function emitJson(CodeGraphBuilder $builder, string $base, array $changed, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
    {
        if ($changed === []) {
            // Empty diff always passes: gate not evaluated, so a bare --fail-on=low can't trip on zero changes.
            $payload = JsonPresenter::emptyDetectChanges($base);

            if ($gateActive) {
                $payload['gate'] = $this->gatePayload(false, [], $failOn, $failOnUnresolved);
            }

            $this->line(JsonPresenter::encode($payload));

            return self::SUCCESS;
        }

        $result = new ImpactAnalyzer($builder->build())->detectChanges($changed);
        $payload = JsonPresenter::detectChanges($result, $base);

        if (! $gateActive) {
            $this->line(JsonPresenter::encode($payload));

            return self::SUCCESS;
        }

        $gate = Gate::evaluate($result['risk'], $this->unresolvedCount($result['coverage']), $failOn, $failOnUnresolved);
        $payload['gate'] = $this->gatePayload($gate['tripped'], $gate['reasons'], $failOn, $failOnUnresolved);

        $this->line(JsonPresenter::encode($payload));

        return $gate['tripped'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{failOn: string|null, failOnUnresolved: bool, tripped: bool, reasons: list<string>}
     */
    private function gatePayload(bool $tripped, array $reasons, ?RiskLevel $failOn, bool $failOnUnresolved): array
    {
        return [
            'failOn' => $failOn?->value,
            'failOnUnresolved' => $failOnUnresolved,
            'tripped' => $tripped,
            'reasons' => $reasons,
        ];
    }

    /** @param  array<string, 'analyzed'|'unresolved'>  $coverage */
    private function unresolvedCount(array $coverage): int
    {
        return count(array_filter($coverage, static fn (string $state): bool => $state === 'unresolved'));
    }

    private function jsonError(string $message, int $exitCode): int
    {
        $this->line(JsonPresenter::encode(['error' => $message]));

        return $exitCode;
    }

    private function emitFailure(bool $json, string $message): int
    {
        if ($json) {
            $this->line(JsonPresenter::encode(['error' => $message]));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
