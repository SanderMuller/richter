<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;

/**
 * An instance method call draws no edge anywhere else in the build. Brain resolves calls only along
 * its route-anchored lanes (`action-to-service`, `controller-to-action` and the rest), and richter's
 * own tracers cover static calls, constants, relations, facades and dispatches — so for every class
 * a route does not reach, `$this->doTheWork()` is invisible. A consumer audit found a model method
 * with ten call sites in six files and no caller edge at all, five of them `$this->` in the
 * declaring file itself.
 *
 * This lane draws the most resolvable of those shapes and only that one: `$this->method()`, where
 * the receiver is the enclosing class by definition and needs no type inference. A call on a
 * property, a parameter or a local is a receiver-typing problem and is left to a later lane rather
 * than guessed at here.
 *
 * Emits an `instance-call` edge from the calling member node to `Class::method`, the same member-level
 * shape {@see StaticCallEdgeTracer} uses. The target member may be inherited rather than declared
 * here; that is deliberate, since {@see ClassHierarchyTracer::inheritedEdges()} then links the
 * referenced `Subclass::method` node to the ancestor whose body runs, which is the lane that exists
 * for exactly this.
 *
 * Two receivers that read as `$this` are refused. Inside an anonymous class `$this` is that class,
 * not the method's own, so a nested call draws nothing rather than a confidently wrong edge — the
 * same rule {@see StaticCallEdgeTracer} applies to `self`/`static`. Inside a TRAIT, `$this` is the
 * consuming class at runtime, so a call is drawn only for a method the trait itself declares; naming
 * the trait for anything else would name a class that never ran it.
 *
 * Dev/CI tooling only.
 */
final class InstanceCallEdgeTracer
{
    /** @return list<array{source: string, target: string, type: string}> */
    public function edgesForSource(string $source): array
    {
        $ast = AppFiles::parseResolved($source);

        return $ast === null ? [] : $this->edgesForClassLikes(array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class)));
    }

    /**
     * @param  list<ClassLike>  $classLikes  every class-like in the file, any depth
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForClassLikes(array $classLikes): array
    {
        $edges = [];

        foreach ($classLikes as $classLike) {
            $fqcn = $classLike->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $declared = array_map(static fn (ClassMethod $method): string => $method->name->toString(), $classLike->getMethods());
            $ownOnly = $classLike instanceof Trait_;

            foreach ($classLike->getMethods() as $method) {
                foreach ($this->edgesForMethod($method, ltrim($fqcn, '\\'), $declared, $ownOnly) as $edge) {
                    $edges[] = $edge;
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * @param  list<string>  $declared  the method names this class-like writes out
     * @param  bool  $ownOnly  draw only for a declared method — true inside a trait
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edgesForMethod(ClassMethod $method, string $fqcn, array $declared, bool $ownOnly): array
    {
        $source = $fqcn . '::' . $method->name->toString();
        $edges = [];

        foreach (AppFiles::nodesOwnedByWithNesting($method, $this->isSelfCall(...)) as [$call, $nested]) {
            /** @var MethodCall|NullsafeMethodCall $call */
            if ($nested || ! $call->name instanceof Identifier) {
                continue;
            }

            $callee = $call->name->toString();
            $target = "{$fqcn}::{$callee}";

            // Recursion is not reach: a member that calls itself tells a reader nothing, and the
            // self-edge would draw a loop in every rendered chain through it.
            if ($target === $source || ($ownOnly && ! in_array($callee, $declared, true))) {
                continue;
            }

            $edges[] = ['source' => $source, 'target' => $target, 'type' => 'instance-call'];
        }

        return $edges;
    }

    /** A call whose receiver is the literal `$this` — the only receiver this lane resolves. */
    private function isSelfCall(Node $node): bool
    {
        return ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->var instanceof Variable
            && $node->var->name === 'this';
    }
}
