<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
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
 * ({@see self::MODEL_RETURNING}), a `$this->method()` whose declaration states a model return type,
 * a `@var` docblock, and a local bound to any of those. Anything else ends the chain before it starts.
 *
 * A chain also ends at a to-MANY hop, after drawing it. `$post->comments` is an Eloquent collection,
 * so a `->author` after it belongs to the collection, not to `Comment`; naming `Comment::author`
 * there would be an edge the expression never makes.
 *
 * Emits `loads-relation`, the same edge an eager-load call draws for the same claim: this member
 * depends on that relation.
 *
 * A model the chain LOADED is the one receiver this types for a call: `$post->author->notify()`, a
 * local bound to `$post->author`, and the value of `foreach ($post->comments as $comment)`. Nothing
 * else in the method names that class, so without an `instance-call` edge to `User::notify` the call
 * reaches it only through the class-level `relation-to-model` edge. A receiver the source types by
 * itself (a parameter, `new`, a static) is left to the receiver-typing lanes.
 *
 * Cross-file — the index completes only after every file is read — so it accumulates via
 * {@see collect()} across the consolidated AST pass in {@see CodeGraphBuilder} and emits once, via
 * {@see edges()}.
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

    /** A step that binds nothing: with an `assign`, it clears that name. */
    private const array CLEARED = ['assign' => null, 'rootClass' => null, 'rootVar' => null, 'names' => [], 'stopAfter' => null];

    /**
     * One entry per method that traverses anything, in file order. Each carries the types the method
     * states up front and the steps to walk once the index can answer.
     *
     * @var list<array{source: string, seeds: array<string, string>, steps: list<array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}>}>
     */
    private array $pending = [];

    private readonly DeclaredTypes $types;

    /** @var array<string, string> */
    private array $ownReturnTypes = [];

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
            $this->ownReturnTypes = $this->types->returnTypesOf($node);

            foreach ($node->getMethods() as $method) {
                $this->collectMethod($method, $fqcn, $properties);
            }
        }
    }

    /**
     * The edges every recorded method resolves to, once the index holds every file.
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(RelationIndex $index): array
    {
        return new RelationChainResolver($index)->edges($this->pending);
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

        $walk = new RelationScopeWalk($scope);

        new NodeTraverser($walk)->traverse([$scope]);

        foreach ($walk->closures as $closure) {
            $this->collectScope($closure, $source, $classFqcn, $properties);
        }

        $nodes = $walk->nodes;

        $steps = [];
        $handled = [];

        // A `foreach` value is the loaded model only inside the loop. After it, the name holds the last
        // element, or its old value when the collection was empty, so it is cleared where the loop ends.
        $clearAfter = [];

        foreach ($nodes as $node) {
            [$cleared, $clearAfter] = $this->clearedBefore($node->getStartFilePos(), $clearAfter);
            $steps = [...$steps, ...$cleared];

            if (isset($handled[spl_object_id($node)])) {
                continue;
            }

            $written = RelationScopeWalk::writtenNames($node);

            if ($written !== null) {
                foreach ($written as $name) {
                    $steps[] = [...self::CLEARED, 'assign' => $name];
                }

                continue;
            }

            if ($node instanceof Assign) {
                // A binding under an `if`, a loop, a `try` or a `match` arm may or may not happen, and
                // a second branch may bind the same name to another model. Source order cannot say
                // which one the runtime took, so a conditional binding clears the variable instead of
                // typing it.
                $this->collectAssignment($node, $classFqcn, $properties, $docblocks, $walk->isConditional($node), $steps, $handled);

                continue;
            }

            if ($node instanceof Foreach_) {
                $bound = $this->collectForeach($node, $classFqcn, $properties, $steps, $handled);

                if ($bound !== null) {
                    $clearAfter[] = [$node->getEndFilePos(), $bound];
                }

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
     * @param  list<array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}>  $steps
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
     * The names a `foreach` binds. Its value is the related model when the loop reads a to-many
     * relation property; every other bound name is cleared, since its old type no longer holds.
     *
     *
     * @param  array<string, string>  $properties
     * @param  list<array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}>  $steps
     * @param  array<int, true>  $handled
     * @return string|null the value name it typed, which the caller clears where the loop ends
     */
    private function collectForeach(Foreach_ $node, string $classFqcn, array $properties, array &$steps, array &$handled): ?string
    {
        $expression = $node->expr;
        $chain = null;

        if ($expression instanceof PropertyFetch || $expression instanceof NullsafePropertyFetch) {
            $chain = $this->chainOf($expression, $classFqcn, $properties);
            $handled[spl_object_id($expression)] = true;
        }

        $value = $node->valueVar instanceof Variable && is_string($node->valueVar->name) ? $node->valueVar->name : null;
        $bound = $chain !== null && $value !== null && $chain['stopAfter'] === null;

        // First: the loop expression is read before the loop binds anything, even a name it reuses.
        if ($chain !== null) {
            $steps[] = $bound ? ['assign' => $value, ...$chain, 'elements' => true] : ['assign' => null, ...$chain];
        }

        foreach (new NodeFinder()->findInstanceOf(array_filter([$node->keyVar, $node->valueVar]), Variable::class) as $variable) {
            if (is_string($variable->name) && (! $bound || $variable !== $node->valueVar)) {
                $steps[] = [...self::CLEARED, 'assign' => $variable->name];
            }
        }

        return $bound ? $value : null;
    }

    /**
     * The clearing steps for every `foreach` value whose loop ended before this position, and the
     * loops still open.
     *
     * @param  list<array{0: int, 1: string}>  $clearAfter  loop end position and the name it bound
     * @return array{0: list<array{assign: string, rootClass: null, rootVar: null, names: array{}, stopAfter: null}>, 1: list<array{0: int, 1: string}>}
     */
    private function clearedBefore(int $position, array $clearAfter): array
    {
        $steps = [];
        $open = [];

        foreach ($clearAfter as [$end, $name]) {
            if ($end < $position) {
                $steps[] = [...self::CLEARED, 'assign' => $name];
            } else {
                $open[] = [$end, $name];
            }
        }

        return [$steps, $open];
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
     * {@see rootedChainOf()}, plus the calls that follow the innermost call while every one of them is
     * a call: on a relation called as a method, those run on its query builder.
     *
     * @param  array<string, string>  $properties
     * @return array{rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}|null
     */
    private function chainOf(PropertyFetch|NullsafePropertyFetch|MethodCall|NullsafeMethodCall $expression, string $classFqcn, array $properties): ?array
    {
        $chain = $this->rootedChainOf($expression, $classFqcn, $properties);

        if ($chain === null) {
            return null;
        }

        $calls = RelationScopeWalk::callsAfterInnermost($expression);

        return $calls === [] ? $chain : [...$chain, 'builderCalls' => $calls];
    }

    /**
     * One `$root->a->b` expression as a root plus the names to resolve against it, or null when the
     * root carries no type this can read.
     *
     * A first name that is a typed property rather than a relation is consumed here: `$this->post` in
     * a service is the property's type, and only what follows it can be a relation.
     *
     * @param  array<string, string>  $properties
     * @return array{rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}|null
     */
    private function rootedChainOf(PropertyFetch|NullsafePropertyFetch|MethodCall|NullsafeMethodCall $expression, string $classFqcn, array $properties): ?array
    {
        $names = [];
        $calls = [];
        $node = $expression;

        while ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch || $node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            if (! $node->name instanceof Identifier) {
                return null;
            }

            // A relation called as a method returns a query builder, so the chain cannot continue
            // past it. Recorded by position, since the calls are collected from the outside in.
            if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
                $calls[] = count($names);
            }

            array_unshift($names, $node->name->toString());
            $node = $node->var;
        }

        // Positions were counted from the outermost name inward; flip to indexes into $names. The
        // innermost call is where the chain stops.
        $callIndexes = array_map(static fn (int $position): int => count($names) - 1 - $position, array_reverse($calls));
        $stopAfter = $callIndexes[0] ?? null;

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

        return $this->chainOnThis($names, $callIndexes, $classFqcn, $properties);
    }

    /**
     * A chain whose root is `$this`: an own method's return type, a typed property, or the enclosing
     * class itself.
     *
     * @param  non-empty-list<string>  $names
     * @param  list<int>  $callIndexes  the indexes into $names that are calls, innermost first
     * @param  array<string, string>  $properties
     * @return array{rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}|null
     */
    private function chainOnThis(array $names, array $callIndexes, string $classFqcn, array $properties): ?array
    {
        $stopAfter = $callIndexes[0] ?? null;

        // `$this->post()->author`: the own method's declared return type is the root.
        $returned = $stopAfter === 0 ? ($this->ownReturnTypes[strtolower($names[0])] ?? null) : null;

        if ($returned !== null) {
            $next = $callIndexes[1] ?? null;

            return count($names) < 2
                ? null
                : ['rootClass' => $returned, 'rootVar' => null, 'names' => array_slice($names, 1), 'stopAfter' => $next === null ? null : $next - 1, 'ownCall' => ['class' => $classFqcn, 'method' => $names[0]]];
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
