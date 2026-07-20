<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;

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
     * The checker's list plus bare `has`/`doesntHave`: overloaded receivers make those unsafe to
     * *validate* strings from (see the checker's LOAD_METHODS note), but for reach edges the
     * model-constant gate below is filter enough — `->has(Model::RELATION)` sites in query builders
     * are real reach that must not go dark on a relation rename.
     *
     * @var list<string>
     */
    private const array RELATION_CALL_METHODS = [...EagerLoadStringChecker::LOAD_METHODS, 'has', 'doesntHave'];

    /**
     * Namespace prefix → emitted edge type. Deliberately a targeted list, not a catch-all over
     * `App\` — class-level reference edges on hub models would light every caller of the class for
     * any method change, an over-reporting shape that trains readers to ignore the check. Each family
     * here is one where the class is a sensible reach unit (renderers, validators, handlers, single-purpose actions).
     *
     * @var array<string, string>
     */
    private const array NAMESPACE_TYPES = [
        'App\\Http\\Resources\\' => 'resource',
        'App\\Transformers\\' => 'resource',
        'App\\Rules\\' => 'validates-with',
        'App\\Handlers\\' => 'references',
        'App\\Actions\\' => 'references',
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

            foreach ($this->referencesIn($method) as $target => $type) {
                // A class referencing itself (nested collection of its own type) is not a dependency edge.
                if ($target !== $classFqcn) {
                    $edges[] = ['source' => $sourceNode, 'target' => $target, 'type' => $type];
                }
            }

            foreach ($this->relationsLoadedIn($method) as $relationNode) {
                $edges[] = ['source' => $sourceNode, 'target' => $relationNode, 'type' => 'loads-relation'];
            }
        }

        // A trait's methods run inside every class that uses it, but no call edge ever targets the
        // trait — a changed trait method (app/Models/Concerns/…) otherwise reads unplaceable. The
        // using class stands in as the caller; the member-declaration pass links the trait's methods.
        foreach ($traitUses as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traitFqcn = AppFiles::resolveName($trait);

                if (str_starts_with($traitFqcn, 'App\\')) {
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
     * @return list<string>
     */
    private function relationsLoadedIn(ClassMethod $method): array
    {
        $finder = new NodeFinder();
        /** @var list<MethodCall|StaticCall> $calls */
        $calls = [...$finder->findInstanceOf($method, MethodCall::class), ...$finder->findInstanceOf($method, StaticCall::class)];
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

                if (! str_starts_with($model, 'App\\Models\\')) {
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
     * @return array<string, string> FQCN → edge type
     */
    private function referencesIn(Node $node): array
    {
        $references = [];

        foreach (new NodeFinder()->findInstanceOf($node, Name::class) as $name) {
            $fqcn = AppFiles::resolveName($name);

            foreach (self::NAMESPACE_TYPES as $prefix => $type) {
                if (str_starts_with($fqcn, $prefix)) {
                    $references[$fqcn] = $type;
                }
            }

            // Custom validator classes live in per-domain `Validators` sub-namespaces under
            // Http\Requests (`App\Http\Requests\Post\Validators\…`) — a segment match, not a prefix.
            if (str_starts_with($fqcn, 'App\\') && str_contains($fqcn, '\\Validators\\')) {
                $references[$fqcn] = 'validates-with';
            }
        }

        return $references;
    }
}
