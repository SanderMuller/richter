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
     * The dispatch target a `map` callable returns, or null when it does not provably return one.
     *
     * EVERY return has to be a dispatch target. A callable that returns a job on one branch and a
     * DTO on another proves nothing about the batch — some items are not jobs — and "the batch holds
     * something else too" must keep the site's doubt rather than read as resolved.
     *
     * @return list<string>|null
     */
    private static function jobsReturnedBy(Expr $callable): ?array
    {
        $returned = self::returnedExpression($callable);

        if (! $returned instanceof New_ || ! $returned->class instanceof Name) {
            return null;
        }

        $job = AppFiles::resolveName($returned->class);

        return DispatchTarget::matches($job) ? [$job] : null;
    }

    /**
     * The one expression the callable always returns, or null when it cannot be proved to return one.
     *
     * An arrow function is total by construction: it has exactly one expression and no other path
     * out. A closure only qualifies when its whole body is a single `return` — anything else can
     * fall through to an implicit `null`, or return different things on different branches, and
     * neither proves what the mapped collection holds. Nested callables inside the body are
     * deliberately not read: a `return` inside one of them belongs to that callable, not this one.
     */
    private static function returnedExpression(Expr $callable): ?Expr
    {
        if ($callable instanceof ArrowFunction) {
            return $callable->expr;
        }

        if (! $callable instanceof Closure || count($callable->stmts) !== 1) {
            return null;
        }

        $only = $callable->stmts[0];

        return $only instanceof Return_ ? $only->expr : null;
    }
}
