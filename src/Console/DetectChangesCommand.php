<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use SanderMuller\Richter\Analysis\EditorLink;
use SanderMuller\Richter\Analysis\Gate;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Console\Concerns\WarnsAboutEntryPointCoverage;
use SanderMuller\Richter\Console\Concerns\WarnsAboutRootNamespace;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\RichterConfig;
use Throwable;

/**
 * Advisory change-impact report for the current diff. Prints which entry points and flows the
 * changed files can reach plus a coarse risk level. Advisory by default (exit 0, never a gate);
 * `--fail-on` / `--fail-on-unresolved` opt into a non-zero exit for CI.
 *
 * @phpstan-import-type DetectChangesResult from HtmlFormatter
 */
final class DetectChangesCommand extends Command
{
    use WarnsAboutEntryPointCoverage;
    use WarnsAboutRootNamespace;

    /** Enough to recognise what was skipped without turning a 130-file PR's note into the report. */
    private const int OUT_OF_SCOPE_NAMES_SHOWN = 5;

    /** @var string */
    protected $signature = 'richter:detect-changes
        {--base= : Git ref to diff the current branch against (defaults to the richter.default_base config value)}
        {--head= : Analyse the COMMITTED tree of this ref instead of the working tree — for a run in a dirty checkout whose uncommitted work is not the subject}
        {--json : Emit the report as JSON on stdout}
        {--markdown : Emit the report as GitHub-flavoured markdown, for PR descriptions and comments}
        {--explain : Show the call chain from each reached entry point down to the changed code (JSON always carries the chains)}
        {--fail-on= : Exit non-zero when risk is at least this level (low|medium|high); advisory by default}
        {--fail-on-unresolved : Exit non-zero when any changed PHP file is UNRESOLVED}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}
        {--no-payload-parity : Skip the payload-parity findings lanes (a model field never mirrored in a resource; a removed resource key a frontend consumer still reads)}
        {--profile : Time each graph-build phase and print the split to stderr (forces a build, and names which analysis path it took; add --no-cache to time a cold one)}
        {--html= : Write a self-contained HTML report to this path (all CSS/JS inline; opens offline)}
        {--open : Open the --html report in the default browser after writing it}';

    /** @var string */
    protected $description = 'Report the advisory blast radius of the current branch diff (entry points reached + risk)';

    public function handle(GraphCache $graphs): int
    {
        $json = (bool) $this->option('json');

        if ($json && (bool) $this->option('markdown')) {
            // With --json present the usage error honours the JSON contract: stdout stays one parseable document.
            return $this->emitFailure($json, 'The --json and --markdown options are mutually exclusive.');
        }

        $html = $this->option('html');

        if ($html !== null && ($json || (bool) $this->option('markdown'))) {
            return $this->emitFailure($json, 'The --html option cannot be combined with --json or --markdown.');
        }

        if ($html === '') {
            return $this->emitFailure($json, 'The --html option requires a path: --html=<path>.');
        }

        // --open without --html would silently do nothing; fail instead.
        if ($html === null && (bool) $this->option('open')) {
            return $this->emitFailure($json, 'The --open option requires --html=<path>.');
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

        $this->warnAboutRootNamespace();
        $this->warnAboutUntrackedFiles();

        return $json
            ? $this->handleJson($graphs, $failOn, $failOnUnresolved, $gateActive)
            : $this->handleText($graphs, $failOn, $failOnUnresolved, $gateActive);
    }

    /**
     * `git diff` never shows an untracked (never `git add`-ed) file — the one gap the diff-form fix
     * can't close. Stderr only, so `--json`/`--plain` stdout stays a single parseable document or
     * contract-clean output.
     *
     * Silent under `--head`: that run analyses a COMMITTED tree, which a file never `git add`-ed
     * cannot be part of, so naming it would report a gap the run does not have.
     */
    private function warnAboutUntrackedFiles(): void
    {
        if (RichterConfig::headRef($this->option('head')) !== 'HEAD') {
            return;
        }

        $untracked = ChangedSymbols::untrackedRelevantFiles();

        if ($untracked === []) {
            return;
        }

        $this->getOutput()->getErrorStyle()->writeln(sprintf(
            'Note: %d untracked file(s) under app/, resources/views/, or a configured frontend root are invisible to `git diff` and were not analysed: %s',
            count($untracked),
            implode(', ', $untracked),
        ));
    }

    /**
     * A diff can consist entirely of files no lane analyses — a stylesheet, a CI workflow, a
     * lockfile. The report then reads `No changed PHP files under app/`, which is true of the
     * analyser and false of the diff, and a reader takes it for "nothing to review here". Naming
     * the count says which of the two it is.
     *
     * Stderr, like the untracked note above and for the same reason: `--json`/`--markdown` stdout
     * stays exactly the report. Capped at five names because a large PR can drop dozens.
     *
     * @param  list<string>  $outOfScope
     */
    private function noteOutOfScopeFiles(array $outOfScope): void
    {
        if ($outOfScope === []) {
            return;
        }

        $shown = array_slice($outOfScope, 0, self::OUT_OF_SCOPE_NAMES_SHOWN);
        $remaining = count($outOfScope) - count($shown);

        $this->getOutput()->getErrorStyle()->writeln(sprintf(
            'Note: %d changed file(s) are outside the analysed scope (not PHP under app/, a Blade view, or a configured frontend root) and were not analysed: %s%s',
            count($outOfScope),
            implode(', ', $shown),
            $remaining > 0 ? ", and {$remaining} more" : '',
        ));
    }

    private function handleText(GraphCache $graphs, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
    {
        try {
            $base = RichterConfig::baseRef($this->option('base'));
            ['changed' => $changed, 'outOfScope' => $outOfScope] = ChangedSymbols::resolveWithScope($base, RichterConfig::headRef($this->option('head')));
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

        $this->noteOutOfScopeFiles($outOfScope);

        if ($changed === []) {
            return $this->reportEmptyDiff($base, $failOn, $failOnUnresolved, $gateActive);
        }

        $result = new ImpactAnalyzer($this->graph($graphs))->detectChanges($changed, payloadParityEnabled: $this->payloadParityEnabled());

        $markdown = (bool) $this->option('markdown');
        $tests = TestReferenceIndex::fromTests(base_path('tests'));
        $explain = (bool) $this->option('explain');
        $htmlPath = $this->option('html');

        $gate = $gateActive
            ? Gate::evaluate($result['risk'], $this->unresolvedCount($result['coverage']), $failOn, $failOnUnresolved)
            : null;

        if ($htmlPath !== null) {
            if (! $this->writeHtml($htmlPath, $result, $changed, $base, $tests, $gateActive, $gate, $failOn, $failOnUnresolved)) {
                return self::FAILURE;
            }
        } else {
            $this->line($markdown
                ? MarkdownFormatter::detectChanges($result, $tests, $gateActive, $explain, AppNamespace::unmatchedRootNote())
                : ImpactFormatter::detectChanges($result, $tests, $gateActive, $explain));
        }

        if (! $gateActive || $gate === null) {
            return self::SUCCESS;
        }

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
                ['changed' => $changed, 'outOfScope' => $outOfScope] = ChangedSymbols::resolveWithScope($base, RichterConfig::headRef($this->option('head')));
            } catch (InvalidArgumentException|RuntimeException $expected) {
                // Expected operational failures (bad/option-shaped ref, broken diff): advisory unless gated.
                return $this->jsonError($expected->getMessage(), $gateActive ? self::FAILURE : self::SUCCESS);
            }

            $this->noteOutOfScopeFiles($outOfScope);

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

        $result = new ImpactAnalyzer($this->graph($graphs))->detectChanges($changed, payloadParityEnabled: $this->payloadParityEnabled());
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
     * Write even on an empty diff, so CI never links a report that was never produced. The gate is
     * carried through and rendered as an untripped verdict, matching the JSON path: an empty diff
     * always passes, but a report from a gated run must not claim it was advisory.
     */
    private function reportEmptyDiff(string $base, ?RiskLevel $failOn, bool $failOnUnresolved, bool $gateActive): int
    {
        $this->info("No changed PHP files under app/ against {$base}.");

        $path = $this->option('html');
        $gate = $gateActive ? ['tripped' => false, 'reasons' => []] : null;

        if ($path !== null && ! $this->writeHtml($path, ImpactAnalyzer::emptyDetectChanges(), [], $base, null, $gateActive, $gate, $failOn, $failOnUnresolved)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * A failed write is reported as a failure, never announced as a success — a report CI links but
     * cannot open is the artifact-shaped version of a falsely reassuring "no impact".
     *
     * @param  DetectChangesResult  $result
     * @param  list<ChangedFileSymbols>  $changed
     * @param  array{tripped: bool, reasons: list<string>}|null  $gate
     */
    private function writeHtml(string $path, array $result, array $changed, string $base, ?TestReferenceIndex $tests, bool $gateActive, ?array $gate, ?RiskLevel $failOn, bool $failOnUnresolved): bool
    {
        $verdict = $gate === null ? null : $this->gatePayload($gate['tripped'], $gate['reasons'], $failOn, $failOnUnresolved);
        $editor = EditorLink::fromConfig(RichterConfig::editor(), base_path());
        $html = HtmlFormatter::detectChanges($result, $changed, $base, $tests, $gateActive, $verdict, $editor);

        if (@file_put_contents($path, $html) === false) {
            $this->error("Could not write the report to {$path}.");

            return false;
        }

        $this->info("Report written to {$path}");

        if ((bool) $this->option('open')) {
            $this->openInBrowser($path);
        }

        return true;
    }

    /** Best-effort: the report is on disk already, so an opener failure must not fail the run. */
    private function openInBrowser(string $path): void
    {
        // `start` is a cmd builtin, not an executable, and its first quoted argument is the window
        // title — without the empty title it opens a blank console instead of the report.
        $opener = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $path],
            'Windows' => ['cmd', '/c', 'start', '', $path],
            default => ['xdg-open', $path],
        };

        if (! Process::run($opener)->successful()) {
            $this->warn("Could not open {$path} — open it manually.");
        }
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

    /** null (config decides) unless `--no-payload-parity` explicitly forces the lane off. */
    private function payloadParityEnabled(): ?bool
    {
        return (bool) $this->option('no-payload-parity') ? false : null;
    }

    private function graph(GraphCache $graphs): CodeGraph
    {
        if (! (bool) $this->option('profile')) {
            $graph = $graphs->graph(fresh: (bool) $this->option('no-cache'));

            $this->warnAboutEntryPointCoverage($graph);

            return $graph;
        }

        /** @var array<string, float> $phases */
        $phases = [];
        $path = null;

        // `rebuild`, not `fresh`: refusing the cache HIT gives something to time, while keeping the
        // merge base means the timings — and the path label — describe the build this project
        // actually gets. `--no-cache` alongside still forces the cold one.
        $graph = $graphs->graph(
            fresh: (bool) $this->option('no-cache'),
            onProgress: function (string $event, array $data) use (&$phases, &$path): void {
                if ($event !== 'richter:phase' || ! is_string($data['phase'] ?? null) || ! is_float($data['seconds'] ?? null)) {
                    return;
                }

                $phases[$data['phase']] = $data['seconds'];

                if ($data['phase'] === 'brain-analyze' && is_string($data['path'] ?? null)) {
                    $path = $data['path'];
                }
            },
            rebuild: true,
        );

        $this->printProfile($phases, $path, (bool) $this->option('no-cache'));
        $this->warnAboutEntryPointCoverage($graph);

        return $graph;
    }

    /**
     * Writes the phase-by-phase timing split to STDERR, never stdout — the split composes with
     * --json (stdout stays one parseable document) and --markdown (stdout stays the pasteable body).
     *
     * @param  array<string, float>  $phases
     * @param  string|null  $path  which analysis path `brain-analyze` took, when it reported one
     */
    private function printProfile(array $phases, ?string $path, bool $cold): void
    {
        $total = array_sum($phases);
        $errorOutput = $this->getOutput()->getErrorStyle();

        // The header states only what the invocation asked for. Whether a merge base was actually
        // available and used is a fact about the run, not the flags, and the path label below is
        // where that is reported — a header claiming reuse would be wrong the moment the cache is
        // disabled or holds no entry.
        $errorOutput->writeln($cold
            ? 'Build profile (cold build, cache bypassed):'
            : 'Build profile (forced rebuild):');

        foreach ($phases as $phase => $seconds) {
            $percent = $total > 0.0 ? $seconds / $total * 100 : 0.0;
            // The path rides on this one line rather than a note of its own: "was it scoped?" is a
            // question about this phase, and a reader comparing two runs wants it beside the number.
            $label = $phase === 'brain-analyze' && $path !== null ? "{$phase} ({$path})" : $phase;
            $errorOutput->writeln(sprintf('  %-24s %6.2fs  %4.1f%%', $label, $seconds, $percent));
        }

        $errorOutput->writeln(sprintf('  %-24s %6.2fs', 'total', $total));
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
