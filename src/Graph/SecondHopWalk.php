<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use Closure;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tracers\EntryPointTracer;
use SanderMuller\Richter\Tracers\FacadeEdgeTracer;
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
 * methods of those classes ~4.5s. Only the last two are affordable, and the default is the last —
 * also the most precise, since a method nobody calls has no reason to be read. `richter.second_hop`
 * moves between them: `false` gives the reach back for the build time, `'class'` buys the rest of
 * each target class for the difference.
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
     * @param  'none'|'methods'|'class'  $scope  how much of a target class to read
     *   ({@see RichterConfig::secondHopScope()}).
     */
    public function __construct(private Closure $walk, private string $scope) {}

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
        if ($this->scope === 'none') {
            return ['edges' => [], 'unread' => 0];
        }

        $members = $this->candidatesIn($edges);
        $candidates = array_values(array_diff($members, array_keys($this->alreadyWalked($edges))));

        if ($this->scope === 'class') {
            $candidates = [...$candidates, ...$this->classesOf($members)];
        }

        if ($candidates === []) {
            return ['edges' => [], 'unread' => 0];
        }

        return ($this->walk)($candidates, $projectRoot);
    }

    /**
     * The class part of each member candidate, for the `class` scope — added to the member
     * candidates, never in place of them.
     *
     * The already-walked subtraction is NOT applied here, and cannot be: {@see alreadyWalked()}
     * holds MEMBER ids, so dropping `App\Support\Registry` because `Registry::other` has Brain
     * evidence would also drop `Registry::all` — the very method the static call named. A method
     * the expansion re-reads because another lane already walked it is idempotent, deduped, and
     * costs the ~4ms this class already accepts for a re-walked Brain class.
     *
     * @param  list<string>  $members  `FQCN::method` node ids
     * @return list<string>
     */
    private function classesOf(array $members): array
    {
        $classes = [];

        foreach ($members as $member) {
            $class = strstr($member, '::', before_needle: true);

            if ($class !== false && $class !== '') {
                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }

    /**
     * The `static-call` and `facade-resolves-to` targets in an edge set. Every such target names a
     * member by construction ({@see StaticCallEdgeTracer} emits `Class::method`, and
     * {@see FacadeEdgeTracer} carries that member over to the concrete), so the targets are node ids
     * the walk can address directly. A concrete reached only through a facade is the same leaf as a
     * class reached only through a static call, and the facade targets are a subset of the static-call
     * ones that already sized this pass.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<string>
     */
    private function candidatesIn(array $edges): array
    {
        $targets = [];

        foreach ($edges as $edge) {
            if (in_array($edge['type'], ['static-call', 'facade-resolves-to'], true)) {
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
