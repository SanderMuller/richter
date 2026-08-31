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
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

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
    /** @var array{scopes: list<array{from: int, to: int}>, entries: array<string, array{pos: int, jobs: list<string>}>} */
    public const array NONE = ['scopes' => [], 'entries' => []];

    /**
     * The jobs a dispatched expression provably accumulated, or null when this pass cannot say.
     *
     * Ordering matters the way it does for a single local: appends below the dispatch say nothing about
     * the array dispatched above them, so the read must come after the last append.
     *
     * An empty job list is a real answer, not a miss. A chain built only from inline closures accumulates
     * no named target and hides nothing, so it resolves to no jobs rather than to an unfollowable site.
     *
     * @param  array{scopes: list<array{from: int, to: int}>, entries: array<string, array{pos: int, jobs: list<string>}>}  $accumulated  from {@see in()}
     * @return list<string>|null
     */
    public static function dispatched(?Expr $value, array $accumulated): ?array
    {
        if (! $value instanceof Variable || ! is_string($value->name)) {
            return null;
        }

        $at = $value->getStartFilePos();
        $scope = self::innermostScopeAt($accumulated['scopes'] ?? [], $at);

        if ($scope === null) {
            return null;
        }

        // ONLY the dispatch's own immediate scope. No outward fallback: a closure with no `use ($c)`
        // cannot see the enclosing method's `$c` at all, so resolving against it would claim jobs this
        // dispatch does not send and drop a `not determinable` reason the run should have had.
        $local = ($accumulated['entries'] ?? [])[$scope . ':' . $value->name] ?? null;

        return $local !== null && $local['pos'] >= 0 && $at > $local['pos'] ? $local['jobs'] : null;
    }

    /**
     * The start offset of the narrowest scope containing this position, or null when none does.
     *
     * @param  list<array{from: int, to: int}>  $scopes
     */
    private static function innermostScopeAt(array $scopes, int $at): ?int
    {
        $best = null;
        $bestWidth = PHP_INT_MAX;

        foreach ($scopes as $scope) {
            if ($at < $scope['from'] || $at > $scope['to']) {
                continue;
            }

            $width = $scope['to'] - $scope['from'];

            if ($width < $bestWidth) {
                $bestWidth = $width;
                $best = $scope['from'];
            }
        }

        return $best;
    }

    /**
     * Accumulators this method provably fills, per SCOPE.
     *
     * `entries` is keyed `"{scope start offset}:{name}"`, because two scopes in one method may hold the
     * same name and they are different variables. `scopes` carries every scope's range so a dispatch can
     * be matched to the one that immediately encloses it — {@see dispatched()} resolves against that and
     * nothing wider.
     *
     * `pos` is the LAST append's position, so a caller can require the dispatch to read the name after
     * everything that fills it — the same ordering check the other two proofs use.
     *
     * @param  bool  $exposesLocals  whether the method hands its scope out by name (`compact()`, a dynamic read)
     * @return array{scopes: list<array{from: int, to: int}>, entries: array<string, array{pos: int, jobs: list<string>}>}
     */
    public static function in(ClassMethod $method, bool $exposesLocals): array
    {
        // A method that hands its locals out by name can have the array mutated without mentioning it,
        // and that reach is not scoped — so this stays a method-wide veto rather than a per-scope one.
        if ($exposesLocals) {
            return ['scopes' => [], 'entries' => []];
        }

        $scopes = [];
        $entries = [];

        foreach (self::scopesOf($method) as $scope) {
            $from = $scope->getStartFilePos();
            $scopes[] = ['from' => $from, 'to' => $scope->getEndFilePos()];

            $external = self::namesFromOutside($scope);

            foreach (self::startsIn($scope) as $name => $start) {
                // A name that arrives through `use (...)` or a parameter is not this scope's own local:
                // appends here do not describe the array the outer name holds, and a by-reference capture
                // is a mutation this proof cannot bound.
                //
                // No test can distinguish this check from the accounting below, and that is worth stating
                // rather than leaving someone to hunt for one: the `use` clause variable and the parameter
                // are THEMSELVES mentions inside this scope's subtree, so the count already fails. It is
                // kept because the two rules coincide by a property of the counting, not by logic — narrow
                // `occurrencesIn()` to skip a `use` clause on the reasonable-sounding grounds that it is
                // not a real read, and this check becomes the only thing left standing between an
                // outer array and a wrong "resolved".
                if (isset($external[$name])) {
                    continue;
                }

                $appends = self::provableAppends($scope, $name);

                if ($appends === null || $appends['count'] === 0) {
                    continue;
                }

                // The start, every append's own mention, and exactly one read left for the dispatch.
                // Counted over the WHOLE scope subtree, nested scopes included: a nested closure that
                // captures this name by reference can append to it, so those mentions have to keep
                // rejecting. For the method scope this is identical to the pre-scope count.
                if (self::occurrencesIn($scope, $name) !== 1 + $appends['count'] + 1) {
                    continue;
                }

                $entries[$from . ':' . $name] = [
                    'pos' => max($start['pos'], $appends['pos']),
                    'jobs' => array_values(array_unique([...$start['jobs'], ...$appends['jobs']])),
                ];
            }
        }

        return ['scopes' => $scopes, 'entries' => $entries];
    }

    /**
     * Every scope in this method: the body itself, then every function-like nested in it.
     *
     * `FunctionLike` rather than closures alone, and that breadth is the point. A nested named `function`
     * has its own locals just as a closure does, and an anonymous class's methods do too — miss any of
     * them and a dispatch inside one has no scope of its own, so {@see innermostScopeAt()} hands it the
     * enclosing METHOD and it resolves against an accumulator it cannot see. That is the same wrong
     * resolution rule 4 exists to refuse, arriving through a gap in the enumeration instead.
     *
     * @return list<FunctionLike>
     */
    private static function scopesOf(ClassMethod $method): array
    {
        $nested = new NodeFinder()->find(
            [$method],
            static fn (Node $node): bool => $node instanceof FunctionLike && $node !== $method,
        );

        /** @var list<FunctionLike> $scopes */
        $scopes = [$method, ...$nested];

        return $scopes;
    }

    /**
     * The names a scope receives from outside — parameters, and a closure's `use` clause.
     *
     * @param  FunctionLike  $scope
     * @return array<string, true>
     */
    private static function namesFromOutside(Node $scope): array
    {
        $names = [];

        foreach ($scope->getParams() as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[$param->var->name] = true;
            }
        }

        if ($scope instanceof Closure) {
            foreach ($scope->uses as $use) {
                if (is_string($use->var->name)) {
                    $names[$use->var->name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * Every mention of a name within a scope subtree, nested scopes included.
     *
     * @param  FunctionLike  $scope
     */
    private static function occurrencesIn(Node $scope, string $name): int
    {
        return count(new NodeFinder()->find(
            [$scope],
            static fn (Node $node): bool => $node instanceof Variable && $node->name === $name,
        ));
    }

    /**
     * Names started as an array literal at the method's top level — where, and what the literal already
     * holds.
     *
     * Top level of THAT SCOPE only, for the reason the sibling proofs give: `if (…) { $chain = []; }`
     * says nothing about what the name holds on the path that skipped it. An arrow function answers null
     * from `getStmts()` — a single expression can hold neither a start nor a dispatch — so it yields none.
     *
     * @param  FunctionLike  $scope
     * @return array<string, array{pos: int, jobs: list<string>}>
     */
    private static function startsIn(Node $scope): array
    {
        $starts = [];

        foreach ($scope->getStmts() ?? [] as $statement) {
            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                continue;
            }

            $assign = $statement->expr;

            if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
                continue;
            }

            if (! $assign->expr instanceof Array_) {
                continue;
            }

            $seeded = self::seedJobs($assign->expr);

            if ($seeded === null) {
                continue;
            }

            // A name started TWICE needs no guard here: the second start is one more mention of the name,
            // and the occurrence accounting in in() rejects any mention it cannot place. Adding a check
            // would be a second statement of the same rule.
            $starts[$assign->var->name] = ['pos' => $assign->getStartFilePos(), 'jobs' => $seeded];
        }

        return $starts;
    }

    /**
     * The jobs a start literal already holds, or null when any element is not provable.
     *
     * An empty literal answers `[]` — the ordinary accumulator. A non-empty one is read when every element
     * passes the same test an append passes, which composes two readings that already exist rather than
     * adding a third: the inline form `Bus::chain([new FirstJob(), fn () => null])` resolves this way too,
     * and the two disagreeing would be the defect.
     *
     * A KEY does not disqualify an element. `$chain[] =` appends at the maximum integer key plus one, so it
     * cannot collide with a seeded key, and the inline form accepts a keyed element as well.
     *
     * A SPREAD does disqualify: `[...$others]` brings contents in from elsewhere, so nothing here can
     * prove what they are.
     *
     * @return list<string>|null
     */
    private static function seedJobs(Array_ $literal): ?array
    {
        $jobs = [];

        foreach ($literal->items as $item) {
            // Unconditionally, whatever the value is. `[...$others]` brings in contents from elsewhere,
            // which the value test below would also reject — but `[...new SomeJob()]` is legal too, and
            // there the value IS a `new` of a dispatch target while the array holds something else
            // entirely: if the job is Traversable, PHP inserts what it yields, not the job. Accepting that
            // would resolve the dispatch to a target it never sends and drop a `not determinable` reason,
            // which is the under-selection direction.
            //
            // This guard was once deleted as unreachable, on the reasoning that a spread's value is always
            // a variable or an array. A mutation of it survived and appeared to confirm that — because the
            // test only covered `...$others`. The lesson is on the test, not the guard.
            if ($item->unpack) {
                return null;
            }

            [$provable, $job] = self::provableValue($item->value);

            if (! $provable) {
                return null;
            }

            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * Every append to this name in this scope, if all of them are provable — otherwise null.
     *
     * @param  FunctionLike  $scope
     * @return array{jobs: list<string>, count: int, pos: int}|null
     */
    private static function provableAppends(Node $scope, string $name): ?array
    {
        $jobs = [];
        $count = 0;
        $pos = -1;

        foreach (self::assignsOwnedBy($scope) as $assign) {
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

            [$provable, $job] = self::provableValue($assign->expr);

            if (! $provable) {
                return null;
            }

            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return ['jobs' => array_values(array_unique($jobs)), 'count' => $count, 'pos' => $pos];
    }

    /**
     * Every `Assign` in this scope's OWN body, not descending into a nested scope.
     *
     * The distinction decides correctness. A nested closure that does not capture the name is writing to a
     * DIFFERENT variable, so counting its append as one of ours would attribute a job to an array that
     * never holds it — a false edge, and the occurrence formula can balance exactly right for it. A nested
     * closure that DOES capture by reference is a real mutation, and it is refused elsewhere: its mentions
     * still land in {@see occurrencesIn()}, which counts the whole subtree, so the accounting fails.
     *
     * So appends are read narrowly and mentions are counted widely, and the two together are what make
     * both cases come out conservative.
     *
     * @param  FunctionLike  $scope
     * @return list<Assign>
     */
    private static function assignsOwnedBy(Node $scope): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Assign> */
            public array $found = [];

            private int $depth = 0;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike) {
                    // The scope itself is the root of this walk; anything deeper owns its own locals.
                    if ($this->depth++ > 0) {
                        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                    }

                    return null;
                }

                if ($node instanceof Assign) {
                    $this->found[] = $node;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse([$scope]);

        return $visitor->found;
    }

    /**
     * What a seed element or an append may hold, as `[provable, job]`.
     *
     * One rule, read by both, deliberately: a seed element and an append make the same claim about the
     * array's contents, and two copies of the test are two things that can drift apart. `provable` false
     * disqualifies the whole accumulator; a true with a null job is the closure case, which contributes
     * no target and hides nothing.
     *
     * @return array{0: bool, 1: string|null}
     */
    private static function provableValue(Expr $expr): array
    {
        // A closure IS the queued work and its body is already traced — the same exemption an inline
        // chain item gets.
        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            return [true, null];
        }

        $job = self::constructedTarget($expr);

        return $job === null ? [false, null] : [true, $job];
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
