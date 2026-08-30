<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
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
        self::markTruthinessTests($method, $styles);
        self::markNullComparisons($method, $styles);
        self::markFallbacks($method, $styles);
        self::markGuardedLocals($method, $styles);

        return [$styles, self::notReads($method)];
    }

    /**
     * The style that wins when one local is tested more than one way, in the order the direct path
     * applies its own marks: a supplied default outranks a null test, which outranks bare tolerance.
     * `$id ?? 'x'` after an `if ($id === null)` is a defaulted read, not a null test.
     */
    private static function strongest(?string $current, string $candidate): string
    {
        $rank = [
            SiblingReads::STYLE_EMPTINESS => 0,
            SiblingReads::STYLE_NULL_TEST => 1,
            SiblingReads::STYLE_FALLBACK => 2,
        ];

        if ($current === null) {
            return $candidate;
        }

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }

    /**
     * A marker that records one style against the expression a guard is applied TO.
     *
     * The expression must BE the property fetch. A guard says something about the value it tests, not
     * about every value nested inside it: `if (accepts($order->external_id))` tests what `accepts()`
     * returned, and that function is free to reject `null`, distinguish it from `false`, or hand it
     * on. Marking the nested fetch there would suppress a finding on the changed side and, worse,
     * manufacture soft evidence on the other side — a claim the source does not make.
     *
     * It is also what keeps the two classifier paths symmetrical: {@see guardedLocals()} marks a local
     * only where the tested expression IS the variable, so `if (accepts($local))` guards nothing.
     * Reported by a review of the first version of this fix, which walked the whole expression.
     *
     * A chained receiver falls out of the same rule: in `$order->customer->name ?? $x` the coalesce's
     * left side is the `name` fetch, so `customer` is untouched.
     *
     * @param  array<int, string>  $styles
     * @return Closure(?Node, string): void
     */
    private static function marker(array &$styles): Closure
    {
        return static function (?Node $node, string $style) use (&$styles): void {
            if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
                $styles[spl_object_id($node)] = $style;
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
            $style = AbsenceTests::helperStyle($call);

            if ($style === null) {
                continue;
            }

            foreach (AbsenceTests::arguments($call) as $argument) {
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

    /**
     * A read the method only tests for truth tolerates an absent value, exactly as `empty()` does:
     * `if (! $question->timer_enabled)` answers the same for `null` and for `false`, which on a
     * tri-state boolean column is the most common way code says "absent is fine".
     *
     * These are the contexts {@see guardedLocals()} already treats as a guard when the value reaches
     * the test through a variable. Applying them to the fetch itself is what makes the two paths
     * agree: before this, `! $order->flag` read as bare while `$f = $order->flag; if (! $f)` read as
     * guarded, and the lane reported the first while staying silent on the second.
     *
     * Marked BEFORE the null comparisons, so `if ($order->flag === null)` still ends as a null-test:
     * that one distinguishes null from false rather than folding them together.
     *
     * @param  array<int, string>  $styles
     */
    private static function markTruthinessTests(ClassMethod $method, array &$styles): void
    {
        $mark = self::marker($styles);

        foreach (AbsenceTests::truthiness() as $class => $subject) {
            foreach (new NodeFinder()->findInstanceOf($method, $class) as $node) {
                foreach ($subject($node) as $tested) {
                    $mark($tested, SiblingReads::STYLE_EMPTINESS);
                }
            }
        }
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

        foreach ($finder->findInstanceOf($method, CoalesceAssign::class) as $node) {
            $mark($node->var, SiblingReads::STYLE_FALLBACK);
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
                $styles[spl_object_id($assign->expr)] = $guarded[$assign->var->name];
            }
        }
    }

    /**
     * Locals the method treats as possibly-absent ANYWHERE in its body.
     *
     * Flow-insensitive on purpose: it only ever removes a claim, which is the direction to fail in.
     *
     * @return array<string, string> local name => the style its strongest guard implies
     */
    private static function guardedLocals(ClassMethod $method): array
    {
        $finder = new NodeFinder();
        $guarded = [];

        // Node type => the sub-expressions whose absence it tests, and the style that implies. One
        // table rather than one loop each: the shapes differ, the question does not.
        foreach (AbsenceTests::all() as $class => [$subject, $style]) {
            foreach ($finder->findInstanceOf($method, $class) as $node) {
                foreach ($subject($node) as $expression) {
                    if ($expression instanceof Variable && is_string($expression->name)) {
                        $guarded[$expression->name] = self::strongest($guarded[$expression->name] ?? null, $style($node));
                    }
                }
            }
        }

        return $guarded;
    }
}
