<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use Illuminate\Support\Facades\Facade;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\ProviderBindings;
use Throwable;

/**
 * A call through an application facade dead-ends at the facade. `Reports::generate()` resolves to
 * `App\Facades\Reports`, an app class like any other, so {@see StaticCallEdgeTracer} draws a
 * `static-call` edge to `App\Facades\Reports::generate` — a member the facade does not declare —
 * and nothing links it to the class the accessor names. Change the concrete method and richter
 * reports no callers at all, while every call site sits in a file it parsed.
 *
 * Brain emits this edge type too, from `maybeWireFacadeResolution()`, but only for a `CallChainEdge`
 * — a facade call inside a route-reached body. That is the half richter does not need.
 *
 * Emits `Facade::method → Concrete::method`, typed `facade-resolves-to`. The facade member is kept
 * rather than rewritten away: it is what makes a change to the facade itself (a repointed accessor)
 * reach the callers, and what lets `richter:trace` show that the call goes through a facade.
 *
 * Facade-ness is decided by `is_subclass_of()`, not by the base class's name in the `extends`
 * clause: a facade extending an app-side or package-side intermediate base is still a facade. The
 * accessor is read statically — a `getFacadeAccessor()` returning `Concrete::class`, looked up along
 * the recorded parent chain when the class inherits it. A container-key accessor
 * (`return 'reports'`) is resolved through the key map {@see ProviderBindings} builds from
 * `app/Providers`; a key nothing there binds, or one two providers disagree on, still draws
 * nothing.
 *
 * Cross-file by nature — the facade and its call sites are different files — so it accumulates every
 * class-like via {@see collect()} across the consolidated AST pass in {@see CodeGraphBuilder} and
 * emits once, via {@see resolutionEdges()}, after every file has been collected.
 *
 * @internal
 */
final class FacadeEdgeTracer
{
    private const string ACCESSOR_METHOD = 'getFacadeAccessor';

    /** @var array<string, array{parent: string|null, accessor: array{kind: string, value: string}|null}> keyed by FQCN */
    private array $records = [];

    /** @var array<string, bool> reflection results, memoised for the process */
    private static array $reflected = [];

    /**
     * @param  array<string, string>  $containerKeys  container key => concrete FQCN
     *   ({@see ProviderBindings::forProject()}). Empty by default, so a caller with no project to
     *   scan resolves `::class` accessors exactly as before and a key accessor draws nothing.
     */
    public function __construct(private array $containerKeys = []) {}

    /**
     * Record every class in one file. Fed per file by the consolidated AST loop in
     * {@see CodeGraphBuilder}; call once per file, then {@see resolutionEdges()}.
     *
     * @param  list<ClassLike>  $classLikes  every ClassLike in the file, any depth
     */
    public function collect(array $classLikes): void
    {
        foreach ($classLikes as $node) {
            // Only a class can extend Facade, and an anonymous one carries no name for a
            // `static-call` edge to have targeted in the first place.
            if (! $node instanceof Class_) {
                continue;
            }

            $fqcn = $node->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $this->records[$fqcn] = [
                'parent' => $node->extends instanceof Name ? AppFiles::resolveName($node->extends) : null,
                'accessor' => $this->accessorIn($node),
            ];
        }
    }

    /**
     * The bridging edges for the `static-call` edges that landed on a facade member.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges  the branch's edges so far
     * @return list<array{source: string, target: string, type: string}>
     */
    public function resolutionEdges(array $edges): array
    {
        $concretes = $this->concreteByFacade();

        if ($concretes === []) {
            return [];
        }

        $resolved = [];

        foreach ($edges as $edge) {
            if ($edge['type'] !== 'static-call') {
                continue;
            }

            [$class, $method] = array_pad(explode('::', $edge['target'], 2), 2, '');
            $concrete = $concretes[$class] ?? null;

            if ($concrete === null) {
                continue;
            }

            // A class-level edge to the facade names no member to carry over.
            if ($method === '') {
                continue;
            }

            // A facade method the concrete does not have is `__call`-backed magic, whose real target
            // is not statically known. `method_exists` rather than the declared-method list: a method
            // the concrete picks up from a trait or a parent is just as much the code that runs.
            //
            // That admits one imprecise node: a method inherited from a VENDOR parent richter never
            // scanned lands on `Concrete::method`, which the concrete's own file does not declare
            // and no `inherits` edge can carry further (ancestors are app-scoped by design).
            // Deliberate, and the same call {@see StaticCallEdgeTracer} already makes — it checks
            // only that the receiver loads. Demanding a scanned declaration would drop the edge
            // entirely for a concrete extending a framework base, which is the reach this lane
            // exists to add; an approximate node beats none.
            if (! $this->hasMethod($concrete, $method)) {
                continue;
            }

            $resolved[] = ['source' => $edge['target'], 'target' => "{$concrete}::{$method}", 'type' => 'facade-resolves-to'];
        }

        return AppFiles::dedupeEdges($resolved, byType: true);
    }

