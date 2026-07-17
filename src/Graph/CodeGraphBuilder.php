<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Edge;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Changes\MemberResolver;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tracers\BladeViewTracer;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;
use SanderMuller\Richter\Tracers\EntryPointTracer;
use SanderMuller\Richter\Tracers\PolicyEdgeTracer;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

/**
 * Builds a {@see CodeGraph} from the live codebase using Laravel Brain's static analysis. Widens
 * Brain's default route/command globs (which only match `{dir}/{file}.php`) to also cover route and
 * command files directly under `routes/` and `app/Console/Commands/`, plus one nesting level. Dev/CI only.
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

        // The override must not outlive the build: the process may be a long-lived MCP server whose
        // global config repository the host app shares. Only analyze() reads these keys.
        $overrides = [
            'laravel-brain.route_paths' => self::ROUTE_PATHS,
            'laravel-brain.channel_paths' => self::ROUTE_PATHS,
            'laravel-brain.commands.console_route_paths' => self::ROUTE_PATHS,
            'laravel-brain.commands.class_paths' => self::COMMAND_CLASS_PATHS,
        ];
        $snapshot = array_map(config(...), array_combine(array_keys($overrides), array_keys($overrides)));

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

        // One FQCN-keyed id per symbol, read from Brain's own node data — the anti-corruption boundary
        // that lets the post-hoc tracers below address symbols by plain FQCN and join the route chain.
        $canonical = [];

        foreach ($analysis->fullGraph->nodes() as $node) {
            $canonical[$node->id] = NodeNormalizer::canonicalId($node->id, $node->data);
        }

        /** @var list<array{source: string, target: string, type: string}> $edges */
        $edges = [];

        /** @var Edge $edge */
        foreach ($analysis->fullGraph->edges() as $edge) {
            $edges[] = [
                'source' => $canonical[$edge->source] ?? $edge->source,
                'target' => $canonical[$edge->target] ?? $edge->target,
                'type' => $edge->type,
            ];
        }

        // Brain's graph is route-anchored; add queue/console/helper entry points (+ `$listen`
        // event→listener and interface→impl links) Brain misses. Tracer edges are FQCN-keyed, so they
        // join the normalised nodes above directly.
        foreach (new EntryPointTracer(RichterConfig::entryPointRoots())->trace($projectRoot) as $entryPointEdge) {
            $edges[] = $entryPointEdge;
        }

        // One consolidated AST pass feeds the dispatch/policy/reference/interface tracers — each used
        // to re-parse the whole app tree itself, which cost ~30-60s per tracer per build.
        $consolidated = $this->consolidatedTracerEdges($projectRoot);

        foreach ($consolidated['edges'] as $tracerEdge) {
            $edges[] = $tracerEdge;
        }

        // Descend into the views a view renders (`<x-...>`, `@include`/`@extends`) and link the
        // policies views gate on — both surfaces Brain's route-anchored graph misses.
        foreach (new BladeViewTracer()->trace($projectRoot) as $viewEdge) {
            $edges[] = $viewEdge;
        }

        foreach (new PolicyEdgeTracer()->bladeEdges($projectRoot) as $bladePolicyEdge) {
            $edges[] = $bladePolicyEdge;
        }

        $edges = self::resolveShortControllerIds($edges, $this->controllerBasenames($projectRoot));
        $edges = self::resolveMiddlewareAliases($edges, self::middlewareAliasMap($this->kernelSource($projectRoot)));

        foreach ($this->memberDeclarationEdges($edges, $projectRoot) as $memberEdge) {
            $edges[] = $memberEdge;
        }

        foreach (self::declaresEdges($edges) as $declaresEdge) {
            $edges[] = $declaresEdge;
        }

        return new CodeGraph(AppFiles::dedupeEdges($edges, byType: true), $consolidated['unresolvedDispatches'] > 0);
    }

    /**
     * One parse + name-resolution per app file, shared by every AST-walking tracer. The tracers'
     * own `edgesForSource()` fronts (parse-per-call) stay for tests and single-file use.
     *
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedDispatches: int}
     */
    private function consolidatedTracerEdges(string $projectRoot): array
    {
        $dispatchTracer = new DispatchEdgeTracer(RichterConfig::dispatchHelpers());
        $policyTracer = new PolicyEdgeTracer();
        $referenceTracer = new ReferenceEdgeTracer();
        // Roots deliberately unconfigured: only interfaceEdgesForResolvedAst() is used here, which ignores them.
        $entryPointTracer = new EntryPointTracer();

        $edges = [];
        $unresolved = 0;

        foreach (AppFiles::phpClasses($projectRoot . '/app', $projectRoot) as $class) {
            $ast = AppFiles::parseResolved((string) file_get_contents($class['path']));

            if ($ast === null) {
                // A file the graph cannot read is an unfollowable dispatch surface by definition —
                // counting it keeps the unresolved-dispatch honesty (a job whose only dispatcher
                // lives in this file must read "unknown", not "none").
                ++$unresolved;

                continue;
            }

            // Dispatchers → jobs incl. configured custom helpers + the unresolved-dispatch signal
            // (a variable dispatch must make a job read "unknown", not "none").
            $dispatch = $dispatchTracer->edgesForResolvedAst($ast, $class['fqcn']);
            $unresolved += $dispatch['unresolved'];

            array_push($edges, ...$dispatch['edges']);
            array_push($edges, ...$policyTracer->edgesForResolvedAst($ast, $class['fqcn']));
            array_push($edges, ...$referenceTracer->edgesForResolvedAst($ast, $class['fqcn']));
            array_push($edges, ...$entryPointTracer->interfaceEdgesForResolvedAst($ast, $class['fqcn']));
        }

        return ['edges' => AppFiles::dedupeEdges($edges, byType: true), 'unresolvedDispatches' => $unresolved];
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
        $resolve = static function (string $node) use ($aliasToFqcn): string {
            // `middleware::throttle:api` carries parameters — the alias is the part before the colon.
            if (preg_match('/^middleware::([\w.\-]+)(?::.*)?$/', $node, $matches) !== 1) {
                return $node;
            }

            return $aliasToFqcn[$matches[1]] ?? $node;
        };

        return array_map(static fn (array $edge): array => [
            'source' => $resolve($edge['source']),
            'target' => $resolve($edge['target']),
            'type' => $edge['type'],
        ], $edges);
    }

    /**
     * The Kernel's `$middlewareAliases` map, alias → middleware FQCN. The legacy `$routeMiddleware`
     * property name is deliberately not read — this Kernel uses `$middlewareAliases`, and a dead
     * branch for a name the codebase never uses is speculative generality.
     *
     * @return array<string, string>
     */
    public static function middlewareAliasMap(string $kernelSource): array
    {
        $ast = AppFiles::parseResolved($kernelSource);

        if ($ast === null) {
            return [];
        }

        $map = [];

        foreach (new NodeFinder()->findInstanceOf($ast, Property::class) as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== 'middlewareAliases') {
                    continue;
                }

                if (! $prop->default instanceof Array_) {
                    continue;
                }

                foreach ($prop->default->items as $item) {
                    if (! $item->key instanceof String_) {
                        continue;
                    }

                    if ($item->value instanceof ClassConstFetch && $item->value->class instanceof Name) {
                        $map[$item->key->value] = AppFiles::resolveName($item->value->class);
                    } elseif ($item->value instanceof String_) {
                        // Laravel also accepts a class-string literal as the alias value.
                        $map[$item->key->value] = ltrim($item->value->value, '\\');
                    }
                }
            }
        }

        return $map;
    }

    private function kernelSource(string $projectRoot): string
    {
        $kernel = $projectRoot . '/app/Http/Kernel.php';

        return is_file($kernel) ? (string) file_get_contents($kernel) : '';
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
     * @return list<array{source: string, target: string, type: string}>
     */
    private function memberDeclarationEdges(array $edges, string $projectRoot): array
    {
        $declares = [];

        foreach ($edges as $edge) {
            foreach ([$edge['source'], $edge['target']] as $node) {
                if (preg_match('/^App\\\\[\w\\\\]+$/', $node) !== 1) {
                    continue;
                }

                if (isset($declares[$node])) {
                    continue;
                }

                $file = $projectRoot . '/app/' . str_replace('\\', '/', substr($node, strlen('App\\'))) . '.php';
                $declares[$node] = is_file($file)
                    ? self::declaredMemberEdges((string) file_get_contents($file), $node)
                    : [];
            }
        }

        $memberEdges = array_values($declares);

        return $memberEdges === [] ? [] : array_merge(...$memberEdges);
    }

    /**
     * Rewrite Brain's short controller/action ids (`action::SocialAuthController::login` — emitted
     * when Brain couldn't resolve the FQCN) onto the FQCN scheme the seeds use, so a change to such
     * a controller joins its route chain instead of reading UNRESOLVED. Only a basename with exactly
     * one candidate FQCN rewrites — an ambiguous short id (five controller basenames are duplicated)
     * stays verbatim rather than claiming the wrong class.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, list<string>>  $basenameToFqcns
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function resolveShortControllerIds(array $edges, array $basenameToFqcns): array
    {
        $resolve = static function (string $node) use ($basenameToFqcns): string {
            if (preg_match('/^(?:controller|action)::([A-Za-z_]\w*)(?:::(\w+))?$/', $node, $matches) !== 1) {
                return $node;
            }

            $candidates = $basenameToFqcns[$matches[1]] ?? [];

            if (count($candidates) !== 1) {
                return $node;
            }

            $method = $matches[2] ?? null;

            // A short id can also denote a routed class Brain failed to resolve *outside* the map
            // (a vendor controller sharing the basename) — requiring the method to actually exist on
            // the candidate stops grafting a foreign route chain onto the wrong class.
            if ($method !== null && ! method_exists($candidates[0], $method)) {
                return $node;
            }

            return $candidates[0] . ($method !== null ? "::{$method}" : '');
        };

        return array_map(static fn (array $edge): array => [
            'source' => $resolve($edge['source']),
            'target' => $resolve($edge['target']),
            'type' => $edge['type'],
        ], $edges);
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
                if (preg_match('/^(App\\\\[\w\\\\]+)::\w+$/', $node, $matches) === 1) {
                    $declares[$node] = ['source' => $matches[1], 'target' => $node, 'type' => 'declares'];
                }
            }
        }

        return array_values($declares);
    }
}
