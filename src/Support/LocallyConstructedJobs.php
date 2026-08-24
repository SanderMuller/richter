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
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\StaticVar;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * Which of a method's local variables provably hold a dispatch target when it dispatches them.
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
    /** @var array{jobs: array<string, array{pos: int, jobs: list<string>}>, collections: array<string, array{pos: int, jobs: list<string>}>, arrays: array<string, array{pos: int, jobs: list<string>}>} */
    private const array NOTHING = ['jobs' => [], 'collections' => [], 'arrays' => []];

    /**
     * Variables this method provably fills with a dispatch target, by name, and the ones it fills
     * with a collection of them.
     *
     * The bar is deliberately high, because the payoff is dropping an unfollowable-dispatch signal and
     * the cost of being wrong is a selection that omits a real test. A name qualifies only when the
     * method writes it EXACTLY ONCE, that write is a top-level `= new SomeTarget(...)` or a top-level
     * mapped collection, and nothing else in the method can rebind it — no second assignment, no
     * `foreach` binding, no by-reference `use`, no parameter of the same name. A conditional
     * assignment therefore never qualifies: it sits below the top level, so the variable could still
     * hold whatever it held before.
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
     * @return array{jobs: array<string, array{pos: int, jobs: list<string>}>, collections: array<string, array{pos: int, jobs: list<string>}>, arrays: array<string, array{pos: int, jobs: list<string>}>}
     */
    public static function in(ClassMethod $method): array
    {
        $assignments = self::topLevelAssignments($method);

        // Nothing built, mapped or STARTED EMPTY at the top level means nothing to prove, and most
        // methods are that case. The write count below walks the whole method, so asking the cheap
        // questions first keeps that walk off every method that could never qualify — the graph build
        // runs this per method, and a whole extra AST pass over each one is not free.
        $hasCandidate = self::hasCandidate($assignments);
        $starts = AccumulatedArrayJobs::startsIn($method);

        if (! $hasCandidate && $starts === []) {
            return self::NOTHING;
        }

        $counts = self::variableCounts($method);

        // A dynamic write (`$$name = …`) names no variable this pass can read, so it could be a write
        // to any of them. Nothing in this method is provable once one appears.
        if ($counts === null) {
            return self::NOTHING;
        }

        ['writes' => $writes, 'occurrences' => $occurrences, 'exposesLocals' => $exposesLocals] = $counts;

        $arrays = $starts === [] ? [] : AccumulatedArrayJobs::in($method, $starts, $occurrences, $exposesLocals);

        if (! $hasCandidate) {
            return ['jobs' => [], 'collections' => [], 'arrays' => $arrays];
        }

        $jobs = [];
        $collections = [];

        // Source order, so a chain split over two statements resolves: a binding may read one made
        // above it and never one made below. The write count is applied HERE rather than to the
        // finished map, so a name the method rebinds cannot feed a later binding before it is dropped.
        foreach ($assignments as ['name' => $name, 'pos' => $pos, 'expr' => $expr]) {
            if (($writes[$name] ?? 0) !== 1) {
                continue;
            }

            $job = self::constructedTarget($expr);

            if ($job !== null) {
                $jobs[$name] = ['pos' => $pos, 'jobs' => [$job]];

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
        return ['jobs' => $jobs, 'collections' => $collections, 'arrays' => $arrays];
    }

    /**
     * The `$x = …` assignments directly in the method's body, in source order.
     *
     * Top level only, and that is the whole point: an assignment inside a branch says nothing about
     * what the variable holds further down. Only the two right-hand sides that can ever qualify are
     * carried, so the scan stays a statement walk rather than a reason to descend.
     *
     * @return list<array{name: string, pos: int, expr: New_|MethodCall}>
     */
    private static function topLevelAssignments(ClassMethod $method): array
    {
        $assignments = [];

        foreach ($method->stmts ?? [] as $stmt) {
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

            if (! $assign->expr instanceof New_ && ! $assign->expr instanceof MethodCall) {
                continue;
            }

            // The byte offset, not the line: the caller compares it against the dispatch argument's
            // own offset, and a whole method body written on one line must still order correctly.
            $assignments[] = ['name' => $assign->var->name, 'pos' => $assign->getStartFilePos(), 'expr' => $assign->expr];
        }

        return $assignments;
    }

    /**
     * Whether any assignment could qualify at all, read without the whole-method walk.
     *
     * A mapped chain is asked WITHOUT the bindings the build passes it, so this answers "does one
     * statement prove itself" — enough to decide whether the method is worth walking. A chain split
     * over two statements has such a statement above it by definition.
     *
     * @param  list<array{name: string, pos: int, expr: New_|MethodCall}>  $assignments
     */
    private static function hasCandidate(array $assignments): bool
    {
        return array_any($assignments, fn (array $assignment) => self::constructedTarget($assignment['expr']) !== null || MappedCollectionJobs::in($assignment['expr']) !== null);
    }

    /** The dispatch target a `new SomeTarget(...)` right-hand side names, or null when it names none. */
    private static function constructedTarget(Expr $expr): ?string
    {
        if (! $expr instanceof New_ || ! $expr->class instanceof Name) {
            return null;
        }

        $job = AppFiles::resolveName($expr->class);

        return DispatchTarget::matches($job) ? $job : null;
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

        foreach ($method->params as $param) {
            $count($param->var);
        }

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
