<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;
use RuntimeException;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Support\RichterConfig;

#[IsReadOnly]
final class DetectChangesTool extends Tool
{
    protected string $name = 'detect-changes';

    protected string $description = 'Advisory change-impact for the current branch diff: which HTTP/CLI entry points and flows the changed PHP files reach, plus a coarse risk level. Diffs against the given base ref (defaults to the richter.default_base config value).';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()
                ->description('Git ref to diff the current branch against. Defaults to the richter.default_base config value.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $base = RichterConfig::baseRef($request->get('base'));
            $changed = ChangedSymbols::resolve($base);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        if ($changed === []) {
            return new ResponseFactory(Response::text("No changed PHP files under app/ against {$base}."))
                ->withStructuredContent(JsonPresenter::emptyDetectChanges($base));
        }

        $result = new ImpactAnalyzer($this->graphs->graph())->detectChanges($changed);

        return new ResponseFactory(Response::text(ImpactFormatter::detectChanges($result, TestReferenceIndex::fromTests(base_path('tests')))))
            ->withStructuredContent(JsonPresenter::detectChanges($result, $base));
    }

    /** @return array<string, mixed> */
    #[Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            // The three map-shaped fields are plain object() rather than an object|array anyOf:
            // anyOf() is missing from Illuminate\JsonSchema on this package's framework floor, and
            // an empty PHP map JSON-encodes as [] — the description carries that caveat instead.
            'base' => $schema->string()->description('The git ref the diff was taken against.'),
            'changed' => $schema->object()
                ->description('Changed file => resolved seed count. Empty map serializes as [].'),
            'coverage' => $schema->object()
                ->description('Changed file => "analyzed" or "unresolved". Empty map serializes as [].'),
            'entryPoints' => $schema->array()->items($schema->string()),
            'entryPointPaths' => $schema->object()
                ->description('Entry-point node => call chain down to the changed code. Empty map serializes as [].'),
            'impacted' => $schema->integer()->description('Distinct impacted graph nodes.'),
            'relatedModels' => $schema->array()->items($schema->string()),
            'risk' => $schema->string()->description('low, medium or high.'),
            'lowConfidence' => $schema->boolean(),
            'coarseCapApplied' => $schema->boolean(),
            'findings' => $schema->array()->items($schema->string()),
            'unresolved' => $schema->boolean()->description('True when any changed file could not be placed in the graph.'),
        ];
    }
}
