<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Analysis\Hazards\GuardArrival;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * What became of a removed guard that the same diff adds somewhere else: whether the new place still
 * runs for every controller action the old one ran for.
 *
 * The comparison is per ACTION, the controller method. The graph links a route to its controller class
 * and the class to its actions, so it cannot say which route runs which action of a controller that has
 * several. A route is named only where its controller has one action.
 *
 * Three answers, and each needs its own evidence:
 *
 * - A path leads there: a traced call leads from the old place to the new one. That is no proof that the
 *   check runs on every call, so where the new file is also new, it must be a form request that refuses.
 * - No longer runs: the new place is one richter can place, and no path of any kind leads to it.
 * - Not compared: everything else. A gate closure, a middleware and a constructor are run by the
 *   framework with no call richter traces, so an absent path says nothing about them.
 */
final readonly class MovedGuards
{
    private const int MAX_NAMED = 5;

    /** Where the framework calls a class's methods, with no call richter traces. */
    private const array FRAMEWORK_RUN = ['\\Middleware\\', '\\Providers\\', '\\Policies\\', '\\Observers\\', '\\Listeners\\', '\\Livewire\\'];

    /** @param  callable(list<array{depth: int, node: string, via: string}>): list<string>  $entryPointsAmong */
    public function __construct(
        private CodeGraph $graph,
        private int $maxDepth,
        private mixed $entryPointsAmong,
    ) {}

    /**
     * The hazard that remains, if any, and the finding that replaces it, if any.
     *
     * An arrival in a file the diff also added silences a removal only where a path leads to it and it
     * certainly refuses. One in an existing file silences it unless the paths disprove the move.
     *
     * @param  non-empty-list<GuardArrival>  $arrivals
     * @return array{0: Hazard|null, 1: string|null}
     */
    public function judge(Hazard $hazard, array $arrivals): array
    {
        [$covered, $lost] = $this->compare($hazard, $arrivals);
        $answering = array_values(array_filter($arrivals, fn (GuardArrival $arrival): bool => ! $arrival->inNewFile || ($arrival->refuses && $this->isRequestAuthorize($arrival->site))));
        $covered = $answering === [] || $covered === [] ? [] : $this->compare($hazard, $answering)[0];
        $from = "{$hazard->member} — `{$hazard->guard}` is gone";
        $sites = $this->sites($arrivals);

        if ($lost !== []) {
            return [$hazard->withEvidence("{$hazard->evidence}; the check is now in {$sites}, and richter found no path there from " . $this->list(array_map($this->label(...), $lost))), null];
        }

        if ($covered !== []) {
            $surfaces = $this->list(array_map($this->label(...), $covered));

            return [null, "{$from}; the check is now in " . $this->sites($this->reachedBy($covered, $arrivals)) . ", and a path leads there from {$surfaces}. Check that it always runs and refuses, and that it decides about the same object"];
        }

        if (array_all($arrivals, static fn (GuardArrival $arrival): bool => $arrival->inNewFile)) {
            return [$hazard->withEvidence("{$hazard->evidence}; the check is now in {$sites}, and richter did not prove that it runs and refuses there"), null];
        }

        return [null, "{$from}; the check is now in {$sites}, and where it runs was not compared"];
    }

    /**
     * The actions the guard still runs for, and those it no longer runs for. Both empty when the two
     * places cannot be compared; the first is empty when any action is lost.
     *
     * @param  non-empty-list<GuardArrival>  $arrivals
     * @return array{0: list<string>, 1: list<string>}
     */
    private function compare(Hazard $hazard, array $arrivals): array
    {
        $origin = $this->originOf($hazard);
        $seeds = array_values(array_unique(array_merge([], ...array_map($this->seedsOf(...), $arrivals))));

        if ($origin === null || $seeds === []) {
            return [[], []];
        }

        $actions = $this->actionsRunning($origin);
        $reached = $this->callersOf($seeds, ['declares']);

        // A path straight from the old place to the new one covers whatever runs the old place, whether
        // or not an action is known to.
        if ($actions === [] || in_array($origin[0], $reached, strict: true)) {
            $missing = in_array($origin[0], $reached, strict: true) ? [] : null;
            $actions = $actions === [] ? [$origin[0]] : $actions;
        } else {
            $missing = array_values(array_diff($actions, $reached));
        }

        if ($missing === null) {
            return [[], []];
        }

        if ($missing === []) {
            return [$actions, []];
        }

        return [[], $this->provenLost($missing, $arrivals, $seeds)];
    }

    /**
     * The missing actions, where the absence means something: richter placed an arrival, the framework
     * runs none of the others, and the widest walk finds no path either. Otherwise none.
     *
     * @param  list<string>  $missing
     * @param  non-empty-list<GuardArrival>  $arrivals
     * @param  list<string>  $seeds
     * @return list<string>
     */
    private function provenLost(array $missing, array $arrivals, array $seeds): array
    {
        $placed = array_filter($arrivals, $this->isPlaced(...));

        // Something richter can see and nothing calls runs for no action, so it is no reason to doubt the
        // others. What the framework runs, and a class the graph does not hold, may be where the check went.
        if ($placed === [] || array_any(array_diff_key($arrivals, $placed), fn (GuardArrival $arrival): bool => $this->mayRunUnseen($arrival))) {
            return [];
        }

        return array_values(array_diff($missing, $this->callersOf($seeds, [])));
    }

    /**
     * The nodes the removed guard sat on. Null for a hazard that names no check, a member that is gone
     * from the graph, and a constructor, which runs for members this walk cannot name.
     *
     * @return non-empty-list<string>|null
     */
    private function originOf(Hazard $hazard): ?array
    {
        if ($hazard->guard === null || str_ends_with($hazard->member, '::__construct')) {
            return null;
        }

        $class = explode('::', $hazard->member, 2)[0];
        $request = $this->isRequestAuthorize($hazard->member) && $this->graph->hasNode($class) ? [$class] : [];
        $nodes = [...($this->graph->hasNode($hazard->member) ? [$hazard->member] : []), ...$request];

        return $nodes === [] ? null : $nodes;
    }

    /**
     * @param  non-empty-list<string>  $origin
     * @return list<string>
     */
    private function actionsRunning(array $origin): array
    {
        return $this->isAction($origin[0])
            ? [$origin[0]]
            : array_values(array_filter($this->callersOf($origin, ['declares']), $this->isAction(...)));
    }

    /** @return list<string> */
    private function seedsOf(GuardArrival $arrival): array
    {
        $class = explode('::', $arrival->site, 2)[0];

        return array_values(array_filter(
            $this->isRequestAuthorize($arrival->site) ? [$arrival->site, $class] : [$arrival->site],
            $this->graph->hasNode(...),
        ));
    }

    /** Whether a traced path leads to an arrival from something that runs, so that a missing one counts. */
    private function isPlaced(GuardArrival $arrival): bool
    {
        $hops = array_map(
            static fn (string $node): array => ['depth' => 0, 'node' => $node, 'via' => ''],
            $this->callersOf($this->seedsOf($arrival), ['declares']),
        );

        return array_any($hops, fn (array $hop): bool => $this->isAction($hop['node'])) || ($this->entryPointsAmong)($hops) !== [];
    }

    private function mayRunUnseen(GuardArrival $arrival): bool
    {
        [$class, $method] = [...explode('::', $arrival->site, 2), ''];

        // A method the graph holds nothing of is one nothing calls, as long as it holds its class.
        return (! $this->graph->hasNode($class) && $this->graph->nodesContaining("{$class}::") === [])
            || array_any(self::FRAMEWORK_RUN, static fn (string $namespace): bool => str_contains($class, $namespace))
            || in_array($method, ['__construct', '__invoke', 'handle'], strict: true);
    }

    /**
     * The seeds and everything upstream of them. `declares` is excluded where the answer must name the
     * member: it leads from a method to its class, and from there to whatever uses the class for
     * another method.
     *
     * @param  list<string>  $seeds
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private function callersOf(array $seeds, array $excluded): array
    {
        $callers = $this->graph->callersOf($seeds, $this->maxDepth, [...ImpactAnalyzer::ASSOCIATION_EDGE_TYPES, ...$excluded]);

        return array_values(array_unique([...$seeds, ...array_column($callers, 'node')]));
    }

    /** Laravel runs a request's `authorize()` for whoever takes the class as a parameter. */
    private function isRequestAuthorize(string $member): bool
    {
        return str_ends_with($member, '::authorize') && str_contains($member, '\Requests\\');
    }

    private function isAction(string $node): bool
    {
        return str_contains($node, '::')
            && in_array($node, $this->graph->outgoingTargetsOfType([explode('::', $node, 2)[0]], 'controller-to-action'), strict: true);
    }

    /** An action, with its routes where its controller has no other action they could belong to. */
    private function label(string $node): string
    {
        $class = explode('::', $node, 2)[0];

        if ($this->graph->outgoingTargetsOfType([$class], 'controller-to-action') !== [$node]) {
            return $node;
        }

        $routes = [];

        foreach ($this->graph->callerEdgesOf([$class], 1) as $edge) {
            if ($edge['via'] === 'route-to-controller') {
                $routes[] = $edge['source'];
            }
        }

        return $routes === [] ? $node : "{$node} [" . $this->list($routes) . ']';
    }

    /**
     * The arrivals that run for what the guard still runs for. The others are the same ability arriving
     * for something else, and all of them where no single one can be told apart.
     *
     * @param  list<string>  $covered
     * @param  non-empty-list<GuardArrival>  $arrivals
     * @return non-empty-list<GuardArrival>
     */
    private function reachedBy(array $covered, array $arrivals): array
    {
        $reached = array_values(array_filter($arrivals, fn (GuardArrival $arrival): bool => array_intersect($covered, $this->callersOf($this->seedsOf($arrival), ['declares'])) !== []));

        return $reached === [] ? $arrivals : $reached;
    }

    /** @param  list<GuardArrival>  $arrivals */
    private function sites(array $arrivals): string
    {
        return $this->list(array_map(static fn (GuardArrival $arrival): string => $arrival->label(), $arrivals));
    }

    /** @param  list<string>  $items */
    private function list(array $items): string
    {
        $more = count($items) - self::MAX_NAMED;

        return implode(', ', array_slice($items, 0, self::MAX_NAMED)) . ($more > 0 ? " and {$more} more" : '');
    }
}
