<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Console\Concerns\WarnsAboutEntryPointCoverage;
use SanderMuller\Richter\Console\Concerns\WarnsAboutRootNamespace;
use SanderMuller\Richter\Console\Concerns\WritesDocuments;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\GraphCache;
use Throwable;

final class ImpactCommand extends Command
{
    use WarnsAboutEntryPointCoverage;
    use WarnsAboutRootNamespace;
    use WritesDocuments;

    /** @var string */
    protected $signature = 'richter:impact
        {symbol : An FQCN or substring to analyse, e.g. "App\\Models\\User"}
        {--explain : Show the call chain from each reached entry surface down to the symbol}
        {--json : Emit the blast radius as JSON on stdout}
        {--markdown : Emit the blast radius as GitHub-flavoured markdown, for PR descriptions and comments}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'Show the static blast radius (callers and dependencies) of a code symbol';

    public function handle(GraphCache $graphs): int
    {
        $symbol = (string) $this->argument('symbol');
        $markdown = (bool) $this->option('markdown');

        $this->warnAboutRootNamespace();

        if ($this->option('json')) {
            if ($markdown) {
                // JSON mode owns stdout even for usage errors — one parseable document, never plain text.
                $this->writeDocument(JsonPresenter::encode(['error' => 'The --json and --markdown options are mutually exclusive.']));

                return self::FAILURE;
            }

            return $this->handleJson($graphs, $symbol);
        }

        if (! $markdown) {
            // Markdown lands in a PR field; a progress line would pollute the pasteable document.
            $this->info('Resolving code graph…');
        }

        $graph = $this->graph($graphs);
        $result = new ImpactAnalyzer($graph)->impact($symbol);
        $explain = (bool) $this->option('explain');
        $tests = $this->testIndexFor($result, $graph);

        if ($markdown) {
            // Markdown carries meaning in its blank lines, its two-space nesting and its `→`/`⚠` glyphs, so
            // it goes where a rebound OutputStyle cannot rewrite it. The prose report loses only
            // presentation. {@see WritesDocuments}
            $this->writeDocument(MarkdownFormatter::impact($result, $tests, $explain));
        } else {
            $this->line(ImpactFormatter::impact($result, $tests, $explain));
        }

        return self::SUCCESS;
    }

    /**
     * Lazy: the tests/ scan only runs when the walk actually reached an entry surface,
     * so the common no-entry-surface call pays nothing new.
     *
     * The graph is passed in rather than fetched: it is the one the walk already ran on, and a second
     * {@see graph()} call would revive a whole second graph. Without it a class-driven route reads
     * unreferenced here while `detect-changes` reports the same route referenced.
     *
     * @param  array{entryPoints: list<string>, ...}  $result
     */
    private function testIndexFor(array $result, CodeGraph $graph): ?TestReferenceIndex
    {
        if ($result['entryPoints'] === []) {
            return null;
        }

        $tests = TestReferenceIndex::fromTests(base_path('tests'), base_path());
        $tests->useGraph($graph);

        return $tests;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout (no progress line), so the output is a
     * single parseable value. Any error becomes `{"error": …}` rather than a leaked stack trace.
     */
    private function handleJson(GraphCache $graphs, string $symbol): int
    {
        try {
            $graph = $this->graph($graphs);
            $result = new ImpactAnalyzer($graph)->impact($symbol);

            $this->writeDocument(JsonPresenter::encode(JsonPresenter::impact($result, $this->testIndexFor($result, $graph))));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->writeDocument(JsonPresenter::encode(['error' => $throwable->getMessage()]));

            return self::FAILURE;
        }
    }

    private function graph(GraphCache $graphs): CodeGraph
    {
        $graph = $graphs->graph(fresh: (bool) $this->option('no-cache'));

        $this->warnAboutEntryPointCoverage($graph);

        return $graph;
    }
}
