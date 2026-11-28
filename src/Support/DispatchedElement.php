<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * What one element of a dispatched array provably holds, plus the one guess {@see namedConstructorTarget()}.
 *
 * Two readers ask this. {@see DispatchEdgeTracer} reads a literal written in the dispatch call, and
 * {@see AccumulatedArrayJobs} reads the literal that starts a local and every append to it. Both
 * claim what the array holds, so they ask here and cannot read one element two ways.
 *
 * @internal
 */
final class DispatchedElement
{
    /** The framework job that wraps a queued closure. `dispatch(fn () => …)` builds one itself. */
    private const string QUEUED_CLOSURE = 'Illuminate\\Queue\\CallQueuedClosure';

    /**
     * The fluent calls a wrapped closure may carry and stay the same queued work: each returns
     * `$this` and adds no job. `chain()`, `prependToChain()` and `appendToChain()` add jobs, and
     * `through()` and `onFailure()` take values this reading does not look into, so each is left out.
     */
    private const array QUEUED_CLOSURE_FLUENT = ['name', 'onQueue', 'onConnection', 'onGroup', 'delay', 'withoutDelay', 'afterCommit', 'beforeCommit'];

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
     * @return list<string>|null
     */
    public static function jobsOf(Expr $value): ?array
    {
        if (self::isQueuedClosure($value)) {
            return [];
        }

        if (! $value instanceof New_ || ! $value->class instanceof Name) {
            return null;
        }

        $job = self::constructedTarget($value);

        return $job === null ? [] : [$job];
    }

    /**
     * Whether the value is a closure written at the dispatch: bare, or wrapped by hand the way the
     * framework wraps it, `CallQueuedClosure::create(function () { … })`, with any number of
     * {@see self::QUEUED_CLOSURE_FLUENT} calls on the result.
     *
     * The wrapper is read only around an INLINE closure. `create($callback)` queues whatever the
     * variable holds, and nothing here says what that is.
     */
    public static function isQueuedClosure(Expr $value): bool
    {
        if ($value instanceof Closure || $value instanceof ArrowFunction) {
            return true;
        }

        while ($value instanceof MethodCall
            && $value->name instanceof Identifier
            && in_array($value->name->toString(), self::QUEUED_CLOSURE_FLUENT, strict: true)
        ) {
            $value = $value->var;
        }

        if (! $value instanceof StaticCall
            || ! $value->class instanceof Name
            || ! $value->name instanceof Identifier
            || $value->name->toString() !== 'create'
            || $value->isFirstClassCallable()
            || AppFiles::resolveName($value->class) !== self::QUEUED_CLOSURE
        ) {
            return false;
        }

        $args = $value->getArgs();

        return count($args) === 1
            && ! $args[0]->unpack
            && ($args[0]->value instanceof Closure || $args[0]->value instanceof ArrowFunction);
    }

    public static function constructedTarget(Expr $expr): ?string
    {
        if (! $expr instanceof New_ || ! $expr->class instanceof Name) {
            return null;
        }

        $job = AppFiles::resolveName($expr->class);

        return DispatchTarget::matches($job) ? $job : null;
    }

    /**
     * The dispatch target a `SomeJob::for($x)` argument names, if its receiver is one.
     *
     * A guess, not a proof: nothing here shows the method returns its own class. The caller draws the
     * edge and keeps the site.
     *
     * @return list<string>
     */
    public static function namedConstructorTarget(?Expr $value): array
    {
        if (! $value instanceof StaticCall || ! $value->class instanceof Name) {
            return [];
        }

        $job = AppFiles::resolveName($value->class);

        return DispatchTarget::matches($job) ? [$job] : [];
    }
}
