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
        $tests = TestReferenceIndex::fromTests(base_path('tests'));

        return new ResponseFactory(Response::text(ImpactFormatter::detectChanges($result, $tests)))
            ->withStructuredContent(JsonPresenter::detectChanges($result, $base, $tests));
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
                ->description('Entry-point node => call chain down to the changed code; each hop may carry a project-relative file/line. Empty map serializes as [].'),
            'entryPointLocations' => $schema->object()
                ->description('Entry-point node => {file, line?} defining location, when known. Empty map serializes as [].'),
            'entryPointSecurity' => $schema->object()
                ->description('Entry-point route => Brain security surface {exposure, riskLevel, issues[]}. Advisory annotation inherited from laravel-brain; routes only, never an input to risk or the gate. Empty map serializes as [].'),
            'entryPointGates' => $schema->object()
                ->description('Entry-point route => Pennant feature flags gating it (EnsureFeaturesAreActive middleware). Advisory annotation; never an input to risk or the gate. Empty map serializes as [].'),
            'entryPointTestReferences' => $schema->object()
                ->description('Entry-point node => "referenced" | "referenced-no-behavioural-assertion" | "unreferenced". A node whose reference state could not be determined is omitted. Advisory annotation; never an input to risk, the gate, or affected-tests selection. Empty map serializes as [].'),
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
