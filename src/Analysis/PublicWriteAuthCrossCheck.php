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
     * Auth middleware applied to each `PUBLIC_WRITE` route that Brain's own check could not see.
     *
     * Brain reads a middleware two ways: by NAME — a case-insensitive prefix against `auth`,
     * `sanctum`, `jwt`, … plus a BASENAME match against `Authenticate` and `ValidateSignature` — and,
     * since 2.5.0, by walking the class's `extends` chain. That walk terminates on ONE base,
     * `Illuminate\Auth\Middleware\Authenticate`, so the shapes this lane was written for — a
     * subclass named `TenantAuthenticate` or `EnsureUserIsAuthenticated` — are now Brain's own
     * answer, and no `PUBLIC_WRITE` reaches this lane to contradict.
     *
     * What is left is the rest of {@see AuthMiddlewareVocabulary::AUTH_MIDDLEWARE_BASES}. A middleware descending from
     * `AuthenticateWithBasicAuth`, `EnsureEmailIsVerified` or `ValidateSignature` under a name of its
     * own matches no pattern, no basename and no chain: every route behind it is classified
     * `[public]`, and a mutating verb draws a "requires no authentication" issue — `high` for
     * `DELETE`, `medium` for the rest — on a route that is, in fact, authenticated. This walks the ancestry of all four bases, over the route's own
     * `route-to-middleware` edges, so it covers that remainder rather than the same set.
     *
     * Keep the walk pointed at all four even after upstream widens its own: a base Brain resolves
     * makes this lane silent for that shape, never wrong about it, and the lane must not depend on
     * which Brain version a consumer resolved.
     *
     * Those edges carry what the route files themselves declare — including a
     * `Route::middleware([...])->group(...)` wrapper, whose list Brain's route analyzer attaches to
     * every route inside it. They do NOT carry the members of a *named* middleware group: Brain
     * parses the Kernel's `$middlewareGroups` into its registry and never expands it (its
     * `resolveMiddlewares()` resolves aliases only, and `MiddlewareRegistry::resolveGroup()` has no
     * callers), and richter declines to expand groups too — mapping a global group onto every class
     * in its stack would flood each of them with every route ({@see CodeGraphBuilder::resolveMiddlewareAliases()}).
     * So a route gated only by an `api`/`web` group member is out of this lane's reach by design.
     *
     * Annotation only, exactly like {@see gatesByEntryPoint()}: it
     * contradicts the finding beside it and never suppresses it, because "extends an auth middleware"
     * is strong evidence of a gate, not proof that this particular route is gated.
     *
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @return array<string, list<string>>  entry-point node → auth-middleware FQCNs applied to it
     */
    public function authMiddlewareByEntryPoint(array $security): array
    {
        $applied = [];

        foreach ($security as $entryPoint => $surface) {
            if (! $this->isPublicWriteRoute($entryPoint, $surface)) {
                continue;
            }

            $middleware = [];

            foreach ($this->graph->outgoingTargetsOfType([$entryPoint], 'route-to-middleware') as $node) {
                $fqcn = str_starts_with($node, 'middleware::') ? substr($node, strlen('middleware::')) : $node;

                if (AuthMiddlewareVocabulary::extendsAuthMiddleware($fqcn)) {
                    $middleware[] = $fqcn;
                }
            }

            if ($middleware !== []) {
                $applied[$entryPoint] = $middleware;
            }
        }

        return $applied;
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
