<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use Closure;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Edge;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Changes\MemberResolver;
use SanderMuller\Richter\Console\InternalTracerBranchCommand;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tracers\BladeViewTracer;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;
use SanderMuller\Richter\Tracers\ConfigRegistryTracer;
use SanderMuller\Richter\Tracers\ConstantReferenceTracer;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;
use SanderMuller\Richter\Tracers\EntryPointTracer;
use SanderMuller\Richter\Tracers\FacadeEdgeTracer;
use SanderMuller\Richter\Tracers\PolicyEdgeTracer;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;
use SanderMuller\Richter\Tracers\StaticCallEdgeTracer;
use SanderMuller\Richter\Tracers\ViewRenderTracer;

/**
 * Builds a {@see CodeGraph} from the live codebase using Laravel Brain's static analysis. Widens
 * Brain's default route/command globs (which only match `{dir}/{file}.php`) to also cover route and
 * command files directly under `routes/` and `app/Console/Commands/`, plus one nesting level. Dev/CI only.
 *
 * @phpstan-import-type MetadataShape from NodeMetadata
 */
final class CodeGraphBuilder
{
    /** @var list<string> */
    private const array ROUTE_PATHS = ['routes/*.php', 'routes/api/*.php', 'routes/*/*.php'];

    /** @var list<string> */
    private const array COMMAND_CLASS_PATHS = ['app/Console/Commands/*.php', 'app/Console/Commands/*/*.php'];

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $onProgress  silent by default;
     *   Brain otherwise echoes its progress straight to stdout, which pollutes command/MCP output.
     */
    public function build(?string $projectRoot = null, ?callable $onProgress = null): CodeGraph
    {
        $projectRoot ??= base_path();

        // Start richter's tracer branch (Branch B) concurrently when eligible, so it overlaps Brain's
        // analyze() below. Null → the branch runs in-process (serial / profiling / fallback).
        $pending = TracerBranchRunner::start($projectRoot, $onProgress);

        // The override must not outlive the build: the process may be a long-lived MCP server whose
        // global config repository the host app shares. Only analyze() reads these keys.
        $overrides = [
            'laravel-brain.route_paths' => self::ROUTE_PATHS,
            'laravel-brain.channel_paths' => self::ROUTE_PATHS,
            'laravel-brain.commands.console_route_paths' => self::ROUTE_PATHS,
            'laravel-brain.commands.class_paths' => self::COMMAND_CLASS_PATHS,
        ];
        $snapshot = array_map(config(...), array_combine(array_keys($overrides), array_keys($overrides)));

        // Timing is opt-in: hrtime() and event dispatch only run when a caller supplied a callback,
        // so the no-listener path (the common case — cache warms silently) stays allocation-free.
        $phaseStart = $onProgress !== null ? (float) hrtime(true) : 0.0;

        try {
            foreach ($overrides as $key => $paths) {
                config()->set($key, $paths);
            }

            $analysis = new ProjectAnalyzer()->analyze(
                $projectRoot,
                $onProgress ?? static fn (string $event, array $data): null => null,
            );
        } finally {
            foreach ($snapshot as $key => $original) {
                config()->set($key, $original);
            }
        }

        $phaseStart = $this->emitPhase($onProgress, 'brain-analyze', $phaseStart);

        // One FQCN-keyed id per symbol, read from Brain's own node data — the anti-corruption boundary
        // that lets the post-hoc tracers below address symbols by plain FQCN and join the route chain.
        // The same pass keeps each node's annotation (file/line, route uri, security surface), merged
        // field-wise when two Brain nodes normalise onto one canonical id.
        $canonical = [];
        $metadata = [];

        foreach ($analysis->fullGraph->nodes() as $node) {
            $id = NodeNormalizer::canonicalId($node->id, $node->data);
            $canonical[$node->id] = $id;
            $nodeMetadata = NodeMetadata::fromBrainNodeData($node->data, $projectRoot);

            if ($nodeMetadata !== null) {
                $metadata[$id] = isset($metadata[$id]) ? NodeMetadata::merge($metadata[$id], $nodeMetadata) : $nodeMetadata;
            }
        }

        /** @var list<array{source: string, target: string, type: string}> $edges */
        $edges = [];
        $routeMiddlewareEdges = [];

        /** @var Edge $edge */
        foreach ($analysis->fullGraph->edges() as $edge) {
            // Pennant gates live in the RAW middleware id (`middleware::X:flag`) — the canonical
            // mapping below rewrites it onto the bare FQCN (the node's own `fqcn` carries no
            // params), so the flags must be read before that happens.
            if (str_starts_with($edge->source, 'route::') && str_starts_with($edge->target, 'middleware::')) {
                $routeMiddlewareEdges[] = ['source' => $edge->source, 'target' => $edge->target, 'type' => $edge->type];
            }

            $edges[] = [
                'source' => $canonical[$edge->source] ?? $edge->source,
                'target' => $canonical[$edge->target] ?? $edge->target,
                'type' => $edge->type,
            ];
        }

        $phaseStart = $this->emitPhase($onProgress, 'canonicalize-metadata', $phaseStart);

        // Branch B: richter's own source-tracer edges. The concurrent worker's result when it ran
        // and succeeded, else in-process (serial / profiling / fallback). Returned in the same order
        // build() appends them serially, so the merged graph is byte-identical either way (plan 050).
        $tracerBranch = ($pending instanceof PendingTracerBranch ? TracerBranchRunner::finish($pending) : null)
            ?? $this->buildTracerBranch($projectRoot, $onProgress);

        foreach ($tracerBranch['edges'] as $tracerEdge) {
            $edges[] = $tracerEdge;
        }

        $phaseStart = $onProgress !== null ? (float) hrtime(true) : 0.0;

        // One hop past what richter's own edges placed. Must run BEFORE the rewrites and the member
        // passes below: its edges have to reach declaresEdges() and, above all, inheritedEdgesFor(),
        // which is what turns a newly-read body into a connection to an inherited method's work.
        // A tracer of its own: `traceMembers()` addresses methods by FQCN and never reads the
        // configured roots, so the branch's instance has nothing to hand over.
        $secondHop = new SecondHopWalk(new EntryPointTracer()->traceMembers(...), RichterConfig::secondHopEnabled())
            ->edgesFor($edges, $projectRoot);

        foreach ($secondHop['edges'] as $secondHopEdge) {
            $edges[] = $secondHopEdge;
        }

        $phaseStart = $this->emitPhase($onProgress, 'second-hop-walk', $phaseStart, [
            'edges' => count($secondHop['edges']),
            'unread' => $secondHop['unread'],
        ]);

        $controllerBasenames = $this->controllerBasenames($projectRoot);
        $middlewareAliases = MiddlewareAliases::forProject($projectRoot);
        $metadata = NodeMetadata::withRouteGates($routeMiddlewareEdges, $metadata, $middlewareAliases);
        $edges = self::resolveShortControllerIds($edges, $controllerBasenames);
        $edges = self::resolveMiddlewareAliases($edges, $middlewareAliases);
        // The rewrites rename node ids in the edges; the metadata keys must follow or the
        // annotation would dangle on ids the graph no longer contains.
        $metadata = NodeMetadata::remapKeys($metadata, self::shortControllerIdResolver($controllerBasenames));
        $metadata = NodeMetadata::remapKeys($metadata, self::middlewareAliasResolver($middlewareAliases));

        foreach ($this->memberDeclarationEdges($edges, $projectRoot, $tracerBranch['declares']) as $memberEdge) {
            $edges[] = $memberEdge;
        }

        foreach (self::declaresEdges($edges) as $declaresEdge) {
            $edges[] = $declaresEdge;
        }

        // Last, over the whole merged set: a method a class inherits without overriding runs in the
        // parent, but every call resolves against the receiver's static type and lands on the
        // subclass node. Drawn only for member nodes something already references.
        foreach (ClassHierarchyTracer::inheritedEdgesFor($tracerBranch['inheritance'], $edges) as $inheritedEdge) {
            $edges[] = $inheritedEdge;
        }

        $edges = AppFiles::dedupeEdges($edges, byType: true);

        $graph = new CodeGraph(
            $edges,
            $tracerBranch['unparseableFiles'] > 0,
            $tracerBranch['unresolvedDispatchSites'],
            NodeMetadata::withFallbackFiles($edges, $metadata, $projectRoot),
        );

        $this->emitPhase($onProgress, 'rewrites-and-members', $phaseStart);

        return $graph;
    }

