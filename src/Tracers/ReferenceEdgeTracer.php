<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\LoadableClass;

/**
 * Brain has no notion of API resources, transformers, or custom validation rules:
 * `XResource::make(...)`, nested resource composition, and `new SomeRule()` inside `rules()` produce
 * no edge, so a changed resource or rule reads as unplaceable — the exact blind spot behind a payload
 * field silently going missing. Emits edges from the referencing method to the
 * referenced class; the class-level target is deliberate, the member-declaration pass in
 * {@see CodeGraphBuilder} links its methods.
 *
 * Consumed per file by the consolidated AST loop in {@see CodeGraphBuilder} — this class walks
 * nothing itself; all namespace targets (see NAMESPACE_TYPES) share that one pass. Dev/CI tooling only.
 */
final class ReferenceEdgeTracer
{
    /**
     * {@see NAMESPACE_TYPES} with its keys qualified against the app's root namespace, built on first
     * use. Per instance, which is per build — the root namespace cannot change under a running build.
     *
     * @var array<string, string>|null
     */
    private ?array $qualifiedTypes = null;

    /**
     * The checker's list plus bare `has`/`doesntHave`: overloaded receivers make those unsafe to
     * *validate* strings from (see the checker's LOAD_METHODS note), but for reach edges the
     * model-constant gate below is filter enough — `->has(Model::RELATION)` sites in query builders
     * are real reach that must not go dark on a relation rename.
     *
     * @var list<string>
     */
    private const array RELATION_CALL_METHODS = [...EagerLoadStringChecker::LOAD_METHODS, 'has', 'doesntHave'];

    /**
     * Namespace fragment (relative to the app root, {@see AppNamespace::qualify()}) → emitted edge
     * type. Deliberately a targeted list, not a catch-all over the whole root namespace — class-level
     * reference edges on hub models would light every caller of the class for any method change, an
     * over-reporting shape that trains readers to ignore the check. Each family here is one where the
     * class is a sensible reach unit (renderers, validators, handlers, single-purpose actions).
     *
     * @var array<string, string>
     */
    private const array NAMESPACE_TYPES = [
        'Http\\Resources\\' => 'resource',
        'Transformers\\' => 'resource',
        'Rules\\' => 'validates-with',
        'Handlers\\' => 'references',
        'Actions\\' => 'references',
    ];

    /** @return list<array{source: string, target: string, type: string}> */
    public function edgesForSource(string $source, string $classFqcn): array
    {
        $ast = AppFiles::parseResolved($source);

        return $ast === null ? [] : $this->edgesForResolvedAst($ast, $classFqcn);
    }

    /**
     * @param  list<Node\Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForResolvedAst(array $ast, string $classFqcn): array
    {
        $finder = new NodeFinder();

        return $this->edgesForNodes(
            array_values($finder->findInstanceOf($ast, ClassMethod::class)),
            array_values($finder->findInstanceOf($ast, TraitUse::class)),
            $classFqcn,
        );
    }

    /**
     * Bucket-fed variant of {@see edgesForResolvedAst()}: the consolidated loop in
     * {@see CodeGraphBuilder} collects each file's nodes in one descent and hands every tracer its
     * bucket, so no tracer re-walks the full tree.
     *
     * @param  list<ClassMethod>  $classMethods  every ClassMethod in the file, any depth
     * @param  list<TraitUse>  $traitUses  every TraitUse in the file
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForNodes(array $classMethods, array $traitUses, string $classFqcn): array
    {
        $classFqcn = ltrim($classFqcn, '\\');
        $edges = [];

        foreach ($classMethods as $method) {
            $sourceNode = $classFqcn . '::' . $method->name->toString();
            // One descent of the method feeding both lanes. Each used to run its own NodeFinder over
            // the same body — three full walks per method, on every app file in the tree.
            ['names' => $names, 'calls' => $calls, 'instantiations' => $instantiations] = $this->namesAndCalls($method);

            foreach ($this->referencesIn($names) as $target => $type) {
                // A class referencing itself (nested collection of its own type) is not a dependency edge.
                if ($target !== $classFqcn) {
                    $edges[] = ['source' => $sourceNode, 'target' => $target, 'type' => $type];
                }
            }

            foreach ($this->relationsLoadedIn($calls) as $relationNode) {
                $edges[] = ['source' => $sourceNode, 'target' => $relationNode, 'type' => 'loads-relation'];
            }

            foreach ($this->constructorsCalledIn($instantiations, $classFqcn) as $constructor) {
                $edges[] = ['source' => $sourceNode, 'target' => $constructor, 'type' => 'constructs'];
            }
        }

        // A trait's methods run inside every class that uses it, but no call edge ever targets the
        // trait — a changed trait method (app/Models/Concerns/…) otherwise reads unplaceable. The
        // using class stands in as the caller; the member-declaration pass links the trait's methods.
        foreach ($traitUses as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traitFqcn = AppFiles::resolveName($trait);

                if (AppNamespace::isInApp($traitFqcn)) {
                    $edges[] = ['source' => $classFqcn, 'target' => $traitFqcn, 'type' => 'uses-trait'];
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * Relation member nodes loaded via a model constant inside a `load`/`with`/`whereHas`-family
     * call: `->with([Review::ANSWERS])` links to `App\Models\Review::answers` — the relation
     * *method* node — so renaming a relation lights up its eager-load call sites. The constant's
     * declaring model stands in for the receiver, which is not statically knowable; the
     * convention that relation constants live on the model declaring the relation makes that sound.
     *
     * @param  list<MethodCall|StaticCall>  $calls
     * @return list<string>
     */
    private function relationsLoadedIn(array $calls): array
    {
        $finder = new NodeFinder();
        $relations = [];

        foreach ($calls as $call) {
            if ($call->isFirstClassCallable()) {
                continue;
            }

            if (! $call->name instanceof Identifier) {
                continue;
            }

            if (! in_array($call->name->toString(), self::RELATION_CALL_METHODS, strict: true)) {
                continue;
            }

            // Constants inside a constraint closure (`with([X::REL => fn ($q) => $q->select(Y::COL)])`)
            // are columns, not relation names — collect only the const fetches outside closure bodies.
            // A nested `->with()` *call* inside the closure is not lost: it is iterated as its own call.
            $insideClosures = [];

            foreach ($finder->find($call->getArgs(), static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction) as $closure) {
                foreach ($finder->findInstanceOf($closure, ClassConstFetch::class) as $nested) {
                    $insideClosures[spl_object_id($nested)] = true;
                }
            }

            foreach ($finder->findInstanceOf($call->getArgs(), ClassConstFetch::class) as $constant) {
                if (isset($insideClosures[spl_object_id($constant)])) {
                    continue;
                }

                if (! $constant->class instanceof Name) {
                    continue;
                }

                if (! $constant->name instanceof Identifier) {
                    continue;
                }

                $model = AppFiles::resolveName($constant->class);

                if (! str_starts_with($model, AppNamespace::qualify('Models\\'))) {
                    continue;
                }

                $value = AppFiles::stringConstantValue($model, $constant->name->toString());

                if ($value !== null) {
                    // A dotted value names a nested path; the constant's own model only declares the first segment.
                    $firstSegment = strstr($value, '.', before_needle: true);
                    $relations["{$model}::" . ($firstSegment === false || $firstSegment === '' ? $value : $firstSegment)] = true;
                }
            }
        }

        return array_keys($relations);
    }

