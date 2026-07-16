<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use RuntimeException;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * Advisory change-impact report for the current diff. Prints which entry points and flows the
 * changed files can reach plus a coarse risk level. Run locally by an engineer or agent as a
 * self-review aid — never a gate.
 */
final class DetectChangesCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:detect-changes {--base= : Git ref to diff the current branch against (defaults to the richter.default_base config value)}';

    /** @var string */
    protected $description = 'Report the advisory blast radius of the current branch diff (entry points reached + risk)';

    public function handle(CodeGraphBuilder $builder): int
    {
        $base = RichterConfig::baseRef($this->option('base'));

        try {
            $changed = ChangedSymbols::resolve($base);
        } catch (RuntimeException $runtimeException) {
            // Advisory tooling exits successfully, but a broken diff must read as an error,
            // not as a falsely empty blast radius.
            $this->warn($runtimeException->getMessage());

            return self::SUCCESS;
        }

        if ($changed === []) {
            $this->info("No changed PHP files under app/ against {$base}.");

            return self::SUCCESS;
        }

        $result = new ImpactAnalyzer($builder->build())->detectChanges($changed);

        $this->line(ImpactFormatter::detectChanges($result, TestReferenceIndex::fromTests(base_path('tests'))));

        return self::SUCCESS;
    }
}
