<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\DeclaredTypes;
use SanderMuller\Richter\Support\RelationIndex;

/**
 * Code that walks a relation — `$this->post->author`, `$post->comments` — draws nothing on its own,
 * because the graph knows relations only as declarations. Rename `Comment::author` and every body
 * that traverses it reports no callers, while each call site sits in a file richter parsed.
 *
 * A hop needs no type inference: {@see RelationIndex} says which model a relation method returns, so
 * only the ROOT of a chain needs a type. The roots this reads are the ones the source states —
 * `$this`, a typed property, a typed parameter, `new Post`, a model-returning static
 * ({@see self::MODEL_RETURNING}), a `@var` docblock, and a local bound to any of those. Anything
 * else ends the chain before it starts.
 *
 * A chain also ends at a to-MANY hop, after drawing it. `$post->comments` is an Eloquent collection,
 * so a `->author` after it belongs to the collection, not to `Comment`; naming `Comment::author`
 * there would be an edge the expression never makes.
 *
 * Emits `loads-relation`, the same edge an eager-load call draws for the same claim: this member
 * depends on that relation. Cross-file — the index completes only after every file is read — so it
 * accumulates via {@see collect()} across the consolidated AST pass in {@see CodeGraphBuilder} and
 * emits once, via {@see edges()}.
 *
 * @internal
 */
final class RelationTraversalTracer
{
    /**
     * Static calls on a model class that hand back ONE model, so a chain can continue through them.
     *
     * `query()`, `where()` and the rest of the builder surface are deliberately absent: they return a
     * builder, and `all()`/`get()` return a collection. A chain resumes after those only when the
     * source calls something from this list on the result, which is a `MethodCall`, not a static.
     *
     * @var list<string>
     */
    private const array MODEL_RETURNING = [
        'first', 'firstOrFail', 'firstOrNew', 'firstOrCreate', 'firstWhere', 'findSole',
        'create', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'updateOrCreate', 'make', 'sole',
    ];

    /**
     * Statics that return one model for a scalar id and a COLLECTION for an array or `Arrayable` one
     * (`Builder::find()` hands an array straight to `findMany()`). A chain past `find([1, 2])` reads
     * the collection, so these resolve only where the argument is a scalar literal — the one shape
     * that cannot turn out to be an array at runtime.
     *
     * @var list<string>
     */
    private const array FIND_BY_ID = ['find', 'findOrFail', 'findOrNew'];

    /**
     * One entry per method that traverses anything, in file order. Each carries the types the method
     * states up front and the steps to walk once the index can answer.
     *
     * @var list<array{source: string, seeds: array<string, string>, steps: list<array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null}>}>
     */
    private array $pending = [];

    private readonly DeclaredTypes $types;

    public function __construct()
    {
        $this->types = new DeclaredTypes();
    }

    /**
     * Record the traversals in one file's class-likes. Fed per file by the consolidated AST loop in
     * {@see CodeGraphBuilder}; call once per file, then {@see edges()}.
     *
     * @param  list<ClassLike>  $classLikes  every ClassLike in the file, any depth
     * @param  list<Use_>  $uses  the file's imports, so a `@var` docblock resolves the way the code does
     */
    public function collect(array $classLikes, array $uses = []): void
    {
        $this->types->readImports($uses);

        foreach ($classLikes as $node) {
            $fqcn = $node->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $properties = $this->types->propertyTypesOf($node);

            foreach ($node->getMethods() as $method) {
                $this->collectMethod($method, $fqcn, $properties);
            }
        }
    }

