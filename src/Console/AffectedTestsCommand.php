<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\FrontendTestIndex;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Support\RichterConfig;
use Throwable;

/**
 * Prints the test files exercising the surface the current diff can reach, so a consumer can run
 * `php artisan test $(php artisan richter:affected-tests --plain)` instead of the full suite.
 *
 * Exit codes are the contract: 0 = the selection is determinable (possibly empty), 2 = it is not —
 * run the full suite. In `--plain` mode an undeterminable run prints nothing, which makes the
 * command-substitution form fail safe by construction: empty arguments mean the runner executes
 * everything. Selection is reference-based recall, not proof of coverage — reached entry points
 * nothing references contribute nothing, and the report says how many those are.
 */
final class AffectedTestsCommand extends Command
{
    /** The selection could not be determined — the caller must run the full suite. */
    public const int UNDETERMINED = 2;

    /** @var string */
    protected $signature = 'richter:affected-tests
        {--base= : Git ref to diff the current branch against (defaults to the richter.default_base config value)}
        {--json : Emit the selection as JSON on stdout}
        {--plain : Print one test path per line and nothing else — for command substitution}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'List the test files exercising the surface the current branch diff can reach';

    public function handle(GraphCache $graphs): int
    {
        $json = (bool) $this->option('json');
        $plain = (bool) $this->option('plain');

        if ($json && $plain) {
            // With --json present the usage error honours the JSON contract: stdout stays one parseable document.
            $this->line(JsonPresenter::encode(['error' => 'The --json and --plain options are mutually exclusive.']));

            return self::FAILURE;
        }

        $untracked = ChangedSymbols::untrackedRelevantFiles();
        $this->warnAboutUntrackedFiles($untracked);

        $requestedBase = $this->option('base');

        try {
            try {
                $base = RichterConfig::baseRef($requestedBase);

                if ($untracked !== []) {
                    // An untracked (never `git add`-ed) file under app/, resources/views/, or a
                    // frontend root is invisible to every diff form — the one gap this command's
                    // analysis can never close. The cardinal rule is never under-selecting, so a
                    // tracked change existing alongside it does not save the selection: narrowing
                    // to what the diff alone can see would silently drop the untracked surface.
                    // Fail toward the full suite instead, on whichever surface the mode allows.
                    return $this->emitUndetermined($json, $plain, $base, [sprintf(
                        '%d untracked file(s) under app/, resources/views/, or a configured frontend root can\'t be analysed — `git add` them or run the full suite: %s',
                        count($untracked),
                        implode(', ', $untracked),
                    )]);
                }

                $changed = ChangedSymbols::resolve($base);
            } catch (InvalidArgumentException|RuntimeException $exception) {
                // A diff that can't be taken means the selection can't be determined — fail toward
                // the full suite, with the reason on the surface the mode allows.
                return $this->emitUndetermined($json, $plain, is_string($requestedBase) ? $requestedBase : '', [$exception->getMessage()]);
            }

            if ($changed === []) {
                return $this->emit($json, $plain, $base, [
                    'determinable' => true,
                    'reasons' => [],
                    'tests' => [],
                    'frontendTests' => [],
                    'unreferencedEntryPoints' => 0,
                ]);
            }

            $graph = $graphs->graph(fresh: (bool) $this->option('no-cache'));
            $result = new ImpactAnalyzer($graph)->detectChanges($changed);
            $selection = AffectedTests::select(
                $result,
                $changed,
                TestReferenceIndex::fromTests(base_path('tests'), base_path()),
                $graph->hasUnresolvedDispatches(),
                $graph->hasUnparseableFiles(),
                $graph,
                $this->frontendTestIndex(),
            );

            return $this->emit($json, $plain, $base, $selection);
        } catch (Throwable $throwable) {
            // Backstop: an unexpected failure is not "no affected tests" — in JSON stdout stays a
            // single parseable document, in plain stdout stays empty (= run everything).
            if ($json) {
                $this->line(JsonPresenter::encode(['error' => $throwable->getMessage()]));

                return self::FAILURE;
            }

            if ($plain) {
                return self::FAILURE;
            }

            throw $throwable;
        }
    }

    /**
     * `git diff` never shows an untracked (never `git add`-ed) file, HEAD-mode or otherwise — the one
     * gap the diff-form fix can't close. Stderr only, so `--json`/`--plain` stdout stays a single
     * parseable document or contract-clean output (a bare `--plain` selection, or nothing at all).
     * Below this, `handle()` additionally forces the selection itself undetermined — this command's
     * one-line note is not enough on its own, since a silently narrowed selection is exactly the
     * under-selection this tool exists to prevent.
     *
     * @param  list<string>  $untracked
     */
    private function warnAboutUntrackedFiles(array $untracked): void
    {
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
     * The frontend spec index, only when the bridge (or an explicit test path) is configured —
     * an unconfigured project must not pay a directory scan per run.
     */
    private function frontendTestIndex(): ?FrontendTestIndex
    {
        if (RichterConfig::frontendRoots() === [] && RichterConfig::frontendTestPaths() === []) {
            return null;
        }

        return FrontendTestIndex::fromConfiguredPaths(base_path());
    }

    /** @param  array{determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int}  $selection */
    private function emit(bool $json, bool $plain, string $base, array $selection): int
    {
        $exit = $selection['determinable'] ? self::SUCCESS : self::UNDETERMINED;

        if ($json) {
            $this->line(JsonPresenter::encode(['base' => $base] + $selection));

            return $exit;
        }

        if ($plain) {
            // Only a determinable selection may print — an undetermined one keeps stdout empty so
            // command substitution degrades to the full suite. Frontend specs never print here:
            // this output feeds the PHP test runner.
            if ($selection['determinable']) {
                foreach ($selection['tests'] as $test) {
                    $this->line($test);
                }
            }

            return $exit;
        }

        if (! $selection['determinable']) {
            $this->warn('Affected tests could not be determined — run the full suite.');

            foreach ($selection['reasons'] as $reason) {
                $this->line("  ! {$reason}");
            }

            return $exit;
        }

        $this->line('Affected tests: ' . count($selection['tests']));

        foreach ($selection['tests'] as $test) {
            $this->line("  - {$test}");
        }

        if ($selection['frontendTests'] !== []) {
            $this->line('Frontend specs referencing the touched routes (run with your JS runner): ' . count($selection['frontendTests']));

            foreach ($selection['frontendTests'] as $test) {
                $this->line("  - {$test}");
            }
        }

        if ($selection['unreferencedEntryPoints'] > 0) {
            $this->line("Note: {$selection['unreferencedEntryPoints']} reached entry point(s) have no referencing test — the selection cannot cover them.");
        }

        return $exit;
    }

    /** @param  list<string>  $reasons */
    private function emitUndetermined(bool $json, bool $plain, string $base, array $reasons): int
    {
        return $this->emit($json, $plain, $base, [
            'determinable' => false,
            'reasons' => $reasons,
            'tests' => [],
            'frontendTests' => [],
            'unreferencedEntryPoints' => 0,
        ]);
    }
}
