<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use RuntimeException;
use SanderMuller\Richter\Graph\NodeMetadata;
use SanderMuller\Richter\Support\RunningApplication;
use Throwable;

/**
 * The third cross-check evidence source: the booted router's fully expanded middleware stack, for
 * the one shape the graph-based lanes document as out of reach — a guard applied through a *named*
 * middleware group ({@see PublicWriteAuthCrossCheck::authMiddlewareByEntryPoint()}). Evidence
 * beside Brain's finding, never a suppression; {@see HazardReach} consumes it through the same
 * overturn door as the two existing cross-check maps.
 *
 * Fail-closed everywhere. The lane runs only when the analyzed root IS the booted application
 * (a null root reads as "unknown", not as "assume yes" — a wrong guard is a wrong level, unlike
 * the group-count note's lenient default) and only for an analysis of the working tree (the
 * caller passes no root when the head is a named commit: the router describes the tree, not the
 * commit). Every failure — an unreadable router, an unmatched node, an unguarded colliding
 * registration, an unresolvable class — is silence, never a guess.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 *
 * @internal
 */
final class RuntimeRouterGuards
{
    /** @var array<string, list<RoutingRoute>>|null node id => registered routes rebuilding to it */
    private ?array $registered = null;

    public function __construct(private readonly ?string $analyzedRoot) {}

    /**
     * Runtime-proven guards per candidate route: every `[public]`-classified route plus every
     * `PUBLIC_WRITE` carrier. Several registered routes can rebuild to one node id (it carries no
     * domain and no action), so evidence needs EVERY matching registration guarded, and the entry
     * carries the intersection of their guard classes — an empty intersection is silence.
     *
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @return array<string, list<array{middleware: string, group: string|null}>>
     */
    public function guardsByEntryPoint(array $security): array
    {
        if ($this->analyzedRoot === null || ! RunningApplication::isProject($this->analyzedRoot)) {
            return [];
        }

        $guards = [];

        foreach ($security as $entryPoint => $surface) {
            if (! $this->isCandidate($entryPoint, $surface)) {
                continue;
            }

            $matched = $this->registered()[$entryPoint] ?? [];

            if ($matched === []) {
                continue;
            }

            $perRoute = [];

            foreach ($matched as $route) {
                $routeGuards = $this->recognizedGuards($route);

                if ($routeGuards === []) {
                    // One unguarded registration sharing the node makes the evidence ambiguous:
                    // attaching a twin's guard is the one failure worse than silence.
                    continue 2;
                }

                $perRoute[] = $routeGuards;
            }

            $common = array_intersect_key(...$perRoute);

            if ($common !== []) {
                $guards[$entryPoint] = array_map(
                    // Provenance is display only, and it must be TRUE for every colliding
                    // registration: routes carrying the same guard through different groups agree
                    // on the guard, not on the group, so the ambiguous provenance reads null.
                    static function (string $middleware, ?string $group) use ($perRoute): array {
                        foreach ($perRoute as $routeGuards) {
                            if (($routeGuards[$middleware] ?? null) !== $group) {
                                $group = null;

                                break;
                            }
                        }

                        return ['middleware' => $middleware, 'group' => $group];
                    },
                    array_keys($common),
                    array_values($common),
                );
            }
        }

        return $guards;
    }

    /**
     * A `[public]`-classified route, or one carrying a `PUBLIC_WRITE` issue whatever its exposure.
     * The `route::` prefix enforces the routes-only contract the same way the sibling lane does.
     *
     * @param  SecurityShape  $surface
     */
    private function isCandidate(string $entryPoint, array $surface): bool
    {
        return str_starts_with($entryPoint, 'route::')
            && (
                ($surface['exposure'] ?? '') === 'public'
                || array_any($surface['issues'], static fn (array $issue): bool => $issue['type'] === 'PUBLIC_WRITE')
            );
    }

    /**
     * Registered routes indexed by the node id Brain derives: one id per non-HEAD method, method
     * uppercased, URI slash-normalized (`RouteAnalyzer` upstream builds `route::{METHOD}::{uri}`
     * from exactly this). Built once; any router failure reads as an empty table.
     *
     * @return array<string, list<RoutingRoute>>
     */
    private function registered(): array
    {
        if ($this->registered !== null) {
            return $this->registered;
        }

        $index = [];

        try {
            foreach ($this->router()->getRoutes()->getRoutes() as $route) {
                $uri = '/' . ltrim($route->uri(), '/');

                foreach ($route->methods() as $method) {
                    if (! is_string($method)) {
                        continue;
                    }

                    $method = strtoupper($method);

                    if ($method === 'HEAD') {
                        continue;
                    }

                    $index["route::{$method}::{$uri}"][] = $route;
                }
            }
        } catch (Throwable) {
            $index = [];
        }

        return $this->registered = $index;
    }

