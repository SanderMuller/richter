<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use SanderMuller\Richter\Analysis\Hazard;

/**
 * A guard removed from a route declaration in `routes/*.php`.
 *
 * Not a {@see HazardLane}: the lanes run behind a class-like gate, and a route file declares none.
 * It is dispatched from `ChangedSymbols::resolveWithScope()` instead, which is also where the base
 * and head source of any changed path is already in hand.
 *
 * **Per route, never per file.** {@see RouteDeclarations} resolves each route's EFFECTIVE guard set —
 * the middleware written on the route itself plus every enclosing `Route::middleware(...)->group()`
 * and `Route::group(['middleware' => ...], ...)` wrapper, minus its own `->withoutMiddleware()` — and
 * this compares those, side to side. Wrapping existing routes in a guarded group is the commonest
 * edit these files see, and a file-wide token difference would report every one of those routes as
 * newly unguarded while the group above them says otherwise. The per-member lesson
 * {@see AuthHazardLane} learned, applied to a surface with no members.
 *
 * A route the head no longer declares raises nothing. A deleted route is not a route someone can now
 * reach unguarded, and the deletion itself is visible in the diff.
 *
 * The reach lane resolves the hazard through its `member`: the route's own action where the file
 * names one, so the entry points that reach it answer for it, and the route node id for a closure
 * route, which matches an entry point directly whenever the declared URI is the registered one.
 *
 * @internal
 *
 * @phpstan-import-type RouteRecord from RouteDeclarations
 */
final class RouteFileHazards
{
    /**
     * @return array{0: list<Hazard>, 1: list<string>, 2: list<string>} hazards, the tokens this file
     *   added, and any finding about the file itself
     */
    public static function for(string $file, string $headSrc, ?string $baseSrc): array
    {
        $head = RouteDeclarations::of($headSrc);

        // A new route file has nothing to have lost. Its guards still count as arrivals: a route moved
        // out of one file into a new one must not report the move as a removal.
        if ($baseSrc === null) {
            return $head === null ? [[], [], []] : [[], self::allGuards($head), []];
        }

        $base = RouteDeclarations::of($baseSrc);

        if ($head === null || $base === null) {
            $side = $head === null ? 'head' : 'base';

            return [[], [], ["{$file} could not be parsed at {$side} — route middleware was not compared"]];
        }

        $hazards = [];
        $added = [];
        $lost = [];
        $pairs = self::pair($base, $head);

        foreach ($head as $key => $route) {
            $baseKey = array_search($key, $pairs, strict: true);
            $added = [...$added, ...array_diff($route['guards'], is_string($baseKey) ? $base[$baseKey]['guards'] : [])];
        }

        foreach ($base as $key => $route) {
            $headRoute = isset($pairs[$key]) ? $head[$pairs[$key]] : null;

            if ($headRoute === null) {
                continue;
            }

            foreach (array_diff($route['guards'], $headRoute['guards']) as $token) {
                $lost[] = $token;

                $hazards[] = new Hazard(
                    'auth',
                    3,
                    str_starts_with($token, 'middleware:can') ? 'CWE-862' : 'CWE-306',
                    // The HEAD action, not the base one. One edit can drop a route's middleware and
                    // repoint it at another controller, and the base action is a member the head graph
                    // no longer holds — the reach lane would answer `no-known-path` for a route that
                    // is plainly reachable.
                    $headRoute['member'],
                    sprintf('the `%s` middleware is gone from the %s route in %s', substr($token, strlen('middleware:')), self::label($route), $file),
                    [$token],
                );
            }
        }

        // A token this file BOTH lost and gained is not an arrival. `middleware:auth` names no
        // particular surface — nearly every guarded route carries it — so one route gaining it while
        // another loses it would put it in the whole-diff union and suppress the very removal beside
        // it. Two different routes are two different surfaces, not one guard moving. An arrival that
        // stands alone still suppresses, which is the case this file's tokens exist for: a guard moved
        // out of a controller constructor or a middleware group and onto a route.
        return [$hazards, array_values(array_diff(array_unique($added), $lost)), []];
    }

    /**
     * Which head route each base route is. The key — the registration as written — lines the two sides
     * up for every edit but one: changing a route's verb or URI while removing its guard changes the
     * key too, and the base route would read as deleted, which raises nothing.
     *
     * So an unmatched base route falls back to its ACTION, and only when that action names exactly one
     * unmatched route on each side. A controller method serving several routes names none of them
     * uniquely, and pairing the wrong two would report a removal on a route that never had the guard.
     *
     * @param  array<string, RouteRecord>  $base
     * @param  array<string, RouteRecord>  $head
     * @return array<string, string> base key => head key
     */
    private static function pair(array $base, array $head): array
    {
        $pairs = [];

        foreach (array_keys($base) as $key) {
            if (isset($head[$key])) {
                $pairs[$key] = $key;
            }
        }

        $unmatchedBase = array_diff_key($base, $pairs);
        $unmatchedHead = array_diff_key($head, array_flip($pairs));

        foreach ($unmatchedBase as $key => $route) {
            if (! self::namesAnAction($route['member'])) {
                continue;
            }

            $candidates = array_keys(array_filter(
                $unmatchedHead,
                static fn (array $other): bool => $other['member'] === $route['member'],
            ));

            $siblings = array_filter(
                $unmatchedBase,
                static fn (array $other): bool => $other['member'] === $route['member'],
            );

            if (count($candidates) === 1 && count($siblings) === 1) {
                $pairs[$key] = $candidates[0];
            }
        }

        return $pairs;
    }

    /**
     * Whether a member is a real class member rather than one of the stand-ins a route with no action
     * falls back to. Only a real one identifies a route across a rename.
     */
    private static function namesAnAction(string $member): bool
    {
        return str_contains($member, '::') && ! str_starts_with($member, 'route::');
    }

    /**
     * @param  array<string, RouteRecord>  $routes
     * @return list<string>
     */
    private static function allGuards(array $routes): array
    {
        $guards = [];

        foreach ($routes as $route) {
            $guards = [...$guards, ...$route['guards']];
        }

        return array_values(array_unique($guards));
    }

    /**
     * @param  RouteRecord  $route
     */
    private static function label(array $route): string
    {
        return $route['verb'] === 'group'
            ? "group loading {$route['uri']}"
            : strtoupper($route['verb']) . ' ' . $route['uri'];
    }
}
