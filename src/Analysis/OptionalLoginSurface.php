<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Routing\Route;
use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * The security surface of a route whose only auth middleware lets a guest through: the one Brain gives a
 * route with no auth middleware, with a message that names the middleware.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 *
 * @internal
 */
final class OptionalLoginSurface
{
    private const array WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param  SecurityShape  $surface
     * @param  list<Route>  $routes  the registered routes the entry point stands for
     * @return SecurityShape
     */
    public static function of(array $surface, string $entryPoint, string $middleware, array $routes): array
    {
        [, $method, $uri] = [...explode('::', $entryPoint, 3), '', ''];

        $surface['exposure'] = 'public';

        if (! in_array($method, self::WRITES, strict: true) || array_any($routes, self::isTrusted(...))) {
            return $surface;
        }

        $severity = $method === 'DELETE' ? 'high' : 'medium';

        $surface['issues'][] = [
            'type' => 'PUBLIC_WRITE',
            'severity' => $severity,
            'message' => "Mutating `{$method} {$uri}` requires no authentication — its only auth middleware, {$middleware}, lets a guest through.",
        ];

        if ($surface['riskLevel'] !== 'critical' && $surface['riskLevel'] !== 'high') {
            $surface['riskLevel'] = $severity;
        }

        return $surface;
    }

    /** Brain's own `trusted_route_names` and `trusted_route_uris`, matched as Brain matches them. */
    private static function isTrusted(Route $route): bool
    {
        $name = $route->getName();

        return (is_string($name) && $name !== '' && self::matches('trusted_route_names', $name))
            || self::matches('trusted_route_uris', ltrim($route->uri(), '/'));
    }

    private static function matches(string $key, string $subject): bool
    {
        $patterns = config("laravel-brain.security.{$key}", []);

        return array_any(
            is_array($patterns) ? $patterns : [],
            static fn (mixed $pattern): bool => is_string($pattern) && fnmatch(ltrim($pattern, '/'), $subject, FNM_CASEFOLD),
        );
    }
}
