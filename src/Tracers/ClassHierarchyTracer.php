<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Class-Hierarchy Analysis (CHA). Brain resolves a method call to the receiver's STATIC type, so a
 * call on an abstract class or interface never reaches the concrete override that actually runs under
 * polymorphic dispatch (a config-registry driver, a factory, `app()->make($runtimeClass)`, plain
 * constructor-injected polymorphism). The concrete override is then orphaned in the graph — a change
 * to it reaches no entry point even though every polymorphic call site executes it.
 *
 * This tracer draws an `override` edge `Ancestor::method → Descendant::method` for every method a
 * class declares that an app-scanned ancestor (a transitive parent class, or a directly/transitively
 * implemented interface) also declares. The edge is oriented ancestor → descendant so that
 * `callersOf(concrete::m)` walks UP to the abstract call site (and `dependenciesOf(abstract::m)`
 * reaches the override) — the same inversion the App-interface `implements` edges rely on. It is
 * strictly ADDITIVE: it only adds reachability, never removes it.
 *
 * The edges are emitted per hierarchy; which of them a WALK may chain is a separate decision that
 * lives in {@see CodeGraph::bfs()}. An `override` hop out of a node reached only through type
 * structure (`implements` → `declares` onto an interface method, say) is refused there, so one class
 * in a wide hierarchy does not drag in every sibling implementor. A path that carries a call still
 * fans out — that is the dispatch this tracer exists to follow.
 *
 * CHA is inherently cross-file — the inverse subclass/implementor map spans files — so it accumulates
 * every class-like via {@see collect()} across the whole consolidated AST pass and emits edges once,
 * via {@see overrideEdges()}, after every file has been collected.
 *
 * Scope decisions (spec `class-hierarchy-analysis.md`): overrides are matched by method NAME only
 * (OQ2); `private` methods (never polymorphic) and `static` methods (hidden, not overridden) are
 * excluded, as is `__construct` (called on the concrete type directly, not virtually dispatched);
 * ancestors are APP-SCOPED (OQ3) — a vendor base richter never scanned is simply absent here, so the
 * traversal stops at it and no edge to an unknown method is drawn. Traits are excluded entirely: a
 * trait method is copied into the using class, not dispatched.
 *
 * @internal
 */
final class ClassHierarchyTracer
{
    /** @var array<string, array{parent: string|null, interfaces: list<string>, methods: list<string>, declared: list<string>}> keyed by FQCN */
    private array $records = [];

    /**
     * Record every class-like in one file. Fed per file by the consolidated AST loop in
     * {@see CodeGraphBuilder}; call once per file, then {@see overrideEdges()}.
     *
     * @param  list<ClassLike>  $classLikes  every ClassLike in the file, any depth
     */
    public function collect(array $classLikes): void
    {
        foreach ($classLikes as $node) {
            // Anonymous classes carry no namespacedName and can be neither an override source nor a
            // target (nothing statically refers to them by name). Traits are copied, not dispatched.
            if ($node instanceof Trait_) {
                continue;
            }

            $fqcn = $node->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $this->records[$fqcn] = [
                'parent' => $this->parentOf($node),
                'interfaces' => $this->interfacesOf($node),
                'methods' => $this->overridableMethods($node),
                // Every method name the class writes out, including the private/static/__construct
                // ones `methods` filters away: "does this class declare m itself?" is a different
                // question from "can m be overridden?", and inheritedEdges() asks the first.
                'declared' => $this->declaredMethods($node),
            ];
        }
    }

