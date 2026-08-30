<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\LogicalXor;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\While_;

/**
 * Which sub-expressions a node treats as possibly absent, and what that says about the read.
 *
 * TWO QUESTIONS decide every entry, in this order:
 *
 *   1. Does the construct SUPPLY a value where the property is absent? Then it is soft, and nothing
 *      else matters. `?? $other` hands the caller something usable; whether it also covers an empty
 *      string is beside the point, because the read no longer depends on the property being there.
 *      This is the lane's founding shape — a bare read beside a sibling's `external_id ?? account_id`
 *      — so a rule that made `??` hard would delete the lane's original purpose.
 *   2. Otherwise: does it TOLERATE an absent value, treating it the same as an empty one, or
 *      DISCRIMINATE it, detecting `null` while an empty string walks past?
 *
 *   supplies      soft.
 *   tolerates     soft. The read handles absence, so there is nothing to report.
 *   discriminates `null-test`. It detects null but not the empty case, which IS the mismatch this
 *                 lane was built to report — the founding example was a `=== null` beside a sibling's
 *                 `filled()`, with an empty string slipping through.
 *
 * Asked as one question rather than two, `??` reads as discriminating (`'' ?? 'x'` stays empty) and
 * lands on the wrong side. It was raised that way in review; the order above is the answer.
 *
 * Applied to every construct rather than to the ones that happened to come up:
 *
 *   `??` `?:` `??=`           supply a value            soft
 *   `empty()` `filled()` `blank()`  treat empty as absent     soft
 *   `!` `if` `while` `&&` `(bool)`  treat empty as absent     soft
 *   `== null` `!= null`       match '' and 0 too        soft
 *   `=== null` `!== null` `is_null()`  match null alone  null-test
 *   `isset()`                 true for '' and false     null-test
 *   `?->`                     short-circuits on null only  null-test
 *
 * ADDING A SOFT FORM: add it here, and add its spelling to the parity list in
 * `SiblingReadsTest::a_direct_test_and_a_test_through_a_local_agree()`. Four soft forms have been
 * missed one spelling at a time — boolean negation, a plain `if`, `empty()`/`isset()` on a local, and
 * `??=` — each of them recognised in one spelling and invisible in another. The pattern is always the
 * same: PHP writes one idea several ways, and a classifier that enumerates node types by hand will
 * eventually enumerate only some of them.
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
     * Every construct PHP evaluates for truth, because `null` and `false` answer alike in all of
     * them. Enumerated rather than sampled: an incomplete list here is the defect that has recurred
     * four times, and each entry only ever REMOVES a finding, so a wrong inclusion costs recall while
     * a missing one costs correctness.
     *
     * Deliberately NOT here, each considered and rejected: `assert()` demands presence rather than
     * tolerating absence, and is compiled out entirely in production, so it guards nothing at
     * runtime; `match ($x)` and `switch ($x)` over any other subject compare values; a callback
     * passed to `array_filter()` takes its tolerance from the calling API, not from the expression;
     * and coalescing the RESULT of a call guards that result, not the property handed to it.
     *
     * @return array<class-string<Node>, Closure(Node): list<Node>>
     */
    public static function truthiness(): array
    {
        return [
            BooleanNot::class => static fn (Node $n): array => $n instanceof BooleanNot ? [$n->expr] : [],
            Ternary::class => static fn (Node $n): array => $n instanceof Ternary ? [$n->cond] : [],
            If_::class => static fn (Node $n): array => $n instanceof If_ ? [$n->cond] : [],
            ElseIf_::class => static fn (Node $n): array => $n instanceof ElseIf_ ? [$n->cond] : [],
            While_::class => static fn (Node $n): array => $n instanceof While_ ? [$n->cond] : [],
            Do_::class => static fn (Node $n): array => $n instanceof Do_ ? [$n->cond] : [],
            // Only the LAST condition controls the loop. `for ($i = 0; $a, $b;)` evaluates both and
            // continues on `$b` alone, so the earlier expressions are plain reads.
            For_::class => static fn (Node $n): array => $n instanceof For_ && $n->cond !== [] ? [$n->cond[array_key_last($n->cond)]] : [],
            BooleanAnd::class => static fn (Node $n): array => $n instanceof BooleanAnd ? [$n->left, $n->right] : [],
            BooleanOr::class => static fn (Node $n): array => $n instanceof BooleanOr ? [$n->left, $n->right] : [],
            LogicalAnd::class => static fn (Node $n): array => $n instanceof LogicalAnd ? [$n->left, $n->right] : [],
            LogicalOr::class => static fn (Node $n): array => $n instanceof LogicalOr ? [$n->left, $n->right] : [],
            LogicalXor::class => static fn (Node $n): array => $n instanceof LogicalXor ? [$n->left, $n->right] : [],
            Bool_::class => static fn (Node $n): array => $n instanceof Bool_ ? [$n->expr] : [],
            // Only the `true`-subject forms: `match (true)` and `switch (true)` use their arm and case
            // expressions as predicates, while `match ($x)` compares values and says nothing about
            // absence.
            Match_::class => static fn (Node $n): array => $n instanceof Match_ ? self::predicatesOfTrueSubject($n->cond, array_merge(...array_map(static fn (MatchArm $arm): array => $arm->conds ?? [], $n->arms))) : [],
            Switch_::class => static fn (Node $n): array => $n instanceof Switch_ ? self::predicatesOfTrueSubject($n->cond, array_values(array_filter(array_map(static fn (Case_ $case): ?Node => $case->cond, $n->cases)))) : [],
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
            // `?->` short-circuits on null and on nothing else: an empty string continues into the
            // call and fails there. It detects absence rather than tolerating it, so it sits with
            // `isset()` and the strict comparisons.
            //
            // CORRECT BUT ALL BUT UNREACHABLE, and deliberately kept. These two mark the RECEIVER of
            // the nullsafe — `$order?->external_id` speaks about `$order` — and a receiver is being
            // dereferenced as an object, while {@see SiblingReadIndex::nullableScalars()} reports only
            // nullable SCALARS. The two populations barely meet: it takes a property whose docblock
            // says `string|null` while it actually holds an object. Measured on a corpus with 232
            // nullsafe sites, reclassifying these changed not one verdict. Do not delete them as dead
            // code — they become reachable the day findings widen past scalars, and they are right by
            // the rule either way.
            NullsafeMethodCall::class => [static fn (Node $n): array => $n instanceof NullsafeMethodCall ? [$n->var] : [], static fn (Node $n): string => SiblingReads::STYLE_NULL_TEST],
            NullsafePropertyFetch::class => [static fn (Node $n): array => $n instanceof NullsafePropertyFetch ? [$n->var] : [], static fn (Node $n): string => SiblingReads::STYLE_NULL_TEST],
            Coalesce::class => [static fn (Node $n): array => $n instanceof Coalesce ? [$n->left] : [], static fn (Node $n): string => SiblingReads::STYLE_FALLBACK],
            // `$x ??= 'd'` is the same defaulting written as an assignment: it READS the value (a
            // read-modify-write always does) and supplies one when it is absent. Missed once, because
            // the coalesce operator and the coalesce-assign operator are different node types.
            CoalesceAssign::class => [static fn (Node $n): array => $n instanceof CoalesceAssign ? [$n->var] : [], static fn (Node $n): string => SiblingReads::STYLE_FALLBACK],
            Identical::class => [static fn (Node $n): array => $n instanceof Identical ? self::nullComparedSides($n->left, $n->right) : [], static fn (Node $n): string => SiblingReads::STYLE_NULL_TEST],
            NotIdentical::class => [static fn (Node $n): array => $n instanceof NotIdentical ? self::nullComparedSides($n->left, $n->right) : [], static fn (Node $n): string => SiblingReads::STYLE_NULL_TEST],
            // `== null` matches '', 0 and false as well, so it tolerates absence the way `! $x` does.
            // `=== null` above matches null alone, which is why the two land on opposite sides.
            Equal::class => [static fn (Node $n): array => $n instanceof Equal ? self::nullComparedSides($n->left, $n->right) : [], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS],
            NotEqual::class => [static fn (Node $n): array => $n instanceof NotEqual ? self::nullComparedSides($n->left, $n->right) : [], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS],
            FuncCall::class => [
                static fn (Node $n): array => $n instanceof FuncCall ? self::emptinessArguments($n) : [],
                static fn (Node $n): string => ($n instanceof FuncCall ? self::helperStyle($n) : null) ?? SiblingReads::STYLE_EMPTINESS,
            ],
            Empty_::class => [static fn (Node $n): array => $n instanceof Empty_ ? [$n->expr] : [], static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS],
            // `isset()` is a null DETECTOR, not a tolerance: `isset('')` and `isset(false)` are both
            // true, so an empty string walks straight past it. That is the mismatch this lane exists
            // to report, which puts it beside `=== null` rather than beside `empty()`.
            Isset_::class => [static fn (Node $n): array => $n instanceof Isset_ ? array_values($n->vars) : [], static fn (Node $n): string => SiblingReads::STYLE_NULL_TEST],
        ];

        foreach (self::truthiness() as $class => $subject) {
            $tests[$class] = [$subject, static fn (Node $n): string => SiblingReads::STYLE_EMPTINESS];
        }

        return $tests;
    }

    /**
     * The arm or case expressions of a `match`/`switch`, but only when its subject is the literal
     * `true` — that is the form where each expression is evaluated as a predicate.
     *
     * @param  list<Node>  $predicates
     * @return list<Node>
     */
    private static function predicatesOfTrueSubject(?Node $subject, array $predicates): array
    {
        return $subject instanceof ConstFetch && strtolower($subject->name->toString()) === 'true'
            ? $predicates
            : [];
    }

    /**
     * The side of a comparison that is NOT the null literal, when the other side is one.
     *
     * `$id === null` says the method treats `$id` as possibly absent. `$id === $other` says nothing of
     * the kind, and grading it a guard would silence a read the source never guarded.
     *
     * @return list<Node>
     */
    public static function nullComparedSides(Node $left, Node $right): array
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
