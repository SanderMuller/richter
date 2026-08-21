<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;

/**
 * The dispatch targets a `Bus::batch($items->map(fn () => new SomeJob(…))->all())` argument provably
 * holds — written out at the dispatch site, or bound to a local above it.
 *
 * `map()` is total — one item out per item in — so whatever its callable returns IS the item type,
 * whatever the source collection held. That makes the batch's contents provable without knowing the
 * source, which is what separates this shape from every other opaque argument
 * {@see DispatchEdgeTracer} has to record as unfollowable. Building the jobs by mapping and handing
 * the array to `Bus::batch()` is how an application queues work in bulk, and reading it as
 * unfollowable taints `richter:affected-tests` for the whole project — while the graph already
 * carries the edge, drawn from the `new` inside the closure.
 *
 * Everything the proof depends on is checked, with one exception: the calls between the `map` and
 * the dispatch must preserve the item type, the callable must be written out at the call site, and
 * its single return must be a `new` of a dispatch target. Anything else answers null, and the caller
 * doubts the site exactly as before.
 *
 * The exception is the RECEIVER. This reads method names, not types, so an object of its own that
 * happens to spell `map()` and `all()` differently would be believed. Typing the receiver needs the
 * inference lane relation traversals use, and it is not wired here; until it is, a custom
 * collection-shaped class with its own `map()` semantics is the one shape that can hide a dispatch
 * this pass calls resolved.
 *
 * @internal
 */
final class MappedCollectionJobs
{
    /**
     * Collection calls that cannot change what the items ARE: they drop items, reorder them, or hand
     * the collection over as a plain array. A call outside this list (`pluck`, `flatten`, `concat`, a
     * macro) can, so the walk stops there rather than trusting the `map` above it.
     *
     * `toArray()` is NOT here: it converts an item that implements `Arrayable` into an array, so a
     * job written that way would reach the dispatch as an array and the proof would be false. `all()`
     * hands the items over untouched and is the shape this lane is about.
     *
     * @var list<string>
     */
    private const array ITEM_PRESERVING = ['all', 'values', 'filter', 'reject', 'unique', 'sort', 'sortBy', 'sortByDesc', 'reverse', 'take', 'skip', 'slice', 'shuffle', 'whereNotNull', 'collect'];

    /**
     * @param  array<string, array{pos: int, jobs: list<string>}>  $bound  the locals this method
     *   provably binds to a mapped collection, by name ({@see LocallyConstructedJobs}). A batch of
     *   any size names its collection, because the code between the map and the dispatch needs it,
     *   so a walk that stops at a local only ever fires on a chain written out at the dispatch site.
     * @return list<string>|null the dispatch targets, or null when this is not a provable mapped collection
     */
    public static function in(?Expr $value, array $bound = []): ?array
    {
        while ($value instanceof MethodCall && $value->name instanceof Identifier) {
            $method = $value->name->toString();

            // `map` only: it returns one item per input item, so the callable's return type IS the
            // item type. `flatMap` spreads an array return across the result, so the same reasoning
            // does not carry.
            if ($method === 'map') {
                $callable = $value->args[0] ?? null;

                return $callable instanceof Arg ? self::jobsReturnedBy($callable->value) : null;
            }

            if (! in_array($method, self::ITEM_PRESERVING, strict: true)) {
                return null;
            }

            $value = $value->var;
        }

        return self::boundJobs($value, $bound);
    }

    /**
     * The jobs a local provably holds, when the walk ended on one rather than on a call.
     *
     * The binding has to sit ABOVE the read: an assignment below it says nothing about the value
     * read here, and a parser that attached no positions (-1) proves no order at all. That the name
     * is written exactly once, at the top level, is {@see LocallyConstructedJobs}' guard, applied to
     * a collection of targets exactly as it is applied to a single one.
     *
     * @param  array<string, array{pos: int, jobs: list<string>}>  $bound
     * @return list<string>|null
     */
    private static function boundJobs(?Expr $value, array $bound): ?array
    {
        if (! $value instanceof Variable || ! is_string($value->name)) {
            return null;
        }

        $binding = $bound[$value->name] ?? null;
        $readAt = $value->getStartFilePos();

        return $binding !== null && $binding['pos'] >= 0 && $readAt > $binding['pos'] ? $binding['jobs'] : null;
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
