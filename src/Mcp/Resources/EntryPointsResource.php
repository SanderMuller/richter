<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\GraphCache;

final class EntryPointsResource extends Resource
{
    protected string $uri = 'richter://graph/entry-points';

    protected string $mimeType = 'application/json';

    protected string $description = 'Every statically-known entry surface of this app — route::/command::/schedule:: nodes plus Livewire/Filament/Nova component classes — each with its kind and defining file:line when known. The same definition detect-changes reports; diff-relative self-listed entry classes are not part of a static inventory. The first read in a session builds the code graph (cached afterwards).';

    public function __construct(private readonly GraphCache $graphs) {}

    public function handle(): Response
    {
        $graph = $this->graphs->graph();
        $entryPoints = [];

        foreach ($this->inventory($graph) as $node) {
            $entry = ['node' => $node, 'kind' => $this->kindOf($node)];
            $location = $graph->locationOf($node);

            if ($location !== null) {
                $entry += $location;
            }

            $entryPoints[] = $entry;
        }

        return Response::text(JsonPresenter::encode([
            'count' => count($entryPoints),
            'entryPoints' => $entryPoints,
        ]));
    }

    /**
     * Every statically-known entry surface — the same definition detect-changes reports,
     * via {@see ImpactAnalyzer}'s own predicates (single source): `route::`/`command::`/
     * `schedule::` nodes plus Livewire/Filament/Nova component classes, member nodes
     * normalised onto the class. A self-listed entry class is diff-relative by nature
     * and cannot appear in a static inventory.
     *
     * @return list<string> sorted, unique
     */
    private function inventory(CodeGraph $graph): array
    {
        $analyzer = new ImpactAnalyzer($graph);
        $surfaces = array_map(
            static fn (string $node): ?string => $analyzer->isEntryPointNode($node) ? $node : $analyzer->uiComponentClassOf($node),
            $graph->nodes(),
        );

        $inventory = array_values(array_unique(array_filter($surfaces, is_string(...))));
        sort($inventory);

        return $inventory;
    }

    /**
     * Derived from {@see ImpactAnalyzer::ENTRY_POINT_PREFIXES} (single source) — a prefix
     * added there labels correctly here without a second list to maintain.
     */
    private function kindOf(string $node): string
    {
        foreach (ImpactAnalyzer::ENTRY_POINT_PREFIXES as $prefix) {
            if (str_starts_with($node, $prefix)) {
                return rtrim($prefix, ':');
            }
        }

        return 'ui-component';
    }
}
