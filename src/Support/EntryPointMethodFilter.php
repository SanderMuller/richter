<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\EntryPointTracer;

/**
 * Decides which entry-point methods {@see EntryPointTracer} traces.
 * A concrete method whose body contains no call node can only emit zero call edges through Brain's
 * MethodTracer, so skipping it is output-invariant and avoids pure overhead (plan 045 /
 * internal/perf-graph-build-report-2026-07-24.md).
 *
 * @internal
 */
final class EntryPointMethodFilter
{
    public static function shouldTrace(ClassMethod $method): bool
    {
        return ! $method->isAbstract() && self::hasCallNode($method);
    }

    /** Recursive, so a call nested in a closure/conditional still counts. */
    public static function hasCallNode(ClassMethod $method): bool
    {
        $finder = new NodeFinder();

        return array_any([MethodCall::class, StaticCall::class, NullsafeMethodCall::class, FuncCall::class, New_::class], fn (string $callNode) => $finder->findFirstInstanceOf((array) $method->stmts, $callNode) instanceof Node);
    }
}
