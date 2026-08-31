<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\TaskSlice;
use SanderMuller\Richter\Console\Concerns\WarnsAboutRootNamespace;
use SanderMuller\Richter\Console\Concerns\WritesDocuments;
use SanderMuller\Richter\Graph\GraphCache;
use Throwable;

/**
 * One document for an agent working mid-feature: the entry surfaces this task owns, which of them no
 * test proves, the hazards on the diff, the findings in the changed source, and the tests to run.
 *
 * `detect-changes` answers "what does this diff reach" and `affected-tests` answers "what should I
 * run". Neither answers "what does the work in front of me own", and a consumer joining the two and
 * filtering one of them by hand is the workflow this replaces.
 *
 * The graph is walked ONCE. The selection is computed from the same analysis this document reports, so
 * the two halves can never describe different runs — and the selection itself is untouched: a hub list
 * can make this command call a selection undeterminable, never smaller.
 *
 * @phpstan-import-type DetectChangesResult from HtmlFormatter
 */
final class TaskSliceCommand extends Command
{
    use WarnsAboutRootNamespace;
    use WritesDocuments;

    /** @var string */
    protected $signature = 'richter:task-slice
        {--base= : Git ref to diff the current branch against (defaults to the richter.default_base config value)}
        {--head= : Analyse the COMMITTED tree of this ref instead of the working tree}
        {--json : Emit the slice as JSON on stdout (the default and only machine shape)}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'The entry surfaces this task owns, plus the hazards, findings and tests that go with them';

    public function handle(GraphCache $graphs): int
    {
        $this->warnAboutRootNamespace();

        try {
            $base = $this->option('base');
            $head = $this->option('head');
            /** @var DetectChangesResult|null $analysis */
            /** @var DetectChangesResult|null $analysis */
            $analysis = null;

            $selection = AffectedTests::selectForCurrentDiff(
                $graphs,
                is_string($base) ? $base : null,
                (bool) $this->option('no-cache'),
                is_string($head) ? $head : null,
                fullAnalysis: true,
                analysisUsed: $analysis,
            );

            $this->warnAboutUntrackedFiles($selection['untrackedFiles']);
            unset($selection['untrackedFiles']);

            $document = $analysis === null
                // No analysis means the diff never got that far — an unresolvable ref, an untracked
                // file, or nothing changed. The selection already says so; the slice reports an empty
                // keep set beside it rather than inventing one.
                ? JsonPresenter::emptyDetectChanges($selection['base'])
                : JsonPresenter::detectChanges($analysis, $selection['base']);

            $this->writeDocument(JsonPresenter::encode(TaskSlice::compose($document, $selection)));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            // Stdout stays one parseable document: this command has no prose mode, so an agent
            // reading it never has to tell an error apart from a report by its shape.
            $this->writeDocument(JsonPresenter::encode(['error' => $throwable->getMessage()]));

            return self::FAILURE;
        }
    }

    /**
     * The same stderr note `affected-tests` writes, for the same reason: an untracked file is
     * invisible to every diff form, and the selection is already forced undeterminable over it.
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
}