    /**
     * The recognized guards of one registered route: class => the named group it arrived through
     * (`null` for a direct or controller-applied token; display only). Recognition runs on the raw
     * tokens — the route AND controller middleware plus each named group's members, because
     * expansion loses aliases and Brain's config is pattern-based — but a match only counts when
     * its resolved class survives the effective `gatherRouteMiddleware()` stack, so a
     * `withoutMiddleware()` exclusion is never evidence.
     *
     * @return array<string, string|null>
     */
    private function recognizedGuards(RoutingRoute $route): array
    {
        try {
            $router = $this->router();
            $aliases = $router->getMiddleware();
            $groups = $router->getMiddlewareGroups();

            $rawTokens = array_values(array_filter($route->gatherMiddleware(), is_string(...)));

            // Before gatherRouteMiddleware(): Laravel's own group expansion recurses without a
            // cycle guard, so an indirect cycle (a -> b -> a) would exhaust the stack instead of
            // reaching this lane's Throwable-to-silence contract. Only the groups THIS route
            // reaches matter — a cycle in an unused group must not silence every other route.
            foreach ($rawTokens as $token) {
                $name = explode(':', $token, 2)[0];

                if (isset($groups[$name]) && self::groupReachesItself($name, $groups, [])) {
                    return [];
                }
            }

            $effective = [];

            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                if (is_string($middleware)) {
                    $effective[self::classOf($middleware)] = true;
                }
            }

            $guards = [];

            foreach ($rawTokens as $token) {
                $this->walkToken($token, null, $aliases, $groups, $effective, $guards, []);
            }

            return $guards;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Per-token walk: a token naming a group expands member by member (recursively for nested
     * groups) and attributes its guards to the route's own token — the outermost group; any other
     * token resolves through the alias map. A guard reachable through several tokens keeps its
     * first attribution.
     *
     * @param  array<array-key, mixed>  $aliases
     * @param  array<array-key, mixed>  $groups
     * @param  array<string, true>  $effective  classes surviving the expanded stack
     * @param  array<string, string|null>  $guards
     * @param  array<string, true>  $seen  group names, cycle guard
     */
    private function walkToken(string $token, ?string $group, array $aliases, array $groups, array $effective, array &$guards, array $seen): void
    {
        $name = explode(':', $token, 2)[0];

        $members = $groups[$name] ?? null;

        if (is_array($members)) {
            if (isset($seen[$name])) {
                return;
            }

            $seen[$name] = true;

            foreach ($members as $member) {
                if (is_string($member)) {
                    $this->walkToken($member, $group ?? $name, $aliases, $groups, $effective, $guards, $seen);
                }
            }

            return;
        }

        $resolved = $aliases[ltrim($name, '\\')] ?? $name;
        $class = is_string($resolved) ? self::classOf($resolved) : null;

        if ($class === null || ! isset($effective[$class])) {
            return;
        }

        // Pattern evidence needs a loadable class: an UNREGISTERED alias survives Laravel's
        // expansion unchanged, so without this an unresolved `sanctum` token would count as a
        // guard that does not exist. Ancestry implies loadability on its own.
        $recognized = AuthMiddlewareVocabulary::extendsAuthMiddleware($class)
            || (
                AuthMiddlewareVocabulary::loadable($class)
                && (AuthMiddlewareVocabulary::matchesBrainAuthPattern($token) || AuthMiddlewareVocabulary::matchesBrainAuthPattern($name))
            );

        if ($recognized && ! array_key_exists($class, $guards)) {
            $guards[$class] = $group;
        }
    }

    /**
     * Whether the group reaches itself through nesting — Laravel's expansion would recurse forever
     * on it, so a route referencing such a group is answered with silence.
     *
     * @param  array<array-key, mixed>  $groups
     * @param  array<string, true>  $trail
     */
    private static function groupReachesItself(string $group, array $groups, array $trail): bool
    {
        if (isset($trail[$group])) {
            return true;
        }

        $trail[$group] = true;
        $members = $groups[$group] ?? null;

        if (! is_array($members)) {
            return false;
        }

        foreach ($members as $member) {
            if (! is_string($member)) {
                continue;
            }

            $name = explode(':', $member, 2)[0];

            if (isset($groups[$name]) && self::groupReachesItself($name, $groups, $trail)) {
                return true;
            }
        }

        return false;
    }

    /** The class part of a resolved middleware string, parameters cut. */
    private static function classOf(string $middleware): string
    {
        return ltrim(explode(':', $middleware, 2)[0], '\\');
    }

    private function router(): Router
    {
        $router = app('router');

        if (! $router instanceof Router) {
            // Callers catch Throwable and read this as silence — the lane's failure mode.
            throw new RuntimeException('The router binding did not resolve to a Router.');
        }

        return $router;
    }
}