    /**
     * Every `Name`, `MethodCall` and `StaticCall` in one method, in one descent — the buckets the two
     * lanes above consume. Pre-order, so each bucket arrives in the same order a per-type NodeFinder
     * produced it and the emitted edges keep their order.
     *
     * @return array{names: list<Name>, calls: list<MethodCall|StaticCall>, instantiations: list<New_>}
     */
    private function namesAndCalls(ClassMethod $method): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Name> */
            public array $names = [];

            /** @var list<MethodCall|StaticCall> */
            public array $calls = [];

            /** @var list<New_> */
            public array $instantiations = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Name) {
                    $this->names[] = $node;
                } elseif ($node instanceof MethodCall || $node instanceof StaticCall) {
                    $this->calls[] = $node;
                } elseif ($node instanceof New_) {
                    $this->instantiations[] = $node;
                }

                return null;
            }
        };

        new NodeTraverser($visitor)->traverse([$method]);

        return ['names' => $visitor->names, 'calls' => $visitor->calls, 'instantiations' => $visitor->instantiations];
    }

    /**
     * The constructors an app class is built with here — `new Widget(...)` links the building member to
     * `Widget::__construct`, and to nothing else.
     *
     * The target is the CONSTRUCTOR, not the class, and that is what makes this lane affordable. A
     * class-level edge would make every method of a widely-constructed class reach every place that
     * builds one, which is the over-reporting shape {@see NAMESPACE_TYPES} exists to avoid. Depending
     * on a constructor is the narrower and truer claim: changing it changes what every construction
     * site gets, and changing some other method of that class does not.
     *
     * This is the lane that was missing when a value object with one statically visible caller reported
     * no graph node at all, taking the whole report to zero.
     *
     * @param  list<New_>  $instantiations
     * @return list<string>
     */
    private function constructorsCalledIn(array $instantiations, string $classFqcn): array
    {
        $constructors = [];

        foreach ($instantiations as $new) {
            if (! $new->class instanceof Name) {
                continue;
            }

            $constructed = AppFiles::resolveName($new->class);

            // Its own constructor is not a dependency of the class on itself. The loadability check is
            // the same one the static-call lane needs: an unqualified `new DateTimeImmutable()` with no
            // import resolves against this file's namespace and reads as an app class that does not
            // exist ({@see LoadableClass}).
            if ($constructed !== $classFqcn && AppNamespace::isInApp($constructed) && LoadableClass::exists($constructed)) {
                $constructors[$constructed . '::__construct'] = true;
            }
        }

        return array_keys($constructors);
    }

    /**
     * @param  list<Name>  $names
     * @return array<string, string> FQCN → edge type
     */
    private function referencesIn(array $names): array
    {
        // Qualified once per instance, not once per name per type. `qualify()` reads the configured
        // root namespace and the base path on every call, and this loop runs over every name in every
        // method of every app file — the prefixes it builds are the same string each time.
        $this->qualifiedTypes ??= array_combine(
            array_map(AppNamespace::qualify(...), array_keys(self::NAMESPACE_TYPES)),
            array_values(self::NAMESPACE_TYPES),
        );

        $references = [];

        foreach ($names as $name) {
            $fqcn = AppFiles::resolveName($name);

            foreach ($this->qualifiedTypes as $prefix => $type) {
                if (str_starts_with($fqcn, $prefix)) {
                    $references[$fqcn] = $type;
                }
            }

            // Custom validator classes live in per-domain `Validators` sub-namespaces under
            // Http\Requests (`App\Http\Requests\Post\Validators\…`) — a segment match, not a prefix.
            // The segment test runs first: it is a substring scan, while the app-namespace test reads
            // configuration, and only names carrying the segment at all can qualify.
            if (str_contains($fqcn, '\\Validators\\') && AppNamespace::isInApp($fqcn)) {
                $references[$fqcn] = 'validates-with';
            }
        }

        return $references;
    }
}
