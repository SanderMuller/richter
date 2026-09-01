<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;
use SanderMuller\Richter\Analysis\BoundedPresenter;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\SymbolLocator;
use SanderMuller\Richter\Graph\GraphCache;
use Throwable;

#[IsReadOnly]
final class LocateTool extends Tool
{
    protected string $name = 'locate';

    protected string $description = 'Where a symbol or a file is, with no walk — the orientation call that precedes impact and trace, which both need an exact node id. Pass EITHER symbol (an FQCN or substring) OR file (a project-relative path), never both. Returns the matching node ids with their kind and defining file:line. A miss is data, not an error: it comes back with the nearest node ids, or with a known file sharing the name. Matches node IDS, not source text — it finds a symbol, never a behaviour, so "Post" matches App\\Models\\Post but never PostContainer.';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()
                ->description('Symbol to locate — an FQCN or substring, e.g. "App\\Models\\Post". Mutually exclusive with file.'),
            'file' => $schema->string()
                ->description('Project-relative file whose defined nodes to list, e.g. "app/Models/Post.php". An absolute path inside the project works too. Mutually exclusive with symbol.'),
            'limit' => $schema->integer()
                ->description('How many matches to return. Defaults to ' . BoundedPresenter::LIST_CAP . '; total always carries the uncapped count, so raise this to it when bounded is true.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $symbol = $this->textArgument($request, 'symbol');
        $file = $this->textArgument($request, 'file');

        if (($symbol === null) === ($file === null)) {
            return Response::error('Pass exactly one of the symbol and file arguments.');
        }

        $limit = $request->get('limit', BoundedPresenter::LIST_CAP);

        // Validated before the graph is touched: a bad argument must not cost a graph build.
        if (! is_int($limit) || $limit < 1) {
            return Response::error('The limit argument must be a whole number of 1 or more.');
        }

        try {
            $locator = new SymbolLocator($this->graphs->graph());
            $result = $symbol !== null ? $locator->locateSymbol($symbol, $limit) : $locator->locateFile((string) $file, $limit);
        } catch (Throwable $throwable) {
            // An exception escaping a tool is not a usable answer to a client — a failed graph build
            // included. The miss path never lands here; a miss is a result.
            return Response::error($throwable->getMessage());
        }

        return new ResponseFactory(Response::text(ImpactFormatter::locate($result)))
            ->withStructuredContent(JsonPresenter::locate($result));
    }

    /** A blank string is the same usage error as an absent argument, never a lookup of nothing. */
    private function textArgument(Request $request, string $name): ?string
    {
        $value = $request->get($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function outputSchema(JsonSchema $schema): array
    {
        $match = $schema->object([
            'node' => $schema->string()->description('The graph node id, verbatim — pass this to impact or trace.')->required(),
            'kind' => $schema->string()->description('What the id addresses: a known prefix (route, model, …), "class", or "member". ABSENT when richter cannot prove it; never guessed.'),
            'file' => $schema->string()->description('Project-relative defining file, when known.'),
            'line' => $schema->integer()->description('Defining line, when known.'),
        ]);

        return [
            // The five stable keys are marked required: every document carries them, and a schema
            // that permits `{}` would let a consumer read a malformed response as an empty answer.
            // Only the conditional keys below stay optional, and each is absent — never null.
            'query' => $schema->string()->description('The symbol or file as passed, trimmed.')->required(),
            'by' => $schema->string()->description('Which lane answered: "symbol" or "file".')->required(),
            'total' => $schema->integer()->description('Uncapped match count. Compare with the matches length to see what the cap held back.')->required(),
            'limit' => $schema->integer()->description('The cap that was applied. Absent when none was.'),
            'bounded' => $schema->boolean()->description('True when the cap held matches back. A bounded list is never the complete answer — raise limit to total.')->required(),
            'matches' => $schema->array()->items($match)->description('Sorted by node id, then capped, so the visible page does not depend on how the graph was built.')->required(),
            'suggestions' => $schema->array()->items($schema->string())->description('Only on a miss: the nearest node ids for a symbol, or one known file sharing the queried name.'),
            'graphNodeCount' => $schema->integer()->description('Only on a SYMBOL miss with no suggestions: how many nodes were scanned, which separates a wrong name from an empty graph.'),
            'graphFileCount' => $schema->integer()->description('Only on a FILE miss with no suggestions: how many files the graph pins nodes to, which separates a wrong path from a graph that pins none.'),
        ];
    }
}
