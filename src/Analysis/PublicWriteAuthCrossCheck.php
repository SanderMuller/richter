<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\NodeMetadata;
use SanderMuller\Richter\Tracers\PolicyEdgeTracer;

/**
 * Cross-checks Brain's `PUBLIC_WRITE` route findings against richter's own `authorizes` edges. Brain
 * classifies a route's exposure from its static middleware surface and misses both middleware-group
 * contents and the policy-constant in-controller gate (`Gate::authorize(PostPolicy::PUBLISH, …)`), so
 * it can flag a genuinely-gated route "requires no authentication". {@see PolicyEdgeTracer} already
 * recorded that gate as an `authorizes` edge; when a `PUBLIC_WRITE` route's reach authorizes a policy,
 * that contradicts Brain.
 *
 * Annotation only: the result never seeds a walk, never touches `impacted`/`risk`, and the formatters
 * render it as evidence beside Brain's finding — never suppressing it. Lives beside {@see ImpactAnalyzer}
 * rather than inside it (spec Findings) so the analyzer's class complexity budget stays intact.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final readonly class PublicWriteAuthCrossCheck
{
    public function __construct(private CodeGraph $graph) {}

    /**
     * The gating policies each `PUBLIC_WRITE` route's reach authorizes against. Uses the route's
     * downstream reachable NODE SET (`dependenciesOf()`, complete) intersected with every `authorizes`
     * edge from those nodes ({@see CodeGraph::outgoingTargetsOfType()}) — NOT the BFS-tree
     * `dependencyEdgesOf()`, which would drop an `authorizes` edge to an already-reached policy.
     *
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @return array<string, list<string>>  entry-point node → gating policy FQCNs, only for a
     *                                       `PUBLIC_WRITE` route with a non-empty gate set in reach
     */
    public function gatesByEntryPoint(array $security, int $maxDepth): array
    {
        $gates = [];

        foreach ($security as $entryPoint => $surface) {
            if (! $this->isPublicWriteRoute($entryPoint, $surface)) {
                continue;
            }

            $reached = array_column($this->graph->dependenciesOf([$entryPoint], $maxDepth), 'node');
            $policies = $this->graph->outgoingTargetsOfType($reached, 'authorizes');

            if ($policies !== []) {
                $gates[$entryPoint] = $policies;
            }
        }

        return $gates;
    }

    /**
     * A route entry point Brain flagged with a `PUBLIC_WRITE` issue — the only shape this cross-check
     * contradicts. The `route::` guard enforces the routes-only contract the graph boundary doesn't,
     * so a future/fixtured `security` on a non-route entry point can never draw a "this route" note.
     *
     * @param  SecurityShape  $surface
     */
    private function isPublicWriteRoute(string $entryPoint, array $surface): bool
    {
        return str_starts_with($entryPoint, 'route::')
            && array_any($surface['issues'], static fn (array $issue): bool => $issue['type'] === 'PUBLIC_WRITE');
    }
}
