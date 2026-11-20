<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\RelationIndex;

/**
 * The resolving half of {@see RelationTraversalTracer}: walks the steps it recorded against the
 * finished {@see RelationIndex}, in source order per method.
 *
 * @internal
 */
final readonly class RelationChainResolver
{
    /** Builder methods that return the builder, so a scope can still follow them. */
    private const string BUILDER_RETURNING = '/^(?:(?:or)?where\\w*|orderBy\\w*|reorder|inRandomOrder|latest|oldest|limit|take|skip|offset|forPage|with\\w*|without\\w*|has|doesntHave|select|addSelect|distinct|groupBy|having|when|unless|onlyTrashed|lockForUpdate|sharedLock)$/i';

    public function __construct(private RelationIndex $index) {}

    /**
     * The edges every recorded method resolves to.
     *
     * Steps run in source order, because a local carries a type only after the line that bound it: an
     * assignment resolves its own chain, then binds the variable to whatever that chain ended on. A
     * hop the index cannot name ends its chain with no edge, and clears the variable it was going to
     * bind — a reassignment richter cannot follow must not leave the old type standing.
     *
     * @param  list<array{source: string, seeds: array<string, string>, steps: list<array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}>}>  $pending
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(array $pending): array
    {
        $edges = [];

        foreach ($pending as $method) {
            $edges = [...$edges, ...$this->methodEdges($method)];
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * @param  array{source: string, seeds: array<string, string>, steps: list<array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}>}  $method
     * @return list<array{source: string, target: string, type: string}>
     */
    private function methodEdges(array $method): array
    {
        $edges = [];
        $types = $method['seeds'];
        // The locals whose type came out of a relation hop, as opposed to one the source states.
        $loaded = [];

        foreach ($method['steps'] as $step) {
            $class = $step['rootClass'] ?? ($step['rootVar'] === null ? null : ($types[$step['rootVar']] ?? null));
            $rootLoaded = $step['rootClass'] === null && isset($loaded[$step['rootVar'] ?? '']);

            // An own method the index reads as a relation (one that declares an app relation
            // class) is a relation hop on the enclosing class, not a root.
            $ownCall = $step['ownCall'] ?? null;

            if ($ownCall !== null && $this->index->relationOf($ownCall['class'], $ownCall['method']) !== null) {
                // Every name the step kept follows the own call, and they are its builder calls when all
                // of them are calls.
                $calls = count($step['builderCalls'] ?? []) === count($step['names']) - 1 && $step['stopAfter'] === 0 ? $step['names'] : [];
                [$class, $step] = [$ownCall['class'], [...$step, 'names' => [$ownCall['method']], 'stopAfter' => 0, 'builderCalls' => $calls]];
            }

            if ($class !== null) {
                [$class, $stepEdges] = $this->walk($class, $step, $method['source'], $rootLoaded);
                $edges = [...$edges, ...$stepEdges];
            }

            if ($step['assign'] === null) {
                continue;
            }

            // An unresolved right-hand side unbinds the variable rather than leaving a stale type on
            // it. Every name resolved as a relation when a walk ends on a class, so a non-empty walk
            // that does is a loaded model.
            unset($types[$step['assign']], $loaded[$step['assign']]);

            if ($class !== null) {
                $types[$step['assign']] = $class;
            }

            if ($class !== null && $step['names'] !== []) {
                $loaded[$step['assign']] = true;
            }
        }

        return $edges;
    }

    /**
     * One chain walked from a known class: the edges it draws, and the class it ends on (null when a
     * hop did not resolve, or when the chain ended on a collection or a builder).
     *
     * A call the index cannot place as a relation is a call on the model the walk holds. It is drawn
     * only when a relation hop loaded that model, in this chain or in the local it starts from.
     * `elements` asks for the related model of a final to-many hop: what a `foreach` binds.
     *
     * @param  array{assign: string|null, rootClass: string|null, rootVar: string|null, names: list<string>, stopAfter: int|null, ownCall?: array{class: string, method: string}, elements?: true, builderCalls?: list<string>}  $step
     * @return array{0: string|null, 1: list<array{source: string, target: string, type: string}>}
     */
    private function walk(string $class, array $step, string $source, bool $rootLoaded): array
    {
        $edges = [];
        $last = count($step['names']) - 1;

        foreach ($step['names'] as $position => $name) {
            $relation = $this->index->relationOf($class, $name);

            if ($relation === null) {
                if ($position === $step['stopAfter'] && ($position > 0 || $rootLoaded)) {
                    $edges[] = ['source' => $source, 'target' => "{$class}::{$name}", 'type' => 'instance-call'];
                }

                return [null, $edges];
            }

            $edges[] = ['source' => $source, 'target' => "{$relation['owner']}::{$relation['method']}", 'type' => 'loads-relation'];

            if ($relation['toMany'] && isset($step['elements']) && $position === $last) {
                return [$relation['related'], $edges];
            }

            if ($position === $step['stopAfter']) {
                return [null, [...$edges, ...$this->scopeEdges($relation['related'], $step['builderCalls'] ?? [], $source)]];
            }

            // A collection, or a query builder from the method form: nothing left that the next name
            // could be a relation on, and nothing a variable could be bound to either.
            if ($relation['toMany']) {
                return [null, $edges];
            }

            $class = $relation['related'];
        }

        // A `foreach` over a single model iterates its attributes, so it binds no model.
        return [isset($step['elements']) ? null : $class, $edges];
    }

    /**
     * The scope methods a run of calls on a relation's query builder runs. The run ends at the first
     * call that is neither a scope nor a method that hands the builder back
     * ({@see self::BUILDER_RETURNING}): after `get()` or `count()` a name is no scope.
     *
     * @param  list<string>  $calls
     * @return list<array{source: string, target: string, type: string}>
     */
    private function scopeEdges(string $model, array $calls, string $source): array
    {
        $edges = [];

        foreach ($calls as $call) {
            $scope = $this->index->scopeOf($model, $call);

            if ($scope !== null) {
                $edges[] = ['source' => $source, 'target' => $scope, 'type' => 'instance-call'];

                continue;
            }

            if (preg_match(self::BUILDER_RETURNING, $call) !== 1) {
                break;
            }
        }

        return $edges;
    }
}
