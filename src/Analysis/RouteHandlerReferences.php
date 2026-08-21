<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\AppNamespace;

/**
 * The class-driven half of "does a test reference this route".
 *
 * {@see TestReferenceIndex} matches a route two ways: by route NAME and by literal URI. A Livewire
 * component or a Filament page is driven by neither — `livewire(SomePage::class)` names the CLASS —
 * so a surface a test genuinely exercises reads unreferenced. This follows the route to whatever
 * handles it and lets an import of that class count.
 *
 * Two hops, not one: a controller route lands on its class directly, while a Filament resource route
 * arrives as `filament-route-to-resource` and needs `filament-resource-to-page` to reach the page.
 *
 * Runnable-only is what keeps this from lowering precision. `TestReferenceIndex::fromTests()` indexes
 * every PHP file under `tests/`, fixtures and base cases included, and letting one of those grade a
 * surface "referenced" would open a false LOW — the one direction the risk model must not fail in.
 *
 * Lives beside the index rather than inside it: that class sits at its complexity ceiling.
 */
final readonly class RouteHandlerReferences
{
    /** Brain's route-to-handler vocabulary, walked in both hops. */
    private const array HANDLER_EDGES = [
        'route-to-controller',
        'controller-to-action',
        'filament-route-to-page',
        'filament-route-to-resource',
        'filament-resource-to-page',
    ];

    public function __construct(private CodeGraph $graph) {}

    /**
     * The runnable test files importing the class this node is handled by.
     *
     * @param  array<string, list<string>>  $importsByClass  FQCN => every test file importing it
     * @return list<string>
     */
    public function testsDriving(string $node, array $importsByClass): array
    {
        $tests = [];

        foreach ($this->handlerClasses($node, depth: 2) as $class) {
            $tests = [...$tests, ...TestReferenceIndex::runnableOnly($importsByClass[$class] ?? [])];
        }

        return array_values(array_unique($tests));
    }

    /**
     * The app classes a node reaches over the handler vocabulary.
     *
     * @return list<string>
     */
    private function handlerClasses(string $node, int $depth): array
    {
        if ($depth === 0) {
            return [];
        }

        $classes = [];

        foreach (self::HANDLER_EDGES as $type) {
            foreach ($this->graph->outgoingTargetsOfType([$node], $type) as $target) {
                $class = explode('::', $target, 2)[0];

                if (AppNamespace::isAppClass($class)) {
                    $classes[] = $class;
                }

                $classes = [...$classes, ...$this->handlerClasses($target, $depth - 1)];
            }
        }

        return array_values(array_unique($classes));
    }
}
