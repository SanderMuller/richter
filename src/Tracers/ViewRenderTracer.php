<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Connects a `view('posts.show')` call to the view it renders, for the classes a route-anchored
 * analysis never reaches.
 *
 * Laravel Brain draws this edge (`action-to-view`) from a controller a route resolves to, by walking
 * that controller's body. A Livewire component, a Filament page, a mailable, an action class — none
 * of them sit behind a route, so their bodies are never walked and the views they render have no
 * caller at all. The whole class of change then reports UNRESOLVED: the view file has a graph node,
 * nothing points at it, and richter cannot say who is affected by editing it.
 *
 * The mapping is often assumed to need a convention resolver (component FQCN → view name). It does
 * not, for the majority: the call is written out in the source (`return view('livewire.foo');`).
 * This reads the call that is already there.
 *
 * Literal names only. A computed view name (`view($this->template)`) names nothing, and the view has
 * to exist as a file before an edge is drawn — a package-namespaced name (`mail::message`) resolves
 * outside `resources/views`, so an edge to it would mint a node nothing else in the graph shares.
 *
 * Brain's own type is reused rather than a parallel one: the relation is the same, and the merge
 * dedupes by (source, target, type), so a controller Brain already covered yields one edge, not two.
 *
 * Dev/CI tooling only.
 */
final readonly class ViewRenderTracer
{
    /** Facades whose `make()` renders a view by name, alongside the `view()` helper. */
    private const string VIEW_FACADE = 'Illuminate\\Support\\Facades\\View';

    public function __construct(private string $projectRoot) {}

    /**
     * The edges one file's class-likes contribute: rendering member → view node.
     *
     * Class-scoped for the reason {@see StaticCallEdgeTracer} gives: a second class in the same file
     * would otherwise have its renders attributed to the first.
     *
     * An anonymous class is skipped: it has no name to be an edge source, and inventing one from the
     * file's primary class mints a member that may not exist — a caller a reviewer opens and cannot
     * find. Its renders are not lost, they are attributed to the method that builds it, which is where
     * they belong ({@see AppFiles::nodesOwnedBy()}).
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

            foreach ($classLike->getMethods() as $method) {
                $source = $fqcn . '::' . $method->name->toString();

                foreach ($this->viewsRendered($method) as $view) {
                    $edges[] = ['source' => $source, 'target' => BladeViews::nodeId($view), 'type' => 'action-to-view'];
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * The dotted view names a method renders by literal name and that exist as Blade files here.
     *
     * @return list<string>
     */
    private function viewsRendered(ClassMethod $method): array
    {
        $views = [];

        foreach (AppFiles::nodesOwnedBy($method, $this->isViewRender(...)) as $call) {
            /** @var FuncCall|StaticCall $call */
            $argument = ($call->getArgs()[0] ?? null)?->value;

            if (! $argument instanceof String_) {
                continue;
            }

            if (BladeViews::existsIn($this->projectRoot, $argument->value)) {
                $views[$argument->value] = true;
            }
        }

        return array_keys($views);
    }

    private function isViewRender(mixed $node): bool
    {
        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && $node->name->toLowerString() === 'view' && $node->getArgs() !== [];
        }

        return $node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'make'
            && $node->class instanceof Name
            && AppFiles::resolveName($node->class) === self::VIEW_FACADE
            && $node->getArgs() !== [];
    }
}
