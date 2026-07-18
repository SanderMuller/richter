<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\GraphCache;
use Throwable;

final class ImpactCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:impact
        {symbol : An FQCN or substring to analyse, e.g. "App\\Models\\User"}
        {--json : Emit the blast radius as JSON on stdout}
        {--markdown : Emit the blast radius as GitHub-flavoured markdown, for PR descriptions and comments}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'Show the static blast radius (callers and dependencies) of a code symbol';

    public function handle(GraphCache $graphs): int
    {
        $symbol = (string) $this->argument('symbol');
        $markdown = (bool) $this->option('markdown');

        if ($this->option('json')) {
            if ($markdown) {
                // JSON mode owns stdout even for usage errors — one parseable document, never plain text.
                $this->line(JsonPresenter::encode(['error' => 'The --json and --markdown options are mutually exclusive.']));

                return self::FAILURE;
            }

            return $this->handleJson($graphs, $symbol);
        }

        if (! $markdown) {
            // Markdown lands in a PR field; a progress line would pollute the pasteable document.
            $this->info('Resolving code graph…');
        }

        $result = new ImpactAnalyzer($this->graph($graphs))->impact($symbol);

        $this->line($markdown ? MarkdownFormatter::impact($result) : ImpactFormatter::impact($result));

        return self::SUCCESS;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout (no progress line), so the output is a
     * single parseable value. Any error becomes `{"error": …}` rather than a leaked stack trace.
     */
    private function handleJson(GraphCache $graphs, string $symbol): int
    {
        try {
            $result = new ImpactAnalyzer($this->graph($graphs))->impact($symbol);

            $this->line(JsonPresenter::encode(JsonPresenter::impact($result)));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line(JsonPresenter::encode(['error' => $throwable->getMessage()]));

            return self::FAILURE;
        }
    }

    private function graph(GraphCache $graphs): CodeGraph
    {
        return $graphs->graph(fresh: (bool) $this->option('no-cache'));
    }
}
