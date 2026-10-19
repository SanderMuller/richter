<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * What one element of a dispatched array provably holds.
 *
 * Two readers ask this. {@see DispatchEdgeTracer} reads a literal written in the dispatch call, and
 * {@see AccumulatedArrayJobs} reads the literal that starts a local and every append to it. Both
 * claim what the array holds, so they ask here and cannot read one element two ways.
 *
 * @internal
 */
final class DispatchedElement
{
    /**
     * The jobs one array element provably is, or null when this test cannot tell.
     *
     * A SPREAD is never provable. `[...$others]` brings contents in from elsewhere, and
     * `[...new SomeJob()]` is legal but inserts what a Traversable job YIELDS, not the job. The value is
     * a `new` of a dispatch target in the second case, so a test of the value alone accepts it.
     *
     * A null is not a verdict on the whole dispatch. A caller with another reading of the element (a
     * local bound above the dispatch, for example) can still try it.
     *
     * @return list<string>|null
     */
    public static function jobsIn(ArrayItem $item): ?array
    {
        return $item->unpack ? null : self::jobsOf($item->value);
    }

    /**
     * The jobs one value provably is: none for a closure, which IS the queued work and whose body is
     * already traced, and the class a `new` names. Null for anything else, a `new` of a dynamic class
     * included.
     *
     * A `new` of a class that is NO dispatch target is provably no job: `[]`, not null. Laravel calls
     * `chain()`, `onConnection()` or `withBatchId()` on every chain and batch item, so such an object
     * fails at runtime and runs no application code. The one gap is the `Bus::map` handler that
     * {@see DispatchTarget} documents for `dispatch(new SomeCommand())`.
     *
     * Only the array literal and its appends read this. {@see MappedCollectionJobs} and
     * {@see LocallyConstructedJobs} ask {@see constructedTarget()} and keep the site.
     *
     * @return list<string>|null
     */
    public static function jobsOf(Expr $value): ?array
    {
        if ($value instanceof Closure || $value instanceof ArrowFunction) {
            return [];
        }

        if (! $value instanceof New_ || ! $value->class instanceof Name) {
            return null;
        }

        $job = self::constructedTarget($value);

        return $job === null ? [] : [$job];
    }

    /** The dispatch target a `new SomeJob(...)` names, or null when it names none. */
    public static function constructedTarget(Expr $expr): ?string
    {
        if (! $expr instanceof New_ || ! $expr->class instanceof Name) {
            return null;
        }

        $job = AppFiles::resolveName($expr->class);

        return DispatchTarget::matches($job) ? $job : null;
    }
}
