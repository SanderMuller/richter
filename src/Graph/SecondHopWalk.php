<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use Closure;
use SanderMuller\Richter\Tracers\EntryPointTracer;
use SanderMuller\Richter\Tracers\StaticCallEdgeTracer;

/**
 * Reads the bodies of the methods richter's own edges placed, one hop past the merged graph.
 *
 * A method body is walked for the calls it makes in exactly two places: Laravel Brain's call-chain
 * analysis, which is anchored on routes, and {@see EntryPointTracer::trace()}, which walks every
 * method under `richter.entry_point_roots`. Neither covers a class that entered the graph only as
 * the target of a `static-call` edge — a static registry, a named constructor, a factory. Its node
 * exists and nothing it constructs or calls is visible, so the report is quietly narrower than the
 * app. This closes that hop, and by putting the reached member nodes into the edge set it is also
 * what lets {@see ClassHierarchyTracer::inheritedEdgesFor()} draw an `inherits` edge to an
 * inherited method's work.
 *
 * Scope is a measurement, not a preference. On a 4,159-file application: walking every app class
 * costs ~78s, adding `override` targets ~41s, `static-call` target classes ~8.0s, and the called
 * methods of those classes ~4.5s. Only the last is affordable — and it is also the most precise,
 * since a method nobody calls has no reason to be read. `richter.second_hop` trades that reach back
 * for the build time.
 *
 * @internal
 */
final readonly class SecondHopWalk
{
    /**
     * Edge types that are evidence a body was already read: the vocabulary
     * {@see EntryPointTracer::traceMethod()} passes through from Brain's `CallChainEdge`.
     *
     * Brain's OWN prefixed forms (`action-to-service`, `command-to-…`) are deliberately absent.
     * `action-to-job` is emitted both by Brain's call chain and by richter's per-file dispatch
     * tracer, which reads no body at all — so matching prefixed types would mark a class as walked
     * when it was not, skipping exactly the classes this walk exists to reach. The cost of the
     * narrower test is re-walking what Brain already covered: idempotent, deduped, ~4ms each.
     *
     * @var list<string>
     */
    private const array WALKED_EVIDENCE = [
        'service', 'repository', 'model', 'job', 'event', 'action', 'view', 'mail',
        'notification', 'enum', 'interface', 'trait', 'abstract_class', 'resource',
        'references', 'validates-with',
    ];

    /**
     * @param  Closure(list<string>, string): array{edges: list<array{source: string, target: string, type: string}>, unread: int}  $walk
     *   the body walk itself — {@see EntryPointTracer::traceMembers()} in the build, a stub in tests.
     *   A closure rather than a type: the tracer is `final` by the package's own convention, and one
     *   method does not earn this package its first interface.
     */
    public function __construct(private Closure $walk, private bool $enabled) {}

    /**
     * The edges the walk adds, plus how many of the methods it tried to read it could not.
     *
     * One round, not an iteration. {@see StaticCallEdgeTracer} runs
     * per file across the whole app, so every static call is already an edge before this starts:
     * in a chain `A::x → B::y → C::z` both edges exist up front and both targets are candidates
     * here. There is no second hop to discover — walking a body yields Brain's call-chain
     * vocabulary, never another `static-call`.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges  the merged graph so far
     * @return array{edges: list<array{source: string, target: string, type: string}>, unread: int}
     */
    public function edgesFor(array $edges, string $projectRoot): array
    {
        $candidates = array_values(array_diff($this->candidatesIn($edges), array_keys($this->alreadyWalked($edges))));

        if (! $this->enabled || $candidates === []) {
            return ['edges' => [], 'unread' => 0];
        }

        return ($this->walk)($candidates, $projectRoot);
    }

    /**
     * The `static-call` targets in an edge set. Every such target names a member by construction
     * ({@see StaticCallEdgeTracer} emits `Class::method`), so the
     * targets are node ids the walk can address directly.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<string>
     */
    private function candidatesIn(array $edges): array
    {
        $targets = [];

        foreach ($edges as $edge) {
            if ($edge['type'] === 'static-call') {
                $targets[$edge['target']] = true;
            }
        }

        return array_keys($targets);
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return array<string, true>
     */
    private function alreadyWalked(array $edges): array
    {
        $sources = [];

        foreach ($edges as $edge) {
            if (in_array($edge['type'], self::WALKED_EVIDENCE, true)) {
                $sources[$edge['source']] = true;
            }
        }

        return $sources;
    }
}
