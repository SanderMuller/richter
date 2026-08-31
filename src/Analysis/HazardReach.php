<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * Each hazard's own reach class — two findings, `public-write` and `gated`, and two admissions,
 * `no-guard-found` and `no-known-path`. Per hazard, never per diff: the walk's entry-point data is an
 * aggregate, and a diff that reaches a public route somewhere says nothing about whether it reaches
 * THIS member.
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
     * Brain exposure values that mean the request was authenticated before it arrived. `public` and
     * `guest` are the other two it emits, and neither is a guard.
     *
     * @var list<string>
     */
    private const array GATED_EXPOSURES = ['authed', 'admin', 'internal'];

    /**
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $entryPointPaths
     * @param  array<string, SecurityShape>  $entryPointSecurity
     * @param  array<string, list<string>>  $entryPointAuthGates
     * @param  array<string, list<string>>  $entryPointAuthMiddleware
     * @param  (callable(list<array{depth: int, node: string, via: string}>): list<string>)|null  $entryPointsAmong
     *   the analyzer's own entry-point classification. Passed in rather than reimplemented because
     *   the vocabulary is wider than the `route::`/`command::`/`schedule::` prefixes: a Livewire,
     *   Filament or Nova CLASS is an entry surface too, and a prefix filter would drop those — sending
     *   a removed member reached only through one of them to `no-known-path`, and a tier-1 hazard on
     *   it to LOW.
     */
    public function __construct(
        private CodeGraph $graph,
        private array $entryPointPaths,
        private array $entryPointSecurity,
        private array $entryPointAuthGates,
        private array $entryPointAuthMiddleware,
        private int $maxDepth,
        private mixed $entryPointsAmong = null,
    ) {}

    /**
     * @param  list<Hazard>  $hazards
     * @return list<Hazard>
     */
    public function attach(array $hazards): array
    {
        return array_map(function (Hazard $hazard): Hazard {
            [$reach, $viaDeclaringClass] = $this->classOf($hazard->member);

            return $hazard->withReach($reach, $viaDeclaringClass);
        }, $hazards);
    }

    /**
     * The reach class, and whether the declaring-class lane is what answered — which the report states,
     * because that lane answers exactly where the diff's own counts cannot corroborate it.
     *
     * @return array{0: string, 1: bool}
     */
    private function classOf(string $member): array
    {
        [$entryPoints, $viaDeclaringClass] = $this->entryPointsReaching($member);

        if ($entryPoints === []) {
            // Nothing found either way, so there is no provenance worth stating: the class and the
            // diff's counts agree that no surface was named.
            return [Hazard::REACH_NO_KNOWN_PATH, false];
        }

        // One public-write route with no guard on it decides the class: the worst reachable exposure
        // is the exposure, and averaging it against better-guarded siblings would hide it.
        foreach ($entryPoints as $entryPoint) {
            if ($this->isPublicWrite($entryPoint) && ! $this->contradictsPublicWrite($entryPoint)) {
                return [Hazard::REACH_PUBLIC_WRITE, $viaDeclaringClass];
            }
        }

        // Every reaching entry point, not any: one without a guard is the way in, and calling the set
        // guarded because its siblings are would be the averaging the loop above refuses.
        return array_all($entryPoints, fn (string $entryPoint): bool => $this->isGated($entryPoint))
            ? [Hazard::REACH_GATED, $viaDeclaringClass]
            : [Hazard::REACH_NO_GUARD_FOUND, $viaDeclaringClass];
    }

    /**
     * The reaching entry points, and whether they came from the declaring class rather than the chains.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function entryPointsReaching(string $member): array
    {
        $viaChains = $this->fromChains($member);

        return $viaChains === [] ? [$this->fromDeclaringClass($member), true] : [$viaChains, false];
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

        // The seeds themselves ride along as depth-0 hops. `callersOf()` answers who calls the class,
        // and a class that is ITSELF an entry surface — a Livewire page, a Filament resource — has no
        // caller in application code, because the framework calls it. Classifying only its callers
        // graded a removed member on a user-facing page `no-known-path`, and a tier-1 hazard on it
        // `low`: the one LOW cell in the matrix, reached by not consulting a path richter knows.
        $callers = [
            ...array_map(static fn (string $seed): array => ['depth' => 0, 'node' => $seed, 'via' => ''], $seeds),
            ...$this->graph->callersOf($seeds, $this->maxDepth),
        ];

        if (is_callable($this->entryPointsAmong)) {
            return ($this->entryPointsAmong)($callers);
        }

        // Prefix-only fallback for a caller that supplied no classifier. Narrower than the real
        // vocabulary, so it can only under-report reach, never over-report it.
        return array_values(array_filter(
            array_column($callers, 'node'),
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
     * A guard richter can actually point at. Two kinds of evidence count, and nothing else does: an
     * exposure Brain read off the middleware surface, and a guard the cross-check correlated.
     *
     * The cross-check's two maps are populated ONLY for routes Brain flagged `PUBLIC_WRITE` — it
     * exists to contradict Brain, so it never runs elsewhere. Keying on them alone would make this a
     * fallthrough meaning "not proven public-write", which is a different claim from the one the name
     * makes.
     *
     * A route with no security entry is not gated. A missing classification proves nothing, which is
     * also why a missing entry never reads as "public".
     */
    private function isGated(string $entryPoint): bool
    {
        return in_array($this->entryPointSecurity[$entryPoint]['exposure'] ?? '', self::GATED_EXPOSURES, strict: true)
            || $this->contradictsPublicWrite($entryPoint);
    }

    /**
     * Whether the cross-check found a guard on a route Brain flagged `PUBLIC_WRITE` — the ONLY
     * evidence allowed to overturn that flag.
     *
     * Exposure is deliberately not enough here, though it is enough for {@see isGated()}. A route
     * carrying a `PUBLIC_WRITE` issue AND an authenticated exposure is Brain contradicting itself, and
     * resolving that in the lenient direction would drop a tier-2 hazard from HIGH to MEDIUM on the
     * strength of the half of the surface that says less. The cross-check is a positive finding made
     * against the graph — a policy it authorizes, or middleware on the route — and overturning the
     * flag is precisely what it was built for.
     */
    private function contradictsPublicWrite(string $entryPoint): bool
    {
        return ($this->entryPointAuthGates[$entryPoint] ?? []) !== []
            || ($this->entryPointAuthMiddleware[$entryPoint] ?? []) !== [];
    }
}
