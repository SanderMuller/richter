<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use Illuminate\Support\Str;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Whether a migration's `up()` starts by returning in production, as a seed for local databases does.
 * Every environment name must be written out, and one a reader cannot see leaves the migration unguarded.
 *
 * @internal
 */
final class ProductionGuard
{
    private const string PRODUCTION = 'production';

    private const array APP_FACADES = ['Illuminate\\Support\\Facades\\App', 'App'];

    public static function opens(ClassMethod $up): bool
    {
        $first = $up->stmts[0] ?? null;

        return $first instanceof If_
            && count($first->stmts) === 1
            && $first->stmts[0] instanceof Return_
            && $first->stmts[0]->expr === null
            && self::holdsInProduction($first->cond, expected: true);
    }

    /** Whether the condition certainly has this answer in production. */
    private static function holdsInProduction(Expr $condition, bool $expected): bool
    {
        if ($condition instanceof BooleanNot) {
            return self::holdsInProduction($condition->expr, ! $expected);
        }

        if ($condition instanceof BooleanOr && $expected) {
            return self::holdsInProduction($condition->left, expected: true) || self::holdsInProduction($condition->right, expected: true);
        }

        if (! self::isOnApplication($condition) || ! $condition->name instanceof Identifier) {
            return false;
        }

        $names = match ($condition->name->toString()) {
            'environment' => self::names($condition->args),
            'isProduction' => [self::PRODUCTION],
            default => null,
        };

        return $names !== null && $names !== [] && Str::is($names, self::PRODUCTION) === $expected;
    }

    /** @phpstan-assert-if-true MethodCall|StaticCall $call */
    private static function isOnApplication(Expr $call): bool
    {
        return match (true) {
            $call instanceof MethodCall => $call->var instanceof FuncCall && $call->var->name instanceof Name && $call->var->name->getLast() === 'app' && $call->var->args === [],
            $call instanceof StaticCall => $call->class instanceof Name && in_array(AppFiles::resolveName($call->class), self::APP_FACADES, strict: true),
            default => false,
        };
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @return list<string>|null  null where one of them is not written out
     */
    private static function names(array $args): ?array
    {
        $names = [];

        foreach ($args as $arg) {
            if (! $arg instanceof Arg || $arg->unpack) {
                return null;
            }

            foreach ($arg->value instanceof Array_ ? $arg->value->items : [$arg] as $item) {
                if (! $item->value instanceof String_ || $item->unpack) {
                    return null;
                }

                $names[] = $item->value->value;
            }
        }

        return $names;
    }
}
