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
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Graph\GraphCache;

#[IsReadOnly]
final class TraceTool extends Tool
{
    protected string $name = 'trace';

    protected string $description = 'Shortest directed path from one symbol to another in call direction — "does FROM reach TO, and through which chain?". Strictly directional: swap the arguments to query the reverse. When found is false, furthestReached is the deepest caller reached from TO within the depth limit — how far upstream connectivity extends, NOT a pointer toward FROM. An unresolvable symbol is an error, never an empty "no path". Pass FQCNs or substrings.';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->description('Symbol the path starts at — an FQCN or substring, e.g. "App\\Http\\Controllers\\PostController".')
                ->required(),
            'to' => $schema->string()
                ->description('Symbol the path must reach, in call direction.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $from = $request->get('from');
        $to = $request->get('to');

        if (! is_string($from) || $from === '' || ! is_string($to) || $to === '') {
            return Response::error('The from and to arguments must be non-empty strings.');
        }

        try {
            $result = new ImpactAnalyzer($this->graphs->graph())->trace($from, $to);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return Response::error($invalidArgumentException->getMessage());
        }

        return new ResponseFactory(Response::text(ImpactFormatter::trace($result)))
            ->withStructuredContent(JsonPresenter::trace($result));
    }

    /** @return array<string, mixed> */
    #[Override]
    public function outputSchema(JsonSchema $schema): array
    {
        $hop = $schema->object([
            'node' => $schema->string(),
            'via' => $schema->string()->description('Edge type to the NEXT hop; the final hop carries "".'),
            'file' => $schema->string()->description('Project-relative defining file, when known.'),
            'line' => $schema->integer()->description('Defining line, when known.'),
        ]);

        return [
            'from' => $schema->string()->description('The from symbol as passed.'),
            'to' => $schema->string()->description('The to symbol as passed.'),
            'resolvedFrom' => $schema->array()->items($schema->string())->description('Graph nodes the from symbol resolved to.'),
            'resolvedTo' => $schema->array()->items($schema->string())->description('Graph nodes the to symbol resolved to.'),
            'found' => $schema->boolean()->description('Whether a call-direction path exists within the depth limit.'),
            'path' => $schema->array()->items($hop)->description('The shortest chain, from-first and to-last; empty when found is false.'),
            'furthestReached' => $schema->object([
                'node' => $schema->string(),
                'depth' => $schema->integer(),
                'file' => $schema->string()->description('Project-relative defining file, when known.'),
                'line' => $schema->integer()->description('Defining line, when known.'),
            ])->description('Only when found is false and TO has callers: the deepest caller reached from TO — upstream extent, not a pointer toward FROM.'),
        ];
    }
}
