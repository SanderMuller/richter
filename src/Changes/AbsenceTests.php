<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\If_;

/**
 * Which sub-expressions a node treats as possibly absent.
 *
 * One table, read by both halves of {@see ReadStyles}: the half that judges a property fetch, and the
 * half that judges a local the fetch was assigned to. They were separate lists once, and they
 * disagreed — `! $order->flag` graded bare while `$f = $order->flag; if (! $f)` graded guarded, so the
 * lane reported the direct form and stayed silent on the same tolerance written the other way round.
 *
 * @internal
 */
final class AbsenceTests
{
    /**
     * Tests that fold `null` and `false` together, which is what makes them soft: `! $x`, `if ($x)`,
     * and a ternary condition all answer the same for both.
     *
     * @return array<class-string<Node>, Closure(Node): Node>
     */
    public static function truthiness(): array
    {
        return [
            BooleanNot::class => static fn (Node $node): Node => $node instanceof BooleanNot ? $node->expr : $node,
            Ternary::class => static fn (Node $node): Node => $node instanceof Ternary ? $node->cond : $node,
            If_::class => static fn (Node $node): Node => $node instanceof If_ ? $node->cond : $node,
        ];
    }

    /**
     * Every shape that says "this may be absent", for judging a LOCAL, WITH the style each one
     * implies. Wider than {@see truthiness()}, and the style matters: a `=== null` on a local is a
     * null test, exactly as it is on the fetch itself, and grading it as emptiness would make it soft.
     * That shipped once — a read assigned to a local and then compared to null suppressed a finding
     * the same comparison written directly would have reported.
     *
     * The style is resolved PER NODE, not per class: `filled()` and `is_null()` are both FuncCalls and
     * they say opposite things about absence.
     *
     * @return array<class-string<Node>, array{0: Closure(Node): list<Node>, 1: Closure(Node): string}>
     */
    public static function all(): array
    {
        $tests = [
            NullsafeMethodCall::class => [static fn (Node $n): array => $n instanceof NullsafeMethodCall ? [$n->var] : [], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS],
            NullsafePropertyFetch::class => [static fn (Node $n): array => $n instanceof NullsafePropertyFetch ? [$n->var] : [], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS],
            Coalesce::class => [static fn (Node $n): array => $n instanceof Coalesce ? [$n->left] : [], static fn (Node $n): string => SiblingReads::STYLE_FALLBACK],
            Identical::class => [static fn (Node $n): array => $n instanceof Identical ? self::nullComparedSides($n->left, $n->right) : [], static fn (Node $n): string => SiblingReads::STYLE_NULL_TEST],
            NotIdentical::class => [static fn (Node $n): array => $n instanceof NotIdentical ? self::nullComparedSides($n->left, $n->right) : [], static fn (Node $n): string => SiblingReads::STYLE_NULL_TEST],
            FuncCall::class => [
                static fn (Node $n): array => $n instanceof FuncCall ? self::emptinessArguments($n) : [],
                static fn (Node $n): string => ($n instanceof FuncCall ? self::helperStyle($n) : null) ?? SiblingReads::STYLE_EMPTINESS,
            ],
            Empty_::class => [static fn (Node $n): array => $n instanceof Empty_ ? [$n->expr] : [], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS],
            Isset_::class => [static fn (Node $n): array => $n instanceof Isset_ ? array_values($n->vars) : [], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS],
        ];

        foreach (self::truthiness() as $class => $subject) {
            $tests[$class] = [static fn (Node $node): array => [$subject($node)], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS];
        }

        return $tests;
    }

    /**
     * The side of a comparison that is NOT the null literal, when the other side is one.
     *
     * `$id === null` says the method treats `$id` as possibly absent. `$id === $other` says nothing of
     * the kind, and grading it a guard would silence a read the source never guarded.
     *
     * @return list<Node>
     */
    private static function nullComparedSides(Node $left, Node $right): array
    {
        foreach ([[$left, $right], [$right, $left]] as [$side, $other]) {
            if ($other instanceof ConstFetch && strtolower($other->name->toString()) === 'null') {
                return [$side];
            }
        }

        return [];
    }

    /**
     * The arguments of an emptiness helper, or nothing for any other call.
     *
     * @return list<Node>
     */
    private static function emptinessArguments(FuncCall $call): array
    {
        if (self::helperStyle($call) === null) {
            return [];
        }

        $arguments = [];

        foreach ($call->args as $argument) {
            if ($argument instanceof Arg) {
                $arguments[] = $argument->value;
            }
        }

        return $arguments;
    }

    /** The style a helper call implies: `is_null()` tests for null, it does not tolerate it. */
    public static function helperStyle(FuncCall $call): ?string
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

    /**
     * The arguments of any call, for the caller that already knows the style.
     *
     * @return list<Node>
     */
    public static function arguments(FuncCall $call): array
    {
        $arguments = [];

        foreach ($call->args as $argument) {
            if ($argument instanceof Arg) {
                $arguments[] = $argument->value;
            }
        }

        return $arguments;
    }
}
