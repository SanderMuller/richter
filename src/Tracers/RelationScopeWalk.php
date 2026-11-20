<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * One descent over a function body for {@see RelationTraversalTracer}: the outermost closures (each
 * its own scope), the nodes that bind or walk a name in preorder (which IS start-position order,
 * because a child never starts before its parent), and the branching ranges. One descent replaces
 * three finds that each walked the same tree, and the quadratic containment filters they needed.
 *
 * @internal
 */
final class RelationScopeWalk extends NodeVisitorAbstract
{
    /** @var list<Closure|ArrowFunction> */
    public array $closures = [];

    /** @var list<Assign|AssignOp|AssignRef|Catch_|Unset_|Foreach_|PropertyFetch|NullsafePropertyFetch|MethodCall|NullsafeMethodCall> */
    public array $nodes = [];

    /** @var list<array{0: int, 1: int}> */
    private array $branching = [];

    public function __construct(private readonly FunctionLike $scope) {}

    public function enterNode(Node $node): ?int
    {
        // `$node !== $this->scope` because the traversal starts on the scope node itself, and
        // stopping there would skip the very body this walk exists to read.
        if ($node !== $this->scope && ($node instanceof Closure || $node instanceof ArrowFunction)) {
            $this->closures[] = $node;

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        // Also a branching range below: it binds its own names, and an assignment in its body
        // may not run.
        if ($node instanceof Foreach_) {
            $this->nodes[] = $node;
        }

        // Bindings that write a name without typing it: each one clears the name.
        if ($node instanceof AssignOp || $node instanceof AssignRef || $node instanceof Catch_ || $node instanceof Unset_) {
            $this->nodes[] = $node;
        }

        if ($node instanceof Assign
            || $node instanceof PropertyFetch
            || $node instanceof NullsafePropertyFetch
            || $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall) {
            $this->nodes[] = $node;

            return null;
        }

        // An assignment inside one of these may or may not run, and a sibling branch may bind
        // the same name to another model — so its range marks bindings as conditional.
        if ($node instanceof If_
            || $node instanceof Else_
            || $node instanceof ElseIf_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Switch_
            || $node instanceof Match_
            || $node instanceof TryCatch
            || $node instanceof Ternary) {
            $this->branching[] = [$node->getStartFilePos(), $node->getEndFilePos()];
        }

        return null;
    }

    /** Whether a node sits inside an `if`, a loop, a `try`, a `match` or a ternary, so it may not run. */
    public function isConditional(Node $node): bool
    {
        return array_any(
            $this->branching,
            static fn (array $range): bool => $node->getStartFilePos() >= $range[0] && $node->getEndFilePos() <= $range[1],
        );
    }

    /**
     * The names a node writes without a type this can read, or null when the node is no such write:
     * `[$a] = $rows`, `$a = &$rows[0]`, `$a .= 'x'`, `catch (E $a)` and `unset($a)`.
     *
     * @return list<string>|null
     */
    public static function writtenNames(Node $node): ?array
    {
        $target = match (true) {
            $node instanceof Assign => $node->var instanceof Variable ? null : $node->var,
            $node instanceof AssignOp, $node instanceof AssignRef, $node instanceof Catch_ => $node->var,
            $node instanceof Unset_ => $node->vars,
            default => null,
        };

        if ($target === null) {
            return null;
        }

        $names = [];

        foreach (new NodeFinder()->findInstanceOf($target, Variable::class) as $variable) {
            if (is_string($variable->name)) {
                $names[] = $variable->name;
            }
        }

        return $names;
    }

    /**
     * The calls written after the innermost call of a chain, in source order, when every name after it
     * is a call: `$post->comments()->published()->get()` gives `published`, `get`. A property fetch
     * between them, or a dynamic name, gives none.
     *
     * @return list<string>
     */
    public static function callsAfterInnermost(Expr $expression): array
    {
        // Outermost first; reversed below so the calls read in source order.
        $calls = [];
        $node = $expression;

        while ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            if (! $node->name instanceof Identifier) {
                return [];
            }

            $calls[] = $node->name->toString();
            $node = $node->var;
        }

        $finder = new NodeFinder();

        // A call further in means the innermost call is not the last one of this run.
        if ($finder->findFirstInstanceOf($node, MethodCall::class) !== null || $finder->findFirstInstanceOf($node, NullsafeMethodCall::class) !== null) {
            return [];
        }

        // The innermost call itself is where the walk stops, not a call after it.
        array_pop($calls);

        return array_reverse($calls);
    }
}
