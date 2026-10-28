<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use Throwable;

/**
 * The booted router, indexed onto route-node ids: per route name, per controller action, per
 * controller class, and per URI template (each template as a regex with `{param}` segments
 * wildcarded, the same matching {@see TestReferenceIndex} uses). Node ids are in the
 * `route::{METHOD}::/{uri}` shape the graph builder derives from Brain's route nodes.
 *
 * The frontend bridge and the dropped-request-field lane share it, so the two cannot disagree on a
 * route's node id.
 *
 * @internal
 */
final readonly class RouterRouteIndex
{
    /**
     * @param  array<string, list<string>>  $byName
     * @param  array<string, list<string>>  $byAction  `Class::method` => route nodes
     * @param  array<string, list<string>>  $byClass
     * @param  list<array{regex: string, nodes: list<string>}>  $uriTemplates
     */
    public function __construct(
        public array $byName,
        public array $byAction,
        public array $byClass,
        public array $uriTemplates,
    ) {}

    /** Null when no router is booted, or it throws while being read. */
    public static function fromRouter(): ?self
    {
        $byName = [];
        $byAction = [];
        $byClass = [];
        $uriTemplates = [];

        try {
            /** @var RoutingRoute $route */
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $nodes = [];

                foreach ($route->methods() as $method) {
                    if (is_string($method) && ! in_array($method, ['HEAD', 'OPTIONS'], strict: true)) {
                        $nodes[] = "route::{$method}::/" . ltrim($route->uri(), '/');
                    }
                }

                if ($nodes === []) {
                    continue;
                }

                $uriTemplates[] = [
                    'regex' => self::uriTemplateRegex('/' . ltrim($route->uri(), '/')),
                    'nodes' => $nodes,
                ];

                $name = $route->getName();

                if (is_string($name) && $name !== '') {
                    $byName[$name] = [...$byName[$name] ?? [], ...$nodes];
                }

                // Invokable controllers arrive as `Class@__invoke` too — the router normalises
                // string actions at registration, so the `@` form is the only controller shape.
                $action = $route->getActionName();

                if (str_contains($action, '@')) {
                    [$class, $method] = explode('@', $action, 2);
                    $class = ltrim($class, '\\');
                    $byAction["{$class}::{$method}"] = [...$byAction["{$class}::{$method}"] ?? [], ...$nodes];
                    $byClass[$class] = [...$byClass[$class] ?? [], ...$nodes];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return new self($byName, $byAction, $byClass, $uriTemplates);
    }

    /**
     * The controller actions registered for one route node, as `Class::method` — the route's OWN
     * actions, which the graph cannot give: its route node points at the controller class, and the
     * class at every action it handles. More than one when two registrations share a URI under
     * different method sets or domains.
     *
     * @return list<string>
     */
    public function actionsFor(string $routeNode): array
    {
        return array_keys(array_filter($this->byAction, static fn (array $nodes): bool => in_array($routeNode, $nodes, true)));
    }

    /**
     * A route URI as a regex over concrete request paths: `{param}` wildcards one segment, and
     * `{param?}` is optional together with its leading slash — `/users/{user?}` serves `/users`,
     * so a literal `/users` must match it. Split on the raw parameters first, then quote only the
     * literal parts: quoting first would demand matching `\{`/`\?` escapes inside the pattern.
     */
    private static function uriTemplateRegex(string $uri): string
    {
        $parts = preg_split('~(/\{[^}]+\}|\{[^}]+\})~', $uri, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $regex = '';

        foreach ($parts === false ? [] : $parts as $part) {
            $regex .= match (true) {
                // `*` zero-or-more (not `+` one-or-more) inside the group so the whole optional
                // group also matches a bare trailing slash (`/users/`) and, at the route root
                // (`/{locale?}`), the bare `/` itself — both legitimately serve the route.
                str_starts_with($part, '/{') => str_ends_with($part, '?}') ? '(?:/[^/]*)?' : '/[^/]+',
                str_starts_with($part, '{') => str_ends_with($part, '?}') ? '[^/]*' : '[^/]+',
                default => preg_quote($part, '#'),
            };
        }

        return "#^{$regex}$#";
    }
}
