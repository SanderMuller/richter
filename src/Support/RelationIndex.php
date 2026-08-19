<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;
use SanderMuller\Richter\Tracers\ConstantReferenceTracer;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

/**
 * Which model a relation method returns — the map Laravel Brain almost builds and then discards.
 *
 * Brain reads the same `$this->hasMany(Comment::class)` call ({@see ModelAnalyzer::RELATIONSHIP_METHODS})
 * but keeps only `['type' => …, 'related' => …]`, dropping the method that declared it, so its graph
 * can say "Post relates to Comment" and never "`comments` returns a Comment". Without the method
 * name, a traversal or a dotted eager-load path has nothing to resolve its next hop against.
 *
 * The target is read from the CALL ARGUMENT, not from a return type, which is what keeps this an
 * index rather than type inference: every hop after the first is a lookup here, and only the root of
 * a chain needs a declared type.
 *
 * Cross-file by nature — the model, the trait it uses and the code that traverses it are different
 * files — so it accumulates via {@see collect()} across the consolidated AST pass in
 * {@see CodeGraphBuilder} and answers only after every file has been collected.
 *
 * @internal
 */
final class RelationIndex
{
    /**
     * The relationship kinds whose value is a COLLECTION rather than a model. A chain through one of
     * them ends there: `$post->comments` is an Eloquent collection, so a `->author` after it is a
     * collection member, not a relation on `Comment`.
     *
     * @var list<string>
     */
    private const array TO_MANY = ['hasMany', 'hasManyThrough', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany'];

    /**
     * @var array<string, array{parent: string|null, traits: list<string>, relations: array<string, array{related: string, toMany: bool}>}>
     *   keyed by FQCN
     */
    private array $records = [];

    /** Trait relations are copied in once, on first lookup — every file must be collected first. */
    private bool $traitsMerged = false;

    /**
     * Record every class-like in one file. Fed per file by the consolidated AST loop in
     * {@see CodeGraphBuilder}; call once per file, then query.
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

            $this->records[$fqcn] = [
                'parent' => $node instanceof Class_ && $node->extends instanceof Name ? AppFiles::resolveName($node->extends) : null,
                'traits' => $this->traitsUsedBy($node),
                'relations' => $this->relationsIn($node),
            ];

            $this->traitsMerged = false;
        }
    }

    /**
     * The relation `$class::$method` resolves to, or null when neither the class, its ancestors, nor
     * its traits declare one.
     *
     * `owner` is the class an edge should name: the declaring ancestor for an inherited relation, and
     * the USING class for one a trait supplies. That split is not a preference — this package treats
     * a trait method as copied into the using class rather than dispatched (the rule
     * {@see ClassHierarchyTracer} and
     * {@see ConstantReferenceTracer} both follow), so a
     * `SomeTrait::comments` node would name code no call ever reaches.
     *
     * @return array{owner: string, method: string, related: string, toMany: bool}|null
     */
    public function relationOf(string $class, string $method): ?array
    {
        $this->mergeTraits();

        $fqcn = ltrim($class, '\\');
        $seen = [];

        while (isset($this->records[$fqcn]) && ! isset($seen[$fqcn])) {
            $seen[$fqcn] = true;
            $relation = $this->records[$fqcn]['relations'][$method] ?? null;

            if ($relation !== null) {
                return ['owner' => $fqcn, 'method' => $method, ...$relation];
            }

            $fqcn = $this->records[$fqcn]['parent'] ?? '';
        }

        return null;
    }

    /**
     * Whether this class has any relation at all here — the test for "the index can speak for this
     * model". A class it knows nothing about (never scanned, or every relation pointing at a vendor
     * model) must not have a missing relation reported against it: absence there is ignorance, not
     * evidence.
     */
    public function declaresAnyRelation(string $class): bool
    {
        $this->mergeTraits();

        $fqcn = ltrim($class, '\\');
        $seen = [];

        while (isset($this->records[$fqcn]) && ! isset($seen[$fqcn])) {
            $seen[$fqcn] = true;

            if ($this->records[$fqcn]['relations'] !== []) {
                return true;
            }

            $fqcn = $this->records[$fqcn]['parent'] ?? '';
        }

        return false;
    }

    /**
     * Whether any indexed class declares a relation by this NAME. The evidence that a segment is a
     * relation name at all, wherever it lives — which is what separates "pointed at the wrong model"
     * from "a method this index simply cannot read as a relation".
     */
    public function isRelationName(string $method): bool
    {
        $this->mergeTraits();

        return array_any($this->records, static fn (array $record): bool => isset($record['relations'][$method]));
    }

    /**
     * Copy each trait's relations into the classes that use it, for traits that were themselves
     * collected. Runs once per collection round rather than per lookup: a chain resolves hop by hop,
     * so a per-lookup merge would repeat the whole walk on every hop.
     */
    private function mergeTraits(): void
    {
        if ($this->traitsMerged) {
            return;
        }

        $this->traitsMerged = true;

        foreach ($this->records as $fqcn => $record) {
            foreach ($this->traitRelationsOf($record['traits'], []) as $method => $relation) {
                // A relation the class declares itself wins: the trait's copy is what the class
                // overrode, and the override is the code that runs.
                $this->records[$fqcn]['relations'][$method] ??= $relation;
            }
        }
    }

    /**
     * The relations a list of traits supplies, following traits that use traits. `$seen` stops a
     * cyclic `use` from recursing forever — invalid PHP, but a parse of it must not hang a build.
     *
     * @param  list<string>  $traits
     * @param  array<string, true>  $seen
     * @return array<string, array{related: string, toMany: bool}>
     */
    private function traitRelationsOf(array $traits, array $seen): array
    {
        $relations = [];

        foreach ($traits as $trait) {
            if (isset($seen[$trait]) || ! isset($this->records[$trait])) {
                continue;
            }

            $seen[$trait] = true;
            $relations = [
                ...$this->traitRelationsOf($this->records[$trait]['traits'], $seen),
                ...$this->records[$trait]['relations'],
                ...$relations,
            ];
        }

        return $relations;
    }

    /**
     * The relation methods one class-like declares: a method whose body calls
     * `$this-><relationshipMethod>(Related::class)`.
     *
     * A method naming two different targets declares nothing here. The runtime picks between them
     * from state this cannot see, and naming one would send a reader to a model the code may never
     * return — the same abort {@see ReferenceEdgeTracer} and the contract parsers make.
     *
     * @return array<string, array{related: string, toMany: bool}>
     */
    private function relationsIn(ClassLike $node): array
    {
        $relations = [];
        $finder = new NodeFinder();

        foreach ($node->getMethods() as $method) {
            $targets = [];

            foreach ($finder->findInstanceOf($method, MethodCall::class) as $call) {
                $related = $this->relatedIn($call);

                if ($related !== null) {
                    $targets[$related['related']] = $related;
                }
            }

            if (count($targets) === 1) {
                $relations[$method->name->toString()] = array_values($targets)[0];
            }
        }

        return $relations;
    }

    /**
     * The model one `$this->hasMany(Related::class)`-shaped call names, or null for anything else:
     * a call on another receiver, a relationship method with no class argument (`morphTo()`), a
     * variable target, or a model outside the app namespace, whose node no lane could walk anyway.
     *
     * @return array{related: string, toMany: bool}|null
     */
    private function relatedIn(MethodCall $call): ?array
    {
        if (! $call->var instanceof Variable
            || $call->var->name !== 'this'
            || ! $call->name instanceof Identifier
            || ! in_array($call->name->toString(), ModelAnalyzer::RELATIONSHIP_METHODS, true)
            || $call->isFirstClassCallable()) {
            return null;
        }

        $argument = $call->getArgs()[0]->value ?? null;

        if (! $argument instanceof ClassConstFetch
            || ! $argument->name instanceof Identifier
            || $argument->name->toString() !== 'class'
            || ! $argument->class instanceof Name) {
            return null;
        }

        $related = AppFiles::resolveName($argument->class);

        if (! AppNamespace::isInApp($related)) {
            return null;
        }

        return ['related' => $related, 'toMany' => in_array($call->name->toString(), self::TO_MANY, true)];
    }

    /**
     * The traits one class-like uses, read from its own statements rather than from the file's flat
     * trait-use list: a file declaring two classes would otherwise give each the other's traits.
     *
     * @return list<string>
     */
    private function traitsUsedBy(ClassLike $node): array
    {
        $traits = [];

        foreach ($node->stmts as $statement) {
            if (! $statement instanceof TraitUse) {
                continue;
            }

            foreach ($statement->traits as $trait) {
                $traits[] = AppFiles::resolveName($trait);
            }
        }

        return $traits;
    }
}
