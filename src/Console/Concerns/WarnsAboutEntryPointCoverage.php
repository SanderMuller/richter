<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console\Concerns;

use SanderMuller\Richter\Analysis\EntryPointRootCoverage;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * The companion to {@see WarnsAboutRootNamespace}: that one catches a whole app traced under the
 * wrong root, this one catches a whole subsystem missing from the graph because its directory is
 * not a configured entry-point root ({@see EntryPointRootCoverage}). Both turn a report that is
 * quietly narrower than the app into a one-line diagnosis.
 *
 * Stderr only, so `--json`/`--plain`/`--markdown` stdout stays a single parseable/pasteable
 * document.
 *
 * Not wired into `richter:affected-tests`: that command never holds the graph (it resolves one
 * inside {@see AffectedTests::selectForCurrentDiff()}, past several early returns that build none),
 * and its stdout contract is a test list on a CI hot path. The three reporting commands are where a
 * reader is looking at coverage anyway.
 */
trait WarnsAboutEntryPointCoverage
{
    private function warnAboutEntryPointCoverage(CodeGraph $graph): void
    {
        $notes = EntryPointRootCoverage::notes(base_path(), $graph, RichterConfig::entryPointRoots());

        if ($notes === []) {
            return;
        }

        $this->getOutput()->getErrorStyle()->writeln($notes);
    }
}
