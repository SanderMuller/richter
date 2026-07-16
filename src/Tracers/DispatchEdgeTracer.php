<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;
use Throwable;

/**
 * Brain resolves the standard dispatch forms — `Job::dispatch()`, `dispatch()`, and, since v2.3.1, the
 * `Bus` facade, `$this->dispatch(...)`, and `dispatch_sync()` — but only ever emits the *resolved* edge.
 * It never covers project-custom dispatch helper functions (configured via `richter.dispatch_helpers`),
 * nor flags a dispatch whose job can't be seen statically. This tracer fills both gaps: it emits
 * `action-to-job` edges (FQCN-keyed, joining the normalised graph) and records an unresolved-dispatch
 * signal for a variable / factory / closure argument, so such a job reads as "unknown", not "none".
 * Dev/CI tooling only.
 */
final class DispatchEdgeTracer
{
    // `dispatch` / `dispatch_sync` stay though Brain resolves them (v2.3.1): only this tracer counts an
    // unfollowable dispatch (see class docblock).
    private const array DISPATCH_FUNCTIONS = ['dispatch', 'dispatch_sync'];

    private const array DISPATCH_STATICS = ['dispatch', 'dispatchSync', 'dispatchNow', 'dispatchIf', 'dispatchUnless', 'dispatchAfterResponse'];

    private const array BUS_SINGLE = ['dispatch', 'dispatchSync', 'dispatchNow'];

    private const array BUS_GROUP = ['chain', 'batch'];

    /** @var array<string, bool> */
    private array $jobClassCache = [];

    /** @var list<string> */
    private readonly array $dispatchFunctions;

    /** @param  list<string>  $dispatchHelpers  project-custom global dispatch helper functions ({@see RichterConfig::dispatchHelpers()}) */
    public function __construct(array $dispatchHelpers = [])
    {
        $this->dispatchFunctions = [...self::DISPATCH_FUNCTIONS, ...$dispatchHelpers];
    }

    /** @return array{edges: list<array{source: string, target: string, type: string}>, unresolved: int} */
    public function edgesForSource(string $source, string $classFqcn): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return ['edges' => [], 'unresolved' => 0];
        }

        return $this->edgesForResolvedAst($ast, $classFqcn);
    }

    /**
     * @param  list<Node\Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolved: int}
     */
    public function edgesForResolvedAst(array $ast, string $classFqcn): array
    {
        $edges = [];
        $unresolved = 0;

        /** @var ClassMethod $method */
        foreach (new NodeFinder()->findInstanceOf($ast, ClassMethod::class) as $method) {
            $dispatcher = ltrim($classFqcn, '\\') . '::' . $method->name->toString();
            $calls = new NodeFinder()->find($method, static fn (Node $n): bool => $n instanceof FuncCall || $n instanceof MethodCall || $n instanceof StaticCall);

            foreach ($calls as $call) {
                foreach ($this->jobsFromCall($call, $unresolved) as $jobFqcn) {
                    $edges[] = ['source' => $dispatcher, 'target' => $jobFqcn . '::handle', 'type' => 'action-to-job'];
                }
            }

            // Any job instantiation links the constructing method — over-approximate on purpose: the
            // dispatch verb often receives the job as a variable (`$job = new X(...); dispatch($job)`),
            // which no dispatch-site pattern above can follow — a defect class that ships unseen otherwise.
            foreach (new NodeFinder()->findInstanceOf($method, New_::class) as $new) {
                if ($new->class instanceof Name && $this->isJobClass($job = $this->resolveName($new->class)) && $job !== ltrim($classFqcn, '\\')) {
                    $edges[] = ['source' => $dispatcher, 'target' => $job . '::handle', 'type' => 'action-to-job'];
                }
            }
        }

        return ['edges' => AppFiles::dedupeEdges($edges), 'unresolved' => $unresolved];
    }

    /** @return list<string> */
    private function jobsFromCall(Node $call, int &$unresolved): array
    {
        // A first-class callable (`Job::dispatch(...)`) builds a closure, not a dispatch — and
        // calling getArgs() on it throws. It's not a dispatch site, so skip it.
        if ($call instanceof CallLike && $call->isFirstClassCallable()) {
            return [];
        }

        $site = $this->dispatchSite($call);

        return match ($site['mode'] ?? null) {
            'single' => $this->jobsFromArg($site['arg'], $unresolved),
            'array' => $this->jobsFromArray($site['arg']?->value, $unresolved),
            'class' => $this->isJobClass($site['class']) ? [$site['class']] : [],
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
            return $call->var instanceof Variable && $call->var->name === 'this'
                && $call->name instanceof Identifier && $call->name->toString() === 'dispatch'
                ? ['mode' => 'single', 'arg' => $call->getArgs()[0] ?? null]
                : null;
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
        $class = $this->resolveName($call->class);

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

    /** @return list<string> */
    private function jobsFromArg(?Arg $arg, int &$unresolved): array
    {
        $value = $arg?->value;

        if ($value instanceof New_) {
            return $this->jobFromNew($value, $unresolved);
        }

        if ($value instanceof Array_) {
            return $this->jobsFromArray($value, $unresolved);
        }

        // A dispatch verb whose job we can't see (a variable, factory, closure).
        ++$unresolved;

        return [];
    }

    /** @return list<string> */
    private function jobsFromArray(?Expr $value, int &$unresolved): array
    {
        if (! $value instanceof Array_) {
            ++$unresolved;

            return [];
        }

        $jobs = [];

        foreach ($value->items as $item) {
            if ($item->value instanceof New_) {
                $jobs = [...$jobs, ...$this->jobFromNew($item->value, $unresolved)];

                continue;
            }

            // An opaque item in a chain/batch (a variable, a factory call) is an unfollowable
            // dispatch on its own — count it, or a job reached only this way reads as "none".
            ++$unresolved;
        }

        return $jobs;
    }

    /** @return list<string> */
    private function jobFromNew(New_ $new, int &$unresolved): array
    {
        if (! $new->class instanceof Name) {
            ++$unresolved;

            return [];
        }

        $job = $this->resolveName($new->class);

        return $this->isJobClass($job) ? [$job] : [];
    }

    private function resolveName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim($resolved instanceof Name ? $resolved->toString() : $name->toString(), '\\');
    }

    private function isJobClass(string $fqcn): bool
    {
        if (str_contains($fqcn, '\\Jobs\\')) {
            return true;
        }

        // Memoize the autoloading check — the full-app scan asks the same FQCNs repeatedly, and
        // class_exists()/is_subclass_of() autoload, which is the expensive part.
        return $this->jobClassCache[$fqcn] ??= $this->isQueueable($fqcn);
    }

    private function isQueueable(string $fqcn): bool
    {
        try {
            return class_exists($fqcn) && is_subclass_of($fqcn, ShouldQueue::class);
        } catch (Throwable) {
            return false;
        }
    }
}
