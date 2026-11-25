<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * The checks of a form request's `authorize()` that always run and whose refusal refuses the request.
 *
 * Read from the method's own statements and nothing nested in them, up to the first one that can end
 * the method with anything but a refusal. A check this does not see may still refuse.
 *
 * @internal
 */
final class RefusingChecks
{
    /** A check that throws where it refuses. */
    private const array THROWING = ['authorize', 'authorizeForUser'];

    /** A check that answers true where it allows. */
    private const array ALLOWING = ['allows', 'check', 'any', 'can', 'canAny', 'allowed'];

    /** A check that answers true where it refuses. */
    private const array REFUSING = ['denies', 'none', 'cannot', 'cant', 'denied'];

    /** What replaces the step that asks `authorize()`, or the one that acts on its answer. */
    private const array OVERRIDES = ['passesAuthorization', 'failedAuthorization'];

    /** Whether the request leaves both what asks `authorize()` and what acts on its answer to Laravel. */
    public static function isAsked(?ClassLike $request): bool
    {
        return $request instanceof ClassLike
            && array_all(self::OVERRIDES, static fn (string $method): bool => ! $request->getMethod($method) instanceof ClassMethod);
    }

    /** @return list<Expr> every call such a check is written with, the outermost of a chain included */
    public static function of(ClassMethod $authorize): array
    {
        $calls = [];

        foreach ($authorize->stmts ?? [] as $statement) {
            $calls = [...$calls, ...match (true) {
                $statement instanceof Expression => self::named($statement->expr, self::THROWING),
                // Laravel reads a returned `Response` for its answer. Anywhere else, the object is only truthy.
                $statement instanceof Return_ && $statement->expr instanceof Expr => [...self::deciding($statement->expr, allows: true), ...self::named($statement->expr, ['inspect'])],
                $statement instanceof If_ && self::onlyRefuses($statement) => self::deciding($statement->cond, allows: false),
                default => [],
            }];

            if (! self::onlyRefuses($statement) && new NodeFinder()->findFirstInstanceOf([$statement], Return_::class) instanceof Return_) {
                break;
            }
        }

        return $calls;
    }

    /** An `if` without another branch whose body is one `return false;`. */
    private static function onlyRefuses(Stmt $statement): bool
    {
        if (! $statement instanceof If_ || $statement->elseifs !== [] || $statement->else !== null || count($statement->stmts) !== 1) {
            return false;
        }

        $only = $statement->stmts[0];

        return $only instanceof Return_ && $only->expr instanceof ConstFetch && $only->expr->name->toLowerString() === 'false';
    }

    /**
     * @param  list<string>  $names
     * @return list<Expr>
     */
    private static function named(Expr $expr, array $names): array
    {
        return ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall)
            && $expr->name instanceof Identifier
            && in_array($expr->name->toString(), $names, strict: true) ? self::chain($expr) : [];
    }

    /**
     * The calls that each decide the answer alone: every operand of `&&` where the answer must be true,
     * and every operand of `||` where it must be false.
     *
     * @return list<Expr>
     */
    private static function deciding(Expr $expr, bool $allows): array
    {
        return match (true) {
            $expr instanceof BooleanNot => self::deciding($expr->expr, ! $allows),
            $allows && $expr instanceof BooleanAnd, ! $allows && $expr instanceof BooleanOr => [...self::deciding($expr->left, $allows), ...self::deciding($expr->right, $allows)],
            default => self::named($expr, [...self::THROWING, ...($allows ? self::ALLOWING : self::REFUSING)]),
        };
    }

    /** @return list<Expr> */
    private static function chain(Expr $expr): array
    {
        $calls = [];

        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall) {
            $calls[] = $expr;

            if ($expr instanceof StaticCall) {
                break;
            }

            $expr = $expr->var;
        }

        return $calls;
    }
}
