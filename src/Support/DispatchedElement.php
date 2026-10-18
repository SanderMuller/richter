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
 * claim what the array holds, so they ask here. Two copies of the test once disagreed on
 * `[...new SomeJob()]` (the accumulator recorded an unresolved site, and the inline reader took the
 * spread as the job and recorded none) and on a `new` of a class that is no dispatch target (the
 * inline reader ignored it, and the accumulator recorded a site).
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
     * A `new` of a class that is NO dispatch target is provably no job: `[]`, not null. The class
     * loads, is not dispatchable by declaration, and has neither `handle()` nor `__invoke()` (a class
     * that does not load counts as a target). Laravel calls `chain()` and `onConnection()` on every
     * chain item and `withBatchId()` on every batch item, so such an object fails at runtime and runs
     * no application code. Refusing it would add an unresolved site, and one site holds every
     * `richter:affected-tests` run at "not determinable".
     *
     * One gap remains, and it is the one {@see DispatchTarget} already documents for
     * `dispatch(new SomeCommand())`: a command that uses `Queueable` and runs through a separate
     * `Bus::map` handler. That handler's code is not reached here either.
     *
     * Only the array literal and its appends read this. A mapped batch with a non-job branch
     * ({@see MappedCollectionJobs}) and a single bound local ({@see LocallyConstructedJobs}) ask
     * {@see constructedTarget()} and keep the site.
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
