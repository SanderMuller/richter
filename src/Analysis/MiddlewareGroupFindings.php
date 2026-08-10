<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use LaraMint\LaravelBrain\Analysis\MiddlewareAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\MiddlewareAliases;
use Throwable;

/**
 * Advisory: a changed middleware class that reaches the graph through a middleware GROUP rather
 * than through an alias.
 *
 * An aliased middleware already reaches its routes — {@see MiddlewareAliases} maps `middleware::auth`
 * onto the FQCN and the chain joins up. A group member does not. Route middleware is resolved by
 * ALIAS only upstream, so `->middleware('api')` reaches the graph as a bare `middleware::api` node
 * and the classes inside that group are linked to nothing. Richter deliberately does not expand the
 * group into edges: mapping a global group onto every route would make each of its members report
 * every route in the app as an entry point.
 *
 * The cost of that choice is a wrongly SIZED answer, not a missing one. The middleware still
 * self-lists (it is under `\Http\Middleware\`, an entry-point namespace), so a reviewer reads "one
 * entry point: the middleware itself" for a change that runs on every route in the group. This note
 * supplies the size the edges withhold.
 *
 * Findings only — never `risk`, `--fail-on`, or `affected-tests`. Letting the group's routes count
 * toward risk would raise the level of every middleware edit in every consuming app at once, which
 * is a decision that needs its own evidence, not a side effect of this note.
 *
 * Group membership comes from Brain's {@see MiddlewareAnalyzer}, which already reads
 * `$middlewareGroups` from a Laravel 10 Kernel and the `->web(append: [...])` form from a Laravel 11+
 * `bootstrap/app.php` — and then never uses it (`MiddlewareRegistry::resolveGroup()` has no callers
 * upstream). One caveat comes with it: that analyzer takes the Kernel when both files exist, where
 * richter's own alias reader prefers bootstrap. An upgraded app that kept an empty Kernel stub
 * therefore yields no groups and no note — a reach limit, never a wrong one.
 *
 * @internal
 */
final class MiddlewareGroupFindings
{
    /** @var array<string, list<array{group: string, routes: int}>>|null FQCN => the groups it runs in */
    private ?array $membership = null;

    public function __construct(private readonly CodeGraph $graph, private readonly ?string $projectRoot = null) {}

    /**
     * The note for one changed class, or nothing when it is in no group.
     *
     * @return list<string>
     */
    public function findingsFor(string $fqcn): array
    {
        $findings = [];

        foreach ($this->membership()[ltrim($fqcn, '\\')] ?? [] as $entry) {
            $findings[] = sprintf(
                "%s runs in middleware group '%s', which guards %d %s; group membership is not drawn as edges, so those routes are not in the reach above",
                $fqcn,
                $entry['group'],
                $entry['routes'],
                $entry['routes'] === 1 ? 'route' : 'routes',
            );
        }

        return $findings;
    }

    /**
     * Built once per run, and only for a run that asks — the analyzer parses at most one file, but a
     * report whose diff touches no middleware should not pay even that.
     *
     * @return array<string, list<array{group: string, routes: int}>>
     */
    private function membership(): array
    {
        if ($this->membership !== null) {
            return $this->membership;
        }

        $root = $this->projectRoot ?? base_path();
        $aliases = MiddlewareAliases::forProject($root);
        $membership = [];

        foreach ($this->groups($root) as $group => $entries) {
            $routes = $this->routesGuarding($group);

            // A group no route in the graph references sizes nothing, and "guards 0 routes" is a
            // line that teaches its reader to skip the check.
            if ($routes === 0) {
                continue;
            }

            foreach ($entries as $entry) {
                $fqcn = $this->resolveEntry($entry, $aliases);
                $membership[$fqcn][] = ['group' => $group, 'routes' => $routes];
            }
        }

        return $this->membership = $membership;
    }

    /**
     * @return array<string, list<string>>
     */
    private function groups(string $root): array
    {
        try {
            /** @var array<string, list<string>> $groups */
            $groups = new MiddlewareAnalyzer()->analyze($root)->groups;

            return $groups;
        } catch (Throwable) {
            // An unreadable or exotic Kernel is a missing note, never a failed report.
            return [];
        }
    }

    /** How many routes carry the group, counted off the `route:: → middleware::<group>` edges. */
    private function routesGuarding(string $group): int
    {
        $node = "middleware::{$group}";

        if (! $this->graph->hasNode($node)) {
            return 0;
        }

        $routes = array_filter(
            $this->graph->callersOf([$node], maxDepth: 1),
            static fn (array $hop): bool => str_starts_with($hop['node'], 'route::'),
        );

        return count($routes);
    }

    /**
     * A group entry is written the way the app writes it: an FQCN, or an alias, either one possibly
     * carrying parameters (`throttle:api`). Parameters are cut before the alias lookup, the same
     * split the upstream resolver makes.
     *
     * @param  array<string, string>  $aliases
     */
    private function resolveEntry(string $entry, array $aliases): string
    {
        $name = ltrim(explode(':', $entry, 2)[0], '\\');

        return $aliases[$name] ?? $name;
    }
}