    /**
     * The edges every recorded method resolves to.
     *
     * Steps run in source order, because a local carries a type only after the line that bound it: an
     * assignment resolves its own chain, then binds the variable to whatever that chain ended on. A
     * hop the index cannot name ends its chain with no edge, and clears the variable it was going to
     * bind — a reassignment richter cannot follow must not leave the old type standing.
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(RelationIndex $index): array
    {
        $edges = [];

        foreach ($this->pending as $method) {
            $types = $method['seeds'];

            foreach ($method['steps'] as $step) {
                $class = $step['rootClass'] ?? ($step['rootVar'] === null ? null : ($types[$step['rootVar']] ?? null));

                if ($class !== null) {
                    [$class, $stepEdges] = $this->walk($index, $class, $step, $method['source']);
                    $edges = [...$edges, ...$stepEdges];
                }

                if ($step['assign'] !== null) {
                    // An unresolved right-hand side unbinds the variable rather than leaving a stale
                    // type on it.
                    $class === null ? $types[$step['assign']] = '' : $types[$step['assign']] = $class;

                    if ($types[$step['assign']] === '') {
                        unset($types[$step['assign']]);
                    }
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * One chain walked from a known class: the edges it draws, and the class it ends on (null when a
     * hop did not resolve, or when the chain ended on a collection or a builder).
     *
     * @param  array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null}  $step
     * @return array{0: string|null, 1: list<array{source: string, target: string, type: string}>}
     */
    private function walk(RelationIndex $index, string $class, array $step, string $source): array
    {
        $edges = [];

        foreach ($step['names'] as $position => $name) {
            $relation = $index->relationOf($class, $name);

            if ($relation === null) {
                return [null, $edges];
            }

            $edges[] = ['source' => $source, 'target' => "{$relation['owner']}::{$relation['method']}", 'type' => 'loads-relation'];

            // A collection, or a query builder from the method form: nothing left that the next name
            // could be a relation on, and nothing a variable could be bound to either.
            if ($relation['toMany'] || $position === $step['stopAfter']) {
                return [null, $edges];
            }

            $class = $relation['related'];
        }

        return [$class, $edges];
    }

    /**
     * @param  array<string, string>  $properties  property name => declared class type
     */
    private function collectMethod(ClassMethod $method, string $classFqcn, array $properties): void
    {
        $this->collectScope($method, $classFqcn . '::' . $method->name->toString(), $classFqcn, $properties);
    }

    /**
     * The steps of one function body. A closure is its own scope, collected separately with only its
     * own parameters seeded: `function () { $post = Order::find(1); }` beside an outer `$post` must
     * not retype the outer one, and a variable the closure imports by `use` has a type this cannot
     * see. Both directions therefore stop at the boundary rather than guess across it.
     *
     * @param  array<string, string>  $properties  property name => declared class type
     */
    private function collectScope(FunctionLike $scope, string $source, string $classFqcn, array $properties): void
    {
        // Docblocks are per assignment, not per scope: `@var` speaks for the statement it sits on.
        $docblocks = $this->types->docblockTypesIn($scope);
        $seeds = $this->types->parameterTypesOf($scope);

        $finder = new NodeFinder();
        // `$scope !== $node` because a closure's own body search finds the closure itself, and
        // recursing into that is a stack overflow rather than a scope.
        /** @var list<Closure|ArrowFunction> $closures */
        $closures = $finder->find($scope, static fn (Node $node): bool => $node !== $scope
            && ($node instanceof Closure || $node instanceof ArrowFunction));
        $outermost = array_values(array_filter(
            $closures,
            static fn (Node $closure): bool => ! array_any(
                $closures,
                static fn (Node $other): bool => $other !== $closure
                    && $other->getStartFilePos() < $closure->getStartFilePos()
                    && $other->getEndFilePos() > $closure->getEndFilePos(),
            ),
        ));

        foreach ($outermost as $closure) {
            $this->collectScope($closure, $source, $classFqcn, $properties);
        }

        // One descent for every node kind that can start or continue a chain, then source order:
        // a local's type comes from the line above it, so the steps have to run as written.
        $nodes = new NodeFinder()->find(
            $scope,
            static fn (Node $node): bool => $node instanceof Assign
                || $node instanceof PropertyFetch
                || $node instanceof NullsafePropertyFetch
                || $node instanceof MethodCall
                || $node instanceof NullsafeMethodCall,
        );

        // A node inside a nested closure belongs to that closure's own scope, not to this one.
        $nodes = array_values(array_filter($nodes, static fn (Node $node): bool => ! array_any(
            $outermost,
            static fn (Node $closure): bool => $node !== $closure
                && $node->getStartFilePos() >= $closure->getStartFilePos()
                && $node->getEndFilePos() <= $closure->getEndFilePos(),
        )));

        usort($nodes, static fn (Node $a, Node $b): int => $a->getStartFilePos() <=> $b->getStartFilePos());

        $steps = [];
        $handled = [];
        $branching = $this->branchRanges($scope);

        foreach ($nodes as $node) {
            if (isset($handled[spl_object_id($node)])) {
                continue;
            }

            if ($node instanceof Assign) {
                // A binding under an `if`, a loop, a `try` or a `match` arm may or may not happen, and
                // a second branch may bind the same name to another model. Source order cannot say
                // which one the runtime took, so a conditional binding clears the variable instead of
                // typing it.
                $conditional = array_any(
                    $branching,
                    static fn (array $range): bool => $node->getStartFilePos() >= $range[0] && $node->getEndFilePos() <= $range[1],
                );

                $this->collectAssignment($node, $classFqcn, $properties, $docblocks, $conditional, $steps, $handled);

                continue;
            }

            if (! $node instanceof PropertyFetch && ! $node instanceof NullsafePropertyFetch && ! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall) {
                continue;
            }

            $chain = $this->chainOf($node, $classFqcn, $properties);

            if ($chain !== null) {
                $steps[] = ['assign' => null, ...$chain];
            }
        }

        if ($steps !== []) {
            $this->pending[] = ['source' => $source, 'seeds' => $seeds, 'steps' => $steps];
        }
    }

