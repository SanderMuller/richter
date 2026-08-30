<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;

/**
 * How one method treats each property fetch in it: which guard, if any, stands between the fetch and
 * an absent value — and which fetches are not reads at all.
 *
 * Split out of {@see SiblingReads} because that class had no headroom against phpstan.neon's
 * cognitive-complexity ceiling. The split is forced rather than stylistic, though "what does this
 * expression say about absence" is also a question of its own: {@see SiblingReads} decides WHICH
 * reads to record, this decides what each one CLAIMS.
 *
 * @internal
 */
final class ReadStyles
{
    /**
     * The guard style per fetch, and the fetches that are not reads at all.
     *
     * Guard detection is deliberately broad: a local the method tests ANYWHERE disarms the read
     * assigned into it, even where control flow says the test comes first. That is the recall ceiling,
     * and it is chosen so the lane under-reports rather than reporting a guard that is not there.
     *
     * Only the OUTERMOST fetch of a guarded expression is guarded. In `$this->order->id ?? $x` the
     * coalesce says nothing about `order`, which is a receiver on the way to `id`.
     *
     * @return array{0: array<int, string>, 1: array<int, true>}
     */
    public static function of(ClassMethod $method): array
    {
        $styles = [];

        self::markEmptinessChecks($method, $styles);
        self::markNullComparisons($method, $styles);
        self::markFallbacks($method, $styles);
        self::markGuardedLocals($method, $styles);

        return [$styles, self::notReads($method)];
    }

    /**
     * A marker that records one style against the OUTERMOST fetches of an expression.
     *
     * Outermost only: in `$order->customer->name ?? $x` the coalesce guards the name, and says
     * nothing about `customer`, which is a receiver on the way there.
     *
     * @param  array<int, string>  $styles
     * @return Closure(?Node, string): void
     */
    private static function marker(array &$styles): Closure
    {
        return static function (?Node $node, string $style) use (&$styles): void {
            if (! $node instanceof Node) {
                return;
            }

            $finder = new NodeFinder();
            $nested = [];

            foreach ([PropertyFetch::class, MethodCall::class, NullsafePropertyFetch::class, NullsafeMethodCall::class] as $class) {
                foreach ($finder->findInstanceOf($node, $class) as $outer) {
                    if ($outer->var instanceof PropertyFetch) {
                        $nested[spl_object_id($outer->var)] = true;
                    }
                }
            }

            foreach ($finder->findInstanceOf($node, PropertyFetch::class) as $fetch) {
                if ($fetch->name instanceof Identifier && ! isset($nested[spl_object_id($fetch)])) {
                    $styles[spl_object_id($fetch)] = $style;
                }
            }
        };
    }

    /**
     * Fetches that never look at the value: an assignment TARGET, an `unset()`, a by-reference
     * argument. Reporting one would be a claim about code that did not read it.
     *
     * @return array<int, true>
     */
    private static function notReads(ClassMethod $method): array
    {
        $finder = new NodeFinder();
        $notReads = [];

        foreach ($finder->findInstanceOf($method, Assign::class) as $assign) {
            if ($assign->var instanceof PropertyFetch) {
                $notReads[spl_object_id($assign->var)] = true;
            }
        }

        foreach ($finder->findInstanceOf($method, Unset_::class) as $unset) {
            foreach ($unset->vars as $var) {
                if ($var instanceof PropertyFetch) {
                    $notReads[spl_object_id($var)] = true;
                }
            }
        }

        foreach ($finder->findInstanceOf($method, Arg::class) as $arg) {
            if ($arg->byRef && $arg->value instanceof PropertyFetch) {
                $notReads[spl_object_id($arg->value)] = true;
            }
        }

        return $notReads;
    }

    /** @param  array<int, string>  $styles */
    private static function markEmptinessChecks(ClassMethod $method, array &$styles): void
    {
        $finder = new NodeFinder();
        $mark = self::marker($styles);

        foreach ($finder->findInstanceOf($method, FuncCall::class) as $call) {
            $style = self::helperStyle($call);

            if ($style === null) {
                continue;
            }

            foreach (self::arguments($call) as $argument) {
                $mark($argument, $style);
            }
        }

        foreach ($finder->findInstanceOf($method, Empty_::class) as $node) {
            $mark($node->expr, SiblingReads::STYLE_EMPTINESS);
        }

        foreach ($finder->findInstanceOf($method, Isset_::class) as $node) {
            foreach ($node->vars as $var) {
                $mark($var, SiblingReads::STYLE_EMPTINESS);
            }
        }

        self::markNullsafeReceivers($method, $styles);
    }

