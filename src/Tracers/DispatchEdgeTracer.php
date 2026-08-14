<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use Illuminate\Support\Facades\Bus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\DispatchTarget;
use SanderMuller\Richter\Support\LocallyConstructedJobs;

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

    /**
     * Whether this source can possibly dispatch — a cheap pre-check before the AST walk.
     *
     * An instance method rather than a static one, and that is the whole point: the verbs this tracer
     * answers to include the project's own ({@see RichterConfig::dispatchHelpers()}), and a helper
     * named `queue_job` spells none of the built-in tokens. A static gate could not know that, and
     * would have skipped the file — losing both its edges and its unresolved-dispatch site, silently.
     *
     * The remaining three tokens cover the rest: every built-in verb carries `dispatch` in its name
     * except the `Bus` facade's `chain`/`batch`, and the instantiation over-approximation needs a
     * `new`. That last one is matched on a word boundary, not `'new '` — `new` may be followed by any
     * whitespace, including a line break, and a missed one costs an edge.
     */
    public function mayMatch(string $source): bool
    {
        if (stripos($source, 'dispatch') !== false || str_contains($source, 'Bus') || preg_match('/\bnew\b/i', $source) === 1) {
            return true;
        }

        return array_any(
            $this->dispatchFunctions,
            static fn (string $helper): bool => stripos($source, $helper) !== false,
        );
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
        $finder = new NodeFinder();

        return $this->edgesForMethods(
            array_values($finder->findInstanceOf($ast, ClassMethod::class)),
            $classFqcn,
            array_values($finder->findInstanceOf($ast, ClassLike::class)),
        );
    }

    /**
     * Bucket-fed variant of {@see edgesForResolvedAst()}: the consolidated loop in
     * {@see CodeGraphBuilder} collects each file's nodes in one descent and hands every tracer its
     * bucket, so no tracer re-walks the full tree.
     *
     * @param  list<ClassMethod>  $classMethods  every ClassMethod in the file, any depth
     * @param  list<ClassLike>  $classLikes  the file's class-likes, read for the string constants that
     *   let `self::SOME_EVENT` be resolved the same way a bare literal is
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedSites: list<array{line: int, dispatcher: string}>}
     */
    public function edgesForMethods(array $classMethods, string $classFqcn, array $classLikes = []): array
    {
        $stringConstants = $this->stringConstantsByMethod($classLikes);

        $edges = [];
        $unresolvedSites = [];

        foreach ($classMethods as $method) {
            $dispatcher = ltrim($classFqcn, '\\') . '::' . $method->name->toString();
            $ownConstants = $stringConstants[spl_object_id($method)] ?? [];
            $localJobs = LocallyConstructedJobs::in($method);
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

                foreach ($this->jobsFromCall($call, $origin, $unresolvedSites, $ownConstants, $localJobs) as $jobFqcn) {
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
            // Hence the same constant map as above: a method whose only dispatch is a component event
            // does not dispatch, and must not unlock this.
            $methodDispatches = array_any(
                $calls,
                fn (Node $call): bool => (! $call instanceof CallLike || ! $call->isFirstClassCallable())
                    && $this->dispatchSite($call, $ownConstants) !== null,
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
     * @param  array<string, true>  $stringConstants
     * @param  array<string, array{pos: int, jobs: list<string>}>  $localJobs
     * @return list<string>
     */
    private function jobsFromCall(Node $call, array $origin, array &$unresolvedSites, array $stringConstants = [], array $localJobs = []): array
    {
        // A first-class callable (`Job::dispatch(...)`) builds a closure, not a dispatch — and
        // calling getArgs() on it throws. It's not a dispatch site, so skip it.
        if ($call instanceof CallLike && $call->isFirstClassCallable()) {
            return [];
        }

        $site = $this->dispatchSite($call, $stringConstants);

        return match ($site['mode'] ?? null) {
            'single' => $this->jobsFromArg($site['arg'], $origin, $unresolvedSites, $localJobs),
            'array' => $this->jobsFromArray($site['arg']?->value, $origin, $unresolvedSites, $localJobs),
            'class' => DispatchTarget::matches($site['class']) ? [$site['class']] : [],
            default => [],
        };
    }

    /**
     * Per method, the string-literal constants its own declaring class declares.
     *
     * Keyed by method rather than folded into one map per file, because a file may declare several
     * classes and the methods arrive as one flat list. A name-only map across all of them reads
     * `self::EVENT` in one class as a string because a sibling declares a string of that name — or
     * because a parent declares something else entirely — and a suppressed site is a diff that
     * reports `determinable` when nothing determined it.
     *
     * @param  list<ClassLike>  $classLikes
     * @return array<int, array<string, true>>
     */
    private function stringConstantsByMethod(array $classLikes): array
    {
        $byMethod = [];

        foreach ($classLikes as $classLike) {
            $names = [];

            foreach ($classLike->stmts as $stmt) {
                if (! $stmt instanceof ClassConst) {
                    continue;
                }

                foreach ($stmt->consts as $const) {
                    if ($const->value instanceof String_) {
                        $names[$const->name->toString()] = true;
                    }
                }
            }

            foreach ($classLike->getMethods() as $method) {
                $byMethod[spl_object_id($method)] = $names;
            }
        }

        return $byMethod;
    }

    /**
     * Whether the argument is a string as far as this file can tell — a literal, or one of the
     * declaring class's own constants holding one.
     *
     * `self::` only, not `static::`: late static binding reads the constant off the runtime class, so
     * a subclass can supply a different value and nothing here sees it. And a constant reached through
     * a parent or another class would need the cross-file map, which is not built when this runs.
     * Both cases stay recorded, because guessing risks dropping a genuine unfollowable dispatch — the
     * one error direction that costs a project real coverage.
     *
     * @param  array<string, true>  $stringConstants
     */
    private function resolvesToString(?Arg $arg, array $stringConstants): bool
    {
        $value = $arg?->value;

        if ($value instanceof String_) {
            return true;
        }

        return $value instanceof ClassConstFetch
            && $value->class instanceof Name
            // Lowercased: `self` is a keyword, so `SELF::EVENT` is the same constant, and a spelling
            // this failed to match would keep the very site the resolution exists to drop.
            && $value->class->toLowerString() === 'self'
            && $value->name instanceof Identifier
            && isset($stringConstants[$value->name->toString()]);
    }

    /**
     * Classify a call as a dispatch shape, or null when it isn't one.
     *
     * @param  array<string, true>  $stringConstants
     * @return array{mode: 'single'|'array', arg: Arg|null}|array{mode: 'class', class: string}|null
     */
    private function dispatchSite(Node $call, array $stringConstants = []): ?array
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

            // A string is never a job. `DispatchesJobs::dispatch()` takes a job OBJECT, so
            // `$this->dispatch('some-event')` is a different method that happens to share the name —
            // Livewire's browser-event dispatch being the common one. Counting it as an unfollowable
            // job dispatch is the same spurious taint the `$x->dispatch($y)` exclusion above avoids,
            // and worse: there is nothing to restructure, so the site would block a test selection
            // permanently with no repair available to the project.
            //
            // `self::SOME_EVENT` counts too when the class declares it as a string: naming the event
            // through a constant is the same call, and testing only for a bare literal just waits for
            // the next argument shape.
            return $this->resolvesToString($first, $stringConstants) ? null : ['mode' => 'single', 'arg' => $first];
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
     * @param  array<string, array{pos: int, jobs: list<string>}>  $localJobs
     * @return list<string>
     */
    private function jobsFromArg(?Arg $arg, array $origin, array &$unresolvedSites, array $localJobs = []): array
    {
        $value = $arg?->value;

        if ($value instanceof New_) {
            return $this->jobFromNew($value, $origin, $unresolvedSites);
        }

        if ($value instanceof Array_) {
            return $this->jobsFromArray($value, $origin, $unresolvedSites, $localJobs);
        }

        // An inline closure IS the job, and its body sits in the very AST the tracers just walked —
        // `ReferenceEdgeTracer` descends into it, so the work it does is already edges out of this
        // same dispatching member. There is no hidden target and nothing to restructure, so calling
        // it unfollowable would block a selection over reach the graph already has.
        if ($value instanceof Closure || $value instanceof ArrowFunction) {
            return [];
        }

        $local = $this->localJobFor($value, $localJobs);

        if ($local !== null) {
            return $local;
        }

        // A dispatch verb whose job we can't see (a variable, or a factory call).
        $unresolvedSites[] = $origin;

        // A named constructor on a class that IS a dispatch target — `dispatch(SomeJob::for($x))`.
        // The edge is worth drawing: a change to that job should reach this member either way. The
        // site stays, because nothing here proves the method returns an instance of its own class,
        // and a wrong "resolved" would drop a real target from a selection.
        return $this->namedConstructorTarget($value);
    }

    /**
     * @param  array{line: int, dispatcher: string}  $origin
     * @param  list<array{line: int, dispatcher: string}>  $unresolvedSites
     * @param  array<string, array{pos: int, jobs: list<string>}>  $localJobs
     * @return list<string>
     */
    private function jobsFromArray(?Expr $value, array $origin, array &$unresolvedSites, array $localJobs = []): array
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

            // A closure item is the same case as a closure argument: it IS the queued work, and its
            // body is in the source the tracers already read.
            if ($item->value instanceof Closure) {
                continue;
            }

            if ($item->value instanceof ArrowFunction) {
                continue;
            }

            $local = $this->localJobFor($item->value, $localJobs);

            if ($local !== null) {
                $jobs = [...$jobs, ...$local];

                continue;
            }

            // An opaque item in a chain/batch (a variable, a factory call) is an unfollowable
            // dispatch on its own — record it, or a job reached only this way reads as "none".
            $unresolvedSites[] = $origin;
            $jobs = [...$jobs, ...$this->namedConstructorTarget($item->value)];
        }

        return $jobs;
    }

    /**
     * The jobs a dispatched variable provably holds, or null when this pass cannot say.
     *
     * `$job = new SomeJob(...); dispatch($job);` hides nothing: the instantiation is right there, the
     * graph already carries the edge, and there is no restructuring for a project to do — the same
     * argument that exempts an inline closure. Recording it as unfollowable taints every selection
     * over reach the graph already has.
     *
     * @param  array<string, array{pos: int, jobs: list<string>}>  $localJobs
     * @return list<string>|null
     */
    private function localJobFor(?Expr $value, array $localJobs): ?array
    {
        if (! $value instanceof Variable || ! is_string($value->name)) {
            return null;
        }

        $local = $localJobs[$value->name] ?? null;
        $dispatchedAt = $value->getStartFilePos();

        // Constructed BEFORE this argument. An assignment below the dispatch says nothing about the
        // value dispatched here, and a parser that attached no positions (-1) proves no order at all.
        return $local !== null && $local['pos'] >= 0 && $dispatchedAt > $local['pos'] ? $local['jobs'] : null;
    }

    /**
     * The dispatch target a `SomeJob::for($x)` argument names, if its receiver is one.
     *
     * @return list<string>
     */
    private function namedConstructorTarget(?Expr $value): array
    {
        if (! $value instanceof StaticCall || ! $value->class instanceof Name) {
            return [];
        }

        $job = AppFiles::resolveName($value->class);

        return DispatchTarget::matches($job) ? [$job] : [];
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
