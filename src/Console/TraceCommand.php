<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Console\Concerns\WarnsAboutEntryPointCoverage;
use SanderMuller\Richter\Console\Concerns\WarnsAboutRootNamespace;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\GraphCache;
use Throwable;

/**
 * Shortest directed path between two symbols, in call direction — "does FROM reach TO,
 * and through which chain?". Strictly directional: swapping the arguments queries the
 * reverse. A no-path result is data, not an error (exit 0); an unresolvable symbol is an
 * error — an empty trace would read as "no path", the one misleading answer this command
 * must never give.
 */
final class TraceCommand extends Command
{
    use WarnsAboutEntryPointCoverage;
    use WarnsAboutRootNamespace;

    /** @var string */
    protected $signature = 'richter:trace
        {from : Symbol the path starts at — an FQCN or substring, e.g. "App\\Http\\Controllers\\PostController"}
        {to : Symbol the path must reach, in call direction}
        {--json : Emit the trace as JSON on stdout}
        {--markdown : Emit the trace as GitHub-flavoured markdown, for PR descriptions and comments}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'Show the shortest call-direction path from one symbol to another';

    public function handle(GraphCache $graphs): int
    {
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');
        $markdown = (bool) $this->option('markdown');

        $this->warnAboutRootNamespace();

        if ($this->option('json')) {
            if ($markdown) {
                // JSON mode owns stdout even for usage errors — one parseable document, never plain text.
                $this->line(JsonPresenter::encode(['error' => 'The --json and --markdown options are mutually exclusive.']));

                return self::FAILURE;
            }

            return $this->handleJson($graphs, $from, $to);
        }

        if (! $markdown) {
            // Markdown lands in a PR field; a progress line would pollute the pasteable document.
            $this->info('Resolving code graph…');
        }

        try {
            $result = new ImpactAnalyzer($this->graph($graphs))->trace($from, $to);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        $this->line($markdown ? MarkdownFormatter::trace($result) : ImpactFormatter::trace($result));

        return self::SUCCESS;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout (no progress line), so the output is a
     * single parseable value. Any error becomes `{"error": …}` rather than a leaked stack trace.
     */
    private function handleJson(GraphCache $graphs, string $from, string $to): int
    {
        try {
            $result = new ImpactAnalyzer($this->graph($graphs))->trace($from, $to);

            $this->line(JsonPresenter::encode(JsonPresenter::trace($result)));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line(JsonPresenter::encode(['error' => $throwable->getMessage()]));

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