    /**
     * `$order->external_id?->format(...)` reads the property and tolerates its absence in one step.
     *
     * @param  array<int, string>  $styles
     */
    private static function markNullsafeReceivers(ClassMethod $method, array &$styles): void
    {
        $finder = new NodeFinder();
        $mark = self::marker($styles);

        foreach ([NullsafeMethodCall::class, NullsafePropertyFetch::class] as $class) {
            foreach ($finder->findInstanceOf($method, $class) as $node) {
                $mark($node->var instanceof PropertyFetch ? $node->var : null, SiblingReads::STYLE_EMPTINESS);
            }
        }
    }

    /** The style a helper call implies — `is_null()` tests for null, it does not tolerate it. */
    private static function helperStyle(FuncCall $call): ?string
    {
        if (! $call->name instanceof Name) {
            return null;
        }

        return match (strtolower($call->name->toString())) {
            'filled', 'blank', 'empty' => SiblingReads::STYLE_EMPTINESS,
            'is_null' => SiblingReads::STYLE_NULL_TEST,
            default => null,
        };
    }

    /** @return list<Node> */
    private static function arguments(FuncCall $call): array
    {
        $arguments = [];

        foreach ($call->args as $argument) {
            if ($argument instanceof Arg) {
                $arguments[] = $argument->value;
            }
        }

        return $arguments;
    }

    /** @param  array<int, string>  $styles */
    private static function markNullComparisons(ClassMethod $method, array &$styles): void
    {
        $finder = new NodeFinder();
        $mark = self::marker($styles);

        foreach ([Identical::class, NotIdentical::class] as $comparison) {
            foreach ($finder->findInstanceOf($method, $comparison) as $node) {
                foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$side, $other]) {
                    if ($other instanceof ConstFetch && strtolower($other->name->toString()) === 'null') {
                        $mark($side, SiblingReads::STYLE_NULL_TEST);
                    }
                }
            }
        }
    }

    /** @param  array<int, string>  $styles */
    private static function markFallbacks(ClassMethod $method, array &$styles): void
    {
        $finder = new NodeFinder();
        $mark = self::marker($styles);

        foreach ($finder->findInstanceOf($method, Coalesce::class) as $node) {
            $mark($node->left, SiblingReads::STYLE_FALLBACK);
        }

        foreach ($finder->findInstanceOf($method, Ternary::class) as $node) {
            if ($node->if === null) {
                $mark($node->cond, SiblingReads::STYLE_FALLBACK);
            }
        }
    }

    /** @param  array<int, string>  $styles */
    private static function markGuardedLocals(ClassMethod $method, array &$styles): void
    {
        $guarded = self::guardedLocals($method);

        foreach (new NodeFinder()->findInstanceOf($method, Assign::class) as $assign) {
            if ($assign->var instanceof Variable
                && is_string($assign->var->name)
                && isset($guarded[$assign->var->name])
                && $assign->expr instanceof PropertyFetch
                && $assign->expr->name instanceof Identifier) {
                $styles[spl_object_id($assign->expr)] = SiblingReads::STYLE_EMPTINESS;
            }
        }
    }

    /**
     * Locals the method treats as possibly-absent ANYWHERE in its body.
     *
     * Flow-insensitive on purpose: it only ever removes a claim, which is the direction to fail in.
     *
     * @return array<string, true>
     */
    private static function guardedLocals(ClassMethod $method): array
    {
        $finder = new NodeFinder();
        $guarded = [];

        // Node type => the sub-expressions whose absence that node is testing. One table rather than
        // one loop each: the shapes differ, the question does not.
        $tested = [
            NullsafeMethodCall::class => static fn (NullsafeMethodCall $node): array => [$node->var],
            NullsafePropertyFetch::class => static fn (NullsafePropertyFetch $node): array => [$node->var],
            Coalesce::class => static fn (Coalesce $node): array => [$node->left],
            Ternary::class => static fn (Ternary $node): array => [$node->cond],
            BooleanNot::class => static fn (BooleanNot $node): array => [$node->expr],
            If_::class => static fn (If_ $node): array => [$node->cond],
            Identical::class => static fn (Identical $node): array => [$node->left, $node->right],
            NotIdentical::class => static fn (NotIdentical $node): array => [$node->left, $node->right],
            FuncCall::class => self::emptinessArguments(...),
        ];

        foreach ($tested as $class => $subject) {
            foreach ($finder->findInstanceOf($method, $class) as $node) {
                foreach ($subject($node) as $expression) {
                    if ($expression instanceof Variable && is_string($expression->name)) {
                        $guarded[$expression->name] = true;
                    }
                }
            }
        }

        return $guarded;
    }

    /**
     * The arguments of an emptiness helper, or nothing for any other call.
     *
     * @return list<Node>
     */
    private static function emptinessArguments(FuncCall $call): array
    {
        return self::helperStyle($call) === null ? [] : self::arguments($call);
    }
}
