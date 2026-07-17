<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
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
    private const array DEFAULT_ROOTS = ['Jobs', 'Listeners', 'Console/Commands', 'Helpers', 'Http/Middleware', 'Livewire', 'Observers'];

    /** @var list<string> */
    private array $roots;

    /** @param  list<string>|null  $roots  overrides {@see self::DEFAULT_ROOTS} ({@see RichterConfig::entryPointRoots()}) */
    public function __construct(?array $roots = null)
    {
        $this->roots = $roots ?? self::DEFAULT_ROOTS;
    }

    /** @return list<array{source: string, target: string, type: string}> */
    public function trace(string $projectRoot): array
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
                foreach ($this->methodsOf($parser, $fqcn, $projectRoot) as $method) {
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
            ...$this->eventListenerEdges($projectRoot),
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

    /** @return list<string> */
    private function methodsOf(PhpFileParser $parser, string $fqcn, string $projectRoot): array
    {
        $file = $projectRoot . '/app/' . str_replace('\\', '/', substr($fqcn, strlen('App\\'))) . '.php';
        $parsed = $parser->parse($file);

        if ($parsed['ast'] === null) {
            return [];
        }

        $methods = [];

        foreach (new NodeFinder()->findInstanceOf($parsed['ast'], ClassMethod::class) as $method) {
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
     * @return list<array{source: string, target: string, type: string}>
     */
    private function eventListenerEdges(string $projectRoot): array
    {
        $file = $projectRoot . '/app/Providers/EventServiceProvider.php';

        if (! is_file($file)) {
            return [];
        }

        $ast = AppFiles::parseResolved((string) file_get_contents($file));

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
     * Resolve interface → concrete bindings registered in service providers.
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    private function bindingEdges(string $projectRoot): array
    {
        $edges = [];

        foreach (new ContainerBindingAnalyzer()->analyze($projectRoot)->all() as $abstract => $record) {
            if ($record->concreteFqcn !== null && $record->concreteFqcn !== '') {
                $edges[] = [
                    'source' => ltrim((string) $abstract, '\\'),
                    'target' => ltrim($record->concreteFqcn, '\\'),
                    'type' => 'binding',
                ];
            }
        }

        return $edges;
    }

    /**
     * Link an app interface to the classes that implement it. Most app contracts are resolved by
     * type, not a container binding, so Brain never connects them — a change to such an interface
     * otherwise seeds nothing. The edge runs implementor → interface so `callersOf` an interface walks
     * up through its implementors to their entry points. Fed per file by the consolidated AST loop
     * in {@see CodeGraphBuilder}.
     *
     * @param  list<Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{source: string, target: string, type: string}>
     */
    public function interfaceEdgesForResolvedAst(array $ast, string $classFqcn): array
    {
        $edges = [];

        foreach (new NodeFinder()->findInstanceOf($ast, ClassLike::class) as $node) {
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
