<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\SecondHopWalk;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\EntryPointMethodFilter;
use SanderMuller\Richter\Support\ProviderBindings;
use Throwable;

/**
 * Brain anchors its graph on web routes, so code reached only via queues, the console, or helpers is
 * absent and reports a falsely empty blast radius. Traces those entry points (jobs, listeners,
 * commands, helpers) plus the container bindings Brain misses (scanned by
 * {@see ProviderBindings}), emitting edges keyed by FQCN so they join the FQCN-normalised graph
 * (see CodeGraphBuilder).
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
     * @param  ProviderBindings|null  $providerBindings  the provider scan the caller already ran
     *   ({@see CodeGraphBuilder::buildTracerBranch()} needs its key map before this runs); null
     *   scans here, so a stand-alone caller still gets the binding edges.
     * @return list<array{source: string, target: string, type: string}>
     */
    public function trace(string $projectRoot, array $resolvedAstsByPath = [], ?ProviderBindings $providerBindings = null): array
    {
        $parser = new PhpFileParser();
        $tracer = new MethodTracer();
        $psr4 = [AppNamespace::root() => [$projectRoot . '/app']];

        $edges = [];

        foreach ($this->roots as $dir) {
            foreach (AppFiles::phpClasses($projectRoot . '/app/' . $dir, $projectRoot) as $class) {
                $fqcn = $class['fqcn'];

                // Trace every method, not just handle()/__invoke(): MethodTracer does not recurse
                // into a class's own private methods, so the entry method alone misses the
                // service/model calls those private helpers make.
                foreach ($this->methodsOf($parser, $fqcn, $projectRoot, $resolvedAstsByPath) as $method) {
                    foreach ($this->traceMethod($tracer, $fqcn, $method, $psr4, $projectRoot) ?? [] as $edge) {
                        $edges[] = $edge;
                    }
                }
            }
        }

        // Tracing every method of a class re-walks shared downstream paths, so dedupe before returning.
        // Interface→implementor edges are NOT emitted here — they come from the consolidated per-file
        // AST loop in {@see CodeGraphBuilder} via {@see interfaceEdgesForResolvedAst()}. Nor are
        // event→listener links: Brain reads `$listen`, `$subscribe` and `#[AsEventListener]` since
        // v2.4.0, a superset of the `$listen`-only reader this used to carry.
        $bindings = $providerBindings ?? ProviderBindings::forProject($projectRoot);

        return AppFiles::dedupeEdges([...$edges, ...$bindings->edges], byType: true);
    }

    /**
     * The same body walk {@see trace()} runs, aimed at named methods instead of whole directories —
     * for {@see SecondHopWalk}, which learns its targets from the graph rather than from config.
     * Root-agnostic: a method is addressed by FQCN, and the roots this instance carries are never
     * consulted. The catch-and-skip lives in {@see traceMethod()}, so both callers share it.
     *
     * A node without a `::` is a CLASS, which {@see SecondHopWalk} sends at its `class` scope: read
     * every traceable method the class declares, not only the one a static call named.
     *
     * `unread` counts what the tracer could not read at all — an unparseable file, a class that does
     * not resolve. A method counts once; a class whose file cannot be read counts once for the
     * class, since none of its methods could even be named. A readable class with no traceable
     * method is not unread: it was read, and it had nothing to walk. Silence anywhere here would be
     * the same falsely-reassuring answer the whole package exists to avoid, so the count travels out
     * for a caller to report.
     *
     * @param  list<string>  $nodes  `FQCN::method` member ids, and (at the `class` scope) bare FQCNs
     * @return array{edges: list<array{source: string, target: string, type: string}>, unread: int}
     */
    public function traceMembers(array $nodes, string $projectRoot): array
    {
        $tracer = new MethodTracer();
        $psr4 = [AppNamespace::root() => [$projectRoot . '/app']];

        $traced = [];
        $unread = 0;

        foreach ($nodes as $node) {
            [$fqcn, $method] = array_pad(explode('::', $node, 2), 2, '');

            // A bare FQCN expands to the class's own traceable methods; null means the class itself
            // could not be read, which is one gap, not one per method it might have had.
            $methods = $method === '' ? $this->traceableMethodsOf($fqcn, $projectRoot) : [$method];

            if ($methods === null) {
                ++$unread;

                continue;
            }

            foreach ($methods as $name) {
                $edges = $this->traceMethod($tracer, $fqcn, $name, $psr4, $projectRoot);

                if ($edges === null) {
                    ++$unread;

                    continue;
                }

                $traced[] = $edges;
            }
        }

        return [
            'edges' => AppFiles::dedupeEdges(array_merge([], ...$traced), byType: true),
            'unread' => $unread,
        ];
    }

    /**
     * The traceable methods one class declares, or null when the class could not be read at all.
     *
     * Deliberately not {@see methodsOf()}, which cannot serve this caller: it returns `[]` for an
     * unreadable file and for a readable class with no traceable method alike, and it collects every
     * `ClassMethod` in the file rather than the requested class's own. Leaving it untouched also
     * keeps {@see trace()}'s edge output and order — pinned byte-identical between the serial and
     * concurrent build paths — out of this change.
     *
     * `app/`-only, the same lookup {@see methodsOf()} makes. A static-call target may be in the app
     * namespace with no file there ({@see StaticCallEdgeTracer} admits any class that loads); that
     * class is reported unread rather than silently skipped.
     *
     * Parsed through {@see AppFiles::parseResolved()} because the ownership test reads
     * `namespacedName`, which only name resolution sets.
     *
     * @return list<string>|null
     */
    private function traceableMethodsOf(string $fqcn, string $projectRoot): ?array
    {
        $file = $projectRoot . '/app/' . AppNamespace::relativePath($fqcn) . '.php';

        if (! is_file($file)) {
            return null;
        }

        $ast = AppFiles::parseResolved((string) file_get_contents($file));

        if ($ast === null) {
            return null;
        }

        $methods = null;

        foreach (new NodeFinder()->findInstanceOf($ast, ClassLike::class) as $classLike) {
            if ($classLike->namespacedName?->toString() !== ltrim($fqcn, '\\')) {
                continue;
            }

            // Only this class's own methods: a file declaring a sibling class, an enum, or an
            // anonymous `new class` in a body would otherwise expand to methods the candidate never
            // declared. `shouldTrace()` drops an abstract method and a body with no call node —
            // neither can emit an edge (plan 049).
            $methods = [];

            foreach ($classLike->getMethods() as $method) {
                if (EntryPointMethodFilter::shouldTrace($method)) {
                    $methods[] = $method->name->toString();
                }
            }
        }

        // Null, not []: a file that declares the class under another name was not read for it.
        return $methods;
    }

    /**
     * Null distinguishes "could not read this method" from "read it, it calls nothing" — the two
     * look identical in an edge list, and only the first is a gap worth reporting.
     *
     * @param  array<string, list<string>>  $psr4
     * @return list<array{source: string, target: string, type: string}>|null
     */
    private function traceMethod(MethodTracer $tracer, string $fqcn, string $method, array $psr4, string $projectRoot): ?array
    {
        try {
            $traced = $tracer->traceMethod($fqcn, $method, $psr4, $projectRoot);
        } catch (Throwable) {
            // A class the tracer can't parse is skipped, not fatal — this is best-effort advisory tooling.
            return null;
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
        $file = $projectRoot . '/app/' . AppNamespace::relativePath($fqcn) . '.php';

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
            // Trace only methods with an edge-source node: a body with none emits no edge through
            // Brain's MethodTracer, so skipping it is output-invariant and avoids pure overhead
            // (plan 049; see EntryPointMethodFilter for the node set).
            if (EntryPointMethodFilter::shouldTrace($method)) {
                $methods[] = $method->name->toString();
            }
        }

        return $methods;
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
                if (AppNamespace::isInApp($interface)) {
                    $edges[] = ['source' => ltrim($classFqcn, '\\'), 'target' => $interface, 'type' => 'implements'];
                }
            }
        }

        return $edges;
    }
}
