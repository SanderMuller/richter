<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\GraphCache;

/**
 * Scaffolds a `richter.benchmark_cases` fixture from a historical fix commit: dry-runs the exact
 * replay `richter:benchmark` uses, reports what the case would score today, and prints a
 * ready-to-paste config stanza. Read-only by design — it never edits the consuming project's
 * config file (programmatically rewriting a consumer's PHP config would mangle their formatting
 * and comments; printing a stanza matches Richter's advisory ethos).
 */
final class BenchmarkAddCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:benchmark:add
        {fix-commit : Historical fix commit to replay}
        {--control : Scaffold a harmless-change control (expect_signal false, max_risk capped at the replayed risk)}
        {--key= : Case key to use instead of the derived one}
        {--expect-finding= : A substring the replay\'s findings list must contain (e.g. a payload-parity note)}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'Dry-run a fix commit through the change-impact replay and print a ready-to-paste richter.benchmark_cases entry';

    public function handle(GraphCache $graphs): int
    {
        $commit = (string) $this->argument('fix-commit');

        if ($commit === '' || str_starts_with($commit, '-')) {
            $this->error("Git ref \"{$commit}\" may not start with \"-\".");

            return self::FAILURE;
        }

        if (! Process::path(base_path())->run(['git', 'cat-file', '-e', '--end-of-options', "{$commit}^{commit}"])->successful()) {
            $this->error("Commit {$commit} is not available in this checkout.");

            return self::FAILURE;
        }

        $subject = $this->commitSubject($commit);

        try {
            $changed = ChangedSymbols::resolve("{$commit}^", $commit);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        if ($changed === []) {
            $this->warn("Commit {$commit} changes no PHP files under app/ — a fixture built from it would never exercise the report.");

            return self::FAILURE;
        }

        $analyzer = new ImpactAnalyzer($graphs->graph(fresh: (bool) $this->option('no-cache')));
        $result = $analyzer->detectChanges($changed);

        $unresolved = count(array_filter($result['coverage'], static fn (string $coverage): bool => $coverage === 'unresolved'));
        $this->line('entry points: ' . count($result['entryPoints'])
            . ", impacted: {$result['impacted']}, risk: {$result['risk']->value}, unresolved files: {$unresolved}");

        $isControl = (bool) $this->option('control');
        $key = $this->deriveKey($commit, $subject);
        $bugClass = $subject === '' ? 'TODO: describe the bug class' : $subject;
        $expectSignal = ! $isControl;
        $maxRisk = $isControl ? $result['risk'] : RiskLevel::High;
        $expectFindingOption = $this->option('expect-finding');
        $expectFinding = is_string($expectFindingOption) && $expectFindingOption !== '' ? $expectFindingOption : null;

        $case = new BenchmarkCase(
            key: $key,
            fixCommit: $commit,
            bugClass: $bugClass,
            expectSignal: $expectSignal,
            maxRisk: $maxRisk,
            expectFinding: $expectFinding,
        );

        $failures = $case->evaluate($result);

        if ($failures === []) {
            $this->info('Would currently PASS richter:benchmark.');
        } else {
            foreach ($failures as $failure) {
                $this->error("Would currently FAIL — {$failure}");
            }
        }

        $this->printStanza($key, $commit, $bugClass, $expectSignal, $maxRisk, $expectFinding);

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /** `git log`'s subject line for the commit, or `''` when it cannot be read. */
    private function commitSubject(string $commit): string
    {
        $log = Process::path(base_path())->run(['git', 'log', '-1', '--format=%s', '--end-of-options', $commit]);

        return $log->successful() ? trim($log->output()) : '';
    }

    private function deriveKey(string $commit, string $subject): string
    {
        $option = $this->option('key');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        if ($subject !== '' && preg_match('/\b[A-Z][A-Z0-9]*-\d+\b/', $subject, $matches) === 1) {
            return $matches[0];
        }

        $revParse = Process::path(base_path())->run(['git', 'rev-parse', '--short', '--end-of-options', $commit]);

        if ($revParse->successful() && trim($revParse->output()) !== '') {
            return trim($revParse->output());
        }

        return substr($commit, 0, 7);
    }

    private function printStanza(string $key, string $commit, string $bugClass, bool $expectSignal, RiskLevel $maxRisk, ?string $expectFinding): void
    {
        $escapedKey = $this->escapeForSingleQuotedString($key);
        $escapedCommit = $this->escapeForSingleQuotedString($commit);
        $escapedBugClass = $this->escapeForSingleQuotedString($bugClass);
        $expectSignalLiteral = $expectSignal ? 'true' : 'false';

        $this->newLine();
        $this->line('Add this entry to the benchmark_cases list in config/richter.php:');
        $this->newLine();
        $this->line('    [');
        $this->line("        'key' => '{$escapedKey}',");
        $this->line("        'fix_commit' => '{$escapedCommit}',");
        $this->line("        'bug_class' => '{$escapedBugClass}',");
        $this->line("        'expect_signal' => {$expectSignalLiteral},");
        $this->line("        'max_risk' => '{$maxRisk->value}',");

        if ($expectFinding !== null) {
            $this->line("        'expect_finding' => '" . $this->escapeForSingleQuotedString($expectFinding) . "',");
        }

        $this->line('    ],');
    }

    private function escapeForSingleQuotedString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
