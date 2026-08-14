<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\StaticVar;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * Which of a method's local variables provably hold a dispatch target when it dispatches them.
 *
 * `$job = new SomeJob(...); dispatch($job);` is the shape this exists for. The graph already carries
 * the edge — the instantiation is right there in the same method — so recording the dispatch as
 * unfollowable taints every test selection over reach nothing is missing from. Answering "does this
 * variable provably hold a job?" is what lets {@see DispatchEdgeTracer}
 * tell that apart from a variable it genuinely cannot see into.
 */
final class LocallyConstructedJobs
{
    /**
     * Variables this method provably fills with a dispatch target, by name.
     *
     * The bar is deliberately high, because the payoff is dropping an unfollowable-dispatch signal and
     * the cost of being wrong is a selection that omits a real test. A name qualifies only when the
     * method writes it EXACTLY ONCE, that write is a top-level `= new SomeTarget(...)`, and nothing
     * else in the method can rebind it — no second assignment, no `foreach` binding, no by-reference
     * `use`, no parameter of the same name. A conditional assignment therefore never qualifies: it
     * sits below the top level, so the variable could still hold whatever it held before.
     *
     * The one residual is a by-reference parameter of some callee reassigning it between the
     * construction and the dispatch, which needs the callee's signature to see. Every other unknown
     * shape lands on "not provable", which keeps the site.
     *
     * @return array<string, array{pos: int, jobs: list<string>}>
     */
    public static function in(ClassMethod $method): array
    {
        $writes = self::variableWriteCounts($method);
        $constructed = [];

        foreach ($method->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof Assign) {
                continue;
            }

            $assign = $stmt->expr;
            if (! $assign->var instanceof Variable) {
                continue;
            }

            if (! is_string($assign->var->name)) {
                continue;
            }

            if (! $assign->expr instanceof New_) {
                continue;
            }

            if (! $assign->expr->class instanceof Name) {
                continue;
            }

            if (($writes[$assign->var->name] ?? 0) !== 1) {
                continue;
            }

            $job = AppFiles::resolveName($assign->expr->class);

            if (DispatchTarget::matches($job)) {
                // The byte offset, not the line: the caller compares it against the dispatch argument's
                // own offset, and a whole method body written on one line must still order correctly.
                $constructed[$assign->var->name] = ['pos' => $assign->getStartFilePos(), 'jobs' => [$job]];
            }
        }

        return $constructed;
    }

    /**
     * How many times each variable name is bound anywhere in the method, at any depth.
     *
     * Counting is deliberately blunt: a closure's own `$job = ...` is a different variable in a
     * different scope, but counting it disqualifies the outer name, and over-counting only ever costs
     * an exemption. Under-counting would cost a test.
     *
     * @return array<string, int>
     */
    private static function variableWriteCounts(ClassMethod $method): array
    {
        $counts = [];
        $count = static function (?Node $node) use (&$counts): void {
            if ($node instanceof Variable && is_string($node->name)) {
                $counts[$node->name] = ($counts[$node->name] ?? 0) + 1;
            }
        };

        foreach ($method->params as $param) {
            $count($param->var);
        }

        foreach (new NodeFinder()->find([$method], static fn (Node $node): bool => true) as $node) {
            match (true) {
                $node instanceof Assign, $node instanceof AssignRef, $node instanceof AssignOp => array_map($count, self::assignedVariables($node->var)),
                $node instanceof Foreach_ => array_map($count, [$node->keyVar, $node->valueVar]),
                $node instanceof ClosureUse => array_map($count, [$node->var]),
                $node instanceof Static_ => array_map($count, array_map(static fn (StaticVar $var): Variable => $var->var, $node->vars)),
                $node instanceof Global_ => array_map($count, $node->vars),
                $node instanceof Catch_ => array_map($count, [$node->var]),
                $node instanceof Unset_ => array_map($count, $node->vars),
                default => null,
            };
        }

        return $counts;
    }

    /**
     * The variables an assignment target binds — one for `$a = …`, several for `[$a, $b] = …`.
     *
     * @return list<Node|null>
     */
    private static function assignedVariables(Expr $target): array
    {
        if (! $target instanceof Array_ && ! $target instanceof List_) {
            return [$target];
        }

        $bound = [];

        foreach ($target->items as $item) {
            if ($item instanceof ArrayItem) {
                $bound = [...$bound, ...self::assignedVariables($item->value)];
            }
        }

        return $bound;
    }
}
