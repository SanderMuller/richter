<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use Illuminate\Contracts\Process\InvokedProcess;

/**
 * Handle for an in-flight tracer-branch worker: the running child process plus the temp file it
 * writes its JSON result to. Produced by {@see TracerBranchRunner::start()}, consumed by
 * {@see TracerBranchRunner::finish()} (plan 046).
 *
 * @internal
 */
final readonly class PendingTracerBranch
{
    public function __construct(
        public InvokedProcess $process,
        public string $outPath,
    ) {}
}
