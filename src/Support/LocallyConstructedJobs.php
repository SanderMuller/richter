<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\StaticVar;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * Which of a method's local variables provably hold a dispatch target when it dispatches them, and
 * which hold the result of one factory call (the tracer reads that call's declared return type).
 *
 * `$job = new SomeJob(...); dispatch($job);` is the shape this exists for. The graph already carries
 * the edge — the instantiation is right there in the same method — so recording the dispatch as
 * unfollowable taints every test selection over reach nothing is missing from. Answering "does this
 * variable provably hold a job?" is what lets {@see DispatchEdgeTracer} tell that apart from a
 * variable it genuinely cannot see into.
 *
 * A COLLECTION of targets is the same question in a different shape, and is answered beside it:
 * `$jobs = $items->map(fn () => new SomeJob(...)); Bus::batch($jobs->all());` proves its item type
 * through {@see MappedCollectionJobs}, and a batch of any size is written that way, because the code
 * between the map and the dispatch needs the collection named. Both kinds pass the same guards, and
 * they are returned apart: a local holding one job is not a batch argument, and a local holding a
 * collection is not a `dispatch()` argument.
 *
 * @internal
 */
final class LocallyConstructedJobs
{
    /** @var array{jobs: array<string, array{pos: int, jobs: list<string>}>, collections: array<string, array{pos: int, jobs: list<string>}>, arrays: array{scopes: list<array{from: int, to: int}>, entries: array<string, array{pos: int, jobs: list<string>}>}, calls: array<string, array{pos: int, call: MethodCall|StaticCall}>} */
    private const array NOTHING = ['jobs' => [], 'collections' => [], 'arrays' => ['scopes' => [], 'entries' => []], 'calls' => []];

    /**
     * Variables this method provably fills with a dispatch target, by name, the ones it fills
     * with a collection of them, and the ones it binds once to a factory call.
     *
     * The bar is deliberately high, because the payoff is dropping an unfollowable-dispatch signal and
     * the cost of being wrong is a selection that omits a real test. A name qualifies only when the
     * method writes it EXACTLY ONCE, that write is a top-level `= new SomeTarget(...)`, a top-level
     * factory call, or a top-level mapped collection, and nothing else in the method can rebind it — no
     * second assignment, no `foreach` binding, no by-reference `use`, no parameter of the same name.
     * The body of a top-level `try` counts as top level ({@see topLevelStatements()}). A conditional
     * assignment never qualifies: it sits below the top level.
     *
     * A COLLECTION carries one more guard, because the two proofs claim different things. That a
     * variable is never rebound proves what it IS, which is the whole claim for a single job: no call
     * changes an object's class, so `$job->onQueue('high')` between the `new` and the dispatch is
     * harmless. A collection's proof is about its CONTENTS, and `$jobs->push($somethingElse)` changes
     * those without writing the name at all. So a collection name must occur exactly twice in the
     * method — the binding and the one read that dispatches it. Any other mention, a mutator call, a
     * pass to a helper that could keep the handle, even a read this pass could have allowed, refuses
     * the binding rather than reasoning about which calls mutate.
     *
     * Both proofs read the method's variables as NODES, so a method that reaches its locals by name
     * instead is refused wholesale: `extract()`, `eval()`, an `include`, and a call through a name
     * this pass cannot read can all rebind any name without writing it,
     * so nothing in such a method is provable, and `compact()` / `get_defined_vars()` hand the
     * collection object to code this pass cannot follow, which is the mutation hole again through an
     * alias the occurrence count never sees. A single job survives that second pair: the alias can
     * call anything on the object, and no call changes its class.
     *
     * The one residual is a by-reference parameter of some callee reassigning it between the
     * construction and the dispatch, which needs the callee's signature to see. Every other unknown
     * shape lands on "not provable", which keeps the site.
     *
     * @return array{jobs: array<string, array{pos: int, jobs: list<string>}>, collections: array<string, array{pos: int, jobs: list<string>}>, arrays: array{scopes: list<array{from: int, to: int}>, entries: array<string, array{pos: int, jobs: list<string>}>}, calls: array<string, array{pos: int, call: MethodCall|StaticCall}>}
     */
    public static function in(ClassMethod $method): array
    {
        $assignments = self::topLevelAssignments($method);

        // Nothing built, called or STARTED EMPTY at the top level means nothing to prove. The write
        // count below walks the whole method, so asking the cheap questions first keeps that walk off
        // a method that could never qualify. The tracer only asks for a method that dispatches.
        $hasCandidate = self::hasCandidate($assignments);
        // An accumulator can live in ANY scope of the method, so the cheap top-level scan no longer
        // answers "could this method have one" — a single predicate-only pass does.
        $mayAccumulate = self::mayAccumulate($method);

        if (! $hasCandidate && ! $mayAccumulate) {
            return self::NOTHING;
        }

        $counts = self::variableCounts($method);

        // A dynamic write (`$$name = …`) names no variable this pass can read, so it could be a write
        // to any of them. Nothing in this method is provable once one appears.
        if ($counts === null) {
            return self::NOTHING;
        }

        ['writes' => $writes, 'occurrences' => $occurrences, 'exposesLocals' => $exposesLocals] = $counts;

        $arrays = $mayAccumulate ? AccumulatedArrayJobs::in($method, $exposesLocals) : AccumulatedArrayJobs::NONE;

        if (! $hasCandidate) {
            return ['jobs' => [], 'collections' => [], 'arrays' => $arrays, 'calls' => []];
        }

        $jobs = [];
        $collections = [];
        $calls = [];

        // Source order, so a chain split over two statements resolves: a binding may read one made
        // above it and never one made below. The write count is applied HERE rather than to the
        // finished map, so a name the method rebinds cannot feed a later binding before it is dropped.
        foreach ($assignments as ['name' => $name, 'pos' => $pos, 'expr' => $expr]) {
            if (($writes[$name] ?? 0) !== 1) {
                continue;
            }

            $job = DispatchedElement::constructedTarget($expr);

            if ($job !== null) {
                $jobs[$name] = ['pos' => $pos, 'jobs' => [$job]];

                continue;
            }

            // A factory call: the caller resolves its type ({@see FactoryReturnType}). One object, so
            // the write count is the whole guard, as for a `new`.
            if ($expr instanceof StaticCall || ($expr instanceof MethodCall && MappedCollectionJobs::in($expr, $collections) === null)) {
                $calls[$name] = ['pos' => $pos, 'call' => $expr];

                continue;
            }

            // The binding and the read that dispatches it, and nothing else — see the docblock: a
            // mutator call keeps the name's write count at one while changing what the batch holds,
            // and a name-keyed read of the scope hands out the same handle without a mention at all.
            if ($exposesLocals || ($occurrences[$name] ?? 0) !== 2) {
                continue;
            }

            $mapped = MappedCollectionJobs::in($expr, $collections);

            if ($mapped !== null) {
                $collections[$name] = ['pos' => $pos, 'jobs' => $mapped];
            }
        }

        // The accumulator is proved over OCCURRENCES rather than the write count above, because
        // `$chain[] = …` assigns to an ArrayDimFetch and that count never sees it. {@see AccumulatedArrayJobs}
        return ['jobs' => $jobs, 'collections' => $collections, 'arrays' => $arrays, 'calls' => $calls];
    }

    /**
     * The jobs a dispatched variable provably holds, or null when this pass cannot say.
     *
     * @param  array<string, array{pos: int, jobs: list<string>}>  $localJobs
     * @return list<string>|null
     */
    public static function jobsBoundTo(?Expr $value, array $localJobs): ?array
    {
        if (! $value instanceof Variable || ! is_string($value->name)) {
            return null;
        }

        $local = $localJobs[$value->name] ?? null;
        $dispatchedAt = $value->getStartFilePos();

        // Constructed BEFORE this argument. An assignment below the dispatch says nothing about the
        // value dispatched here, and a parser that attached no positions (-1) proves no order at all.
        return $local !== null && $local['pos'] >= 0 && $dispatchedAt > $local['pos'] ? $local['jobs'] : null;
    }

    /**
     * The `$x = …` assignments directly in the method's body, in source order.
     *
     * Top level only, and that is the whole point: an assignment inside a branch says nothing about
     * what the variable holds further down. Only the right-hand sides that can ever qualify are
     * carried, so the scan stays a statement walk rather than a reason to descend.
     *
     * @return list<array{name: string, pos: int, expr: New_|MethodCall|StaticCall}>
     */
    private static function topLevelAssignments(ClassMethod $method): array
    {
        $assignments = [];

        foreach (self::topLevelStatements($method->stmts ?? []) as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof Assign) {
                continue;
            }

            $assign = $stmt->expr;

            if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
                continue;
            }

            if (! $assign->expr instanceof New_ && ! $assign->expr instanceof MethodCall && ! $assign->expr instanceof StaticCall) {
                continue;
            }

            // The byte offset, not the line: the caller compares it against the dispatch argument's
            // own offset, and a whole method body written on one line must still order correctly.
            $assignments[] = ['name' => $assign->var->name, 'pos' => $assign->getStartFilePos(), 'expr' => $assign->expr];
        }

        return $assignments;
    }

    /**
     * The method's top-level statements, with the body of a top-level `try` read as top level too.
     *
     * A `try` body runs in order like the method body does. A binding in it either completes or throws
     * to a catch, and a name the method writes exactly once has no other value to hold after the
     * `try`: it holds that one value or it is unset, and an unset variable dispatches nothing. The
     * catch and finally blocks are not read: they run only on some paths.
     *
     * @param  array<Stmt>  $stmts
     * @return list<Stmt>
     */
    private static function topLevelStatements(array $stmts): array
    {
        $flat = [];

        foreach ($stmts as $stmt) {
            $flat = $stmt instanceof TryCatch ? [...$flat, ...array_values($stmt->stmts)] : [...$flat, $stmt];
        }

        return $flat;
    }

    /**
     * Whether any assignment could qualify at all, read without the whole-method walk.
     *
     * A `new` must name a dispatch target to count. Any call counts: whether a factory call returns a
     * job is the tracer's question, and a mapped chain split over two statements starts with a call.
     *
     * @param  list<array{name: string, pos: int, expr: New_|MethodCall|StaticCall}>  $assignments
     */
    private static function hasCandidate(array $assignments): bool
    {
        return array_any($assignments, static fn (array $assignment) => ! $assignment['expr'] instanceof New_ || DispatchedElement::constructedTarget($assignment['expr']) !== null);
    }

    /**
     * Whether any scope in this method assigns an array literal to a name — the cheap precondition for
     * an accumulator, asked before the per-scope walks the proof itself needs.
     */
    private static function mayAccumulate(ClassMethod $method): bool
    {
        return new NodeFinder()->findFirst(
            [$method],
            static fn (Node $node): bool => $node instanceof Assign
                && $node->var instanceof Variable
                && $node->expr instanceof Array_,
        ) instanceof Node;
    }

    /**
     * How many times each variable name is bound anywhere in the method, at any depth, and how many
     * times it is mentioned at all.
     *
     * Counting is deliberately blunt: a closure's own `$job = ...` is a different variable in a
     * different scope, but counting it disqualifies the outer name, and over-counting only ever costs
     * an exemption. Under-counting would cost a test.
     *
     * Null when the method contains a write this pass cannot attribute to a name — a dynamic write,
     * or a call that rebinds by name. `exposesLocals` reports the weaker case: the method hands its
     * whole scope to something, so an object it built can be reached without naming it. See
     * {@see in()}.
     *
     * @return array{writes: array<string, int>, occurrences: array<string, int>, exposesLocals: bool}|null
     */
    private static function variableCounts(ClassMethod $method): ?array
    {
        $counts = [];
        $occurrences = [];
        $exposesLocals = false;
        $dynamic = false;
        $count = static function (?Node $node) use (&$counts, &$dynamic): void {
            if (! $node instanceof Variable) {
                return;
            }

            if (is_string($node->name)) {
                $counts[$node->name] = ($counts[$node->name] ?? 0) + 1;

                return;
            }

            $dynamic = true;
        };

        foreach (new NodeFinder()->find([$method], static fn (Node $node): bool => true) as $node) {
            // Every mention, read or write: the walk visits each node once, so a name's occurrences
            // are counted here rather than in a second pass over the same tree.
            if ($node instanceof Variable) {
                if (is_string($node->name)) {
                    $occurrences[$node->name] = ($occurrences[$node->name] ?? 0) + 1;
                } else {
                    // A dynamic READ (`$$name->push(...)`) names no variable, so it adds no occurrence
                    // to the name it actually reaches — the same aliasing hole `compact()` opens. A
                    // dynamic WRITE is caught below and ends the method's provability outright.
                    $exposesLocals = true;
                }
            }

            // A name-keyed reach into the local scope. `extract()` and `eval()` write a name this
            // walk never sees, so they end the method's provability the way a dynamic write does;
            // `compact()` and `get_defined_vars()` only read, and a read is enough to alias an object.
            // The last name part is compared, so a namespaced import of one is caught too — matching
            // one of these too eagerly only ever costs an exemption.
            // An included file runs in THIS scope, so it writes names this method never mentions, and
            // a call through a variable can be any of the functions below under another name.
            if ($node instanceof Eval_ || $node instanceof Include_) {
                return null;
            }

            if ($node instanceof FuncCall) {
                if (! $node->name instanceof Name) {
                    return null;
                }

                // The RESOLVED name, not the written one: names are parsed with `replaceNodes` off,
                // so `use function extract as hydrate;` leaves `hydrate` in the node and carries
                // `extract` on the attribute {@see AppFiles::resolveName()} reads. Only the last part
                // is compared, so a namespaced function of the same name is caught as well — matching
                // one too eagerly costs an exemption, missing one costs a test.
                $parts = explode('\\', AppFiles::resolveName($node->name));
                $function = strtolower(end($parts));

                if ($function === 'extract') {
                    return null;
                }

                $exposesLocals = $exposesLocals || $function === 'compact' || $function === 'get_defined_vars';
            }

            match (true) {
                // Both sides of a reference bind. `$alias =& $job` makes a later `$alias = …` a write
                // to `$job` as well, and counting only the left side would leave `$job` looking like a
                // variable written once — the exemption dropping a dispatch whose target moved.
                $node instanceof AssignRef => array_map($count, [...self::assignedVariables($node->var), ...self::assignedVariables($node->expr)]),
                $node instanceof Assign, $node instanceof AssignOp => array_map($count, self::assignedVariables($node->var)),
                // Through `assignedVariables()`, like every other target: `foreach ($rows as [$job])`
                // binds through a list, and a branch that only recognised a plain variable let the
                // rebinding pass unseen — the dispatch below it then read as provably local.
                $node instanceof Foreach_ => array_map($count, [
                    ...($node->keyVar instanceof Expr ? self::assignedVariables($node->keyVar) : []),
                    ...self::assignedVariables($node->valueVar),
                ]),
                // Every parameter, not only the method's: a closure's `$job` parameter shadows the
                // outer `$job`, and a dispatch inside the closure sends whatever its caller passes.
                $node instanceof Param => array_map($count, [$node->var]),
                $node instanceof ClosureUse => array_map($count, [$node->var]),
                $node instanceof Static_ => array_map($count, array_map(static fn (StaticVar $var): Variable => $var->var, $node->vars)),
                $node instanceof Global_ => array_map($count, $node->vars),
                $node instanceof Catch_ => array_map($count, [$node->var]),
                $node instanceof Unset_ => array_map($count, $node->vars),
                default => null,
            };
        }

        return $dynamic ? null : ['writes' => $counts, 'occurrences' => $occurrences, 'exposesLocals' => $exposesLocals];
    }

    /**
     * The variables an assignment target binds — one for `$a = …`, several for `[$a, $b] = …`.
     *
     * @return list<Node|null>
     */
    private static function assignedVariables(Expr $target): array
    {
        if (! $target instanceof Array_ && ! $target instanceof List_) {
            return [$target];
        }

        $bound = [];

        foreach ($target->items as $item) {
            if ($item instanceof ArrayItem) {
                $bound = [...$bound, ...self::assignedVariables($item->value)];
            }
        }

        return $bound;
    }
}
