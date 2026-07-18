<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use Throwable;

/**
 * Brain anchors its graph on web routes, so code reached only via queues, the console, or helpers is
 * absent and reports a falsely empty blast radius. Traces those entry points (jobs, listeners,
 * commands, helpers) plus the `$listen`-registered event→listener and interface→impl links Brain
 * misses, emitting edges keyed by FQCN so they join the FQCN-normalised graph (see CodeGraphBuilder).
 *
 * Dev/CI tooling only.
 */
final readonly class EntryPointTracer
{
    /**
     * Directories under app/ whose classes are entry points Brain's route-anchored graph misses.
     * Middleware is traced whole-directory (no Kernel-registration check, same over-approximation
     * as tracing every job) because Brain only emits per-route middleware — a global/alias
     * middleware otherwise reads unplaceable despite running on every request.
     *
     * @var list<string>
     */
    private const array DEFAULT_ROOTS = ['Jobs', 'Listeners', 'Console/Commands', 'Filament', 'Helpers', 'Http/Middleware', 'Livewire', 'Observers'];

    /**
     * Container registration methods that take (abstract, concrete) arguments. All kinds collapse
     * into the same `binding` edge — the graph cares about reach, not lifecycle.
     *
     * @var list<string>
     */
    private const array BINDING_METHODS = ['bind', 'singleton', 'scoped', 'bindIf', 'singletonIf', 'scopedIf'];

    /** @var list<string> */
    private array $roots;

    /** @param  list<string>|null  $roots  overrides {@see self::DEFAULT_ROOTS} ({@see RichterConfig::entryPointRoots()}) */
    public function __construct(?array $roots = null)
    {
        $this->roots = $roots ?? self::DEFAULT_ROOTS;
    }

    /**
     * The effective entry-point roots (configured ?? defaults). Exposed so the consolidated pass in
     * {@see CodeGraphBuilder} retains resolved ASTs for exactly the files {@see trace()} consumes —
     * reading the roots from this instance keeps the two from ever diverging.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * @param  array<string, list<Stmt>>  $resolvedAstsByPath  resolved ASTs the consolidated pass in
     *   {@see CodeGraphBuilder} already produced, keyed by absolute file path — a map hit saves this
     *   tracer its own parse of the same file; a miss falls back to parsing.
     * @return list<array{source: string, target: string, type: string}>
     */
    public function trace(string $projectRoot, array $resolvedAstsByPath = []): array
    {
        $parser = new PhpFileParser();
        $tracer = new MethodTracer();
        $psr4 = ['App\\' => [$projectRoot . '/app']];

        $edges = [];

        foreach ($this->roots as $dir) {
            foreach (AppFiles::phpClasses($projectRoot . '/app/' . $dir, $projectRoot) as $class) {
                $fqcn = $class['fqcn'];

                // Trace every method, not just handle()/__invoke(): MethodTracer does not recurse
                // into a class's own private methods, so the entry method alone misses the
                // service/model calls those private helpers make.
                foreach ($this->methodsOf($parser, $fqcn, $projectRoot, $resolvedAstsByPath) as $method) {
                    foreach ($this->traceMethod($tracer, $fqcn, $method, $psr4, $projectRoot) as $edge) {
                        $edges[] = $edge;
                    }
                }
            }
        }

        // Tracing every method of a class re-walks shared downstream paths, so dedupe before returning.
        // Interface→implementor edges are NOT emitted here — they come from the consolidated per-file
        // AST loop in {@see CodeGraphBuilder} via {@see interfaceEdgesForResolvedAst()}.
        return AppFiles::dedupeEdges([
            ...$edges,
            ...$this->eventListenerEdges($projectRoot, $resolvedAstsByPath),
            ...$this->bindingEdges($projectRoot),
        ], byType: true);
    }

    /** FQCN-keyed node id for a `$listen` listener: `Class@method` keeps its method, a bare class uses handle(). */
    public static function listenerTarget(string $listener): string
    {
        if (str_contains($listener, '@')) {
            [$class, $method] = explode('@', $listener, 2);

            return ltrim($class, '\\') . '::' . $method;
        }

        return ltrim($listener, '\\') . '::handle';
    }

    /**
     * @param  array<string, list<string>>  $psr4
     * @return list<array{source: string, target: string, type: string}>
     */
    private function traceMethod(MethodTracer $tracer, string $fqcn, string $method, array $psr4, string $projectRoot): array
    {
        try {
            $traced = $tracer->traceMethod($fqcn, $method, $psr4, $projectRoot);
        } catch (Throwable) {
            // A class the tracer can't parse is skipped, not fatal — this is best-effort advisory tooling.
            return [];
        }

        $edges = [];

        foreach ($traced as $edge) {
            $edges[] = [
                'source' => ltrim($edge->callerFqcn, '\\') . '::' . $edge->callerMethod,
                'target' => ltrim($edge->calleeFqcn, '\\') . ($edge->calleeMethod !== '' ? '::' . $edge->calleeMethod : ''),
                'type' => $edge->type,
            ];
        }

        return $edges;
    }

    /**
     * @param  array<string, list<Stmt>>  $resolvedAstsByPath
     * @return list<string>
     */
    private function methodsOf(PhpFileParser $parser, string $fqcn, string $projectRoot, array $resolvedAstsByPath): array
    {
        $file = $projectRoot . '/app/' . str_replace('\\', '/', substr($fqcn, strlen('App\\'))) . '.php';

        // A retained AST from the consolidated pass lists the same method names a fresh parse would
        // (name resolution is irrelevant to method names). The parse fallback stays: a root outside
        // the consolidated app/ scan, or a file parseResolved rejected, must not silently lose its
        // methods.
        $ast = $resolvedAstsByPath[$file] ?? $parser->parse($file)['ast'];

        if ($ast === null) {
            return [];
        }

        $methods = [];

        foreach (new NodeFinder()->findInstanceOf($ast, ClassMethod::class) as $method) {
            if (! $method->isAbstract()) {
                $methods[] = $method->name->toString();
            }
        }

        return $methods;
    }

    /**
     * Parse `EventServiceProvider::$listen` for event → listener links.
     *
     * laravel-brain#54 (v2.3.1) added event→listener edges by listener-class convention only — not
     * `$listen` mappings — so this method stays until Brain reads `EventServiceProvider::$listen`.
     * (Subscribers in `$subscribe` are wired by neither Brain nor this method: a separate known gap.)
     *
     * @param  array<string, list<Stmt>>  $resolvedAstsByPath
     * @return list<array{source: string, target: string, type: string}>
     */
    private function eventListenerEdges(string $projectRoot, array $resolvedAstsByPath): array
    {
        $file = $projectRoot . '/app/Providers/EventServiceProvider.php';

        // Same map-first pattern as {@see methodsOf()}: the consolidated pass retains this file's
        // resolved AST; the parse fallback covers a trace() call without one.
        $ast = $resolvedAstsByPath[$file]
            ?? (is_file($file) ? AppFiles::parseResolved((string) file_get_contents($file)) : null);

        if ($ast === null) {
            return [];
        }

        $edges = [];

        foreach (new NodeFinder()->findInstanceOf($ast, Property::class) as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'listen' && $prop->default instanceof Array_) {
                    $edges = [...$edges, ...$this->listenEdges($prop->default)];
                }
            }
        }

        return $edges;
    }

    /** @return list<array{source: string, target: string, type: string}> */
    private function listenEdges(Array_ $listen): array
    {
        $edges = [];

        foreach ($listen->items as $item) {
            $event = $this->resolveListenerName($item->key);
            if ($event === null) {
                continue;
            }

            if (! $item->value instanceof Array_) {
                continue;
            }

            foreach ($item->value->items as $listenerItem) {
                $listener = $this->resolveListenerName($listenerItem->value);

                if ($listener !== null) {
                    $edges[] = [
                        'source' => ltrim($event, '\\'),
                        'target' => self::listenerTarget($listener),
                        'type' => 'event-listener',
                    ];
                }
            }
        }

        return $edges;
    }

    private function resolveListenerName(mixed $node): ?string
    {
        if ($node instanceof ClassConstFetch && $node->class instanceof Name) {
            return AppFiles::resolveName($node->class);
        }

        return $node instanceof String_ ? $node->value : null;
    }

    /**
     * Resolve abstract → concrete bindings registered in service providers under app/Providers:
     * {@see self::BINDING_METHODS} calls on an app-like receiver plus the declarative
     * `$bindings`/`$singletons` properties.
     *
     * Scanned natively rather than via laravel-brain's container-binding analyzer, which skips
     * providers whose AST starts with Declare_ — every `declare(strict_types=1)` provider would
     * silently contribute zero binding edges. Two deliberate deltas vs Brain: providers with a
     * leading declare are scanned, and every class in a provider file is scanned, not only the first.
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    private function bindingEdges(string $projectRoot): array
    {
        $edges = [];

        foreach (AppFiles::phpClasses($projectRoot . '/app/Providers', $projectRoot) as $class) {
            $ast = AppFiles::parseResolved((string) file_get_contents($class['path']));

            if ($ast === null) {
                continue;
            }

            // Duplicate edges across providers are fine — trace() dedupes downstream.
            $edges = [...$edges, ...$this->methodCallBindings($ast), ...$this->propertyBindings($ast)];
        }

        return $edges;
    }

    /**
     * Bindings registered by call — `->bind(Abstract::class, Concrete::class)` and friends — on an
     * app-like receiver: `$this->app`, an `$app` variable (closure-injected container), or the
     * `app()` helper.
     *
     * @param  list<Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{source: string, target: string, type: string}>
     */
    private function methodCallBindings(array $ast): array
    {
        $edges = [];

        foreach (new NodeFinder()->findInstanceOf($ast, MethodCall::class) as $call) {
            $edge = $this->methodCallBindingEdge($call);

            if ($edge !== null) {
                $edges[] = $edge;
            }
        }

        return $edges;
    }

    /**
     * The edge one method call registers, or null when it isn't a two-plus-argument binding call on
     * an app-like receiver. A one-argument bind (concrete self-binding) adds no edge the class node
     * doesn't already imply.
     *
     * @return array{source: string, target: string, type: string}|null
     */
    private function methodCallBindingEdge(MethodCall $call): ?array
    {
        // `->bind(...)` (first-class callable) registers nothing, and getArgs() on it throws.
        if (! $call->name instanceof Identifier
            || ! in_array($call->name->toString(), self::BINDING_METHODS, true)
            || ! $this->isAppLikeReceiver($call->var)
            || $call->isFirstClassCallable()) {
            return null;
        }

        $args = $call->getArgs();

        return count($args) < 2 ? null : $this->bindingEdge($args[0]->value, $args[1]->value);
    }

    /**
     * Bindings declared via the non-static `$bindings` / `$singletons` provider properties, where
     * each array item maps abstract (key) to concrete (value).
     *
     * @param  list<Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{source: string, target: string, type: string}>
     */
    private function propertyBindings(array $ast): array
    {
        $edges = [];

        foreach (new NodeFinder()->findInstanceOf($ast, Property::class) as $property) {
            foreach ($property->props as $prop) {
                $edges = [...$edges, ...$this->declaredBindingEdges($property, $prop)];
            }
        }

        return $edges;
    }

    /**
     * The edges one declared property contributes — a non-static `$bindings`/`$singletons` with an
     * array default; each item maps abstract (key) to concrete (value).
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    private function declaredBindingEdges(Property $property, PropertyItem $prop): array
    {
        if ($property->isStatic()
            || ! in_array($prop->name->toString(), ['bindings', 'singletons'], true)
            || ! $prop->default instanceof Array_) {
            return [];
        }

        $edges = [];

        foreach ($prop->default->items as $item) {
            // A keyless item (list-style entry) names no abstract, so bindingEdge() yields no edge.
            $edge = $this->bindingEdge($item->key, $item->value);

            if ($edge !== null) {
                $edges[] = $edge;
            }
        }

        return $edges;
    }

    /**
     * The abstract → concrete edge for one registration, or null when either side is not class-like
     * (a closure concrete, a non-class string, a dynamic expression).
     *
     * @return array{source: string, target: string, type: string}|null
     */
    private function bindingEdge(?Expr $abstract, Expr $concrete): ?array
    {
        $source = $this->classLikeName($abstract);
        $target = $this->classLikeName($concrete);

        if ($source === null || $target === null) {
            return null;
        }

        return ['source' => $source, 'target' => $target, 'type' => 'binding'];
    }

    /**
     * A class-like expression's FQCN: `Xxx::class` (resolved through imports) or a string literal
     * naming a class — it must contain a namespace separator, so container aliases like `'cache'`
     * never become graph nodes. Anything else (null included) → null.
     */
    private function classLikeName(?Expr $expr): ?string
    {
        if ($expr instanceof ClassConstFetch && $expr->name instanceof Identifier && $expr->name->toString() === 'class' && $expr->class instanceof Name) {
            return AppFiles::resolveName($expr->class);
        }

        if ($expr instanceof String_ && str_contains($expr->value, '\\') && preg_match('/^\\\\?[\w\\\\]+$/', $expr->value) === 1) {
            return ltrim($expr->value, '\\');
        }

        return null;
    }

    /** The three receiver shapes a container registration is made on: `$this->app`, `$app`, `app()`. */
    private function isAppLikeReceiver(Expr $receiver): bool
    {
        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier
            && $receiver->name->toString() === 'app') {
            return true;
        }

        if ($receiver instanceof Variable && $receiver->name === 'app') {
            return true;
        }

        return $receiver instanceof FuncCall && $receiver->name instanceof Name && $receiver->name->toString() === 'app';
    }

    /**
     * @param  list<Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{source: string, target: string, type: string}>
     */
    public function interfaceEdgesForResolvedAst(array $ast, string $classFqcn): array
    {
        return $this->interfaceEdgesForClassLikes(array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class)), $classFqcn);
    }

    /**
     * Link an app interface to the classes that implement it. Most app contracts are resolved by
     * type, not a container binding, so Brain never connects them — a change to such an interface
     * otherwise seeds nothing. The edge runs implementor → interface so `callersOf` an interface walks
     * up through its implementors to their entry points. Fed per file by the consolidated AST loop
     * in {@see CodeGraphBuilder}, which collects each file's nodes in one descent and hands every
     * tracer its bucket.
     *
     * @param  list<ClassLike>  $classLikes  every ClassLike in the file, any depth
     * @return list<array{source: string, target: string, type: string}>
     */
    public function interfaceEdgesForClassLikes(array $classLikes, string $classFqcn): array
    {
        $edges = [];

        foreach ($classLikes as $node) {
            // Only Class_ and Enum_ carry `implements`; an interface's `extends` and a trait have none.
            if (! $node instanceof Class_ && ! $node instanceof Enum_) {
                continue;
            }

            foreach ($node->implements as $implemented) {
                $interface = AppFiles::resolveName($implemented);

                // App interfaces only — vendor contracts (ShouldQueue, Arrayable, …) are implemented
                // by hundreds of classes with no app-side reach and would swamp the graph.
                if (str_starts_with($interface, 'App\\')) {
                    $edges[] = ['source' => ltrim($classFqcn, '\\'), 'target' => $interface, 'type' => 'implements'];
                }
            }
        }

        return $edges;
    }
}
