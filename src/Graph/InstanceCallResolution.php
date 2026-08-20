<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tracers\InstanceCallEdgeTracer;

/**
 * Keeps the `instance-call` edges that name a method the app really declares, and drops the rest.
 *
 * {@see InstanceCallEdgeTracer} reads one file at a time, so it can say
 * that `$this->hasMany(...)` calls `hasMany` on the enclosing class but not whether any app class in
 * the chain declares it. Most do not: `hasMany`, `belongsTo`, `middleware`, `authorize` and their kin
 * live in a framework base, and an edge to `App\Models\Post::hasMany` would mint a member node for a
 * method no app file contains — which {@see CodeGraphBuilder::declaresEdges()} then dresses up with a
 * `declares` edge, putting a vendor method in the report as if the application owned it.
 *
 * Whether an app class declares the method is a whole-tree question, and the merge step is where the
 * whole tree exists: the parent chains from Class-Hierarchy Analysis, and the declared members the
 * tracer branch already parsed per class-like. A method declared by the class itself, by an app
 * ancestor, or by a trait the class uses is kept; anything else is dropped rather than guessed at.
 *
 * @internal
 */
final class InstanceCallResolution
{
    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance  parent chain per FQCN
     * @param  array<string, list<array{source: string, target: string, type: string}>>  $declares  declared-member edges per FQCN
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function keepResolvable(array $edges, array $inheritance, array $declares): array
    {
        $declared = self::declaredMethods($declares);
        $traits = self::traitsUsed($edges);

        return array_values(array_filter(
            $edges,
            static fn (array $edge): bool => $edge['type'] !== 'instance-call'
                || self::isDeclaredInApp($edge['target'], $declared, $traits, $inheritance),
        ));
    }

    /**
     * @param  array<string, array<string, true>>  $declared
     * @param  array<string, list<string>>  $traits
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     */
    private static function isDeclaredInApp(string $target, array $declared, array $traits, array $inheritance): bool
    {
        $class = AppNamespace::declaringClassOf($target);

        if ($class === null) {
            return false;
        }

        $method = substr($target, strlen($class) + 2);
        // The class chain first: a trait is copied into the class that uses it, so it can only
        // explain a call the chain does not.
        $seen = [];

        for ($current = $class; $current !== null && ! isset($seen[$current]); $current = $inheritance[$current]['parent'] ?? null) {
            $seen[$current] = true;

            if (isset($declared[$current][$method])) {
                return true;
            }

            if (self::declaredByATrait($current, $method, $declared, $traits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a trait the class uses — or one of the traits THAT trait uses, and so on — declares the
     * method. A trait is copied into whatever uses it, so composition nests, and stopping at the
     * first level would drop a call to a method the outer trait really does have. The seen-set makes
     * a malformed cycle terminate rather than hang the build.
     *
     * @param  array<string, array<string, true>>  $declared
     * @param  array<string, list<string>>  $traits
     */
    private static function declaredByATrait(string $class, string $method, array $declared, array $traits): bool
    {
        $queue = $traits[$class] ?? [];
        $seen = [];

        for ($head = 0; isset($queue[$head]); ++$head) {
            $trait = $queue[$head];

            if (isset($seen[$trait])) {
                continue;
            }

            $seen[$trait] = true;

            if (isset($declared[$trait][$method])) {
                return true;
            }

            $queue = [...$queue, ...$traits[$trait] ?? []];
        }

        return false;
    }

    /**
     * @param  array<string, list<array{source: string, target: string, type: string}>>  $declares
     * @return array<string, array<string, true>> class FQCN => declared method names
     */
    private static function declaredMethods(array $declares): array
    {
        $methods = [];

        foreach ($declares as $fqcn => $classEdges) {
            foreach ($classEdges as $edge) {
                $methods[$fqcn][substr($edge['target'], strlen($fqcn) + 2)] = true;
            }
        }

        return $methods;
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return array<string, list<string>> class FQCN => the traits it uses
     */
    private static function traitsUsed(array $edges): array
    {
        $traits = [];

        foreach ($edges as $edge) {
            if ($edge['type'] === 'uses-trait') {
                $traits[$edge['source']][] = $edge['target'];
            }
        }

        return $traits;
    }
}