    /**
     * Branch B — richter's own source-tracer edges (the dispatch/policy/reference/interface edges
     * from the consolidated AST pass, the queue/console/helper entry points, and the blade views)
     * that Brain's route-anchored analysis misses. Data-independent from Brain's analyze(), so
     * build() runs it concurrently; the {@see InternalTracerBranchCommand}
     * worker runs exactly this method in a child process (plan 050). Edges are returned in the same
     * order build() appends them serially, keeping the merged graph byte-identical either way.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $onProgress
     * @return array{edges: list<array{source: string, target: string, type: string}>, unparseableFiles: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, inheritance: array<string, array{parent: string|null, declared: list<string>}>, declares: array<string, list<array{source: string, target: string, type: string}>>}
     */
    public function buildTracerBranch(string $projectRoot, ?callable $onProgress = null): array
    {
        $phaseStart = $onProgress !== null ? (float) hrtime(true) : 0.0;

        // One instance serves both passes below: the consolidated pass reads its roots to decide
        // which ASTs to retain, and trace() consumes them — same instance, so they can never diverge.
        $entryPointTracer = new EntryPointTracer(RichterConfig::entryPointRoots());

        // One consolidated AST pass feeds the dispatch/policy/reference/interface tracers — each used
        // to re-parse the whole app tree itself, which cost ~30-60s per tracer per build.
        $consolidated = $this->consolidatedTracerEdges($projectRoot, $entryPointTracer);
        $edges = $consolidated['edges'];

        $phaseStart = $this->emitPhase($onProgress, 'consolidated-tracers', $phaseStart);

        // Brain's graph is route-anchored; add queue/console/helper entry points (+ `$listen`
        // event→listener and interface→impl links) Brain misses. Tracer edges are FQCN-keyed, so they
        // join the normalised nodes above directly.
        foreach ($entryPointTracer->trace($projectRoot, $consolidated['entryPointAsts']) as $entryPointEdge) {
            $edges[] = $entryPointEdge;
        }

        $phaseStart = $this->emitPhase($onProgress, 'entry-point-tracer', $phaseStart);

        // Descend into the views a view renders (`<x-...>`, `@include`/`@extends`) and link the
        // policies views gate on — both surfaces Brain's route-anchored graph misses.
        foreach (new BladeViewTracer()->trace($projectRoot) as $viewEdge) {
            $edges[] = $viewEdge;
        }

        foreach (new PolicyEdgeTracer()->bladeEdges($projectRoot) as $bladePolicyEdge) {
            $edges[] = $bladePolicyEdge;
        }

        $this->emitPhase($onProgress, 'blade-tracers', $phaseStart);

        return [
            'edges' => $edges,
            'unparseableFiles' => $consolidated['unparseableFiles'],
            'unresolvedDispatchSites' => $consolidated['unresolvedDispatchSites'],
            'inheritance' => $consolidated['inheritance'],
            'declares' => $consolidated['declares'],
        ];
    }

