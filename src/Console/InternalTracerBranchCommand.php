<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\TracerBranchRunner;
use Throwable;

/**
 * Internal worker for the parallel graph build (plan 046): runs {@see CodeGraphBuilder::buildTracerBranch()}
 * in a child process and writes the JSON result to `--out`. {@see TracerBranchRunner}
 * spawns it and reads the file. Hidden — never invoked by hand; on any failure it exits non-zero so
 * the parent falls back to the in-process branch.
 */
final class InternalTracerBranchCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:internal-tracer-branch
        {--project= : Project root to analyse}
        {--out= : File to write the JSON tracer-branch result to}';

    /** @var string */
    protected $description = "Internal: build richter's tracer branch in a child process (plan 046). Not for direct use.";

    protected $hidden = true;

    public function handle(): int
    {
        $project = (string) $this->option('project');
        $out = (string) $this->option('out');

        if ($project === '' || $out === '') {
            return self::FAILURE;
        }

        try {
            $branch = new CodeGraphBuilder()->buildTracerBranch($project);

            $written = @file_put_contents($out, json_encode($branch, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return self::FAILURE;
        }

        return $written === false ? self::FAILURE : self::SUCCESS;
    }
}
