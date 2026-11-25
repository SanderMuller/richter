<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter\Standard;

/**
 * The guards one method asserts, as comparable tokens with the check that asserts each, and where a
 * file gained one.
 *
 * @internal
 */
final class GuardCalls
{
    /** A call longer than this is a closure or a built array, which says nothing on one line. */
    private const int MAX_CALL = 100;

    /**
     * Every ability this method asserts, as comparable tokens. A literal ability name makes the token
     * exact across files, which is what lets the moved-not-removed guard work; a computed ability
     * falls back to the call's own shape, which matches only an identical call elsewhere — the safe
     * direction, since a guard that fails to match merely reports the removal it saw.
     *
     * @param  string  $id  the method as `Fqcn::name`
     * @param  bool  $enforced  whether a check whose answer nothing uses is left out
     * @param  bool  $anyVariable  see {@see AbilityChecks::isCheck()}
     * @return array<string, string|null> token => the check as written, or null for a middleware
     */
    public static function of(string $id, ClassMethod $method, bool $anyVariable = false, bool $enforced = false): array
    {
        $calls = [];

        $class = explode('::', $id, 2)[0];
        $discarded = $enforced ? AbilityChecks::discardedCalls($method) : [];
        $users = $anyVariable ? [] : AbilityChecks::userVariables($method);

        foreach (HazardSource::within($method, StaticCall::class) as $call) {
            if ($call->name instanceof Identifier && AbilityChecks::isGateCheck($call) && ! in_array($call, $discarded, strict: true)) {
                $calls[AbilityChecks::token($class, $call->name->toString(), $call->args[0] ?? null)] ??= self::written($call);
            }
        }

        foreach ([MethodCall::class, NullsafeMethodCall::class] as $shape) {
            foreach (HazardSource::within($method, $shape) as $call) {
                if (! $call->name instanceof Identifier || $call->isFirstClassCallable()) {
                    continue;
                }

                $name = $call->name->toString();

                if ($name === 'middleware' && HazardSource::isThis($call->var) && $method->name->toString() === '__construct') {
                    $calls += array_fill_keys(self::middlewareTokens($call), null);

                    continue;
                }

                if (AbilityChecks::isCheck($name, $call->var, $users, $anyVariable) && ! in_array($call, $discarded, strict: true)) {
                    $calls[AbilityChecks::token($class, $name, $call->args[0] ?? null)] ??= self::written($call);
                }
            }
        }

        return $calls;
    }

    /** @return list<string> */
    public static function tokens(string $id, ClassMethod $method, bool $anyVariable = false, bool $enforced = false): array
    {
        return array_keys(self::of($id, $method, $anyVariable, $enforced));
    }

    /**
     * The guards each head member holds and its base counterpart did not. A member with no base
     * counterpart is new, so everything it checks is a gain.
     *
     * Counted PER MEMBER and then unioned. Neither cruder reading works: offering every token the head
     * holds lets an untouched sibling that checks the same ability suppress a genuine removal, while a
     * file-wide head-minus-base difference hides a guard that genuinely MOVED from one method to
     * another in this file — the token is in both file-wide sets, so the destination announces nothing
     * and the source reports a removal. Per member, both cases come out right.
     *
     * @return list<GuardArrival>
     */
    public static function arrivals(string $file, string $headSrc, ?string $baseSrc, bool $isNew = false): array
    {
        if (! str_starts_with($file, 'app/') || ($baseSrc === null && ! $isNew)) {
            return [];
        }

        $baseMethods = HazardSource::methods($baseSrc ?? '');
        $classes = $isNew ? HazardSource::classLikes($headSrc) : [];
        $arrivals = [];

        foreach (HazardSource::methods($headSrc) as $id => $method) {
            $before = isset($baseMethods[$id]) ? self::heldBy($id, $baseMethods[$id]) : [];

            $proven = self::of($id, $method, enforced: true);
            $refusing = $isNew && $method->name->toString() === 'authorize' && RefusingChecks::isAsked($classes[explode('::', $id, 2)[0]] ?? null) ? self::among(RefusingChecks::of($method), $id) : [];

            foreach (array_diff_key(self::heldBy($id, $method), $before) as $token => $call) {
                $arrivals[] = new GuardArrival($token, $id, $call, $isNew, in_array($token, $refusing, strict: true), array_key_exists($token, $proven));
            }
        }

        return $arrivals;
    }

    /**
     * @param  list<Expr>  $calls
     * @return list<string>
     */
    private static function among(array $calls, string $id): array
    {
        $holder = new ClassMethod('authorize', ['stmts' => array_map(static fn (Expr $call): Expression => new Expression($call), $calls)]);

        return self::tokens($id, $holder);
    }

    /**
     * What a method guards, for saying where a check went. Where the ability is written out, the receiver
     * need not be a proven user: the ability is what the two places have in common.
     *
     * @return array<string, string|null>
     */
    private static function heldBy(string $id, ClassMethod $method): array
    {
        return self::of($id, $method, enforced: true) + array_filter(
            self::of($id, $method, anyVariable: true, enforced: true),
            static fn (string $token): bool => str_starts_with($token, 'ability:'),
            ARRAY_FILTER_USE_KEY,
        );
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

    private static function written(Expr $call): string
    {
        $code = (string) preg_replace('/\s+/', ' ', new Standard()->prettyPrintExpr($call));

        return mb_strlen($code) > self::MAX_CALL ? mb_substr($code, 0, self::MAX_CALL) . '…' : $code;
    }
}
