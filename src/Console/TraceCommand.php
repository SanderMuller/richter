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
use SanderMuller\Richter\Console\Concerns\WritesDocuments;
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
    use WritesDocuments;

    /** @var string */
    protected $signature = 'richter:trace
        {from : Symbol the path starts at — an FQCN or substring, e.g. "App\\Http\\Controllers\\PostController"}
        {to : Symbol the path must reach, in call direction}
        {--depth= : How many hops to search before giving up (default 6). Raise it when a miss reports a deepest-caller note — that note means the walk ran out of depth, not that no path exists}
        {--json : Emit the trace as JSON on stdout}
        {--markdown : Emit the trace as GitHub-flavoured markdown, for PR descriptions and comments}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'Show the shortest call-direction path from one symbol to another';

    /**
     * A miss at the default depth is indistinguishable from no path at all — the report says which
     * caller it got to and that the limit stopped it, but without this flag there was no way to ask
     * the follow-up question.
     */
    private function maxDepth(): int
    {
        $option = $this->option('depth');

        if ($option === null) {
            return 6;
        }

        if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
            throw new InvalidArgumentException('The --depth option must be a whole number of 1 or more.');
        }

        return (int) $option;
    }

    /** A usage error reaches stdout as JSON in --json mode, so that contract holds even here. */
    private function usageError(string $message): int
    {
        if ($this->option('json')) {
            $this->writeDocument(JsonPresenter::encode(['error' => $message]));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    public function handle(GraphCache $graphs): int
    {
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');
        $markdown = (bool) $this->option('markdown');

        // Validated before anything expensive: a bad option is a usage error, and building the graph
        // first would make the user wait to be told they mistyped a flag.
        try {
            $maxDepth = $this->maxDepth();
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        $this->warnAboutRootNamespace();

        if ($this->option('json')) {
            if ($markdown) {
                // JSON mode owns stdout even for usage errors — one parseable document, never plain text.
                $this->writeDocument(JsonPresenter::encode(['error' => 'The --json and --markdown options are mutually exclusive.']));

                return self::FAILURE;
            }

            return $this->handleJson($graphs, $from, $to, $maxDepth);
        }

        if (! $markdown) {
            // Markdown lands in a PR field; a progress line would pollute the pasteable document.
            $this->info('Resolving code graph…');
        }

        try {
            $result = new ImpactAnalyzer($this->graph($graphs))->trace($from, $to, $maxDepth);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        if ($markdown) {
            // Markdown carries meaning in its blank lines, its two-space nesting and its `→`/`⚠` glyphs, so
            // it goes where a rebound OutputStyle cannot rewrite it. The prose report loses only
            // presentation. {@see WritesDocuments}
            $this->writeDocument(MarkdownFormatter::trace($result));
        } else {
            $this->line(ImpactFormatter::trace($result));
        }

        return self::SUCCESS;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout (no progress line), so the output is a
     * single parseable value. Any error becomes `{"error": …}` rather than a leaked stack trace.
     */
    private function handleJson(GraphCache $graphs, string $from, string $to, int $maxDepth): int
    {
        try {
            $result = new ImpactAnalyzer($this->graph($graphs))->trace($from, $to, $maxDepth);

            $this->writeDocument(JsonPresenter::encode(JsonPresenter::trace($result)));

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