    /**
     * The step (if any) one `$x = …` contributes, plus the direct binds that need no index at all:
     * `new Post`, and `Post::find(…)` and friends.
     *
     * @param  array<string, string>  $properties
     * @param  array<int, array<string, string>>  $docblocks  `@var` types, keyed by the assignment they annotate
     * @param  list<array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null}>  $steps
     * @param  array<int, true>  $handled
     */
    private function collectAssignment(Assign $node, string $classFqcn, array $properties, array $docblocks, bool $conditional, array &$steps, array &$handled): void
    {
        if (! $node->var instanceof Variable || ! is_string($node->var->name)) {
            return;
        }

        $target = $node->var->name;

        // An assignment inside another assignment (`$a = helper($a = new Post())`) runs inside out,
        // and the outer call decides what the name ends up holding. Neither binding is knowable, so
        // the outer one clears the name and the inner one is not read at all.
        $nested = new NodeFinder()->findFirstInstanceOf($node->expr, Assign::class);

        if ($nested instanceof Assign) {
            $handled[spl_object_id($nested)] = true;
            $steps[] = ['assign' => $target, 'rootClass' => null, 'rootVar' => null, 'names' => [], 'stopAfter' => null];

            return;
        }

        if ($conditional) {
            $steps[] = ['assign' => $target, 'rootClass' => $docblocks[spl_object_id($node)][$target] ?? null, 'rootVar' => null, 'names' => [], 'stopAfter' => null];

            return;
        }

        $direct = $this->directTypeOf($node->expr);

        if ($direct !== null) {
            $steps[] = ['assign' => $target, 'rootClass' => $direct, 'rootVar' => null, 'names' => [], 'stopAfter' => null];

            return;
        }

        $expression = $node->expr;

        if ($expression instanceof PropertyFetch || $expression instanceof NullsafePropertyFetch || $expression instanceof MethodCall || $expression instanceof NullsafeMethodCall) {
            $chain = $this->chainOf($expression, $classFqcn, $properties);
            // The right-hand side is walked here, so the same node must not be walked again when the
            // ordered pass reaches it.
            $handled[spl_object_id($expression)] = true;

            $steps[] = $chain === null
                ? ['assign' => $target, 'rootClass' => null, 'rootVar' => null, 'names' => [], 'stopAfter' => null]
                : ['assign' => $target, ...$chain];

            return;
        }

        // Assigned from something this cannot type (a helper call, a match, a literal): the variable
        // stops carrying whatever it carried before, unless the statement's own `@var` docblock states
        // what the author put there — which is the case that shape exists for.
        $declared = $docblocks[spl_object_id($node)][$target] ?? null;

        $steps[] = ['assign' => $target, 'rootClass' => $declared, 'rootVar' => null, 'names' => [], 'stopAfter' => null];
    }

