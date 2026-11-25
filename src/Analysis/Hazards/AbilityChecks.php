<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\UnionType;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Whether a call is an authorization check, decided from the RECEIVER as well as the name, and which
 * ability it names.
 *
 * `$encoder->can('json')` is not an authorization guard, and reporting its removal as a tier-3 hazard
 * is exactly the false positive that trains a reader to ignore the report. So a receiver is proven,
 * never assumed, and an unproven one is not matched: under-firing is the direction every predicate in
 * this family leans.
 *
 * @internal
 */
final class AbilityChecks
{
    /** Gate methods that decide an ability. `abort_if(Gate::denies(…))` is caught by the inner call. */
    private const array GATE_METHODS = ['authorize', 'allows', 'denies', 'check', 'any', 'none', 'inspect'];

    /** `$this->…` covers the `AuthorizesRequests` trait a controller or form request uses. */
    private const array THIS_METHODS = ['authorize', 'authorizeForUser', 'can', 'cannot', 'canAny'];

    private const array USER_METHODS = ['can', 'cannot', 'canAny'];

    /** The checks that refuse by throwing. Every other one only answers. */
    private const array THROWING_METHODS = ['authorize', 'authorizeForUser'];

    private const string AUTHORIZABLE = 'Illuminate\Contracts\Auth\Access\Authorizable';

    public static function isGateCheck(StaticCall $call): bool
    {
        return $call->name instanceof Identifier
            && self::isGateFacade($call)
            && in_array($call->name->toString(), self::GATE_METHODS, strict: true);
    }

    /**
     * @param  list<string>  $users  the variables this method proves to hold a user
     * @param  bool  $anyVariable  whether a variable counts without that proof — for asking if a check
     *   SURVIVES, where missing one would claim a removal that did not happen
     */
    public static function isCheck(string $name, Expr $receiver, array $users, bool $anyVariable): bool
    {
        if (HazardSource::isThis($receiver)) {
            return in_array($name, self::THIS_METHODS, strict: true);
        }

        // `Gate::forUser($user)->authorize(…)` is the facade's own check, for someone else.
        if ($receiver instanceof StaticCall && $receiver->name instanceof Identifier && $receiver->name->toString() === 'forUser') {
            return self::isGateFacade($receiver) && in_array($name, self::GATE_METHODS, strict: true);
        }

        if (! in_array($name, self::USER_METHODS, strict: true)) {
            return false;
        }

        if ($receiver instanceof Variable || $receiver instanceof PropertyFetch) {
            return $anyVariable || ($receiver instanceof Variable && in_array($receiver->name, $users, strict: true));
        }

        return self::isUserCall($receiver);
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
    public static function token(string $class, string $call, mixed $firstArgument): string
    {
        if (! $firstArgument instanceof Arg) {
            return "call:{$call}";
        }

        $value = $firstArgument->value;

        // Laravel takes an enum case as an ability, so `Ability::PUBLISH` and `Ability::PUBLISH->value`
        // name the same one.
        if ($value instanceof PropertyFetch && $value->var instanceof ClassConstFetch && $value->name instanceof Identifier && $value->name->toString() === 'value') {
            $value = $value->var;
        }

        if ($value instanceof String_) {
            return "ability:{$value->value}";
        }

        if ($value instanceof ClassConstFetch && $value->class instanceof Name && $value->name instanceof Identifier) {
            // `self::DELETE` inside a policy and `PostPolicy::DELETE` anywhere else are one constant.
            $owner = in_array($value->class->toLowerString(), ['self', 'static'], strict: true) ? $class : AppFiles::resolveName($value->class);

            return "ability:{$owner}::{$value->name->toString()}";
        }

        return "call:{$call}";
    }

    /**
     * The variables a method proves to hold a user: a parameter typed as one, or the result of a
     * `->user()` call. A variable's name is a convention and proves nothing.
     *
     * A name bound more than once — a reassignment, a loop variable, a closure parameter that shadows
     * it — is proven only where every binding is a user.
     *
     * @return list<string>
     */
    public static function userVariables(ClassMethod $method): array
    {
        $proven = [];

        foreach ([$method, ...HazardSource::within($method, Closure::class), ...HazardSource::within($method, ArrowFunction::class)] as $function) {
            foreach ($function->params as $param) {
                self::bind($proven, $param->var, $param->type instanceof Node && self::isUserType($param->type));
            }
        }

        foreach (HazardSource::within($method, Assign::class) as $assign) {
            self::bind($proven, $assign->var, self::isUserCall($assign->expr));
        }

        foreach (HazardSource::within($method, Foreach_::class) as $loop) {
            self::bind($proven, $loop->valueVar, false);
        }

        return array_keys(array_filter($proven));
    }

    /**
     * The calls in a method that stand as a statement of their own and only answer. `Gate::inspect('x');`
     * asks and ignores what it hears, so it guards nothing, and reading it as the check it replaced would
     * hide that. So does `Gate::inspect('x')->allowed();`, every call of the chain down to one that throws.
     *
     * @return list<Expr>
     */
    public static function discardedCalls(ClassMethod $method): array
    {
        $discarded = [];

        foreach (HazardSource::within($method, Expression::class) as $statement) {
            $call = $statement->expr instanceof BooleanNot ? $statement->expr->expr : $statement->expr;

            while (($call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall)
                && $call->name instanceof Identifier
                && ! in_array($call->name->toString(), self::THROWING_METHODS, strict: true)) {
                $discarded[] = $call;
                $call = $call instanceof StaticCall ? null : $call->var;
            }
        }

        return $discarded;
    }

    /** @param  array<string, bool>  $proven */
    private static function bind(array &$proven, Node $variable, bool $isUser): void
    {
        if ($variable instanceof Variable && is_string($variable->name)) {
            $proven[$variable->name] = $isUser && ($proven[$variable->name] ?? true);
        }
    }

    private static function isGateFacade(StaticCall $call): bool
    {
        // `AppFiles::resolveName()`, not `toString()`: an imported `Gate` stays the bare token in the
        // AST, and matching on that would miss every file that imports the facade.
        return $call->class instanceof Name && str_ends_with(AppFiles::resolveName($call->class), '\\Gate');
    }

    /**
     * Any `->user()` call: `$request->user()`, `auth()->user()` and `Auth::user()` all take that shape.
     */
    private static function isUserCall(Expr $expr): bool
    {
        return ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall)
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'user';
    }

    private static function isUserType(Node $type): bool
    {
        if ($type instanceof NullableType) {
            return self::isUserType($type->type);
        }

        if ($type instanceof UnionType) {
            $types = array_filter($type->types, static fn (Node $member): bool => ! $member instanceof Identifier || $member->toLowerString() !== 'null');

            return $types !== [] && array_all($types, self::isUserType(...));
        }

        if (! $type instanceof Name) {
            return false;
        }

        $fqcn = AppFiles::resolveName($type);

        return $fqcn === self::AUTHORIZABLE || str_ends_with($fqcn, '\\User');
    }
}
