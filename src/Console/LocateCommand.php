<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use SanderMuller\Richter\Analysis\BoundedPresenter;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\SymbolLocator;
use SanderMuller\Richter\Console\Concerns\WarnsAboutRootNamespace;
use SanderMuller\Richter\Console\Concerns\WritesDocuments;
use SanderMuller\Richter\Graph\GraphCache;
use Throwable;

/**
 * Where a symbol or a file is, with no walk. The orientation step before `richter:impact` and
 * `richter:trace`, which both need an exact node id.
 *
 * A miss is data, not an error (exit 0): "nothing named X, nearest are Y and Z" answers the question
 * that was asked. Only a usage error fails. That is the opposite of `richter:trace`, which raises on
 * an unresolvable symbol — there, an empty result would read as "no path", the one misleading answer
 * it must never give. Here there is no such reading.
 *
 * Unlike the MCP tool, `--json` is COMPLETE by default: a script has a disk, not a context window
 * ({@see BoundedPresenter}), so the cap applies only when `--limit`
 * asks for it.
 *
 * @phpstan-import-type LocateResult from SymbolLocator
 */
final class LocateCommand extends Command
{
    use WarnsAboutRootNamespace;
    use WritesDocuments;

    /** @var string */
    protected $signature = 'richter:locate
        {--symbol= : Symbol to locate — an FQCN or substring, e.g. "App\\Models\\Post"}
        {--file= : Project-relative file whose defined nodes to list, e.g. "app/Models/Post.php"}
        {--limit= : Cap the match list. Omitted, the document is complete; the uncapped total is always reported}
        {--json : Emit the result as JSON on stdout}
        {--markdown : Emit the result as GitHub-flavoured markdown, for PR descriptions and comments}
        {--no-cache : Build the code graph fresh, bypassing the graph cache}';

    /** @var string */
    protected $description = 'Show where a symbol or file is defined, without a blast-radius walk';

    public function handle(GraphCache $graphs): int
    {
        $markdown = (bool) $this->option('markdown');

        // Every usage error is decided before the graph is built: being told you mistyped a flag
        // after a multi-second build is the one avoidable cost here.
        try {
            [$symbol, $file] = $this->target();
            $limit = $this->limit();
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->usageError($invalidArgumentException->getMessage());
        }

        if ($this->option('json')) {
            if ($markdown) {
                // JSON mode owns stdout even for usage errors — one parseable document, never plain text.
                $this->writeDocument(JsonPresenter::encode(['error' => 'The --json and --markdown options are mutually exclusive.']));

                return self::FAILURE;
            }

            return $this->handleJson($graphs, $symbol, $file, $limit);
        }

        $this->warnAboutRootNamespace();

        if (! $markdown) {
            // Markdown lands in a PR field; a progress line would pollute the pasteable document.
            $this->info('Resolving code graph…');
        }

        try {
            $result = $this->locate($graphs, $symbol, $file, $limit);
        } catch (InvalidArgumentException $invalidArgumentException) {
            // The analyzer's own guard. Unreachable while target() validates first, and caught anyway
            // so it can never surface as an unhandled exception if that ever stops being true.
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        if ($markdown) {
            $this->writeDocument(MarkdownFormatter::locate($result));
        } else {
            $this->line(ImpactFormatter::locate($result));
        }

        return self::SUCCESS;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout (no progress line), so the output is a
     * single parseable value. Any error becomes `{"error": …}` rather than a leaked stack trace.
     */
    private function handleJson(GraphCache $graphs, ?string $symbol, ?string $file, ?int $limit): int
    {
        try {
            $this->writeDocument(JsonPresenter::encode(JsonPresenter::locate($this->locate($graphs, $symbol, $file, $limit))));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->writeDocument(JsonPresenter::encode(['error' => $throwable->getMessage()]));

            return self::FAILURE;
        }
    }

    /**
     * @return LocateResult
     */
    private function locate(GraphCache $graphs, ?string $symbol, ?string $file, ?int $limit): array
    {
        $locator = new SymbolLocator($graphs->graph(fresh: (bool) $this->option('no-cache')));

        return $symbol !== null ? $locator->locateSymbol($symbol, $limit) : $locator->locateFile((string) $file, $limit);
    }

    /**
     * Exactly one of the two, never both and never neither. A blank value counts as absent: an empty
     * needle would otherwise render as a legitimate "nothing found" rather than as the typo it is.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function target(): array
    {
        $symbol = $this->given($this->option('symbol'));
        $file = $this->given($this->option('file'));

        if (($symbol === null) === ($file === null)) {
            throw new InvalidArgumentException('Pass exactly one of --symbol and --file.');
        }

        return [$symbol, $file];
    }

    private function given(mixed $option): ?string
    {
        return is_string($option) && trim($option) !== '' ? $option : null;
    }

    /** Null means no cap — the CLI default, which keeps the `--json` document complete. */
    private function limit(): ?int
    {
        $option = $this->option('limit');

        if ($option === null) {
            return null;
        }

        if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
            throw new InvalidArgumentException('The --limit option must be a whole number of 1 or more.');
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
}
