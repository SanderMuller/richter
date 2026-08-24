<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use SanderMuller\Richter\Analysis\BenchmarkCase;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Console\Concerns\WritesDocuments;
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
    use WritesDocuments;

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

        $graph = $graphs->graph(fresh: (bool) $this->option('no-cache'));
        $analyzer = new ImpactAnalyzer($graph);
        $tests = TestReferenceIndex::fromTests(base_path('tests'));
        $tests->useGraph($graph);
        // A control's cap is captured from this level, so an analysis without the test index would
        // bake a MEDIUM into the corpus for a change that is actually LOW.
        $result = $analyzer->detectChanges($changed, tests: $tests);

        $unresolved = count(array_filter($result['coverage'], static fn (string $coverage): bool => $coverage === 'unresolved'));
        $this->line('entry points: ' . count($result['entryPoints'])
            . ", impacted: {$result['impacted']}, risk: {$result['risk']->value}, unresolved files: {$unresolved}");

        $isControl = (bool) $this->option('control');

        // Refusing beats warning, which is the tempting softening: the stanza is the whole output,
        // and whoever runs this is usually triaging a control that just went red, where pasting the
        // green no-op is both the obvious move and the one that destroys the fixture. The messages
        // below carry why a HIGH cap asserts nothing.
        if ($isControl && $result['risk'] === RiskLevel::High) {
            $this->error('Refusing to scaffold a control for a change that already reports HIGH.');
            $this->line('A control caps the risk a harmless change may report, and HIGH is the top of the scale, so the cap would assert nothing and the case would pass forever.');
            $this->line('Either this change is not harmless — capture it as a signal case by dropping --control — or the corpus needs a genuinely low-reach commit for this control.');

            return self::FAILURE;
        }

        $key = $this->deriveKey($commit, $subject);
        $bugClass = $subject === '' ? 'TODO: describe the bug class' : $subject;
        $expectSignal = ! $isControl;
        $maxRisk = $isControl ? $result['risk'] : RiskLevel::High;
        // A control pins the hazards it produces today, for the same reason it pins the level: any
        // increase is the over-reporting the case exists to catch. A signal case is left unconstrained
        // at the ceiling, since what it asserts is that the change is SEEN, not that it stays quiet.
        $maxHazardTier = $isControl ? $this->worstTier($result['hazards']) : 3;
        $expectFindingOption = $this->option('expect-finding');
        $expectFinding = is_string($expectFindingOption) && $expectFindingOption !== '' ? $expectFindingOption : null;

        $case = new BenchmarkCase(
            key: $key,
            fixCommit: $commit,
            bugClass: $bugClass,
            expectSignal: $expectSignal,
            maxRisk: $maxRisk,
            expectFinding: $expectFinding,
            maxHazardTier: $maxHazardTier,
        );

        $failures = $case->evaluate($result);

        if ($failures === []) {
            $this->info('Would currently PASS richter:benchmark.');
        } else {
            foreach ($failures as $failure) {
                $this->error("Would currently FAIL — {$failure}");
            }
        }

        $this->printStanza($key, $commit, $bugClass, $expectSignal, $maxRisk, $expectFinding, $maxHazardTier);

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

    /**
     * The worst tier among the hazards a replay produced, or 0 when it produced none.
     *
     * @param  list<Hazard>  $hazards
     */
    private function worstTier(array $hazards): int
    {
        return array_reduce($hazards, static fn (int $worst, Hazard $hazard): int => max($worst, $hazard->tier), 0);
    }

    private function printStanza(string $key, string $commit, string $bugClass, bool $expectSignal, RiskLevel $maxRisk, ?string $expectFinding, int $maxHazardTier): void
    {
        $escapedKey = $this->escapeForSingleQuotedString($key);
        $escapedCommit = $this->escapeForSingleQuotedString($commit);
        $escapedBugClass = $this->escapeForSingleQuotedString($bugClass);
        $expectSignalLiteral = $expectSignal ? 'true' : 'false';

        $this->newLine();
        $this->line('Add this entry to the benchmark_cases list in config/richter.php:');
        $this->newLine();

        // The stanza is PASTED into a config file by hand, so its bytes are the deliverable: the fixed
        // four- and eight-space indentation, and values that came from this command's own input. A host
        // cleaner that collapses whitespace runs would change what the user copies, and one that strips a
        // glyph would change a value they asked for. The heading above it is prose and stays compacted.
        // {@see WritesDocuments}
        $stanza = [
            '    [',
            "        'key' => '{$escapedKey}',",
            "        'fix_commit' => '{$escapedCommit}',",
            "        'bug_class' => '{$escapedBugClass}',",
            "        'expect_signal' => {$expectSignalLiteral},",
            "        'max_risk' => '{$maxRisk->value}',",
        ];

        if ($maxHazardTier < 3) {
            $stanza[] = "        'max_hazard_tier' => {$maxHazardTier},";
        }

        if ($expectFinding !== null) {
            $stanza[] = "        'expect_finding' => '" . $this->escapeForSingleQuotedString($expectFinding) . "',";
        }

        $stanza[] = '    ],';

        // One write per line, not one write for the block. Both are equally protected — the cleaner acts
        // per write either way — but `expectsOutputToContain()` registers one Mockery expectation per
        // underlying write call, and one call can satisfy only one expectation, so a batched block would
        // leave every assertion after the first unmatched.
        foreach ($stanza as $line) {
            $this->writeDocument($line);
        }
    }

    private function escapeForSingleQuotedString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
