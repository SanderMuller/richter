<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Support\AppFiles;

/**
 * A guard the change removed: tier 3, the top of the scale, because someone can now do what they
 * could not.
 *
 * The lane only ever compares a member that EXISTS ON BOTH SIDES — a member that went away entirely
 * is the contract lane's business, and calling its lost `authorize()` an authorization removal would
 * report the same deletion twice. The one exception is a policy class deleted wholesale, which is
 * reported once, class-level (Edge Cases).
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
    /** Facade methods that decide an ability. `abort_if(Gate::denies(…))` is caught by the inner call. */
    private const array GATE_METHODS = ['authorize', 'allows', 'denies', 'check', 'any', 'none'];

    /**
     * Ability checks, by the receiver that carries them. The receiver is CHECKED, never assumed:
     * `$encoder->can('json')` is not an authorization guard, and reporting its removal as a tier-3
     * hazard is exactly the false positive that trains a reader to ignore the report.
     *
     * `$this->…` covers the `AuthorizesRequests` trait a controller or form request uses.
     */
    private const array THIS_ABILITY_METHODS = ['authorize', 'authorizeForUser', 'can', 'cannot', 'canAny'];

    /** The same abilities on something the AST can name as a user: any `->user()` call. */
    private const array USER_ABILITY_METHODS = ['can', 'cannot', 'canAny'];

    public static function for(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/')) {
            return [[], []];
        }

        $headMethods = HazardSource::methods($headSrc);
        $baseMethods = HazardSource::methods($baseSrc);

        // The guard tokens this file GAINED, counted PER MEMBER and then unioned. Neither cruder
        // reading works: offering every token the head holds lets an untouched sibling that checks the
        // same ability suppress a genuine removal, while a file-wide head-minus-base difference hides
        // a guard that genuinely MOVED from one method to another in this file — the token is in both
        // file-wide sets, so the destination announces nothing and the source reports a removal.
        // Per member, both cases come out right.
        $added = self::gainedGuardTokens($headMethods, $baseMethods);

        $hazards = self::deletedPolicyClasses($file, $headSrc, $baseSrc);
        $deletedClasses = array_map(static fn (Hazard $hazard): string => $hazard->member, $hazards);

        foreach ($baseMethods as $id => $baseMethod) {
            $class = explode('::', $id, 2)[0];

            if (in_array($class, $deletedClasses, strict: true)) {
                continue;
            }

            $headMethod = $headMethods[$id] ?? null;

            if ($headMethod === null) {
                $hazards = [...$hazards, ...self::removedPolicyMethod($file, $id, $baseMethod, $baseSrc)];

                continue;
            }

            // A body reduced to `return true;` already SAYS the guard is gone, and the abilities it
            // dropped on the way are the same fact told twice. The narrower statement wins.
            $neutered = self::neuteredAuthorize($file, $id, $baseMethod, $headMethod, $headSrc);

            $hazards = [...$hazards, ...($neutered === [] ? self::lostGuards($id, $baseMethod, $headMethod) : $neutered)];
        }

        return [$hazards, $added];
    }

    /**
     * The guard tokens each head member holds and its base counterpart did not. A member with no base
     * counterpart is new, so everything it checks is a gain.
     *
     * @param  array<string, ClassMethod>  $headMethods
     * @param  array<string, ClassMethod>  $baseMethods
     * @return list<string>
     */
    private static function gainedGuardTokens(array $headMethods, array $baseMethods): array
    {
        $gained = [];

        foreach ($headMethods as $id => $method) {
            $before = isset($baseMethods[$id]) ? self::guardTokens($baseMethods[$id]) : [];
            $gained = [...$gained, ...array_diff(self::guardTokens($method), $before)];
        }

        return array_values(array_unique($gained));
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
        $headTokens = self::guardTokens($head);
        $gone = array_values(array_diff(self::guardTokens($base), $headTokens));

        // A constructor's rate limit reads exactly as a route's: the guard survives at a different
        // value, so only a RAISED limit is a weakening and a tightened one is nothing at all.
        $hazards = self::rateChange($id, self::guardTokens($base), $headTokens);

        foreach (GuardMiddleware::removals($gone, $headTokens) as $token) {
            $hazards[] = str_starts_with($token, 'middleware:')
                ? new Hazard('auth', 3, GuardMiddleware::cweFor($token), $id, "the `{$token}` middleware is gone from the constructor", [$token])
                : new Hazard('auth', 3, 'CWE-862', $id, "the authorization check `{$token}` is gone from the body", [$token]);
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
     * Every ability this method asserts, as comparable tokens. A literal ability name makes the token
     * exact across files, which is what lets the moved-not-removed guard work; a computed ability
     * falls back to the call's own shape, which matches only an identical call elsewhere — the safe
     * direction, since a guard that fails to match merely reports the removal it saw.
     *
     * @return list<string>
     */
    private static function guardTokens(ClassMethod $method): array
    {
        $tokens = [];

        foreach (HazardSource::within($method, StaticCall::class) as $call) {
            if (! $call->name instanceof Identifier || ! $call->class instanceof Name) {
                continue;
            }

            // `AppFiles::resolveName()`, not `toString()`: an imported `Gate` stays the bare token in
            // the AST, and matching on that would miss every file that imports the facade.
            if (! str_ends_with(AppFiles::resolveName($call->class), '\\Gate') || ! in_array($call->name->toString(), self::GATE_METHODS, strict: true)) {
                continue;
            }

            $tokens[] = self::abilityToken($call->name->toString(), $call->args[0] ?? null);
        }

        foreach ([MethodCall::class, NullsafeMethodCall::class] as $shape) {
            foreach (HazardSource::within($method, $shape) as $call) {
                if (! $call->name instanceof Identifier || $call->isFirstClassCallable()) {
                    continue;
                }

                $name = $call->name->toString();

                if ($name === 'middleware' && HazardSource::isThis($call->var) && $method->name->toString() === '__construct') {
                    $tokens = [...$tokens, ...self::middlewareTokens($call)];

                    continue;
                }

                if (self::isAbilityCheck($name, $call->var)) {
                    $tokens[] = self::abilityToken($name, $call->args[0] ?? null);
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Whether this call is an authorization check, decided from the RECEIVER as well as the name.
     *
     * Two receivers qualify, and nothing else. `$this->` is the `AuthorizesRequests` trait's own
     * surface. A `->user()` call is the only other receiver an AST can name as a user without
     * inferring a type — `$request->user()->can(…)`, `auth()->user()->cannot(…)`,
     * `Auth::user()->canAny(…)` all take that shape.
     *
     * A bare `$user->can(…)` is deliberately NOT matched. The variable's name is a convention, not a
     * proof, and this lane would rather miss a real removal than invent one. Under-firing is the
     * direction every predicate in this family leans.
     */
    private static function isAbilityCheck(string $name, Expr $receiver): bool
    {
        if (HazardSource::isThis($receiver)) {
            return in_array($name, self::THIS_ABILITY_METHODS, strict: true);
        }

        if (! in_array($name, self::USER_ABILITY_METHODS, strict: true)) {
            return false;
        }

        return ($receiver instanceof MethodCall || $receiver instanceof NullsafeMethodCall || $receiver instanceof StaticCall)
            && $receiver->name instanceof Identifier
            && $receiver->name->toString() === 'user';
    }

    /**
     * `$this->middleware('auth')` and `$this->middleware(['auth', 'verified'])`, filtered to the
     * middleware that actually gate access by {@see GuardMiddleware} — the same vocabulary the route
     * file lane uses, so a guard moved between a route and a controller constructor produces the same
     * token on both sides and the moved-not-removed guard matches it.
     *
     * @return list<string>
     */
    private static function middlewareTokens(MethodCall|NullsafeMethodCall $call): array
    {
        $names = [];
        $first = $call->args[0] ?? null;

        if ($first instanceof Arg && $first->value instanceof Array_) {
            foreach ($first->value->items as $item) {
                if ($item->value instanceof String_) {
                    $names[] = $item->value->value;
                }
            }
        } elseif (($literal = HazardSource::literalArgument($first)) !== null) {
            $names[] = $literal;
        }

        return GuardMiddleware::tokensFor($names);
    }

    /**
     * The ability a check names, as a token comparable across call shapes — which is the whole basis of
     * the moved-not-removed guard: `Gate::denies('publish')` rewritten as `$user->cannot('publish')`
     * must produce the same token, or a rewrite reads as a removal.
     *
     * A POLICY CONSTANT counts, not only a string literal. `$user->can(PostPolicy::UPDATE, $post)` is
     * the form Laravel's own docs steer projects toward, and a codebase that follows that convention
     * consistently has NO string-literal abilities at all — so keying only on literals turned the guard
     * off entirely for it, and every rewritten check read as a removed guard at tier 3. The constant is
     * tokenised on its resolved class and its own name, which needs no value resolution: two references
     * to the same constant produce the same token wherever they are written.
     *
     * A computed ability still falls back to the call's own shape, which matches only an identical call
     * elsewhere. That is the safe direction — a token that fails to match merely reports the removal the
     * lane actually saw.
     */
    private static function abilityToken(string $call, mixed $firstArgument): string
    {
        if (! $firstArgument instanceof Arg) {
            return "call:{$call}";
        }

        $value = $firstArgument->value;

        if ($value instanceof String_) {
            return "ability:{$value->value}";
        }

        if ($value instanceof ClassConstFetch && $value->class instanceof Name && $value->name instanceof Identifier) {
            return 'ability:' . AppFiles::resolveName($value->class) . '::' . $value->name->toString();
        }

        return "call:{$call}";
    }

    /**
     * A policy or form-request `authorize()` reduced to `return true;`. The base body must have been
     * something else — a method that always returned true still does, and reporting it would be
     * reporting the status quo.
     *
     * @return list<Hazard>
     */
    private static function neuteredAuthorize(string $file, string $id, ClassMethod $base, ClassMethod $head, string $headSrc): array
    {
        if (! HazardSource::returnsTrueOnly($head) || HazardSource::returnsTrueOnly($base)) {
            return [];
        }

        $isPolicyMethod = str_starts_with($file, 'app/Policies/');
        $isRequestAuthorize = $head->name->toString() === 'authorize' && self::isFormRequest($id, $headSrc);

        if (! $isPolicyMethod && ! $isRequestAuthorize) {
            return [];
        }

        // No guard token: nothing was named, so nothing elsewhere in the diff can be the same guard
        // arriving. An empty token is what tells HazardFindings never to suppress this one.
        return [new Hazard('auth', 3, 'CWE-862', $id, 'the body is now exactly `return true;`, where it was not')];
    }

    private static function isFormRequest(string $id, string $headSrc): bool
    {
        $class = HazardSource::classLikes($headSrc)[explode('::', $id, 2)[0]] ?? null;

        return $class instanceof ClassLike && str_ends_with((string) HazardSource::parentOf($class), '\\FormRequest');
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
