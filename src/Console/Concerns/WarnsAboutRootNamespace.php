<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console\Concerns;

use SanderMuller\Richter\Support\AppNamespace;

/**
 * Every command's one-line honesty check on the namespace it traced. An app whose PSR-4 root for
 * `app/` is not the root richter resolved has no app classes under that root at all, so the report
 * can only under-report — and an under-reported result is the failure mode this package exists to
 * prevent. Printing which root was used turns a puzzling empty report into a one-line diagnosis.
 *
 * Stderr only, so `--json`/`--plain`/`--markdown` stdout stays a single parseable/pasteable document.
 */
trait WarnsAboutRootNamespace
{
    private function warnAboutRootNamespace(): void
    {
        $note = AppNamespace::unmatchedRootNote();

        if ($note === null) {
            return;
        }

        $this->getOutput()->getErrorStyle()->writeln($note);
    }
}
