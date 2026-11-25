<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use SanderMuller\Richter\Analysis\Hazard;

/**
 * A guard the change removed: tier 3, the top of the scale, because someone can now do what they
 * could not.
 *
 * The lane compares a member that EXISTS ON BOTH SIDES — a member that went away entirely is the
 * contract lane's business. The exceptions are members that ARE the guard: a policy method, a policy
 * class deleted wholesale, reported once, class-level (Edge Cases), and a form request's
 * `authorize()`. The contract lane leaves those to this one, so the deletion is reported once.
 *
 * Neither route files nor the middleware Kernel are this lane's business. A guard leaves a route by
 * leaving the route ({@see RouteFileHazards}) or by leaving the group the route runs in
 * ({@see MiddlewareGroupHazards}); this lane reads what a class member itself checks.
 *
 * Route files are not this lane's business — they declare no class, so the class-like gate in
 * {@see HazardLanes} never lets one through. {@see RouteFileHazards} compares them route by route,
 * emitting the same `middleware:` tokens this lane does, so a guard moved between a route and a
 * controller constructor matches in the whole-diff pass.
 */
final class AuthHazardLane implements HazardLane
{
    public static function for(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/')) {
            return [[], []];
        }

        $headClasses = HazardSource::classLikes($headSrc);
        $baseClasses = HazardSource::classLikes($baseSrc);
        $headMethods = HazardSource::methods($headSrc);
        $baseMethods = HazardSource::methods($baseSrc);

        $added = array_values(array_unique(array_column(array_filter(GuardCalls::arrivals($file, $headSrc, $baseSrc), static fn (GuardArrival $arrival): bool => $arrival->userProven), 'token')));

        $hazards = self::deletedPolicyClasses($file, $headSrc, $baseSrc);
        $deletedClasses = array_map(static fn (Hazard $hazard): string => $hazard->member, $hazards);

        foreach ($baseMethods as $id => $baseMethod) {
            $class = explode('::', $id, 2)[0];

            if (in_array($class, $deletedClasses, strict: true)) {
                continue;
            }

            $headMethod = $headMethods[$id] ?? null;

            if ($headMethod === null) {
                $hazards = [
                    ...$hazards,
                    ...self::removedPolicyMethod($file, $id, $baseMethod, $baseSrc),
                    ...self::removedRequestAuthorize($id, $baseMethod, $headClasses[$class] ?? null, $baseClasses[$class] ?? null),
                ];

                continue;
            }

            // A body reduced to `return true;` already SAYS the guard is gone, and the abilities it
            // dropped on the way are the same fact told twice. The narrower statement wins.
            $neutered = self::neuteredAuthorize($file, $id, $baseMethod, $headMethod, $headClasses[$class] ?? null);

            $hazards = [...$hazards, ...($neutered === [] ? self::lostGuards($id, $baseMethod, $headMethod) : $neutered)];
        }