    /** @return list<array{source: string, target: string, type: string}> */
    public function overrideEdges(): array
    {
        $edges = [];

        foreach ($this->records as $fqcn => $record) {
            foreach ($this->ancestorsOf($fqcn) as $ancestor) {
                foreach ($record['methods'] as $method) {
                    if (in_array($method, $this->records[$ancestor]['methods'], true)) {
                        $edges[] = ['source' => "{$ancestor}::{$method}", 'target' => "{$fqcn}::{$method}", 'type' => 'override'];
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * The upward complement of {@see overrideEdges()}: a method a class INHERITS without overriding
     * runs in the parent, but Brain resolves the call against the receiver's static type, so it lands
     * on `Subclass::m` while the code lives at `Parent::m` — two unconnected nodes. The parent then
     * reports no callers even though every real call arrives through the subclass, and the parent's
     * own outbound work is cut off from everything upstream. Richter already resolves class CONSTANTS
     * to their declaring class ("an inherited constant still connects"); this is the method lane
     * catching up.
     *
     * Driven by the edge set rather than the records, and therefore emitted after the consolidated
     * loop the way {@see CodeGraphBuilder::declaresEdges()} is: an edge is drawn only for a member
     * node something already references. Emitting one per inherited method regardless would fan out
     * (a base class with 20 methods and 50 subclasses is 1000 edges) and, worse, invent
     * `Subclass::m` nodes nothing calls — which `seedsFor()`'s substring lookup would still match, so
     * a changed member could seed against a node no caller reaches.
     *
     * Oriented `Subclass::m → Ancestor::m`, so `callersOf(Ancestor::m)` climbs to the subclass's real
     * callers and `dependenciesOf(Subclass::m)` reaches the parent's outbound work.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<array{source: string, target: string, type: string}>
     */
    public function inheritedEdges(array $edges): array
    {
        return self::inheritedEdgesFor($this->inheritanceMap(), $edges);
    }

    /**
     * The parent chain and declared-method names per class — everything {@see inheritedEdgesFor()}
     * needs, in a JSON-serialisable shape. The tracer runs inside the tracer branch, which may be a
     * child process, while the edge set it must be applied to only exists in the parent after both
     * branches merge; carrying the map out is what lets the two meet.
     *
     * @return array<string, array{parent: string|null, declared: list<string>}>
     */
    public function inheritanceMap(): array
    {
        return array_map(
            static fn (array $record): array => ['parent' => $record['parent'], 'declared' => $record['declared']],
            $this->records,
        );
    }

    /**
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function inheritedEdgesFor(array $inheritance, array $edges): array
    {
        $inherited = [];

        foreach ($edges as $edge) {
            foreach ([$edge['source'], $edge['target']] as $node) {
                if (preg_match('/^([\\w\\\\]+)::(\\w+)$/', $node, $matches) !== 1) {
                    continue;
                }

                [, $class, $method] = $matches;
                $record = $inheritance[$class] ?? null;
                if ($record === null) {
                    continue;
                }

                if (in_array($method, $record['declared'], true)) {
                    continue;
                }

                $ancestor = self::nearestDeclarerIn($inheritance, $class, $method);

                if ($ancestor !== null) {
                    $inherited["{$class}::{$method}"] = ['source' => "{$class}::{$method}", 'target' => "{$ancestor}::{$method}", 'type' => 'inherits'];
                }
            }
        }

        return array_values($inherited);
    }

    /**
     * The closest ancestor CLASS that declares the method — the one that actually runs. Walks the
     * parent chain only: an interface declares no body, so an edge to it would carry reachability to
     * code that does not exist, and a trait's methods are copied into the using class (where they read
     * as declared) rather than inherited. Stops at the first ancestor richter did not scan, matching
     * {@see ancestorsOf()}'s app-scoped rule.
     *
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     */
    private static function nearestDeclarerIn(array $inheritance, string $fqcn, string $method): ?string
    {
        $seen = [];
        $parent = $inheritance[$fqcn]['parent'] ?? null;

        while ($parent !== null && ! isset($seen[$parent])) {
            $seen[$parent] = true;
            $record = $inheritance[$parent] ?? null;

            if ($record === null) {
                return null;
            }

            if (in_array($method, $record['declared'], true)) {
                return $parent;
            }

            $parent = $record['parent'];
        }

        return null;
    }

    /** @return list<string> */
    private function declaredMethods(ClassLike $node): array
    {
        return array_values(array_map(static fn (ClassMethod $method): string => $method->name->toString(), $node->getMethods()));
    }

    private function parentOf(ClassLike $node): ?string
    {
        // Only a class has a single parent; an interface's `extends` is a list, handled as interfaces.
        return $node instanceof Class_ && $node->extends instanceof Name
            ? AppFiles::resolveName($node->extends)
            : null;
    }

    /** @return list<string> */
    private function interfacesOf(ClassLike $node): array
    {
        $names = match (true) {
            $node instanceof Class_, $node instanceof Enum_ => $node->implements,
            // interface-extends-interface: the extended interfaces are contracts too.
            $node instanceof Interface_ => $node->extends,
            default => [],
        };

        return array_values(array_map(AppFiles::resolveName(...), $names));
    }

    /**
     * Names of the methods a class declares that can participate in an override: public/protected
     * instance methods, excluding the constructor. See the class docblock for the rationale.
     *
     * @return list<string>
     */
    private function overridableMethods(ClassLike $node): array
    {
        $methods = [];

        foreach ($node->getMethods() as $method) {
            if ($method->isPrivate()) {
                continue;
            }

            if ($method->isStatic()) {
                continue;
            }

            if ($method->name->toLowerString() === '__construct') {
                continue;
            }

            $methods[] = $method->name->toString();
        }

        return $methods;
    }

    /**
     * All app-scanned ancestors of a class, transitively: the parent chain plus implemented interfaces
     * and their parents. An ancestor richter never scanned (a vendor base) is absent from the records,
     * so the traversal records it but does not recurse through it — CHA is app-scoped (OQ3). Only
     * scanned ancestors are returned, since only they have known methods to match.
     *
     * @return list<string>
     */
    private function ancestorsOf(string $fqcn): array
    {
        $seen = [];
        $queue = $this->directAncestors($fqcn);

        while ($queue !== []) {
            $ancestor = array_shift($queue);

            if (isset($seen[$ancestor])) {
                continue;
            }

            $seen[$ancestor] = true;

            // Traverse THROUGH an ancestor only when richter scanned it; a vendor base's own ancestors
            // are unknown, so recursion stops there.
            if (isset($this->records[$ancestor])) {
                $queue = [...$queue, ...$this->directAncestors($ancestor)];
            }
        }

        return array_values(array_filter(array_keys($seen), fn (string $ancestor): bool => isset($this->records[$ancestor])));
    }

    /** @return list<string> */
    private function directAncestors(string $fqcn): array
    {
        $record = $this->records[$fqcn] ?? null;

        if ($record === null) {
            return [];
        }

        return array_values(array_filter(
            [$record['parent'], ...$record['interfaces']],
            static fn (?string $ancestor): bool => $ancestor !== null,
        ));
    }
}