    /**
     * The source ranges of every branching or repeating construct in one scope. An assignment inside
     * one of them is conditional: it may not run, and a sibling branch may bind the same name to a
     * different model.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function branchRanges(FunctionLike $scope): array
    {
        $branching = new NodeFinder()->find($scope, static fn (Node $node): bool => $node instanceof If_
            || $node instanceof Else_
            || $node instanceof ElseIf_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Switch_
            || $node instanceof Match_
            || $node instanceof TryCatch
            || $node instanceof Ternary);

        return array_values(array_map(static fn (Node $node): array => [$node->getStartFilePos(), $node->getEndFilePos()], $branching));
    }

    /**
     * The app model an expression names outright: `new Post(...)`, or `Post::find(...)` and the other
     * single-model statics. A `Post::query()` names a builder, so it names nothing here.
     */
    private function directTypeOf(Expr $expression): ?string
    {
        if ($expression instanceof New_ && $expression->class instanceof Name) {
            return $this->types->appClass(AppFiles::resolveName($expression->class));
        }

        if (! $expression instanceof StaticCall || ! $expression->class instanceof Name || ! $expression->name instanceof Identifier) {
            return null;
        }

        $method = $expression->name->toString();

        if (in_array($method, self::MODEL_RETURNING, true)) {
            return $this->types->appClass(AppFiles::resolveName($expression->class));
        }

        return in_array($method, self::FIND_BY_ID, true) && $this->hasScalarId($expression)
            ? $this->types->appClass(AppFiles::resolveName($expression->class))
            : null;
    }

    /** Whether a `find()`-family call names an id that cannot be an array, so it returns one model. */
    private function hasScalarId(StaticCall $call): bool
    {
        if ($call->isFirstClassCallable()) {
            return false;
        }

        $argument = $call->getArgs()[0]->value ?? null;

        return $argument instanceof Int_ || $argument instanceof String_;
    }

    /**
     * One `$root->a->b` expression as a root plus the names to resolve against it, or null when the
     * root carries no type this can read.
     *
     * A first name that is a typed property rather than a relation is consumed here: `$this->post` in
     * a service is the property's type, and only what follows it can be a relation.
     *
     * @param  array<string, string>  $properties
     * @return array{rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null}|null
     */
    private function chainOf(PropertyFetch|NullsafePropertyFetch|MethodCall|NullsafeMethodCall $expression, string $classFqcn, array $properties): ?array
    {
        $names = [];
        $callAt = null;
        $node = $expression;

        while ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch || $node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            if (! $node->name instanceof Identifier) {
                return null;
            }

            // A relation called as a method returns a query builder, so the chain cannot continue
            // past it. Recorded by position, since the calls are collected from the outside in.
            if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
                $callAt = count($names);
            }

            array_unshift($names, $node->name->toString());
            $node = $node->var;
        }

        // Positions were counted from the outermost name inward; flip to an index into $names.
        $stopAfter = $callAt === null ? null : count($names) - 1 - $callAt;

        // A static root — `Post::find($id)->comments` — states its class in the source.
        if ($node instanceof StaticCall) {
            $direct = $this->directTypeOf($node);

            return $direct === null ? null : ['rootClass' => $direct, 'rootVar' => null, 'names' => $names, 'stopAfter' => $stopAfter];
        }

        if ($node instanceof New_) {
            $direct = $this->directTypeOf($node);

            return $direct === null ? null : ['rootClass' => $direct, 'rootVar' => null, 'names' => $names, 'stopAfter' => $stopAfter];
        }

        // No emptiness test on $names: the loop above unshifted at least one, since the expression
        // handed in is itself a fetch or a call.
        if (! $node instanceof Variable || ! is_string($node->name)) {
            return null;
        }

        if ($node->name !== 'this') {
            // A local or parameter: the type is whatever the method bound to it by this point.
            return ['rootClass' => null, 'rootVar' => $node->name, 'names' => $names, 'stopAfter' => $stopAfter];
        }

        $property = $properties[$names[0]] ?? null;

        if ($property === null) {
            // `$this->comments` inside the model itself: the enclosing class is the receiver.
            return ['rootClass' => $classFqcn, 'rootVar' => null, 'names' => $names, 'stopAfter' => $stopAfter];
        }

        // `$this->post` alone reads a property, not a relation: there is no hop left to resolve.
        return count($names) < 2
            ? null
            : ['rootClass' => $property, 'rootVar' => null, 'names' => array_slice($names, 1), 'stopAfter' => $stopAfter === null ? null : $stopAfter - 1];
    }
}
