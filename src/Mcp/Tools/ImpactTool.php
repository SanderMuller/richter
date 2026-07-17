<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Graph\GraphCache;

#[IsReadOnly]
final class ImpactTool extends Tool
{
    protected string $name = 'impact';

    protected string $description = 'Static blast radius of a PHP symbol in this Laravel app: its callers (what breaks if you change it) and its dependencies (what it reaches). Advisory; request-path and Eloquent-relationship coverage. Pass an FQCN or substring, e.g. App\\Models\\User.';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()
                ->description('FQCN or substring to analyse, e.g. "App\\Models\\User".')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $symbol = $request->get('symbol');

        if (! is_string($symbol) || $symbol === '') {
            return Response::error('The symbol argument must be a non-empty string.');
        }

        $result = new ImpactAnalyzer($this->graphs->graph())->impact($symbol);

        return Response::text(ImpactFormatter::impact($result));
    }
}
