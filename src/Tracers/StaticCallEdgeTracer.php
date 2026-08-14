<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use LaraMint\LaravelBrain\Analysis\MethodTracer;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\LoadableClass;

/**
 * Laravel Brain resolves a static call only when it matches a known shape — `Job::dispatch()`, the
 * Event/Bus/View/Notification facades, or an Eloquent static query on a model class. Every other
 * `Foo::bar()` falls off the end of its handler and produces no hop, while the sibling handler for
 * `new Foo` emits one for any app class. So a class reached ONLY through static calls — a static
 * registry, a static named constructor, a static factory — has no node in the graph at all: a change
 * to it reads as unreachable, and `detect-changes` reports that nothing references it while its
 * callers sit two lines away in files richter parsed.
 *
 * Emits a `static-call` edge from the calling method's member node to the callee's member node.
 * Member-level on both ends: the class nodes are linked to their members by the existing `declares`
 * edges, so targeting the member keeps the precision the rest of the graph has without losing reach.
 *
 * Scope (spec `static-call-and-inherited-method-edges.md`): app classes only — an unfiltered version
 * would draw an edge for every `Carbon::now()` and facade call in the codebase. Eloquent static
 * queries are left to Brain, which already types them `model`; re-emitting them here would count one
 * call in two edge types. A variable receiver (`$class::make()`) is not statically resolvable and
 * draws nothing, the same silence every other tracer keeps for a dynamic target.
 *
 * Dev/CI tooling only.
 */
final class StaticCallEdgeTracer
{
    /** @return list<array{source: string, target: string, type: string}> */
    public function edgesForSource(string $source): array
    {
        $ast = AppFiles::parseResolved($source);

        return $ast === null ? [] : $this->edgesForClassLikes(array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class)));
    }

    /**
     * Class-scoped rather than fed the file's flat method bucket: `self::`, `static::` and `parent::`
     * can only be resolved against the class that declares the calling method, and a second class in
     * the same file would otherwise have its calls attributed to the first.
     *
     * An anonymous class is skipped as a SOURCE: it has no name, and taking the file's primary class
     * instead mints a member that may not exist — a caller a reviewer opens and cannot find. Its calls
     * are not lost; {@see AppFiles::nodesOwnedBy()} hands them to the method that builds it, which is
     * the real owner. Scope-relative receivers inside it are dropped there (see
     * {@see edgesForMethod()}), because `self`/`static`/`parent` mean the anonymous class, not the
     * method's own.
     *
     * @param  list<ClassLike>  $classLikes  every class-like in the file, any depth
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForClassLikes(array $classLikes): array
    {
        $edges = [];

        foreach ($classLikes as $classLike) {
            $fqcn = $classLike->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $fqcn = ltrim($fqcn, '\\');
            $parent = $classLike instanceof Class_ && $classLike->extends instanceof Name
                ? AppFiles::resolveName($classLike->extends)
                : null;

            foreach ($classLike->getMethods() as $method) {
                foreach ($this->edgesForMethod($method, $fqcn, $parent) as $edge) {
                    $edges[] = $edge;
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edgesForMethod(ClassMethod $method, string $fqcn, ?string $parent): array
    {
        $source = $fqcn . '::' . $method->name->toString();
        $edges = [];

        foreach (AppFiles::nodesOwnedByWithNesting($method, static fn (Node $n): bool => $n instanceof StaticCall) as [$call, $nested]) {
            /** @var StaticCall $call */
            if (! $call->name instanceof Identifier) {
                continue;
            }

            // Inside an anonymous class, `self`/`static`/`parent` name THAT class. Resolving them
            // against the method's own class would draw a confidently wrong edge, so they are
            // dropped; a fully qualified receiver is unaffected and still links.
            $target = $this->receiverFqcn($call, $fqcn, $nested ? null : $parent, $nested);

            if ($target === null) {
                continue;
            }

            $callee = $call->name->toString();

            if ($this->isBrainsToDraw($target, $callee)) {
                continue;
            }

            $edges[] = ['source' => $source, 'target' => "{$target}::{$callee}", 'type' => 'static-call'];
        }

        return $edges;
    }

    /**
     * The app FQCN the call's receiver names, or null when there is nothing to draw: a dynamic
     * receiver, `parent::` in a class whose parent richter did not resolve, or a vendor class.
     */
    private function receiverFqcn(StaticCall $call, string $fqcn, ?string $parent, bool $nested = false): ?string
    {
        if (! $call->class instanceof Name) {
            return null;
        }

        // A name-resolved AST leaves these three as-is — they are relative to the declaring class,
        // which only this class's own scope knows. They also need no existence check below: `self`
        // and `static` ARE the class being parsed, and `parent` comes from its `extends` clause.
        $keyword = strtolower($call->class->toString());

        if (in_array($keyword, ['self', 'static', 'parent'], true)) {
            if ($nested) {
                return null;
            }

            $resolved = $keyword === 'parent' ? $parent : $fqcn;

            return $resolved !== null && AppNamespace::isInApp($resolved) ? $resolved : null;
        }

        $resolved = ltrim(AppFiles::resolveName($call->class), '\\');

        if (! AppNamespace::isInApp($resolved)) {
            return null;
        }

        // An UNQUALIFIED name with no matching import resolves against the current namespace, so a
        // `Carbon::now()` written without its `use` becomes `App\Services\Carbon` — inside the app
        // namespace by spelling, nonexistent in fact. Drawing that edge invents a node. Nothing real
        // is lost by requiring the class to load: a target richter cannot autoload has no node from
        // any other tracer either, so the edge could only ever point at a phantom.
        return LoadableClass::exists($resolved) ? $resolved : null;
    }

    /**
     * Whether Brain already emits a hop for this call, so drawing one here would count the same call
     * twice under two edge types. Its model-static handling is the overlap: `Post::find()` is already
     * a `model` edge.
     */
    private function isBrainsToDraw(string $target, string $callee): bool
    {
        return in_array($callee, MethodTracer::MODEL_STATIC_METHODS, true) && $this->looksLikeModel($target);
    }

    /** Brain's own rule, narrowed to app classes: a `\Models\`/`\Model\` segment, or the app's models namespace. */
    private function looksLikeModel(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Models\\')
            || str_contains($fqcn, '\\Model\\')
            || str_starts_with($fqcn, AppNamespace::qualify('Models\\'));
    }
}
