<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use SanderMuller\Richter\Analysis\Gate;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\GraphCache;
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
        {--markdown : Emit the report as GitHub-flavoured markdown, for PR descriptions and comments}
        {--explain : Show the call chain from each reached entry point down to the changed code (JSON always carries the chains)}
        {--fail-on= : Exit non-zero when risk is at least this level (low|medium|high); advisory by default}
        {--fail-on-unresolved : Exit non-zero when any changed PHP file is UNRESOLVED}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'Report the advisory blast radius of the current branch diff (entry points reached + risk)';

    public function handle(GraphCache $graphs): int
    {
        $json = (bool) $this->option('json');

        if ($json && (bool) $this->option('markdown')) {
            // With --json present the usage error honours the JSON contract: stdout stays one parseable document.
            return $this->emitFailure($json, 'The --json and --markdown options are mutually exclusive.');
        }

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
            ? $this->handleJson($graphs, $failOn, $failOnUnresolved, $gateActive)
            : $this->handleText($graphs, $failOn, $failOnUnresolved, $gateActive);
    }

    private function handleText(GraphCache $graphs, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
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

        $result = new ImpactAnalyzer($this->graph($graphs))->detectChanges($changed);

        $markdown = (bool) $this->option('markdown');
        $tests = TestReferenceIndex::fromTests(base_path('tests'));
        $explain = (bool) $this->option('explain');

        $this->line($markdown
            ? MarkdownFormatter::detectChanges($result, $tests, $gateActive, $explain)
            : ImpactFormatter::detectChanges($result, $tests, $gateActive, $explain));

        if (! $gateActive) {
            return self::SUCCESS;
        }

        $gate = Gate::evaluate($result['risk'], $this->unresolvedCount($result['coverage']), $failOn, $failOnUnresolved);

        $verdict = $gate['tripped'] ? 'FAIL — ' . implode('; ', $gate['reasons']) : 'PASS';
        $this->line($markdown ? "\n**Gate:** {$verdict}" : "Gate: {$verdict}");

        return $gate['tripped'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout so the output is always a single
     * parseable value. Only ref/diff resolution is advisory (a bad/option-shaped ref or broken diff
     * stays exit 0 unless a gate flag flips it); everything downstream — including an unexpected
     * graph-build or analyze error — reaches the failure backstop rather than being read as "advisory".
     */
    private function handleJson(GraphCache $graphs, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
    {
        try {
            try {
                $base = RichterConfig::baseRef($this->option('base'));
                $changed = ChangedSymbols::resolve($base);
            } catch (InvalidArgumentException|RuntimeException $expected) {
                // Expected operational failures (bad/option-shaped ref, broken diff): advisory unless gated.
                return $this->jsonError($expected->getMessage(), $gateActive ? self::FAILURE : self::SUCCESS);
            }

            return $this->emitJson($graphs, $base, $changed, $failOn, $failOnUnresolved, $gateActive);
        } catch (Throwable $throwable) {
            // Backstop: an unexpected graph-build/analyze (or resolution) error is not "no impact" —
            // fail, but keep stdout a single JSON document instead of a leaked stack trace.
            return $this->jsonError($throwable->getMessage(), self::FAILURE);
        }
    }

    /**
     * @param  list<ChangedFileSymbols>  $changed
     */
    private function emitJson(GraphCache $graphs, string $base, array $changed, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
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

        $result = new ImpactAnalyzer($this->graph($graphs))->detectChanges($changed);
        $tests = TestReferenceIndex::fromTests(base_path('tests'));
        $payload = JsonPresenter::detectChanges($result, $base, $tests);

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

    private function graph(GraphCache $graphs): CodeGraph
    {
        return $graphs->graph(fresh: (bool) $this->option('no-cache'));
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
