<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\RelationIndex;

/**
 * Code that walks a relation — `$this->post->author`, `$post->comments` — draws nothing, because the
 * graph knows relations only as declarations. Rename `Comment::author` and every body that traverses
 * it reports no callers, while each call site sits in a file richter parsed.
 *
 * A hop needs no type inference: {@see RelationIndex} says which model a relation method returns, so
 * only the ROOT of a chain needs a declared type. Three roots carry one — `$this`, a typed property
 * of the enclosing class, and a typed parameter — and anything else ends the chain before it starts.
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
     * Chains whose hops still need the index, keyed to keep one copy of each: a method that walks the
     * same relation five times records it once, and every prefix of a long chain is recorded by the
     * nested fetch that is also its own expression. Without the key, a large application would carry
     * a pending entry per property access in its whole tree.
     *
     * @var array<string, array{source: string, class: string, names: list<string>, stopAfter: int|null}>
     */
    private array $pending = [];

    /**
     * Record the traversals in one file's class-likes. Fed per file by the consolidated AST loop in
     * {@see CodeGraphBuilder}; call once per file, then {@see edges()}.
     *
     * @param  list<ClassLike>  $classLikes  every ClassLike in the file, any depth
     */
    public function collect(array $classLikes): void
    {
        foreach ($classLikes as $node) {
            $fqcn = $node->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $properties = $this->propertyTypesOf($node);

            foreach ($node->getMethods() as $method) {
                $this->collectMethod($method, $fqcn, $properties);
            }
        }
    }

    /**
     * The edges every recorded chain resolves to. A hop the index cannot name ends its chain with no
     * edge; a to-many hop ends it with one.
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(RelationIndex $index): array
    {
        $edges = [];

        foreach ($this->pending as $chain) {
            $class = $chain['class'];

            foreach ($chain['names'] as $position => $name) {
                $relation = $index->relationOf($class, $name);

                if ($relation === null) {
                    break;
                }

                $edges[] = ['source' => $chain['source'], 'target' => "{$relation['owner']}::{$relation['method']}", 'type' => 'loads-relation'];

                // A collection, or a query builder from the method form: nothing left that the next
                // name could be a relation on.
                if ($relation['toMany'] || $position === $chain['stopAfter']) {
                    break;
                }

                $class = $relation['related'];
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * @param  array<string, string>  $properties  property name => declared class type
     */
    private function collectMethod(ClassMethod $method, string $classFqcn, array $properties): void
    {
        $source = $classFqcn . '::' . $method->name->toString();
        $parameters = $this->parameterTypesOf($method);

        // One descent for all four node kinds. Every fetch is taken, not only the outermost: each
        // prefix of a chain is a traversal in its own right. The nullsafe forms are the same
        // traversal — `$post?->author` reads the relation exactly as `$post->author` does.
        $accesses = new NodeFinder()->find(
            $method,
            static fn (Node $node): bool => $node instanceof PropertyFetch
                || $node instanceof NullsafePropertyFetch
                || $node instanceof MethodCall
                || $node instanceof NullsafeMethodCall,
        );

        foreach ($accesses as $access) {
            if ($access instanceof PropertyFetch || $access instanceof NullsafePropertyFetch || $access instanceof MethodCall || $access instanceof NullsafeMethodCall) {
                $this->record($this->chainOf($access, $classFqcn, $properties, $parameters), $source);
            }
        }
    }

    /** @param  array{class: string, names: list<string>, stopAfter: int|null}|null  $chain */
    private function record(?array $chain, string $source): void
    {
        if ($chain === null) {
            return;
        }

        $key = $source . '|' . $chain['class'] . '|' . implode('.', $chain['names']) . '|' . ($chain['stopAfter'] ?? '');
        $this->pending[$key] ??= ['source' => $source, ...$chain];
    }

    /**
     * One `$root->a->b` expression as a starting class plus the names to resolve against it, or null
     * when the root carries no type this can read.
     *
     * A first name that is a typed property rather than a relation is consumed here: `$this->post` in
     * a service is the property's type, and only what follows it can be a relation. That is why the
     * root shapes are exactly the ones with a declared type — this reads types, it never infers them.
     *
     * @param  array<string, string>  $properties
     * @param  array<string, string>  $parameters
     * @return array{class: string, names: list<string>, stopAfter: int|null}|null
     */
    private function chainOf(PropertyFetch|NullsafePropertyFetch|MethodCall|NullsafeMethodCall $expression, string $classFqcn, array $properties, array $parameters): ?array
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

        // No emptiness test on $names: the loop above unshifted at least one, since the expression
        // handed in is itself a fetch or a call.
        if (! $node instanceof Variable || ! is_string($node->name)) {
            return null;
        }

        if ($node->name !== 'this') {
            $type = $parameters[$node->name] ?? null;

            return $type === null ? null : ['class' => $type, 'names' => $names, 'stopAfter' => $stopAfter];
        }

        $property = $properties[$names[0]] ?? null;

        if ($property === null) {
            // `$this->comments` inside the model itself: the enclosing class is the receiver.
            return ['class' => $classFqcn, 'names' => $names, 'stopAfter' => $stopAfter];
        }

        // `$this->post` alone reads a property, not a relation: there is no hop left to resolve.
        return count($names) < 2
            ? null
            : ['class' => $property, 'names' => array_slice($names, 1), 'stopAfter' => $stopAfter === null ? null : $stopAfter - 1];
    }

    /**
     * Declared property types, including promoted constructor properties. A union type names more
     * than one class and is left out rather than guessed at; a nullable one is its inner type.
     *
     * @return array<string, string>
     */
    private function propertyTypesOf(ClassLike $node): array
    {
        $types = [];

        foreach ($node->getProperties() as $property) {
            $type = $this->classTypeOf($property->type);

            foreach ($property->props as $prop) {
                if ($type !== null) {
                    $types[$prop->name->toString()] = $type;
                }
            }
        }

        $constructor = $node->getMethod('__construct');

        foreach ($constructor instanceof ClassMethod ? $constructor->params : [] as $parameter) {
            $type = $this->classTypeOf($parameter->type);

            if ($type !== null && $parameter->flags !== 0 && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $type;
            }
        }

        return $types;
    }

    /** @return array<string, string> */
    private function parameterTypesOf(ClassMethod $method): array
    {
        $types = [];

        foreach ($method->params as $parameter) {
            $type = $this->classTypeOf($parameter->type);

            if ($type !== null && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $type;
            }
        }

        return $types;
    }

    /** The app class a declared type names, or null for a union, a builtin, or a vendor class. */
    private function classTypeOf(?Node $type): ?string
    {
        if ($type instanceof NullableType) {
            return $this->classTypeOf($type->type);
        }

        if (! $type instanceof Name) {
            return null;
        }

        $fqcn = AppFiles::resolveName($type);

        return AppNamespace::isInApp($fqcn) ? $fqcn : null;
    }
}
