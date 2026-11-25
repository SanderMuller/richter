<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;

/**
 * What a migration runs: `up()`, the methods of its class it calls, and the tables they create.
 *
 * @internal
 */
final class MigrationMethods
{
    /**
     * `up()` and every method of its class reachable from it through `$this->…()`, `self::…()` or `static::…()`.
     *
     * @return list<ClassMethod>
     */
    public static function calledFrom(ClassMethod $up, ?ClassLike $class): array
    {
        $methods = [$up];

        for ($index = 0; $index < count($methods); ++$index) {
            $calls = [...HazardSource::within($methods[$index], MethodCall::class), ...HazardSource::within($methods[$index], StaticCall::class)];

            foreach ($calls as $call) {
                $called = self::isOwn($call) && $call->name instanceof Identifier ? $class?->getMethod($call->name->toString()) : null;

                if ($called instanceof ClassMethod && ! in_array($called, $methods, strict: true)) {
                    $methods[] = $called;
                }
            }
        }

        return $methods;
    }

    /**
     * The tables the migration creates, each with the source ranges in which it is certainly new: what
     * runs after the `Schema::create()`. A create behind a condition reaches no further than its branch,
     * because outside it the table may be an old one.
     *
     * @param  list<ClassMethod>  $methods
     * @return array<string, list<array{int, int}>>
     */
    public static function createdTables(array $methods, ?ClassLike $class): array
    {
        $tables = [];

        foreach ($methods as $method) {
            foreach (HazardSource::within($method, StaticCall::class) as $call) {
                if (! MigrationHazards::isSchemaFacade($call) || ! $call->name instanceof Identifier || $call->name->toString() !== 'create') {
                    continue;
                }

                $table = MigrationLiterals::string($call->args[0] ?? null, $class);

                if ($table !== null) {
                    $tables[$table] = [...($tables[$table] ?? []), ...self::runsAfter($call, $method, $methods, $class, [$method])];
                }
            }
        }

        return $tables;
    }

    /**
     * Where the code after a node is, and the code it calls.
     *
     * @param  list<ClassMethod>  $methods
     * @param  list<ClassMethod>  $seen
     * @return list<array{int, int}>
     */
    private static function runsAfter(Node $node, ClassMethod $method, array $methods, ?ClassLike $class, array $seen): array
    {
        $branch = self::branchAround($node, $method);
        $ranges = [[$node->getStartFilePos(), $branch[1] ?? $method->getEndFilePos()]];

        // Past a method that always creates the table, so past every call to it.
        if ($branch === null && ! self::mayReturnBefore($node, $method)) {
            foreach ($methods as $caller) {
                foreach (self::callsTo($method, $caller, $class) as $call) {
                    if (! in_array($caller, $seen, strict: true)) {
                        $ranges = [...$ranges, ...self::runsAfter($call, $caller, $methods, $class, [...$seen, $caller])];
                    }
                }
            }
        }

        return self::withCalled($ranges, $methods, $class);
    }

    /**
     * The ranges and every method called from nowhere else.
     *
     * @param  list<array{int, int}>  $ranges
     * @param  list<ClassMethod>  $methods
     * @return list<array{int, int}>
     */
    private static function withCalled(array $ranges, array $methods, ?ClassLike $class): array
    {
        do {
            $before = count($ranges);

            foreach ($methods as $method) {
                $own = [$method->getStartFilePos(), $method->getEndFilePos()];
                $calls = array_merge([], ...array_map(static fn (ClassMethod $caller): array => self::callsTo($method, $caller, $class), $methods));

                if ($calls !== [] && ! in_array($own, $ranges, strict: true) && array_all($calls, static fn (Node $call): bool => array_any($ranges, static fn (array $range): bool => $range[0] <= $call->getStartFilePos() && $call->getEndFilePos() <= $range[1]))) {
                    $ranges[] = $own;
                }
            }
        } while (count($ranges) > $before);

        return $ranges;
    }

    /** @return list<MethodCall|StaticCall> */
    private static function callsTo(ClassMethod $method, ClassMethod $caller, ?ClassLike $class): array
    {
        return array_values(array_filter(
            [...HazardSource::within($caller, MethodCall::class), ...HazardSource::within($caller, StaticCall::class)],
            static fn (MethodCall|StaticCall $call): bool => self::isOwn($call) && $call->name instanceof Identifier && $class?->getMethod($call->name->toString()) === $method,
        ));
    }

    private static function mayReturnBefore(Node $node, ClassMethod $method): bool
    {
        return array_any(HazardSource::within($method, Return_::class), static fn (Return_ $return): bool => $return->getEndFilePos() < $node->getStartFilePos());
    }

    /**
     * The narrowest branch that holds the node, or null where the method always reaches it.
     *
     * @return array{int, int}|null
     */
    private static function branchAround(Node $node, ClassMethod $method): ?array
    {
        $narrowest = null;

        foreach (new NodeFinder()->find($method->stmts ?? [], static fn (Node $candidate): bool => true) as $candidate) {
            foreach (self::branchesOf($candidate) as $branch) {
                $range = [$branch[0]->getStartFilePos(), $branch[array_key_last($branch)]->getEndFilePos()];

                // Outer before inner, so the last that holds the node is the narrowest.
                if ($range[0] <= $node->getStartFilePos() && $node->getEndFilePos() <= $range[1]) {
                    $narrowest = $range;
                }
            }
        }

        return $narrowest;
    }

    /** @return list<non-empty-list<Node>> */
    private static function branchesOf(Node $node): array
    {
        $branches = match (true) {
            $node instanceof If_, $node instanceof ElseIf_, $node instanceof Else_, $node instanceof Case_, $node instanceof TryCatch, $node instanceof Catch_, $node instanceof Foreach_, $node instanceof For_, $node instanceof While_ => [array_values($node->stmts)],
            $node instanceof BooleanAnd, $node instanceof BooleanOr, $node instanceof LogicalAnd, $node instanceof LogicalOr, $node instanceof Coalesce => [[$node->right]],
            $node instanceof Ternary => [...($node->if instanceof Node ? [[$node->if]] : []), [$node->else]],
            $node instanceof MatchArm => [[$node->body]],
            default => [],
        };

        return array_values(array_filter($branches, static fn (array $branch): bool => $branch !== []));
    }

    private static function isOwn(MethodCall|StaticCall $call): bool
    {
        return $call instanceof MethodCall
            ? HazardSource::isThis($call->var)
            : $call->class instanceof Name && in_array($call->class->toLowerString(), ['self', 'static'], strict: true);
    }
}
