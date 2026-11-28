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
 * traversal stops at it and no edge to an unknown method is drawn. Traits draw no override edge: a
 * trait method is copied into the using class, not dispatched. They do draw an `inherits` edge, from
 * the using class's member to the trait's ({@see inheritedEdgesFor()}).
 *
 * @internal
 */
final class ClassHierarchyTracer
{
    /** Past this many members a family is treated as unlistable: one shared trait can pull in most of an app. */
    private const int FAMILY_CAP = 200;

    /** @var array<string, array{parent: string|null, interfaces: list<string>, methods: list<string>, declared: list<string>, concrete: bool}> keyed by FQCN */
    private array $records = [];

    /** @var array<string, true> types an anonymous class extends or implements directly */
    private array $anonymouslyExtended = [];

    /** @var array<string, list<string>> named class-like (a trait included) => the traits it uses directly */
    private array $traitsUsed = [];

    /** @var array<string, true> traits declared in the scanned tree */
    private array $traits = [];

    /**
     * @var array<string, true> class-likes whose callers cannot be listed from their names: a trait an
     *   anonymous class uses, and a class-like that renames or re-exposes a trait method (`as`, `insteadof`)
     */
    private array $openToUnnamedCallers = [];

    /**
     * Record every class-like in one file. Fed per file by the consolidated AST loop in
     * {@see CodeGraphBuilder}; call once per file, then {@see overrideEdges()}.
     *
     * @param  list<ClassLike>  $classLikes  every ClassLike in the file, any depth
     */
    public function collect(array $classLikes): void
    {
        foreach ($classLikes as $node) {
            $this->collectTraitUse($node);

            // Anonymous classes carry no namespacedName and can be neither an override source nor a
            // target (nothing statically refers to them by name). Traits are copied, not dispatched.
            if ($node instanceof Trait_) {
                continue;
            }

            $fqcn = $node->namespacedName?->toString();

            if ($fqcn === null) {
                // An anonymous class is a subtype no FQCN names, so its bases cannot list their subtypes.
                if ($node instanceof Class_) {
                    foreach ([$this->parentOf($node), ...$this->interfacesOf($node)] as $base) {
                        if ($base !== null) {
                            $this->anonymouslyExtended[$base] = true;
                        }
                    }
                }

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
                'concrete' => ($node instanceof Class_ && ! $node->isAbstract()) || $node instanceof Enum_,
            ];
        }
    }

    /** Which traits a class-like uses, for {@see closedFamilyOf()}. Separate from the override records, which leave traits out. */
    private function collectTraitUse(ClassLike $node): void
    {
        $fqcn = $node->namespacedName?->toString();
        $used = [];

        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $used[] = AppFiles::resolveName($trait);
            }

