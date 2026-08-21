<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * Each hazard's own reach class — `public-write`, `gated`, or `no-known-path`. Per hazard, never per
 * diff: the walk's entry-point data is an aggregate, and a diff that reaches a public route somewhere
 * says nothing about whether it reaches THIS member.
 *
 * **`no-known-path` is not `internal-only`.** Proving a member internal means proving a negative on a
 * graph that under-approximates by design. A member with no known path is unmeasured, not
 * unreachable — which is why tier 3 is HIGH at every reach class.
 *
 * Two different queries answer it, because a hazard sits on one of two kinds of member:
 *
 * - A SURVIVING member is a walk seed, and `entryPointPaths` chains run target-first, seed-last, so
 *   the entry points whose chain contains it are its reach.
 * - A REMOVED member is in no chain at all. Its node is gone from the head graph, so `memberSeeds()`
 *   yields nothing; the change is `resolvable`, so no coarse class seed is armed either. Filtering
 *   the chains would grade every removal `no-known-path` by construction and flatten the matrix for
 *   the whole contract lane. Its reach comes from its DECLARING CLASS instead — the same stand-in the
 *   coarse-seed lane already makes for a change the graph cannot pin.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final readonly class HazardReach
{
    /**
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $entryPointPaths
     * @param  array<string, SecurityShape>  $entryPointSecurity
     * @param  array<string, list<string>>  $entryPointAuthGates
     * @param  array<string, list<string>>  $entryPointAuthMiddleware
     */
    public function __construct(
        private CodeGraph $graph,
        private array $entryPointPaths,
        private array $entryPointSecurity,
        private array $entryPointAuthGates,
        private array $entryPointAuthMiddleware,
        private int $maxDepth,
    ) {}

    /**
     * @param  list<Hazard>  $hazards
     * @return list<Hazard>
     */
    public function attach(array $hazards): array
    {
        return array_map(fn (Hazard $hazard): Hazard => $hazard->withReach($this->classOf($hazard->member)), $hazards);
    }

    private function classOf(string $member): string
    {
        $entryPoints = $this->entryPointsReaching($member);

        if ($entryPoints === []) {
            return Hazard::REACH_NO_KNOWN_PATH;
        }

        // One public-write route with no guard on it decides the class: the worst reachable exposure
        // is the exposure, and averaging it against better-guarded siblings would hide it.
        foreach ($entryPoints as $entryPoint) {
            if ($this->isPublicWrite($entryPoint) && ! $this->isGated($entryPoint)) {
                return Hazard::REACH_PUBLIC_WRITE;
            }
        }

        return Hazard::REACH_GATED;
    }

    /**
     * @return list<string>
     */
    private function entryPointsReaching(string $member): array
    {
        $viaChains = $this->fromChains($member);

        return $viaChains === [] ? $this->fromDeclaringClass($member) : $viaChains;
    }

    /**
     * The entry points whose caller chain passes through this member — the surviving-member case.
     *
     * @return list<string>
     */
    private function fromChains(string $member): array
    {
        $reaching = [];

        foreach ($this->entryPointPaths as $entryPoint => $chain) {
            // The entry point IS the changed member — a changed controller action that a route calls
            // directly. Its chain may not repeat it, so check the key as well as the hops.
            if ($entryPoint === $member) {
                $reaching[] = $entryPoint;

                continue;
            }

            foreach ($chain as $hop) {
                if ($hop['node'] === $member) {
                    $reaching[] = $entryPoint;

                    break;
                }
            }
        }

        return array_values(array_unique($reaching));
    }

    /**
     * The removed-member case: walk up from the declaring class's own nodes and keep the entry points
     * among the callers. A class the graph does not know at all answers `no-known-path`, which is the
     * honest reading — richter cannot see it, not "nothing reaches it".
     *
     * @return list<string>
     */
    private function fromDeclaringClass(string $member): array
    {
        $class = explode('::', $member, 2)[0];
        $seeds = array_values(array_filter(
            [$class, ...$this->graph->nodesContaining($class . '::')],
            $this->graph->hasNode(...),
        ));

        if ($seeds === []) {
            return [];
        }

        // `callersOf()` returns hops, not node ids — the walk carries depth and the edge it arrived on.
        return array_values(array_filter(
            array_column($this->graph->callersOf($seeds, $this->maxDepth), 'node'),
            static fn (string $node): bool => str_starts_with($node, 'route::') || str_starts_with($node, 'command::') || str_starts_with($node, 'schedule::'),
        ));
    }

    /**
     * Brain records `PUBLIC_WRITE` as an ISSUE on the route, not as its exposure value — the same
     * reading {@see PublicWriteAuthCrossCheck::isPublicWriteRoute()} uses, so the two agree about
     * which routes they are talking about.
     */
    private function isPublicWrite(string $entryPoint): bool
    {
        $issues = $this->entryPointSecurity[$entryPoint]['issues'] ?? [];

        return array_any($issues, static fn (array $issue): bool => $issue['type'] === 'PUBLIC_WRITE');
    }

    /**
     * A guard richter can actually point at: authorization the cross-check correlated to this route,
     * or authentication middleware on it. Absence is NOT proof of exposure — it is why `gated` is the
     * fallback for a reached-but-not-public-write surface rather than the other way round.
     */
    private function isGated(string $entryPoint): bool
    {
        return ($this->entryPointAuthGates[$entryPoint] ?? []) !== []
            || ($this->entryPointAuthMiddleware[$entryPoint] ?? []) !== [];
    }
}
