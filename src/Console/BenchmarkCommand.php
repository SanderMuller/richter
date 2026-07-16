<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * Scores the advisory change-impact report against replayable history: defects the report once
 * missed, plus harmless-change controls that cap over-reporting. Fixtures live in the consuming
 * project's `richter.benchmark_cases` config. Run after any change to the graph or tracers — a
 * fixture flipping red→green is the evidence the improvement works; a control flipping green→red
 * is a regression in trustworthiness.
 *
 * The graph is built from the current checkout while fixture diffs are historical, so a fixture can
 * drift if its area is later renamed away — replace the fixture rather than loosening its expectation.
 */
final class BenchmarkCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:benchmark {--case= : Run only the fixture whose key matches}';

    /** @var string */
    protected $description = 'Score the advisory change-impact report against historical bug fixes and benign controls';

    public function handle(CodeGraphBuilder $builder): int
    {
        if (RichterConfig::benchmarkCases() === []) {
            $this->warn('No benchmark cases configured in richter.benchmark_cases — nothing to score.');

            return self::SUCCESS;
        }

        $cases = $this->selectedCases();

        if ($cases === []) {
            $this->warn("No benchmark fixture matches --case={$this->option('case')}.");

            return self::FAILURE;
        }

        $analyzer = new ImpactAnalyzer($builder->build());
        $passed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($cases as $case) {
            match ($this->runCase($analyzer, $case)) {
                'pass' => $passed++,
                'fail' => $failed++,
                'skip' => $skipped++,
            };
        }

        $this->newLine();
        // A skipped fixture is evidence that never ran, not a pass — the score line says so. The
        // exit code stays SUCCESS on skips by choice: shallow clones are legitimate, and no CI
        // consumer reads this command's exit code today.
        $skipSuffix = $skipped > 0 ? ", {$skipped} skipped (not evaluated)" : '';
        $this->line("Score: {$passed} passed, {$failed} failed{$skipSuffix} of " . count($cases) . ' fixtures.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return 'pass'|'fail'|'skip' */
    private function runCase(ImpactAnalyzer $analyzer, BenchmarkCase $case): string
    {
        $this->newLine();
        $this->line("<options=bold>{$case->key}</> — {$case->bugClass}");

        if (! Process::path(base_path())->run(['git', 'cat-file', '-e', '--end-of-options', "{$case->fixCommit}^{commit}"])->successful()) {
            $this->warn("  SKIP — commit {$case->fixCommit} is not available in this checkout (shallow clone?).");

            return 'skip';
        }

        try {
            $changed = ChangedSymbols::resolve("{$case->fixCommit}^", $case->fixCommit);
        } catch (RuntimeException $runtimeException) {
            $this->error('  FAIL — ' . $runtimeException->getMessage());

            return 'fail';
        }

        $result = $analyzer->detectChanges($changed);
        $failures = $case->evaluate($result);

        $unresolved = count(array_filter($result['coverage'], static fn (string $coverage): bool => $coverage === 'unresolved'));
        $this->line('  entry points: ' . count($result['entryPoints'])
            . ", impacted: {$result['impacted']}, risk: {$result['risk']->value}, unresolved files: {$unresolved}");

        if ($failures === []) {
            $this->info('  PASS');

            return 'pass';
        }

        foreach ($failures as $failure) {
            $this->error("  FAIL — {$failure}");
        }

        return 'fail';
    }

    /** @return list<BenchmarkCase> */
    private function selectedCases(): array
    {
        $key = $this->option('case');

        if ($key === null) {
            return RichterConfig::benchmarkCases();
        }

        return array_values(array_filter(
            RichterConfig::benchmarkCases(),
            static fn (BenchmarkCase $case): bool => strcasecmp($case->key, (string) $key) === 0,
        ));
    }
}