            if ($traitUse->adaptations !== [] && $fqcn !== null) {
                $this->openToUnnamedCallers[$fqcn] = true;
            }
        }

        if ($fqcn === null) {
            foreach ($used as $trait) {
                $this->openToUnnamedCallers[$trait] = true;
            }

            return;
        }

        $this->traitsUsed[$fqcn] = $used;

        if ($node instanceof Trait_) {
            $this->traits[$fqcn] = true;
        }
    }

    /**
     * Every class-like whose code can call a non-public method `$start` declares, or null when that
     * set cannot be listed.
     *
     * A private method is callable from the class that holds it. A trait's method is held by every
     * class that uses the trait, and a class holds the methods of every trait it uses, so the set
     * follows trait use in both directions. A protected method is callable from the ancestors and
     * descendants as well, so `$withHierarchy` adds the parent chain and the subclasses of each class.
     *
     * The set is a superset on purpose: following trait use both ways can pull in a class that only
     * shares a trait. A caller that reads every member errs toward "cannot prove", never toward a
     * caller it did not read.
     *
     * Null when a member was never scanned (a vendor parent or trait, a file that did not parse),
     * when an anonymous class extends or uses one, and when one renames a trait method: each of those
     * has code, or a name, this set cannot list. Null past {@see self::FAMILY_CAP} members too.
     *
     * @return list<string>|null
     */
    public function closedFamilyOf(string $start, bool $withHierarchy): ?array
    {
        $children = [];
        $users = [];

        foreach ($this->records as $fqcn => $record) {
            if ($record['parent'] !== null) {
                $children[$record['parent']][] = $fqcn;
            }
        }

        foreach ($this->traitsUsed as $fqcn => $traits) {
            foreach ($traits as $trait) {
                $users[$trait][] = $fqcn;
            }
        }

        $family = [];
        $queue = [$start];

        for ($head = 0; isset($queue[$head]); ++$head) {
            $member = $queue[$head];

            if (isset($family[$member])) {
                continue;
            }

            $isClass = isset($this->records[$member]);

            if ((! $isClass && ! isset($this->traits[$member]))
                || isset($this->openToUnnamedCallers[$member])
                || isset($this->anonymouslyExtended[$member])
                || count($family) >= self::FAMILY_CAP
            ) {
                return null;
            }

            $family[$member] = true;
            array_push($queue, ...$this->traitsUsed[$member] ?? [], ...$users[$member] ?? []);

            if ($withHierarchy && $isClass) {
                array_push($queue, ...array_filter([$this->records[$member]['parent']]), ...$children[$member] ?? []);
            }
        }

        return array_keys($family);
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
     * The classes an object declared as `$type` can be at runtime: `$type` itself when it is concrete,
     * and every concrete app class that extends or implements it. Null when richter never scanned
     * `$type`, because then its subtypes are unknown — a vendor type, or an app class whose file did
     * not parse — and null when an anonymous class extends `$type` or one of its subtypes, because
     * that subtype has no name to list. An enum counts as concrete: its cases are instances.
     *
     * The walk runs over ancestors, the same app-scoped relation {@see overrideEdges()} reads, so a
     * subtype reached only through a vendor class in between is not found. That is the same scope
     * every override edge has.
     *
     * @return list<string>|null
     */
    public function concreteSubtypesOf(string $type): ?array
    {
        if (! isset($this->records[$type])) {
            return null;
        }

        $subtypes = [];

        foreach ($this->records as $fqcn => $record) {
            if ($fqcn !== $type && ! in_array($type, $this->ancestorsOf($fqcn), true)) {
                continue;
            }

            if (isset($this->anonymouslyExtended[$fqcn])) {
                return null;
            }

            if ($record['concrete']) {
                $subtypes[] = $fqcn;
            }
        }

        return $subtypes;
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
        $traits = self::traitDeclarations($edges);

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

                $ancestor = self::nearestDeclarerIn($inheritance, $traits, $class, $method);

                if ($ancestor !== null) {
                    $inherited["{$class}::{$method}"] = ['source' => "{$class}::{$method}", 'target' => "{$ancestor}::{$method}", 'type' => 'inherits'];
                }
            }
        }

        return array_values($inherited);
    }

    /**
     * The trait or ancestor class whose body runs for the method — the one that actually runs. A
     * class's own traits come first, then its parent and the parent's traits, the same order PHP
     * resolves them in. An interface declares no body, so an edge to it would carry reachability to
     * code that does not exist. Stops at the first ancestor richter did not scan, matching
     * {@see ancestorsOf()}'s app-scoped rule.
     *
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     * @param  array{uses: array<string, list<string>>, declared: array<string, array<string, true>>}  $traits
     */
    private static function nearestDeclarerIn(array $inheritance, array $traits, string $fqcn, string $method): ?string
    {
        $trait = self::traitDeclaring($traits, $fqcn, $method);

        if ($trait !== null) {
            return $trait;
        }

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

            $trait = self::traitDeclaring($traits, $parent, $method);

            if ($trait !== null) {
                return $trait;
            }

            $parent = $record['parent'];
        }

        return null;
    }

    /**
     * The traits each class-like uses and the methods each class-like declares, read from the
     * `uses-trait` and `declares` edges: the records skip traits, because a trait is never a target
     * of an override.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return array{uses: array<string, list<string>>, declared: array<string, array<string, true>>}
     */
    private static function traitDeclarations(array $edges): array
    {
        $uses = [];
        $declared = [];

        foreach ($edges as $edge) {
            if ($edge['type'] === 'uses-trait') {
                $uses[$edge['source']][] = $edge['target'];
            } elseif ($edge['type'] === 'declares' && str_starts_with($edge['target'], $edge['source'] . '::')) {
                $declared[$edge['source']][substr($edge['target'], strlen($edge['source']) + 2)] = true;
            }
        }

        return ['uses' => $uses, 'declared' => $declared];
    }

    /**
     * The trait, among those the class uses directly or through another trait, that declares the
     * method. Breadth first, so a trait the class names wins over one a trait pulls in.
     *
     * @param  array{uses: array<string, list<string>>, declared: array<string, array<string, true>>}  $traits
     */
    private static function traitDeclaring(array $traits, string $fqcn, string $method): ?string
    {
        $pending = $traits['uses'][$fqcn] ?? [];
        $seen = [];

        while ($pending !== []) {
            $trait = array_shift($pending);

            if (isset($seen[$trait])) {
                continue;
            }

            $seen[$trait] = true;

            if (isset($traits['declared'][$trait][$method])) {
                return $trait;
            }

            array_push($pending, ...$traits['uses'][$trait] ?? []);
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
