<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use Illuminate\Support\Facades\Bus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\DispatchTarget;

/**
 * Brain resolves the standard dispatch forms — `Job::dispatch()`, `dispatch()`, and, since v2.3.1, the
 * `Bus` facade, `$this->dispatch(...)`, and `dispatch_sync()` — but only ever emits the *resolved* edge.
 * It never covers project-custom dispatch helper functions (configured via `richter.dispatch_helpers`),
 * nor flags a dispatch whose job can't be seen statically. This tracer fills both gaps: it emits
 * `action-to-job` edges (FQCN-keyed, joining the normalised graph) and records an unresolved-dispatch
 * signal for a variable or factory-call argument, so such a job reads as "unknown", not "none".
 * An inline closure and a string literal are deliberately NOT that signal — see {@see dispatchSite()}.
 * Dev/CI tooling only.
 *
 * A resolved target is recognised via the shared {@see DispatchTarget} predicate (plan 043), so the
 * `action-to-job` edge is drawn for every dispatch-target shape — a queued job, a `Dispatchable`
 * command, or a plain self-handling `handle()`/`__invoke()` command — not only `\Jobs\`/ShouldQueue
 * jobs. The `action-to-job` type string is a stable internal label; a command dispatch is
 * risk-bearing exactly like a job dispatch, so the name is kept despite now covering commands.
 */
