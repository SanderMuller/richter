<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use SanderMuller\Richter\Analysis\AffectedTests;
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

        $requestedBase = $this->option('base');

        try {
            try {
                $base = RichterConfig::baseRef($requestedBase);
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
                $graph,
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

    /** @param  array{determinable: bool, reasons: list<string>, tests: list<string>, unreferencedEntryPoints: int}  $selection */
    private function emit(bool $json, bool $plain, string $base, array $selection): int
    {
        $exit = $selection['determinable'] ? self::SUCCESS : self::UNDETERMINED;

        if ($json) {
            $this->line(JsonPresenter::encode(['base' => $base] + $selection));

            return $exit;
        }

        if ($plain) {
            // Only a determinable selection may print — an undetermined one keeps stdout empty so
            // command substitution degrades to the full suite.
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
            'unreferencedEntryPoints' => 0,
        ]);
    }
}
