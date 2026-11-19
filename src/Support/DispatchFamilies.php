<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * Turns the factory dispatches {@see DispatchEdgeTracer} bounded by a declared type into edges, once
 * {@see CodeGraphBuilder} has collected every class.
 *
 * A dispatch declared to return `T` runs the `handle()` of whichever concrete class it built, and PHP
 * guarantees that class is `T` or a subtype of it. So the dispatcher gets an `action-to-job` edge to
 * every concrete dispatch target under `T` — wider than the one job that runs, and never narrower than
 * the app hierarchy richter scanned ({@see ClassHierarchyTracer::concreteSubtypesOf()}). A `T` with
 * no known dispatch target under it — never scanned, or extended by an anonymous class — or with a
 * concrete subtype that is no dispatch target becomes a site again. Only `app/` is scanned, so a
 * subtype declared elsewhere (`bootstrap/`, `routes/`) is not seen.
 *
 * @internal
 */
final class DispatchFamilies
{
    /**
     * @param  list<array{file: string, line: int, dispatcher: string, type: string}>  $families
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedSites: list<array{file: string, line: int, dispatcher: string}>}
     */
    public static function expand(array $families, ClassHierarchyTracer $hierarchy): array
    {
        $edges = [];
        $unresolvedSites = [];
        $subtypesByType = [];

        foreach ($families as ['file' => $file, 'line' => $line, 'dispatcher' => $dispatcher, 'type' => $type]) {
            $subtypes = array_key_exists($type, $subtypesByType)
                ? $subtypesByType[$type]
                : $subtypesByType[$type] = $hierarchy->concreteSubtypesOf($type);

            $targets = array_values(array_filter($subtypes ?? [], DispatchTarget::matches(...)));

            foreach ($targets as $target) {
                $edges[] = ['source' => $dispatcher, 'target' => $target . '::handle', 'type' => 'action-to-job'];
            }

            // The site stays unless every class the object can be is a target with an edge. No target
            // means the object comes from somewhere richter cannot see (a container binding to a vendor
            // class, say), and a subtype that is no target can still run code through a `Bus::map`
            // handler that no edge reaches.
            if ($subtypes === null || $targets === [] || count($targets) !== count($subtypes)) {
                $unresolvedSites[] = ['file' => $file, 'line' => $line, 'dispatcher' => $dispatcher];
            }
        }

        return ['edges' => $edges, 'unresolvedSites' => $unresolvedSites];
    }
}
