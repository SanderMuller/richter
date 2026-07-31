<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Changes\MemberResolver;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Gives class constants and enum cases member-level graph nodes so a change to one pins to the
 * members that READ it, instead of coarse-seeding the whole class (which reads as low confidence).
 * Only methods otherwise get member nodes; a constant/enum-case change collapses to the class node.
 *
 * For every `Class::CONST` / `Enum::Case` read (a `ClassConstFetch`) it emits a `references-constant`
 * edge `reader::method → DeclaringClass::CONST`, and a `declares` edge `DeclaringClass →
 * DeclaringClass::CONST` for every declared constant/case (so a read-nowhere constant still nodes as
 * a leaf rather than reading UNRESOLVED). The reader edge is oriented reader → constant, so
 * `callersOf(DeclaringClass::CONST)` yields the readers.
 *
 * The edge target is the constant's DECLARING class, resolved through the hierarchy — NOT the class
 * named in the read. A constant is inherited, so `Child` reading `self::BASE_CONST` must connect to
 * `Base::BASE_CONST`; targeting `Child::BASE_CONST` would silently drop that reader from a change to
 * the base constant (under-selection). Resolution needs the whole hierarchy, so the tracer
 * accumulates every class-like via {@see collect()} and emits once via {@see edges()} after the last
 * file — the same accumulate-then-flush shape as {@see ClassHierarchyTracer}. Ancestors are
 * app-scoped: a read whose declaring class was never scanned (a vendor constant, a dynamic `$x::C`,
 * `Foo::class`) draws no edge, and a change to such a constant then reads UNRESOLVED, never "no
 * impact". Traits are skipped (a trait constant is copied into the using class, not inherited — so it
 * stays a coarse class seed, kept resolvable-false by {@see MemberResolver}).
 *
 * Reads are gathered from the whole method — parameter defaults and attributes included, not just the
 * body (a `self::CONST` default is a real read) — and a read nested inside an anonymous class keeps a
 * named owner but drops a `self`/`static`/`parent`/`$this` owner (whose scope is the nested class,
 * not this one). See {@see constFetches()}.
 *
 * @internal
 */
final class ConstantReferenceTracer
{
    /** @var array<string, array{parent: string|null, interfaces: list<string>, constants: list<string>}> keyed by FQCN */
    private array $records = [];

    /** @var list<array{reader: string, owner: string, name: string}> */
    private array $reads = [];

    /** @param  list<ClassLike>  $classLikes  every ClassLike in one file (from the consolidated AST pass) */
    public function collect(array $classLikes): void
    {
        foreach ($classLikes as $node) {
            // A trait's constants are copied into the using class, not inherited/dispatched.
            if ($node instanceof Trait_) {
                continue;
            }

            $fqcn = $node->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $parent = $this->parentOf($node);

            $this->records[$fqcn] = [
                'parent' => $parent,
                'interfaces' => $this->interfacesOf($node),
                'constants' => $this->declaredConstants($node),
            ];

            foreach ($node->getMethods() as $method) {
                $reader = "{$fqcn}::{$method->name->toString()}";

                foreach (self::constFetches($method) as [$fetch, $nested]) {
                    // A computed name (`Foo::{$x}`) is not statically resolvable.
                    if (! $fetch->name instanceof Identifier) {
                        continue;
                    }

                    // `Foo::class` is a class reference, not a constant read.
                    if ($fetch->name->toLowerString() === 'class') {
                        continue;
                    }

                    $owner = $this->readOwner($fetch, $fqcn, $parent, $nested);

                    if ($owner !== null) {
                        $this->reads[] = ['reader' => $reader, 'owner' => $owner, 'name' => $fetch->name->toString()];
                    }
                }
            }
        }
    }

    /** @return list<array{source: string, target: string, type: string}> */
    public function edges(): array
    {
        $edges = [];

        // Every declared constant/case nodes via a declares edge — so one nobody reads is a leaf
        // ("analyzed, reaches nothing"), not UNRESOLVED.
        foreach ($this->records as $fqcn => $record) {
            foreach ($record['constants'] as $name) {
                $edges[] = ['source' => $fqcn, 'target' => "{$fqcn}::{$name}", 'type' => 'declares'];
            }
        }

        foreach ($this->reads as $read) {
            $declaring = $this->declaringClassOf($read['owner'], $read['name']);

            // App-scoped: a read whose declaring class was never scanned draws no edge.
            if ($declaring !== null) {
                $edges[] = ['source' => $read['reader'], 'target' => "{$declaring}::{$read['name']}", 'type' => 'references-constant'];
            }
        }

        return $edges;
    }