        return [$hazards, $added];
    }

    /**
     * Guard tokens the base body had and the head body does not. Comparing TOKENS rather than call
     * counts is deliberate: moving a guard within one method, or rewriting `Gate::denies` as
     * `$user->cannot`, keeps the ability and must not read as a removal.
     *
     * @return list<Hazard>
     */
    private static function lostGuards(string $id, ClassMethod $base, ClassMethod $head): array
    {
        $headTokens = GuardCalls::tokens($id, $head, anyVariable: true);
        $calls = GuardCalls::of($id, $base);
        $gone = array_values(array_diff(array_keys($calls), $headTokens));

        // A constructor's rate limit reads exactly as a route's: the guard survives at a different
        // value, so only a RAISED limit is a weakening and a tightened one is nothing at all.
        $hazards = self::rateChange($id, array_keys($calls), $headTokens);

        foreach (GuardMiddleware::removals($gone, $headTokens) as $token) {
            $hazards[] = str_starts_with($token, 'middleware:')
                ? new Hazard('auth', 3, GuardMiddleware::cweFor($token), $id, "the `{$token}` middleware is gone from the constructor", [$token])
                : new Hazard('auth', 3, 'CWE-862', $id, "the authorization check `{$token}` is gone from the body", [$token], guard: $calls[$token]);
        }

        // The check is still written and its answer no longer decides anything. A base that already
        // ignored it has nothing to lose.
        $unused = array_intersect(
            array_diff(GuardCalls::tokens($id, $base, enforced: true), GuardCalls::tokens($id, $head, anyVariable: true, enforced: true)),
            $headTokens,
        );

        foreach ($unused as $token) {
            $hazards[] = new Hazard('auth', 3, 'CWE-862', $id, "nothing uses the answer of the authorization check `{$token}` any more", [$token], guard: $calls[$token] ?? null);
        }

        return $hazards;
    }

    /**
     * The constructor's rate limit, where the head raised it.
     *
     * @param  list<string>  $baseTokens
     * @param  list<string>  $headTokens
     * @return list<Hazard>
     */
    private static function rateChange(string $id, array $baseTokens, array $headTokens): array
    {
        $looser = GuardMiddleware::looserThrottle($baseTokens, $headTokens);

        if ($looser === null) {
            return [];
        }

        return [new Hazard('auth', 2, 'CWE-770', $id, sprintf(
            'the rate limit in the constructor rose from `%s` to `%s`',
            substr($looser[0], strlen('middleware:')), substr($looser[1], strlen('middleware:')),
        ), [$looser[0]])];
    }

    /**
     * A policy or form-request `authorize()` reduced to an unconditional allow. The base body must have
     * been something else — a method that always allowed still does, and reporting it would be
     * reporting the status quo.
     *
     * @return list<Hazard>
     */
    private static function neuteredAuthorize(string $file, string $id, ClassMethod $base, ClassMethod $head, ?ClassLike $headClass): array
    {
        $isPolicyMethod = str_starts_with($file, 'app/Policies/');
        $isRequestAuthorize = $head->name->toString() === 'authorize' && self::isFormRequest($headClass);

        if (! $isPolicyMethod && ! $isRequestAuthorize) {
            return [];
        }

        // `$this->allow()` is the `HandlesAuthorization` trait's, unless the policy wrote its own.
        $thisAllows = $isPolicyMethod && ! $headClass?->getMethod('allow') instanceof ClassMethod;
        $allow = HazardSource::unconditionalAllow($head, $thisAllows);

        if ($allow === null || HazardSource::unconditionalAllow($base, $thisAllows) !== null) {
            return [];
        }

        // No guard token: nothing was named, so nothing elsewhere in the diff can be the same guard
        // arriving. An empty token is what tells HazardFindings never to suppress this one.
        return [new Hazard('auth', 3, 'CWE-862', $id, "the body is now exactly `{$allow}`, where it was not")];
    }

    private static function isFormRequest(?ClassLike $class): bool
    {
        return $class instanceof ClassLike && str_ends_with((string) HazardSource::parentOf($class), '\\FormRequest');
    }

    /**
     * Whether a form request that survives lost the `authorize()` that decided it. Laravel authorizes a
     * request without the method, so this is a guard removed and not only a contract broken.
     *
     * A trait the head newly uses may hold the method, and an `authorize()` that always allowed guarded
     * nothing. A `passesAuthorization()` the base did not have replaces what would have called it. None of these is
     * this lane's to claim, and they stay with the contract lane.
     */
    public static function losesRequestAuthorize(ClassMethod $base, ?ClassLike $headClass, ?ClassLike $baseClass): bool
    {
        return $base->name->toString() === 'authorize'
            && self::isFormRequest($headClass)
            && $headClass instanceof ClassLike
            && $baseClass instanceof ClassLike
            && ! $headClass->getMethod('authorize') instanceof ClassMethod
            && ! HazardSource::overridesNewly('passesAuthorization', $headClass, $baseClass)
            && array_diff(HazardSource::traitsOf($headClass), HazardSource::traitsOf($baseClass)) === []
            && HazardSource::unconditionalAllow($base, thisAllows: false) === null;
    }

    /**
     * One hazard per ability the method asserted, so each can be matched where it arrives; a single one
     * naming nothing, which is never suppressed, where it asserted none.
     *
     * @return list<Hazard>
     */
    private static function removedRequestAuthorize(string $id, ClassMethod $base, ?ClassLike $headClass, ?ClassLike $baseClass): array
    {
        if (! self::losesRequestAuthorize($base, $headClass, $baseClass)) {
            return [];
        }

        $calls = GuardCalls::of($id, $base);
        $member = new Hazard('contract', 2, null, $id, 'the ' . ($base->isProtected() ? 'protected' : 'public') . ' method is gone');

        return $calls === []
            ? [new Hazard('auth', 3, 'CWE-862', $id, 'the form request `authorize()` is gone')]
            : array_map(
                static fn (string $token): Hazard => new Hazard('auth', 3, 'CWE-862', $id, "the authorization check `{$token}` is gone with the form request `authorize()`", [$token], guard: $calls[$token], whenMoved: $member),
                array_keys($calls),
            );
    }

    /**
     * A policy method that went away. Scoped to `app/Policies/` because a policy method IS the guard,
     * where an ordinary removed method is a broken contract (the contract lane, tier 2).
     *
     * @return list<Hazard>
     */
    private static function removedPolicyMethod(string $file, string $id, ClassMethod $base, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/Policies/') || ! $base->isPublic() || $base->name->toString() === '__construct') {
            return [];
        }

        // The tokens are the ABILITY, not the member: a policy method moving to `Gate::authorize(…)`
        // is a move, and a member-shaped token could never match the `ability:`-shaped ones every
        // other lane emits.
        //
        // BOTH spellings, because a caller may use either. `can('delete')` names the ability as a
        // string; `can(PostPolicy::DELETE)` names the constant standing for it. A project following
        // the constant convention writes only the second, so the bare method name alone would leave
        // the guard unable to see the move.
        $ability = $base->name->toString();
        $fqcn = explode('::', $id, 2)[0];

        return [new Hazard('auth', 3, 'CWE-862', $id, 'the policy method is gone', [
            "ability:{$ability}",
            ...self::constantTokensFor($baseSrc, $fqcn, $ability),
        ])];
    }

    /**
     * A policy class the diff deleted whole. Reported once for the class rather than once per method:
     * the deletion is one decision, and a row per ability would bury it.
     *
     * Its tokens are every ability the class named, so moving a whole policy to gates in one diff is
     * recognised as the move it is.
     *
     * @return list<Hazard>
     */
    private static function deletedPolicyClasses(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/Policies/')) {
            return [];
        }

        $head = HazardSource::classLikes($headSrc);
        $hazards = [];

        foreach (HazardSource::classLikes($baseSrc) as $fqcn => $classLike) {
            if (isset($head[$fqcn])) {
                continue;
            }

            $abilities = [];

            foreach ($classLike->getMethods() as $method) {
                if ($method->isPublic() && $method->name->toString() !== '__construct') {
                    $ability = $method->name->toString();
                    $abilities = [...$abilities, "ability:{$ability}", ...self::constantTokensFor($baseSrc, $fqcn, $ability)];
                }
            }

            $hazards[] = new Hazard('auth', 3, 'CWE-862', $fqcn, 'the policy class is gone', array_values(array_unique($abilities)));
        }

        return $hazards;
    }

    /**
     * The `ability:Fqcn::CONST` tokens for every constant this class declares whose literal value is
     * the given ability.
     *
     * Read from the class's OWN parsed source, so it is a literal comparison rather than a naming
     * convention: `const DELETE = 'delete';` links the constant to the method exactly, where matching
     * `DELETE` against `delete` by shape would be a guess.
     *
     * @return list<string>
     */
    private static function constantTokensFor(string $source, string $fqcn, string $ability): array
    {
        $classLike = HazardSource::classLikes($source)[$fqcn] ?? null;

        if (! $classLike instanceof ClassLike) {
            return [];
        }

        $tokens = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof ClassConst) {
                continue;
            }

            foreach ($stmt->consts as $const) {
                if ($const->value instanceof String_ && $const->value->value === $ability) {
                    $tokens[] = "ability:{$fqcn}::{$const->name->toString()}";
                }
            }
        }

        return $tokens;
    }
}
