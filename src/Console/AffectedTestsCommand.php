<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Console\Concerns\WarnsAboutRootNamespace;
use SanderMuller\Richter\Graph\GraphCache;
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
    use WarnsAboutRootNamespace;

    /** The selection could not be determined — the caller must run the full suite. */
    public const int UNDETERMINED = 2;

    /** @var string */
    protected $signature = 'richter:affected-tests
        {--base= : Git ref to diff the current branch against (defaults to the richter.default_base config value)}
        {--head= : Select against the COMMITTED tree of this ref instead of the working tree}
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

        $this->warnAboutRootNamespace();
        try {
            $base = $this->option('base');
            $head = $this->option('head');
            $selection = AffectedTests::selectForCurrentDiff(
                $graphs,
                is_string($base) ? $base : null,
                (bool) $this->option('no-cache'),
                is_string($head) ? $head : null,
            );

            $this->warnAboutUntrackedFiles($selection['untrackedFiles']);

            return $this->emit($json, $plain, $selection);
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
     * {@see AffectedTests::selectForCurrentDiff()} additionally forces the selection itself
     * undetermined — this one-line note is not enough on its own, since a silently narrowed
     * selection is exactly the under-selection this tool exists to prevent.
     *
     * @param  list<string>  $untracked
     */
    private function warnAboutUntrackedFiles(array $untracked): void
    {
        if ($untracked === []) {
            return;
        }

        $this->getOutput()->getErrorStyle()->writeln(sprintf(
            'Note: %d untracked file(s) are invisible to `git diff` and were not analysed: %s',
            count($untracked),
            implode(', ', $untracked),
        ));
    }

    /** @param  array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, untrackedFiles: list<string>}  $selection */
    private function emit(bool $json, bool $plain, array $selection): int
    {
        $exit = $selection['determinable'] ? self::SUCCESS : self::UNDETERMINED;

        // `untrackedFiles` feeds the stderr note only — every stdout document keeps the
        // declared shape.
        unset($selection['untrackedFiles']);

        if ($json) {
            $this->line(JsonPresenter::encode($selection));

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

            // What the run did name, printed as a floor rather than a selection. It is never enough
            // on its own — that is what the verdict above says — but "nothing" reads as "the tool
            // found no connection", which is a different and wrong statement. Stdout stays empty in
            // `--plain`, so the full-suite fallback a CI script depends on is unchanged.
            if ($selection['tests'] !== []) {
                $this->line('');
                $this->line('Tests this run could still name (a floor, not the selection):');

                foreach ($selection['tests'] as $test) {
                    $this->line("  - {$test}");
                }
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
}
