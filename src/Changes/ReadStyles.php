<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
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
     * The guard styles per fetch, and the fetches that are not reads at all.
     *
     * One pass over {@see AbsenceTests::all()}, for both kinds of subject, so the same tests give the
     * same styles on both paths. A tested PROPERTY FETCH collects every style it is tested with. A
     * tested LOCAL collects them too, and the fetch assigned into it takes that whole set.
     *
     * The tested expression must BE the fetch or the variable. A guard says something about the value
     * it tests, not about every value nested inside it: `if (accepts($order->external_id))` tests what
     * `accepts()` returned, and that function is free to reject `null`, distinguish it from `false`, or
     * hand it on. A chained receiver falls out of the same rule: in `$order->customer->name ?? $x` the
     * coalesce's left side is the `name` fetch, so `customer` is untouched.
     *
     * Guard detection is deliberately broad: a local the method tests ANYWHERE disarms the read
     * assigned into it, even where control flow says the test comes first. That is the recall ceiling,
     * and it is chosen so the lane under-reports rather than reporting a guard that is not there.
     *
     * @return array{0: array<int, list<string>>, 1: array<int, true>}
     */
    public static function of(ClassMethod $method): array
    {
        $finder = new NodeFinder();
        $styles = [];
        $locals = [];

        foreach (AbsenceTests::all() as $class => [$subject, $style]) {
            foreach ($finder->findInstanceOf($method, $class) as $node) {
                foreach ($subject($node) as $tested) {
                    if ($tested instanceof PropertyFetch && $tested->name instanceof Identifier) {
                        $styles[spl_object_id($tested)][$style($node)] = true;
                    } elseif ($tested instanceof Variable && is_string($tested->name)) {
                        $locals[$tested->name][$style($node)] = true;
                    }
                }
            }
        }

        foreach ($finder->findInstanceOf($method, Assign::class) as $assign) {
            if ($assign->var instanceof Variable
                && is_string($assign->var->name)
                && isset($locals[$assign->var->name])
                && $assign->expr instanceof PropertyFetch
                && $assign->expr->name instanceof Identifier) {
                $id = spl_object_id($assign->expr);
                $styles[$id] = ($styles[$id] ?? []) + $locals[$assign->var->name];
            }
        }

        return [array_map(array_keys(...), $styles), self::notReads($method)];
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
}
