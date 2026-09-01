<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Throwable;

/**
 * The one place richter decides "does this middleware authenticate?". Two independent tests, used
 * by both cross-check lanes ({@see PublicWriteAuthCrossCheck} on graph edges,
 * {@see RuntimeRouterGuards} on the booted router) so the security vocabulary cannot fork:
 *
 * - {@see extendsAuthMiddleware()} — ancestry against the four framework bases, by reflection.
 * - {@see matchesBrainAuthPattern()} — Brain's own pattern semantics over its complete effective
 *   auth set (built-ins plus `laravel-brain.security.auth_middleware`). Brain's matcher is private
 *   upstream, so this is a mirror; `BrainSecurityContractTest` pins it against upstream behaviour.
 *
 * @internal
 */
final class AuthMiddlewareVocabulary
{
    /**
     * The framework middlewares whose descendants authenticate a request. Laravel's whole set.
     * Brain 2.6 walks the `extends` chain to all four bases itself, so on the supported floor this
     * ancestry test mostly CORROBORATES Brain rather than out-reaching it — kept pointed at all
     * four regardless, because a base Brain resolves makes a lane silent for that shape, never
     * wrong about it, and the lanes must not depend on which Brain version a consumer resolved.
     * `BrainSecurityContractTest` pins the upstream behaviour — named in prose, not linked: a
     * `{@see}` here would be turned into an import of a test class by the style pass, and `tests/`
     * is export-ignored from the dist archive, so the shipped file would import what is not there.
     *
     * @var list<class-string>
     */
    public const array AUTH_MIDDLEWARE_BASES = [
        'Illuminate\\Auth\\Middleware\\Authenticate',
        'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth',
        'Illuminate\\Auth\\Middleware\\EnsureEmailIsVerified',
        'Illuminate\\Routing\\Middleware\\ValidateSignature',
    ];

    /**
     * Brain's built-in auth patterns (`SecurityAnalyzer::AUTH_PATTERNS`, private upstream). A
     * built-in alias inside a named middleware group (`sanctum` in `api`) resolves to a class that
     * need not descend from the four bases, so the pattern layer is what recognises it.
     *
     * @var list<string>
     */
    private const array BRAIN_AUTH_PATTERNS = [
        'auth',
        'auth.basic',
        'sanctum',
        'jwt',
        'passport',
        'verified',
        'signed',
        'Illuminate\\Auth\\Middleware\\Authenticate',
        'Illuminate\\Routing\\Middleware\\ValidateSignature',
    ];

    /**
     * Whether the class is, or descends from, one of Laravel's authentication middlewares. Reflection
     * rather than the graph: the ancestry may run through vendor classes richter never scans, and this
     * runs at analysis time where the checkout is already autoloadable. A name that does not resolve
     * to a class (an unresolved alias, a middleware from a package that is not installed) is not
     * evidence of anything, so it reads false — Brain's finding then stands unchallenged, which is the
     * safe direction for a security annotation.
     */
    public static function extendsAuthMiddleware(string $fqcn): bool
    {
        try {
            if (! class_exists($fqcn)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return array_any(
            self::AUTH_MIDDLEWARE_BASES,
            static fn (string $base): bool => $fqcn === $base || is_subclass_of($fqcn, $base),
        );
    }

    /**
     * Whether the name resolves to a class at all. Pattern-based evidence needs this: Laravel
     * leaves an UNREGISTERED alias unchanged in the expanded stack, so an unresolved `sanctum`
     * token would otherwise survive expansion as itself, match the built-in pattern, and lower a
     * hazard's reach on a middleware that does not exist.
     */
    public static function loadable(string $fqcn): bool
    {
        try {
            return class_exists($fqcn);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Brain's `middlewareMatches()` semantics over its complete effective auth set: exact match,
     * `pattern:` prefix (parameters), `pattern\` prefix (FQCN namespace), and — for an FQCN
     * pattern — a bare basename match, all case-insensitive.
     */
    public static function matchesBrainAuthPattern(string $middleware): bool
    {
        $lower = strtolower($middleware);
        $base = self::basename($middleware);

        foreach (self::effectiveAuthPatterns() as $pattern) {
            $p = strtolower($pattern);

            if ($lower === $p || str_starts_with($lower, $p . ':') || str_starts_with($lower, $p . '\\')) {
                return true;
            }

            if (str_contains($p, '\\') && $base !== '' && $base === self::basename($pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function effectiveAuthPatterns(): array
    {
        $configured = config('laravel-brain.security.auth_middleware', []);

        return [...self::BRAIN_AUTH_PATTERNS, ...array_values(array_filter(is_array($configured) ? $configured : [], is_string(...)))];
    }

    /**
     * The lowercased class name of a middleware, ignoring any `:params` suffix —
     * `App\Http\Middleware\Authenticate:web` → `authenticate`. Brain's `middlewareBasename()`.
     */
    private static function basename(string $middleware): string
    {
        $name = explode(':', $middleware, 2)[0];
        $pos = strrpos($name, '\\');

        return strtolower($pos === false ? $name : substr($name, $pos + 1));
    }
}
