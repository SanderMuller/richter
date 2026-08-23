<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;
use SanderMuller\Richter\Tracers\StaticCallEdgeTracer;

/**
 * Splits the inheritance-reach section into the entries that run the changed code and the ones that
 * only share its member name.
 *
 * Both edge types behind that section are context rather than reach, but they do not make the same
 * claim. A `uses-trait` entry has the changed method copied into it, so the changed bytes execute
 * there. An `override` entry does not: {@see ClassHierarchyTracer::overrideEdges()} draws the edge from
 * a method the class DECLARES itself, so it has its own body and a change to the ancestor's is a
 * contract question, not a behaviour question. Printing fifty of the second kind beside two of the
 * first spends the reader's attention as though they were the same fact.
 *
 * The overrides are grouped by member name, which is all the reach map records — it carries the node
 * reached and the edge types that reached it, never the edge's SOURCE. So a group means "entries whose
 * member is named `m`", never "implementations of one ancestor's `m`": two classes overriding the same
 * name from unrelated hierarchies land together, and no code here can separate them without a new
 * payload field.
 *
 * Group keys are sorted here rather than left in encounter order. The incoming list is sorted by full
 * node id ({@see ImpactAnalyzer}), which orders by CLASS name, so member names would otherwise appear
 * in the order their first class happened to land — and the three formats reading this would each
 * inherit that accident.
 *
 * ## What the grouped lane may be told to claim
 *
 * Only that each entry declares the member itself. That is exact: the edge exists for no other reason.
 * The stronger sentence — the changed body does not run in them — is *usually* true as well, because an
 * override that delegates to `parent::m()` draws a `static-call` edge
 * ({@see StaticCallEdgeTracer}) which is risk-bearing, so
 * {@see ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES} keeps that entry out of this section altogether. It is
 * not universal: the model-static-method dedup in `StaticCallEdgeTracer::isBrainsToDraw()` suppresses
 * that edge and leaves Brain to draw the hop, and whether Brain's own edge is risk-bearing is untraced.
 * A universal claim with a known exception is the falsely-reassuring line this package refuses to print,
 * so the narrow claim is the one that ships. Do not "simplify" it back.
 *
 * A grouped entry is also not always a DESCENDANT. {@see CodeGraph} allows a hierarchy hop out of a
 * seed in either direction, deliberately, so a changed concrete override reaches the ancestor whose
 * member it overrides and that ancestor is what gets grouped. The claim survives — `overrideEdges()`
 * needs the method declared at BOTH ends — but every string here has to stay direction-agnostic, never
 * "descendants that override the changed member".
 *
 * @internal
 */
final class InheritanceSurfaces
{
    /**
     * Groups hold the CLASS half of each node, not the node id: the member name is already the group's
     * key, so repeating it on every line would be noise, and splitting the node in each of the three
     * formats instead of once here is how the three drift apart.
     *
     * @param  list<string>  $reach  the reported inheritance entries, in report order
     * @param  array<string, list<string>>  $via  entry => the edge types that put it here
     * @return array{0: list<string>, 1: array<string, list<string>>} the entries that stay in front of
     *                                                               the reader as node ids, then the classes of the `override` entries, grouped by member name
     */
    public static function partition(array $reach, array $via): array
    {
        $inline = [];
        $groups = [];

        foreach ($reach as $entry) {
            $reasons = $via[$entry] ?? [];

            // Strongest signal wins — the INVERSE of the association fold's weakest-link rule, and the
            // right inverse. There the question is whether any path avoids the weak edge, so one weak
            // hop condemns the surface. Here the question is whether the changed body runs anywhere in
            // this class, and one `uses-trait` reason answers yes whatever else reached it.
            if (in_array('uses-trait', $reasons, strict: true)) {
                $inline[] = $entry;

                continue;
            }

            $split = self::split($entry);

            // Grouping is gated on the `override` reason POSITIVELY, not on "whatever is left after
            // uses-trait". Only that edge type carries the claim the fold makes, so an entry routed into
            // this section under some other reason — a hand-built payload, a future edge type, an empty
            // reason list — must not inherit it. Same for a node that is not `Class::member`: it cannot
            // be grouped by a member it does not name.
            //
            // Neither branch can fire against a payload the analyzer built: `uncountedReachVia()` selects
            // an entry only when its via-type set intersects the section's types, and `overrideEdges()`
            // always writes `Class::method`. They exist so an unexpected shape is SHOWN unannotated
            // rather than folded under a sentence that is not true of it.
            if ($split === null || ! in_array('override', $reasons, strict: true)) {
                $inline[] = $entry;

                continue;
            }

            [$class, $member] = $split;
            $groups[$member][] = $class;
        }

        ksort($groups);

        return [$inline, $groups];
    }

    /**
     * A `Class::member` node as its two halves, or null when it is not a member node at all.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function split(string $node): ?array
    {
        $position = strrpos($node, '::');

        if ($position === false) {
            return null;
        }

        $class = substr($node, 0, $position);
        $member = substr($node, $position + 2);

        return $class === '' || $member === '' ? null : [$class, $member];
    }
}