    /**
     * Emits one `richter:phase` timing event and returns the next phase's start timestamp — or,
     * when nobody is listening, does nothing and hands the same (unused) timestamp straight back.
     * Centralised so build()'s six call sites share one branch instead of each carrying their own.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $onProgress
     */
    /** @param  array<string, int>  $extra  phase-specific counters, for a phase whose seconds alone don't explain it */
    private function emitPhase(?callable $onProgress, string $phase, float $phaseStart, array $extra = []): float
    {
        if ($onProgress === null) {
            return $phaseStart;
        }

        $onProgress('richter:phase', ['phase' => $phase, 'seconds' => (hrtime(true) - $phaseStart) / 1e9, ...$extra]);

        return (float) hrtime(true);
    }

    /**
     * One parse + name-resolution + node collection per app file, shared by every AST-walking
     * tracer ({@see collectTracerNodes()}). The tracers' own `edgesForSource()` fronts
     * (parse-per-call) stay for tests and single-file use.
     *
     * Also retains the resolved ASTs of the entry-point-root files plus EventServiceProvider.php —
     * the bounded subset {@see EntryPointTracer::trace()} would otherwise re-parse itself — keyed by
     * absolute path. Only that subset is kept: retaining every AST would trade the parse win for a
     * memory blow-up on large apps.
     *
     * Two independent counts, per plan 036: `unparseableFiles` (S1 — a file the parser could not
     * read at all; unknown content could hide any edge, so this stays a GLOBAL determinability
     * blocker) and `unresolvedDispatchSites` (S2 — a bus dispatch whose target could not be resolved
     * statically; the target is still bounded to "a dispatchable", so this is change-scopeable).
     * Conflating the two (as pre-036 code did) would make an unrelated unparseable file's taint
     * masquerade as a scopeable dispatch signal — see plan 036 "Why v1 was unsound".
     *
     * @return array{edges: list<array{source: string, target: string, type: string}>, unparseableFiles: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, entryPointAsts: array<string, list<Node\Stmt>>, inheritance: array<string, array{parent: string|null, declared: list<string>}>, declares: array<string, list<array{source: string, target: string, type: string}>>}
     */
    private function consolidatedTracerEdges(string $projectRoot, EntryPointTracer $entryPointTracer): array
    {
        $dispatchTracer = new DispatchEdgeTracer(RichterConfig::dispatchHelpers());
        $policyTracer = new PolicyEdgeTracer();
        $referenceTracer = new ReferenceEdgeTracer();
        $staticCallTracer = new StaticCallEdgeTracer();
        // CHA (plan cha-wire): accumulates class-likes across the whole loop below and flushes its
        // ancestor→override edges once after it — the inverse subclass/implementor map spans files.
        $hierarchyTracer = new ClassHierarchyTracer();
        // Constant/enum-case member references (plan cref-wire): same accumulate-then-flush shape —
        // reads resolve to the constant's declaring class, which needs the full hierarchy.
        $constantTracer = new ConstantReferenceTracer();
        // Facade → concrete resolution: likewise cross-file, and it reads the static-call edges the
        // loop below emits, so it can only run once all of them exist.
        $facadeTracer = new FacadeEdgeTracer();
        // config/*.php scanned once up front: the registries are the same for every app file, and a
        // per-file rescan of the config directory would be the tracer's whole cost.
        $configTracer = new ConfigRegistryTracer($projectRoot);
        // Renders a class writes out by name — the lane that places a Livewire/Filament view, whose
        // component Brain's route-anchored walk never reaches.
        $viewTracer = new ViewRenderTracer($projectRoot);

        // The paths whose ASTs trace() consumes: files under the tracer's own roots.
        $retainPrefixes = array_map(
            static fn (string $root): string => "{$projectRoot}/app/{$root}/",
            $entryPointTracer->roots(),
        );

        $edges = [];
        $entryPointAsts = [];
        $declaresByFqcn = [];
        $unparseableFiles = 0;
        $unresolvedDispatchSites = [];

        foreach (AppFiles::phpClasses($projectRoot . '/app', $projectRoot) as $class) {
            $ast = AppFiles::parseResolved((string) file_get_contents($class['path']));

            if ($ast === null) {
                // A file the graph cannot read has no edges of its own and could reach anything —
                // "could-be-anything" taint (S1). Unlike S2 below, its unknown target is NOT bounded
                // to a dispatchable, so this can never be scoped to a change — it stays global.
                ++$unparseableFiles;

                continue;
            }

            if (array_any($retainPrefixes, static fn (string $prefix): bool => str_starts_with($class['path'], $prefix))) {
                $entryPointAsts[$class['path']] = $ast;
            }

            $nodes = $this->collectTracerNodes($ast);
            // The declares edges this file's classes contribute, derived from the AST already in
            // hand. {@see memberDeclarationEdges()} would otherwise re-read and re-parse every one
            // of these files a second time, after this loop has just parsed them all.
            $declaresByFqcn[$class['fqcn']] = $this->declaredMemberEdgesFrom($nodes['classLikes'], $class['fqcn']);
            $hierarchyTracer->collect($nodes['classLikes']);
            $constantTracer->collect($nodes['classLikes']);
            $facadeTracer->collect($nodes['classLikes']);

            // Dispatchers → jobs incl. configured custom helpers + the unresolved-dispatch signal
            // (a variable dispatch must make a job read "unknown", not "none"). The target is
            // bounded to "a dispatchable" (S2), so unlike S1 above this IS change-scopeable.
            $dispatch = $dispatchTracer->edgesForMethods($nodes['classMethods'], $class['fqcn']);

            // The tracer knows the dispatching member and the line; only this loop knows the file, so
            // it stamps the project-relative path the reports print everywhere else.
            $relativePath = substr($class['path'], strlen($projectRoot) + 1);

            foreach ($dispatch['unresolvedSites'] as $site) {
                $unresolvedDispatchSites[] = ['file' => $relativePath, ...$site];
            }

            array_push($edges, ...$dispatch['edges']);
            array_push($edges, ...$policyTracer->edgesForMethods($nodes['classMethods'], $class['fqcn']));
            array_push($edges, ...$referenceTracer->edgesForNodes($nodes['classMethods'], $nodes['traitUses'], $class['fqcn']));
            array_push($edges, ...$entryPointTracer->interfaceEdgesForClassLikes($nodes['classLikes'], $class['fqcn']));
            array_push($edges, ...$staticCallTracer->edgesForClassLikes($nodes['classLikes']));
            array_push($edges, ...$configTracer->edgesForClassLikes($nodes['classLikes']));
            array_push($edges, ...$viewTracer->edgesForClassLikes($nodes['classLikes']));
        }

        // CHA override edges (ancestor::m → concrete::m). Emitted once here, after every file's
        // class-likes have been collected, because the subclass/implementor map is cross-file.
        array_push($edges, ...$hierarchyTracer->overrideEdges());

        // Constant/enum-case member nodes + reader edges — likewise flushed after the loop, because
        // declaring-class resolution spans files.
        array_push($edges, ...$constantTracer->edges());

        // Facade members onto the concretes their accessors name. Reads the static-call edges above,
        // so it goes last: a call through a facade is drawn to the facade, and this is the hop that
        // carries it to the code that runs.
        array_push($edges, ...$facadeTracer->resolutionEdges($edges));

        // Sorted before it leaves the branch: a report that names sites must not reorder between
        // runs, and the cached payload has to be byte-stable or the fingerprint starts flapping.
        usort(
            $unresolvedDispatchSites,
            static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['dispatcher']] <=> [$b['file'], $b['line'], $b['dispatcher']],
        );