    /**
     * Every collected facade that resolves to an app class, as facade FQCN => concrete FQCN.
     *
     * @return array<string, string>
     */
    private function concreteByFacade(): array
    {
        $concretes = [];

        foreach (array_keys($this->records) as $fqcn) {
            $concrete = $this->concreteFor($this->accessorFor($fqcn));

            if ($concrete !== null && AppNamespace::isInApp($concrete) && $this->isFacade($fqcn)) {
                $concretes[$fqcn] = $concrete;
            }
        }

        return $concretes;
    }

    /**
     * The class an accessor names: the `::class` it returned, or what the container binds its key
     * to. A key the map does not hold — never bound under `app/Providers`, or bound to two
     * different concretes — yields null, the same silence a facade with no readable accessor gets.
     *
     * @param  array{kind: string, value: string}|null  $accessor
     */
    private function concreteFor(?array $accessor): ?string
    {
        if ($accessor === null) {
            return null;
        }

        return $accessor['kind'] === 'class' ? $accessor['value'] : ($this->containerKeys[$accessor['value']] ?? null);
    }

    /**
     * The accessor a class declares, or the nearest one up its recorded parent chain — the abstract
     * base facade pattern, where subclasses differ only in the accessor they return, still has to
     * find the base's when a subclass declares none. The chain stops at the first class richter did
     * not scan, so a vendor base's accessor is out of reach by the same app-scoping every tracer has.
     *
     * @return array{kind: string, value: string}|null
     */
    private function accessorFor(string $fqcn): ?array
    {
        $seen = [];

        while (isset($this->records[$fqcn]) && ! isset($seen[$fqcn])) {
            $seen[$fqcn] = true;

            if ($this->records[$fqcn]['accessor'] !== null) {
                return $this->records[$fqcn]['accessor'];
            }

            $fqcn = $this->records[$fqcn]['parent'] ?? '';
        }

        return null;
    }

    /**
     * The one answer this class's `getFacadeAccessor()` returns — a `::class` (`kind: 'class'`) or a
     * container key (`kind: 'key'`) — or null when it declares none, returns something else, or
     * returns more than one distinct answer.
     *
     * A method with two returns picks its concrete at runtime from state this cannot see. Taking the
     * first would be a guess dressed as a fact — the reader would be sent to one of two files with
     * no hint that the other exists — so it draws nothing, the same abort the contract parsers make
     * on an unenumerable key set. A `::class` and a key are two distinct answers even when the key
     * happens to be bound to that same class: the pair still proves the method branches.
     *
     * The kind is recorded, never inferred from the value. A container key may legally contain a
     * backslash, so a namespace-separator test would file `'reports\primary'` as a class name and
     * lose it.
     *
     * @return array{kind: string, value: string}|null
     */
    private function accessorIn(Class_ $node): ?array
    {
        $returned = [];

        foreach ($node->getMethods() as $method) {
            if ($method->name->toString() !== self::ACCESSOR_METHOD) {
                continue;
            }

            foreach (new NodeFinder()->findInstanceOf($method, Return_::class) as $return) {
                $answer = $this->answerIn($return->expr);

                if ($answer === null) {
                    // A ternary, a match, a variable — nothing statically resolvable, and a sibling
                    // return that IS resolvable must not speak for it either.
                    return null;
                }

                $returned["{$answer['kind']}\0{$answer['value']}"] = $answer;
            }
        }

        return count($returned) === 1 ? array_values($returned)[0] : null;
    }

    /**
     * One `return` expression as an accessor answer: `Concrete::class` or a string literal key.
     *
     * @return array{kind: string, value: string}|null
     */
    private function answerIn(?Expr $expr): ?array
    {
        if ($expr instanceof String_) {
            return ['kind' => 'key', 'value' => $expr->value];
        }

        if ($expr instanceof ClassConstFetch
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'class'
            && $expr->class instanceof Name) {
            return ['kind' => 'class', 'value' => AppFiles::resolveName($expr->class)];
        }

        return null;
    }

    /**
     * Reflection, not the `extends` name: a facade may reach the base through an intermediate class,
     * and the intermediate may live in a package richter never scanned. A class that cannot be
     * autoloaded is not a facade for this purpose — no other lane could place its concrete either.
     */
    private function isFacade(string $fqcn): bool
    {
        return $this->reflect("facade\0{$fqcn}", static fn (): bool => is_subclass_of($fqcn, Facade::class));
    }

    private function hasMethod(string $fqcn, string $method): bool
    {
        return $this->reflect("method\0{$fqcn}\0{$method}", static fn (): bool => method_exists($fqcn, $method));
    }

    /**
     * Memoised per process — the same facade recurs across every call site in the app, and a miss
     * costs a failed autoload each time. A broken autoloader throwing here is uncertainty about a
     * class that, by definition, no other tracer could place either: no edge.
     *
     * @param  callable(): bool  $check
     */
    private function reflect(string $key, callable $check): bool
    {
        try {
            return self::$reflected[$key] ??= $check();
        } catch (Throwable) {
            return self::$reflected[$key] = false;
        }
    }
}
