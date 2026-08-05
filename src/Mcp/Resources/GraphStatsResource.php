<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Graph\GraphCache;

final class GraphStatsResource extends Resource
{
    protected string $uri = 'richter://graph/stats';

    protected string $mimeType = 'application/json';

    protected string $description = 'How complete the code graph is here, at a glance: node and edge counts by edge type, plus the two honesty flags — hasUnparseableFiles (an app file the parser could not read) and hasUnresolvedDispatches (a dispatch whose target could not be followed). The first read in a session builds the code graph (cached afterwards).';

    public function __construct(private readonly GraphCache $graphs) {}

    public function handle(): Response
    {
        $graph = $this->graphs->graph();
        $edgesByType = [];

        foreach ($graph->toArray()['edges'] as $edge) {
            $edgesByType[$edge['type']] = ($edgesByType[$edge['type']] ?? 0) + 1;
        }

        ksort($edgesByType);

        return Response::text(JsonPresenter::encode([
            'nodes' => $graph->nodeCount(),
            'edges' => array_sum($edgesByType),
            'edgesByType' => $edgesByType,
            'hasUnparseableFiles' => $graph->hasUnparseableFiles(),
            'hasUnresolvedDispatches' => $graph->hasUnresolvedDispatches(),
        ]));
    }
}
