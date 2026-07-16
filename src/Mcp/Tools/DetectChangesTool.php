<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;
use RuntimeException;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\RichterConfig;

#[IsReadOnly]
final class DetectChangesTool extends Tool
{
    protected string $name = 'detect-changes';

    protected string $description = 'Advisory change-impact for the current branch diff: which HTTP/CLI entry points and flows the changed PHP files reach, plus a coarse risk level. Diffs against the given base ref (defaults to the richter.default_base config value).';

    public function __construct(private readonly CodeGraphBuilder $builder) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()
                ->description('Git ref to diff the current branch against. Defaults to the richter.default_base config value.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $base = RichterConfig::baseRef($request->get('base'));

        try {
            $changed = ChangedSymbols::resolve($base);
        } catch (RuntimeException $runtimeException) {
            return Response::error($runtimeException->getMessage());
        }

        if ($changed === []) {
            return Response::text("No changed PHP files under app/ against {$base}.");
        }

        $result = new ImpactAnalyzer($this->builder->build())->detectChanges($changed);

        return Response::text(ImpactFormatter::detectChanges($result, TestReferenceIndex::fromTests(base_path('tests'))));
    }
}