final readonly class DispatchEdgeTracer
{
    // `dispatch` / `dispatch_sync` stay though Brain resolves them (v2.3.1): only this tracer counts an
    // unfollowable dispatch (see class docblock).
    private const array DISPATCH_FUNCTIONS = ['dispatch', 'dispatch_sync'];

    private const array DISPATCH_STATICS = ['dispatch', 'dispatchSync', 'dispatchNow', 'dispatchIf', 'dispatchUnless', 'dispatchAfterResponse'];

    private const array BUS_SINGLE = ['dispatch', 'dispatchSync', 'dispatchNow'];

    private const array BUS_GROUP = ['chain', 'batch'];

    /** @var list<string> */
    private array $dispatchFunctions;

    /** @param  list<string>  $dispatchHelpers  project-custom global dispatch helper functions ({@see RichterConfig::dispatchHelpers()}) */
    public function __construct(array $dispatchHelpers = [])
    {
        $this->dispatchFunctions = [...self::DISPATCH_FUNCTIONS, ...$dispatchHelpers];
    }

    /** @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedSites: list<array{line: int, dispatcher: string}>} */
    public function edgesForSource(string $source, string $classFqcn): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return ['edges' => [], 'unresolvedSites' => []];
        }

        return $this->edgesForResolvedAst($ast, $classFqcn);
    }

    /**
     * @param  list<Node\Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedSites: list<array{line: int, dispatcher: string}>}
     */
    public function edgesForResolvedAst(array $ast, string $classFqcn): array
    {
        return $this->edgesForMethods(array_values(new NodeFinder()->findInstanceOf($ast, ClassMethod::class)), $classFqcn);
    }

    /**
     * Bucket-fed variant of {@see edgesForResolvedAst()}: the consolidated loop in
     * {@see CodeGraphBuilder} collects each file's nodes in one descent and hands every tracer its
     * bucket, so no tracer re-walks the full tree.
     *
     * @param  list<ClassMethod>  $classMethods  every ClassMethod in the file, any depth
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedSites: list<array{line: int, dispatcher: string}>}
     */
    public function edgesForMethods(array $classMethods, string $classFqcn): array
    {
        $edges = [];
        $unresolvedSites = [];

        foreach ($classMethods as $method) {
            $dispatcher = ltrim($classFqcn, '\\') . '::' . $method->name->toString();
            // Both lanes below read the same body, so it is descended once. Two NodeFinder passes per
            // method — one for the calls, one for the `new`s — cost a second full walk of every
            // method in the app tree for nodes this one already sees.
            $calls = [];
            $instantiations = [];

            $collector = new class ($calls, $instantiations) extends NodeVisitorAbstract {
                /**
                 * @param  list<Node>  $calls
                 * @param  list<New_>  $instantiations
                 */
                public function __construct(public array &$calls, public array &$instantiations) {}

                public function enterNode(Node $node): null
                {
                    if ($node instanceof FuncCall || $node instanceof MethodCall || $node instanceof StaticCall) {
                        $this->calls[] = $node;
                    } elseif ($node instanceof New_) {
                        $this->instantiations[] = $node;
                    }

                    return null;
                }
            };

            new NodeTraverser($collector)->traverse([$method]);

            // Edges target `::handle` (the method `BusDispatcher::dispatchNow` prefers, falling back
            // to `__invoke` only when `handle` is absent), so an `__invoke`-only self-handling command
            // draws an edge to a `::handle` node that may not exist — a narrow residual, not a
            // regression: before this widening it drew no edge at all, so selection is no worse.
            foreach ($calls as $call) {
                // The whole dispatch statement is the site, not the opaque sub-expression inside it:
                // that is the line a reader opens to see why the target could not be followed, and it
                // keeps two opaque items of one `chain()` from reading as two separate places to look.
                $origin = ['line' => $call->getStartLine(), 'dispatcher' => $dispatcher];

                foreach ($this->jobsFromCall($call, $origin, $unresolvedSites) as $jobFqcn) {
                    $edges[] = ['source' => $dispatcher, 'target' => $jobFqcn . '::handle', 'type' => 'action-to-job'];
                }
            }

            // The instantiation over-approximation exists for two shapes no dispatch-site pattern
            // above can follow: `$job = new X(...); dispatch($job)` (a variable argument), and a
            // dispatch through a helper this tracer doesn't recognise. So an INTRINSIC target (a
            // \Jobs\/ShouldQueue/Dispatchable class, or one that can't be resolved) is linked from a
            // bare `new` unconditionally — that keeps a custom-helper dispatch of a real job caught.
            // A class that matches ONLY because it carries handle()/__invoke() (a shape countless
            // value objects share) is linked only inside a method that actually dispatches; otherwise
            // a `new X(...)` that is merely constructed or returned is object construction, not a
            // dispatch, and must draw no edge — else every DTO-returning method reads as a dispatcher.
            $methodDispatches = array_any(
                $calls,
                fn (Node $call): bool => (! $call instanceof CallLike || ! $call->isFirstClassCallable()) && $this->dispatchSite($call) !== null,
            );

            foreach ($instantiations as $new) {
                if (! $new->class instanceof Name) {
                    continue;
                }

                $job = AppFiles::resolveName($new->class);
                if ($job === ltrim($classFqcn, '\\')) {
                    continue;
                }

                if (! DispatchTarget::matches($job)) {
                    continue;
                }

                if ($methodDispatches || DispatchTarget::isIntrinsicOrUnresolvable($job)) {
                    $edges[] = ['source' => $dispatcher, 'target' => $job . '::handle', 'type' => 'action-to-job'];
                }
            }
        }

        // De-duplicated on the whole record, so a count reads as distinct sites rather than as
        // increments — a `chain()` of two opaque items is one place to look, not two.
        return ['edges' => AppFiles::dedupeEdges($edges), 'unresolvedSites' => $this->dedupeSites($unresolvedSites)];
    }

    /**
     * @param  list<array{line: int, dispatcher: string}>  $sites
     * @return list<array{line: int, dispatcher: string}>
     */
    private function dedupeSites(array $sites): array
    {
        $seen = [];

        foreach ($sites as $site) {
            $seen[$site['dispatcher'] . "\0" . $site['line']] = $site;
        }

        return array_values($seen);
    }

    /**
     * @param  array{line: int, dispatcher: string}  $origin  the dispatch statement a site is recorded against
     * @param  list<array{line: int, dispatcher: string}>  $unresolvedSites
     * @return list<string>
     */
    private function jobsFromCall(Node $call, array $origin, array &$unresolvedSites): array
    {
        // A first-class callable (`Job::dispatch(...)`) builds a closure, not a dispatch — and
        // calling getArgs() on it throws. It's not a dispatch site, so skip it.
        if ($call instanceof CallLike && $call->isFirstClassCallable()) {
            return [];
        }

        $site = $this->dispatchSite($call);

        return match ($site['mode'] ?? null) {
            'single' => $this->jobsFromArg($site['arg'], $origin, $unresolvedSites),
            'array' => $this->jobsFromArray($site['arg']?->value, $origin, $unresolvedSites),
            'class' => DispatchTarget::matches($site['class']) ? [$site['class']] : [],
            default => [],
        };
    }

    /**
     * Classify a call as a dispatch shape, or null when it isn't one.
     *
     * @return array{mode: 'single'|'array', arg: Arg|null}|array{mode: 'class', class: string}|null
     */
    private function dispatchSite(Node $call): ?array
    {
        if ($call instanceof FuncCall) {
            return $call->name instanceof Name && in_array($call->name->toString(), $this->dispatchFunctions, strict: true)
                ? ['mode' => 'single', 'arg' => $call->getArgs()[0] ?? null]
                : null;
        }

        // Only `$this->dispatch(...)` (the Dispatchable form) — not an unrelated `$x->dispatch($y)`,
        // which would spuriously count as an unresolved dispatch and taint every job's coverage.
        if ($call instanceof MethodCall) {
            if (! $call->var instanceof Variable || $call->var->name !== 'this'
                || ! $call->name instanceof Identifier || $call->name->toString() !== 'dispatch') {
                return null;
            }

            $first = $call->getArgs()[0] ?? null;

            // A string literal is never a job. `DispatchesJobs::dispatch()` takes a job OBJECT, so
            // `$this->dispatch('some-event')` is a different method that happens to share the name —
            // Livewire's browser-event dispatch being the common one. Counting it as an unfollowable
            // job dispatch is the same spurious taint the `$x->dispatch($y)` exclusion above avoids,
            // and worse: there is nothing to restructure, so the site would block a test selection
            // permanently with no repair available to the project.
            return $first?->value instanceof String_ ? null : ['mode' => 'single', 'arg' => $first];
        }

        return $call instanceof StaticCall ? $this->staticDispatchSite($call) : null;
    }

    /**
     * @return array{mode: 'single'|'array', arg: Arg|null}|array{mode: 'class', class: string}|null
     */
    private function staticDispatchSite(StaticCall $call): ?array
    {
        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return null;
        }

        $method = $call->name->toString();
        $arg = $call->getArgs()[0] ?? null;
        $class = AppFiles::resolveName($call->class);

        // Bus::dispatch(new Job) / Bus::chain([new A, new B]) / Bus::batch([...]) — the resolved FQCN
        // means an aliased `use Bus as QueueBus` is still recognised.
        if ($class === Bus::class) {
            return match (true) {
                in_array($method, self::BUS_SINGLE, strict: true) => ['mode' => 'single', 'arg' => $arg],
                in_array($method, self::BUS_GROUP, strict: true) => ['mode' => 'array', 'arg' => $arg],
                default => null,
            };
        }

        // SomeJob::dispatch(...) — the static class is the job itself.
        return in_array($method, self::DISPATCH_STATICS, strict: true)
            ? ['mode' => 'class', 'class' => $class]
            : null;
    }

    /**
     * @param  array{line: int, dispatcher: string}  $origin
     * @param  list<array{line: int, dispatcher: string}>  $unresolvedSites
     * @return list<string>
     */
    private function jobsFromArg(?Arg $arg, array $origin, array &$unresolvedSites): array
    {
        $value = $arg?->value;

        if ($value instanceof New_) {
            return $this->jobFromNew($value, $origin, $unresolvedSites);
        }

        if ($value instanceof Array_) {
            return $this->jobsFromArray($value, $origin, $unresolvedSites);
        }

        // An inline closure IS the job, and its body sits in the very AST the tracers just walked —
        // `ReferenceEdgeTracer` descends into it, so the work it does is already edges out of this
        // same dispatching member. There is no hidden target and nothing to restructure, so calling
        // it unfollowable would block a selection over reach the graph already has.
        if ($value instanceof Closure || $value instanceof ArrowFunction) {
            return [];
        }

        // A dispatch verb whose job we can't see (a variable, or a factory call).
        $unresolvedSites[] = $origin;

        return [];
    }

    /**
     * @param  array{line: int, dispatcher: string}  $origin
     * @param  list<array{line: int, dispatcher: string}>  $unresolvedSites
     * @return list<string>
     */
    private function jobsFromArray(?Expr $value, array $origin, array &$unresolvedSites): array
    {
        if (! $value instanceof Array_) {
            $unresolvedSites[] = $origin;

            return [];
        }

        $jobs = [];

        foreach ($value->items as $item) {
            if ($item->value instanceof New_) {
                $jobs = [...$jobs, ...$this->jobFromNew($item->value, $origin, $unresolvedSites)];

                continue;
            }

            // An opaque item in a chain/batch (a variable, a factory call) is an unfollowable
            // dispatch on its own — record it, or a job reached only this way reads as "none".
            $unresolvedSites[] = $origin;
        }

        return $jobs;
    }

    /**
     * @param  array{line: int, dispatcher: string}  $origin
     * @param  list<array{line: int, dispatcher: string}>  $unresolvedSites
     * @return list<string>
     */
    private function jobFromNew(New_ $new, array $origin, array &$unresolvedSites): array
    {
        if (! $new->class instanceof Name) {
            $unresolvedSites[] = $origin;

            return [];
        }

        $job = AppFiles::resolveName($new->class);

        return DispatchTarget::matches($job) ? [$job] : [];
    }
}