        return [
            'edges' => AppFiles::dedupeEdges($edges, byType: true),
            'unparseableFiles' => $unparseableFiles,
            'unresolvedDispatchSites' => $unresolvedDispatchSites,
            'entryPointAsts' => $entryPointAsts,
            // Carried out rather than consumed here: the inherited-method pass is edge-set-driven and
            // the full set only exists in build(), after Brain's branch merges in — a controller that
            // INHERITS its action reaches its member node through Brain, not through this branch.
            'inheritance' => $hierarchyTracer->inheritanceMap(),
            'declares' => $declaresByFqcn,
        ];
    }

    /**
     * The node buckets the consolidated tracers consume, collected in one descent of the file's AST.
     * Each tracer used to run its own NodeFinder walk over the same tree — five full descents per
     * file (three ClassMethod, one TraitUse, one ClassLike) where one suffices. Bucket contents match
     * what findInstanceOf() returned: every instance at any depth (anonymous classes included), in
     * document order.
     *
     * @param  list<Node\Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return array{classMethods: list<ClassMethod>, traitUses: list<TraitUse>, classLikes: list<ClassLike>}
     */
    private function collectTracerNodes(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<ClassMethod> */
            public array $classMethods = [];

            /** @var list<TraitUse> */
            public array $traitUses = [];

            /** @var list<ClassLike> */
            public array $classLikes = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof ClassMethod) {
                    $this->classMethods[] = $node;
                } elseif ($node instanceof TraitUse) {
                    $this->traitUses[] = $node;
                } elseif ($node instanceof ClassLike) {
                    $this->classLikes[] = $node;
                }

                return null;
            }
        };

        new NodeTraverser($visitor)->traverse($ast);

        return ['classMethods' => $visitor->classMethods, 'traitUses' => $visitor->traitUses, 'classLikes' => $visitor->classLikes];
    }

    /**
     * Alias-registered route middleware (`'auth' => Authenticate::class` in the Kernel) reaches the
     * graph as a `middleware::auth` node that no FQCN seed can join — a changed middleware then
     * self-lists instead of reaching the routes it guards. Rewriting the alias node onto the FQCN
     * joins the chain. Group aliases (`web`, `api`) are deliberately NOT expanded: mapping a global
     * group onto every stack class would flood each of its middleware with every route as an entry
     * point — the self-listing already communicates "runs on every request".
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, string>  $aliasToFqcn
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function resolveMiddlewareAliases(array $edges, array $aliasToFqcn): array
    {
        $resolve = self::middlewareAliasResolver($aliasToFqcn);

        return array_map(static fn (array $edge): array => [
            'source' => $resolve($edge['source']),
            'target' => $resolve($edge['target']),
            'type' => $edge['type'],
        ], $edges);
    }

    /**
     * @param  array<string, string>  $aliasToFqcn
     * @return Closure(string):string
     */
    private static function middlewareAliasResolver(array $aliasToFqcn): Closure
    {
        return static function (string $node) use ($aliasToFqcn): string {
            // `middleware::throttle:api` carries parameters — the alias is the part before the colon.
            if (preg_match('/^middleware::([\w.\-]+)(?::.*)?$/', $node, $matches) !== 1) {
                return $node;
            }

            return $aliasToFqcn[$matches[1]] ?? $node;
        };
    }

    /**
     * For every class the graph references at class level (`new Job(...)`, `$user->can(Policy::X)`),
     * parse the class and declare its methods as member nodes. Callers land on the class node while a
     * changed method seeds its member node — without these edges the two never join, so a change to
     * e.g. a policy method falsely reads as unplaceable. Scoped to classes with actual reach: a class
     * no edge references stays out, so genuine coverage gaps still read UNRESOLVED, not "no impact".
     * Complements {@see declaresEdges}, which covers member nodes that appear in edges without their
     * class ever being referenced class-level — the overlap between the two is deduped downstream.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, list<array{source: string, target: string, type: string}>>  $parsed  the
     *   declares edges the tracer branch already derived, keyed by FQCN — a lookup here rather than a
     *   second parse of every app class file
     * @return list<array{source: string, target: string, type: string}>
     */
    private function memberDeclarationEdges(array $edges, string $projectRoot, array $parsed): array
    {
        $declares = [];

        foreach ($edges as $edge) {
            foreach ([$edge['source'], $edge['target']] as $node) {
                if (! AppNamespace::isAppClass($node)) {
                    continue;
                }

                if (isset($declares[$node])) {
                    continue;
                }

                if (isset($parsed[$node])) {
                    $declares[$node] = $parsed[$node];

                    continue;
                }

                // Not a file the tracer branch walked — an app-namespaced id whose file lives outside
                // `app/`, or one it could not parse. Reading it here keeps the fallback honest rather
                // than silently dropping a class's members.
                $file = $projectRoot . '/app/' . AppNamespace::relativePath($node) . '.php';
                $declares[$node] = is_file($file)
                    ? self::declaredMemberEdges((string) file_get_contents($file), $node)
                    : [];
            }
        }

        $memberEdges = array_values($declares);

        return $memberEdges === [] ? [] : array_merge(...$memberEdges);
    }

    /**
     * {@see declaredMemberEdges()} against class-likes already parsed, instead of source that would
     * have to be parsed again. Same contract deliberately, including the quirk it inherits from
     * {@see MemberResolver::resolve()}: every class-like in the file contributes, all attributed to
     * the file's own FQCN, so a second class in one file lends its methods to the first.
     *
     * @param  list<ClassLike>  $classLikes
     * @return list<array{source: string, target: string, type: string}>
     */
    private function declaredMemberEdgesFrom(array $classLikes, string $fqcn): array
    {
        $edges = [];

        foreach ($classLikes as $classLike) {
            foreach ($classLike->getMethods() as $method) {
                $edges[] = ['source' => $fqcn, 'target' => $fqcn . '::' . $method->name->toString(), 'type' => 'declares'];
            }
        }

        return $edges;
    }

    /**
     * Rewrite the under-qualified controller ids Brain emits for a string-form route action onto the
     * FQCN scheme the seeds use, so such a controller joins its route chain instead of reading
     * UNRESOLVED. Two shapes arrive, and both come from `'FooController@bar'` rather than
     * `[FooController::class, 'bar']`:
     *
     * - a short id (`action::SocialAuthController::login`), where Brain resolved no namespace at all;
     * - a partially qualified one (`Post\ReviewController`), where a `->namespace('Post')` group was
     *   applied without the root the provider adds. It is FQCN-shaped, so it survives
     *   {@see NodeNormalizer::canonicalId()} as a node of its own — a phantom beside the real class,
     *   reached from the route while every code edge hangs off the class the route never reaches.
     *   Nothing reads as broken: both nodes exist, and the chain between them is simply cut.
     *
     * A candidate must be unambiguous either way, and for the partial shape it must extend the id on
     * a namespace boundary. An id that already names a controller is left alone outright: a deeper
     * class can nest another's whole path, so the boundary test alone would move a route off the
     * class it had correctly reached. What it cannot rule out is a non-controller class that is a
     * namespace-suffix of exactly one controller — the map holds controllers only, so there is
     * nothing here to recognise it by.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, list<string>>  $basenameToFqcns
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function resolveShortControllerIds(array $edges, array $basenameToFqcns): array
    {
        $resolve = self::shortControllerIdResolver($basenameToFqcns);

        return array_map(static fn (array $edge): array => [
            'source' => $resolve($edge['source']),
            'target' => $resolve($edge['target']),
            'type' => $edge['type'],
        ], $edges);
    }

    /**
     * @param  array<string, list<string>>  $basenameToFqcns
     * @return Closure(string):string
     */
    private static function shortControllerIdResolver(array $basenameToFqcns): Closure
    {
        return static function (string $node) use ($basenameToFqcns): string {
            if (preg_match('/^(?:(?:controller|action)::([A-Za-z_]\w*)|([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+))(?:::(\w+))?$/', $node, $matches) !== 1) {
                return $node;
            }

            $partial = $matches[2] ?? '';
            $basename = $partial === '' ? ($matches[1] ?? '') : substr($partial, (int) strrpos($partial, '\\') + 1);
            $candidates = $basenameToFqcns[$basename] ?? [];

            // The partial shape narrows on the namespace it did carry, so a duplicated basename still
            // resolves when only one of its candidates ends that way — which is the common case, since
            // the missing part is a shared root and the part that survived is what tells them apart.
            if ($partial !== '') {
                // An id already naming a controller is resolved, and a deeper class can still nest its
                // whole path (`…\Api\App\Http\Controllers\PostController`): without this, the suffix
                // filter would move a route off the class it correctly reached.
                if (in_array($partial, $candidates, true)) {
                    return $node;
                }

                $candidates = array_values(array_filter(
                    $candidates,
                    static fn (string $candidate): bool => str_ends_with($candidate, '\\' . $partial),
                ));
            }

            if (count($candidates) !== 1) {
                return $node;
            }

            $method = $matches[3] ?? null;

            // A short id can also denote a routed class Brain failed to resolve *outside* the map
            // (a vendor controller sharing the basename) — requiring the method to actually exist on
            // the candidate stops grafting a foreign route chain onto the wrong class.
            if ($method !== null && ! method_exists($candidates[0], $method)) {
                return $node;
            }

            return $candidates[0] . ($method !== null ? "::{$method}" : '');
        };
    }

    /** @return array<string, list<string>> controller class basename → candidate FQCNs */
    private function controllerBasenames(string $projectRoot): array
    {
        $map = [];

        foreach (AppFiles::phpClasses($projectRoot . '/app/Http/Controllers', $projectRoot) as $class) {
            $basename = substr($class['fqcn'], (int) strrpos($class['fqcn'], '\\') + 1);
            $map[$basename][] = $class['fqcn'];
        }

        return $map;
    }

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function declaredMemberEdges(string $source, string $fqcn): array
    {
        $resolved = MemberResolver::resolve($source);
        $edges = [];

        foreach ($resolved['members'] as $member) {
            if ($member['kind'] === MemberChange::KIND_METHOD) {
                $edges[] = ['source' => $fqcn, 'target' => "{$fqcn}::{$member['name']}", 'type' => 'declares'];
            }
        }

        return $edges;
    }

    /**
     * Link every member node to its declaring class (`App\X → App\X::method`). Callers mostly
     * reference the class node (`new Job(...)`, `$user->can(Policy::ABILITY, …)`) while a changed
     * method seeds its member node — without this edge, `callersOf` a changed member walks past its
     * own class's callers and the change falsely reads as unreached. Excluded from risk counting in
     * {@see ImpactAnalyzer} (declaration is association, not invocation).
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function declaresEdges(array $edges): array
    {
        $declares = [];

        foreach ($edges as $edge) {
            foreach ([$edge['source'], $edge['target']] as $node) {
                $declaringClass = AppNamespace::declaringClassOf($node);

                if ($declaringClass !== null) {
                    $declares[$node] = ['source' => $declaringClass, 'target' => $node, 'type' => 'declares'];
                }
            }
        }

        return array_values($declares);
    }
}
