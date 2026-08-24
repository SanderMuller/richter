<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;

/**
 * The dispatch targets a local provably accumulates before it is dispatched.
 *
 * `$chain = []; $chain[] = new FirstJob(...); $chain[] = new SecondJob(...); Bus::chain($chain)` is the
 * shape this exists for — the plain accumulator, beside the single local {@see LocallyConstructedJobs}
 * proves and the mapped collection {@see MappedCollectionJobs} proves. Every job is named right there in
 * the method, so the graph already carries the edges, and recording the dispatch as unfollowable taints
 * `richter:affected-tests` over reach nothing is missing from. One such site is enough to make every run
 * report `not determinable`, so an unread shape here costs the command everywhere, not only where it sits.
 *
 * ## Why the proof cannot lean on the write count
 *
 * `$chain[] = …` assigns to an `ArrayDimFetch`, not a `Variable`, so {@see LocallyConstructedJobs}'s
 * write counting does not see it at all — an accumulator's name reads as written exactly once. Leaning on
 * that count would therefore accept a method that also does `$chain[0] = $mystery`, `array_push($chain,
 * $mystery)`, or hands `$chain` to something by reference, all of which are equally invisible to it.
 *
 * So this proof is stated over OCCURRENCES instead, and it is deliberately absolute: every single mention
 * of the name in the method must be one of exactly three things — the `= []` that starts it, the left side
 * of an append whose value is provable, or the read that dispatches it. One mention that is none of those
 * and the name is not provable, whatever that mention does. That is what makes `array_push()`, a keyed
 * write, an alias, and a second read all fail closed without needing to be enumerated.
 *
 * ## What an append may hold
 *
 * A `new` of a dispatch target, or an inline closure. The closure exemption is not new leniency: an inline
 * `Bus::chain([new A, fn () => …])` already skips its closure items, because a closure IS the queued work
 * and its body is in the source the tracers read. An accumulator built the same way deserves the same
 * reading, and a chain of closures alone therefore resolves to no jobs and no unfollowable site — which is
 * correct, not a gap.
 *
 * A conditional append is allowed, and this is the one rule that differs from the single-local proof.
 * There, a conditional assignment is fatal because the claim is what the variable IS, and a branch not
 * taken leaves it holding something else. Here the claim is what the array CONTAINS, and an append inside
 * a branch either happens or does not — either way every element that can be there is a named target.
 *
 * @internal
 */
final class AccumulatedArrayJobs
{
    /**
     * The jobs a dispatched expression provably accumulated, or null when this pass cannot say.
     *
     * Ordering matters the way it does for a single local: appends below the dispatch say nothing about
     * the array dispatched above them, so the read must come after the last append.
     *
     * An empty job list is a real answer, not a miss. A chain built only from inline closures accumulates
     * no named target and hides nothing, so it resolves to no jobs rather than to an unfollowable site.
     *
     * @param  array<string, array{pos: int, jobs: list<string>}>  $accumulated  from {@see in()}
     * @return list<string>|null
     */
    public static function dispatched(?Expr $value, array $accumulated): ?array
    {
        if (! $value instanceof Variable || ! is_string($value->name)) {
            return null;
        }

        $local = $accumulated[$value->name] ?? null;

        return $local !== null && $local['pos'] >= 0 && $value->getStartFilePos() > $local['pos']
            ? $local['jobs']
            : null;
    }

    /**
     * Locals this method provably fills as an array of dispatch targets, keyed by name.
     *
     * `pos` is the LAST append's position, so a caller can require the dispatch to read the name after
     * everything that fills it — the same ordering check the other two proofs use.
     *
     * @param  array<string, int>  $starts  from {@see startsIn()}, so the cheap scan is not repeated
     * @param  array<string, int>  $occurrences  every mention of each name ({@see LocallyConstructedJobs})
     * @param  bool  $exposesLocals  whether the method hands its scope out by name (`compact()`, a dynamic read)
     * @return array<string, array{pos: int, jobs: list<string>}>
     */
    public static function in(ClassMethod $method, array $starts, array $occurrences, bool $exposesLocals): array
    {
        // A method that hands its locals out by name can have the array mutated without mentioning it.
        if ($exposesLocals) {
            return [];
        }

        $found = [];

        foreach ($starts as $name => $start) {
            $appends = self::provableAppends($method, $name);

            if ($appends === null || $appends['jobs'] === [] && $appends['count'] === 0) {
                continue;
            }

            // The init, every append's own mention, and exactly one read left over for the dispatch.
            // Fewer means nothing dispatches it; more means a mention this proof did not account for.
            if (($occurrences[$name] ?? 0) !== 1 + $appends['count'] + 1) {
                continue;
            }

            $found[$name] = ['pos' => max($start, $appends['pos']), 'jobs' => $appends['jobs']];
        }

        return $found;
    }

    /**
     * Names started as an empty array at the method's top level, and where.
     *
     * Public because it is also the CHEAP PRECONDITION for the proof: a method with no such start can
     * never have an accumulator, and answering that from the top-level statements alone keeps the
     * whole-method walk the proof needs off every method that could not qualify.
     *
     * Top level only, for the reason the sibling proofs give: `if (…) { $chain = []; }` says nothing
     * about what the name holds on the path that skipped it.
     *
     * @return array<string, int>
     */
    public static function startsIn(ClassMethod $method): array
    {
        $starts = [];

        foreach ($method->stmts ?? [] as $statement) {
            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                continue;
            }

            $assign = $statement->expr;

            if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
                continue;
            }

            if (! $assign->expr instanceof Array_ || $assign->expr->items !== []) {
                continue;
            }

            // A name started empty TWICE needs no guard here: the second `= []` is one more mention of
            // the name, and the occurrence accounting in in() rejects any mention it cannot place. Adding
            // a check would be a second statement of the same rule.
            $starts[$assign->var->name] = $assign->getStartFilePos();
        }

        return $starts;
    }

    /**
     * Every append to this name, if all of them are provable — otherwise null.
     *
     * @return array{jobs: list<string>, count: int, pos: int}|null
     */
    private static function provableAppends(ClassMethod $method, string $name): ?array
    {
        $jobs = [];
        $count = 0;
        $pos = -1;

        foreach (new NodeFinder()->find([$method], static fn (Node $node): bool => $node instanceof Assign) as $assign) {
            if (! $assign instanceof Assign || ! $assign->var instanceof ArrayDimFetch) {
                continue;
            }

            $target = $assign->var;

            if (! $target->var instanceof Variable || $target->var->name !== $name) {
                continue;
            }

            // `$chain[0] = …` replaces rather than appends, so the contents no longer follow from the
            // appends alone. Only the bare `$chain[] = …` form is provable.
            if ($target->dim instanceof Expr) {
                return null;
            }

            ++$count;
            $pos = max($pos, $assign->getStartFilePos());

            // A closure IS the queued work and its body is already traced — the same exemption an
            // inline chain item gets.
            if ($assign->expr instanceof Closure || $assign->expr instanceof ArrowFunction) {
                continue;
            }

            $job = self::constructedTarget($assign->expr);

            if ($job === null) {
                return null;
            }

            $jobs[] = $job;
        }

        return ['jobs' => array_values(array_unique($jobs)), 'count' => $count, 'pos' => $pos];
    }

    /** The dispatch target a `new SomeJob(...)` names, or null when it is not one. */
    private static function constructedTarget(Expr $expr): ?string
    {
        if (! $expr instanceof New_ || ! $expr->class instanceof Name) {
            return null;
        }

        $job = AppFiles::resolveName($expr->class);

        return DispatchTarget::matches($job) ? $job : null;
    }
}
