<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\EntryPointTracer;

/**
 * Decides which entry-point methods {@see EntryPointTracer} traces. A concrete method whose body
 * contains none of the AST node kinds Brain's MethodTracer draws edges from can only emit zero
 * edges, so skipping it is output-invariant and avoids the traceMethod call (plan 049).
 *
 * @internal
 */
final class EntryPointMethodFilter
{
    public static function shouldTrace(ClassMethod $method): bool
    {
        return ! $method->isAbstract() && self::hasCallNode($method);
    }

    /**
     * Recursive, so an edge-source node nested in a closure/conditional still counts. The set mirrors
     * Brain MethodTracer's dispatch (calls, `new`, and `::const`/`::class`/enum-case fetches via
     * ClassConstFetch) — keep it in sync if Brain widens what it draws edges from, or a method whose
     * only such node is dropped here would silently under-report.
     */
    public static function hasCallNode(ClassMethod $method): bool
    {
        $finder = new NodeFinder();

        return array_any([MethodCall::class, StaticCall::class, NullsafeMethodCall::class, FuncCall::class, New_::class, ClassConstFetch::class], fn (string $edgeNode) => $finder->findFirstInstanceOf((array) $method->stmts, $edgeNode) instanceof Node);
    }
}