    /**
     * Every `ClassConstFetch` anywhere under a method — parameter defaults and attributes included, not
     * just the body — paired with whether it sits inside a NESTED class-like (an anonymous or inner
     * class). Walking the whole method is what catches a `self::CONST` in a parameter default; the
     * nesting flag lets {@see readOwner()} drop scope-relative owners that would resolve wrong.
     *
     * @return list<array{0: ClassConstFetch, 1: bool}>
     */
    private static function constFetches(ClassMethod $method): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{0: ClassConstFetch, 1: bool}> */
            public array $found = [];

            private int $depth = 0;

            public function enterNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    ++$this->depth;
                } elseif ($node instanceof ClassConstFetch) {
                    $this->found[] = [$node, $this->depth > 0];
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    --$this->depth;
                }

                return null;
            }
        };

        new NodeTraverser($visitor)->traverse([$method]);

        return $visitor->found;
    }

    /**
     * The FQCN the read's owner names: `self`/`static`/`$this` → the enclosing class; `parent` → its
     * parent; a written class name → its resolved FQCN. A scope-relative owner (`self`/`static`/
     * `parent`/`$this`) inside a nested class-like is dropped — its scope is that nested class, not
     * this one. Null for a dynamic `$var::C` or `parent::` with no parent.
     */
    private function readOwner(ClassConstFetch $fetch, string $enclosingFqcn, ?string $parentFqcn, bool $nested): ?string
    {
        // `$this::CONST` — late static binding via the instance; scope-relative to the enclosing class.
        if ($fetch->class instanceof Variable && $fetch->class->name === 'this') {
            return $nested ? null : $enclosingFqcn;
        }

        // Any other dynamic owner (`$var::C`) is not statically resolvable.
        if (! $fetch->class instanceof Name) {
            return null;
        }

        if ($fetch->class->isSpecialClassName()) {
            // self/static/parent are relative to the LEXICAL class; inside a nested class-like they
            // resolve against the wrong scope, so skip them there (a named owner is fine either way).
            if ($nested) {
                return null;
            }

            return match (strtolower($fetch->class->toString())) {
                'self', 'static' => $enclosingFqcn,
                'parent' => $parentFqcn,
                default => null,
            };
        }

        return AppFiles::resolveName($fetch->class);
    }

    /** The nearest app-scanned class in the owner's hierarchy that declares the constant, or null. */
    private function declaringClassOf(string $owner, string $name): ?string
    {
        if ($this->declares($owner, $name)) {
            return $owner;
        }

        foreach ($this->ancestorsOf($owner) as $ancestor) {
            if ($this->declares($ancestor, $name)) {
                return $ancestor;
            }
        }

        return null;
    }

    private function declares(string $fqcn, string $name): bool
    {
        return in_array($name, $this->records[$fqcn]['constants'] ?? [], true);
    }

    private function parentOf(ClassLike $node): ?string
    {
        return $node instanceof Class_ && $node->extends instanceof Name
            ? AppFiles::resolveName($node->extends)
            : null;
    }

    /** @return list<string> */
    private function interfacesOf(ClassLike $node): array
    {
        $names = match (true) {
            $node instanceof Class_, $node instanceof Enum_ => $node->implements,
            $node instanceof Interface_ => $node->extends,
            default => [],
        };

        return array_values(array_map(AppFiles::resolveName(...), $names));
    }

    /** @return list<string> */
    private function declaredConstants(ClassLike $node): array
    {
        $names = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof ClassConst) {
                foreach ($stmt->consts as $const) {
                    $names[] = $const->name->toString();
                }
            } elseif ($stmt instanceof EnumCase) {
                $names[] = $stmt->name->toString();
            }
        }

        return $names;
    }

    /**
     * App-scanned ancestors of a class, nearest first (BFS over the parent chain + implemented
     * interfaces, transitively). A vendor ancestor is absent from the records, so traversal stops at
     * it — CHA-style app-scoping.
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
