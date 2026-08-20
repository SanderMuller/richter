<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * The dispatch targets a `Bus::batch($items->map(fn () => new SomeJob(…))->all())` argument provably
 * holds.
 *
 * `map()` is total — one item out per item in — so whatever its callable returns IS the item type,
 * whatever the source collection held. That makes the batch's contents provable without knowing the
 * source, which is what separates this shape from every other opaque argument
 * {@see DispatchEdgeTracer} has to record as unfollowable. Building the jobs by mapping and handing
 * the array to `Bus::batch()` is how an application queues work in bulk, and reading it as
 * unfollowable taints `richter:affected-tests` for the whole project — while the graph already
 * carries the edge, drawn from the `new` inside the closure.
 *
 * Everything the proof depends on is checked: the calls between the `map` and the dispatch must
 * preserve the item type, the callable must be written out at the call site, and every return must
 * be a `new` of a resolvable class. Anything else answers null, and the caller doubts the site
 * exactly as before.
 *
 * @internal
 */
final class MappedCollectionJobs
{
    /**
     * Collection calls that cannot change what the items ARE: they drop items, reorder them, or hand
     * the collection over as an array. A call outside this list (`pluck`, `flatten`, `concat`, a
     * macro) can, so the walk stops there rather than trusting the `map` above it.
     *
     * @var list<string>
     */
    private const array ITEM_PRESERVING = ['all', 'toArray', 'values', 'filter', 'reject', 'unique', 'sort', 'sortBy', 'sortByDesc', 'reverse', 'take', 'skip', 'slice', 'shuffle', 'whereNotNull', 'collect'];

    /** @return list<string>|null the dispatch targets, or null when this is not a provable mapped collection */
    public static function in(?Expr $value): ?array
    {
        while ($value instanceof MethodCall && $value->name instanceof Identifier) {
            $method = $value->name->toString();

            if ($method === 'map' || $method === 'flatMap') {
                $callable = $value->args[0] ?? null;

                return $callable instanceof Arg ? self::jobsReturnedBy($callable->value) : null;
            }

            if (! in_array($method, self::ITEM_PRESERVING, strict: true)) {
                return null;
            }

            $value = $value->var;
        }

        return null;
    }

    /**
     * The dispatch targets a `map` callable returns, or null when it does not provably return
     * instances. A callable that maps to a DTO answers null rather than "no jobs here": "the batch
     * holds nothing dispatchable" and "cannot tell" are different answers, and only the second one
     * may keep the site's doubt.
     *
     * @return list<string>|null
     */
    private static function jobsReturnedBy(Expr $callable): ?array
    {
        $returns = self::returnsOf($callable);

        if ($returns === null || $returns === []) {
            return null;
        }

        $jobs = [];

        foreach ($returns as $return) {
            if (! $return instanceof New_ || ! $return->class instanceof Name) {
                return null;
            }

            $jobs[] = AppFiles::resolveName($return->class);
        }

        $dispatchable = array_values(array_filter($jobs, DispatchTarget::matches(...)));

        return $dispatchable === [] ? null : $dispatchable;
    }

    /**
     * Every expression the callable returns, or null when it is not a literal callable — a variable,
     * a first-class callable, or a string name says nothing about the item type here.
     *
     * @return list<Expr|null>|null
     */
    private static function returnsOf(Expr $callable): ?array
    {
        if ($callable instanceof ArrowFunction) {
            return [$callable->expr];
        }

        if (! $callable instanceof Closure) {
            return null;
        }

        return array_values(array_map(
            static fn (Return_ $return): ?Expr => $return->expr,
            new NodeFinder()->findInstanceOf($callable->stmts, Return_::class),
        ));
    }
}
