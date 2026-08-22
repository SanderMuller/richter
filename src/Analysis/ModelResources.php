<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

/**
 * The API resources that belong to a model, and how that was decided. Shared by
 * {@see PayloadParityChecker}, which asks whether a resource mirroring a model missed a field it
 * gained, and {@see ColumnReferences}, which asks whether one still carries a key for a column a
 * migration dropped.
 *
 * **Graph first, names only when the graph gave nothing.** Wiring is independent evidence that a
 * resource and a model belong together; a name match on an empty graph result is not. The two are one
 * policy rather than two lookups, which is why `candidatesFor()` returns the path it took: a caller
 * has to weigh name-matched candidates more carefully than wired ones, and splitting the lookup in two
 * would let each caller sequence them its own way.
 *
 * No whole-tree index. A model's candidates are one graph query, and only the name fallback reads a
 * directory — memoized per instance so a run pays for it once.
 *
 * @internal
 */
final class ModelResources
{
    /** The two directories {@see ReferenceEdgeTracer} maps to the `resource` edge. */
    private const array RESOURCE_DIRECTORIES = ['app/Http/Resources', 'app/Transformers'];

    /** @var list<array{fqcn: string, path: string}>|null every resource class on disk, scanned once */
    private ?array $onDisk = null;

    /** @param  string|null  $projectRoot  overrides base_path() for tests */
    public function __construct(
        private readonly CodeGraph $graph,
        private readonly ?string $projectRoot = null,
    ) {}

    /**
     * @return array{candidates: list<array{fqcn: string, path: string}>, viaGraph: bool} `viaGraph`
     *   false means the candidates are name matches, which are weaker evidence
     */
    public function candidatesFor(string $modelFqcn): array
    {
        $wired = $this->wiredCandidates($modelFqcn);

        return $wired === []
            ? ['candidates' => $this->nameMatchedCandidates($modelFqcn), 'viaGraph' => false]
            : ['candidates' => $wired, 'viaGraph' => true];
    }

    /**
     * Resources reached from the model's own nodes via {@see CodeGraph::callersOf()} at depth 2, then
     * those callers' own outgoing `resource`-typed edges. Depth 2, not the analyzer's default 6 — the
     * point of preferring wiring over names is locality; a hub model at depth 6 would pull in
     * unrelated features' resources.
     *
     * @return list<array{fqcn: string, path: string}>
     */
    private function wiredCandidates(string $modelFqcn): array
    {
        $seeds = $this->graph->nodesContaining(ltrim($modelFqcn, '\\'));

        if ($seeds === []) {
            return [];
        }

        $callerNodes = array_values(array_unique(array_map(
            static fn (array $hop): string => $hop['node'],
            $this->graph->callersOf($seeds, maxDepth: 2),
        )));

        if ($callerNodes === []) {
            return [];
        }

        $resourceFqcns = [];

        foreach ($callerNodes as $node) {
            foreach ($this->graph->dependencyEdgesOf([$node], maxDepth: 1) as $edge) {
                if ($edge['via'] === 'resource') {
                    $resourceFqcns[$edge['target']] = true;
                }
            }
        }

        $candidates = [];

        foreach (array_keys($resourceFqcns) as $fqcn) {
            $location = $this->graph->locationOf($fqcn);

            // No known location means no readable source — silently uncheckable, not a guess.
            if ($location !== null) {
                $candidates[] = ['fqcn' => $fqcn, 'path' => $location['file']];
            }
        }

        return $candidates;
    }

    /**
     * Only reached when the graph gave nothing: resources whose FQCN carries the model's short name
     * as a class-name or namespace segment, under the two namespaces
     * {@see ReferenceEdgeTracer} already treats as resources.
     *
     * @return list<array{fqcn: string, path: string}>
     */
    private function nameMatchedCandidates(string $modelFqcn): array
    {
        $shortName = $this->shortNameOf($modelFqcn);

        return array_values(array_filter(
            $this->resourcesOnDisk(),
            static fn (array $class): bool => self::matchesModelName($class['fqcn'], $shortName),
        ));
    }

    /** @return list<array{fqcn: string, path: string}> */
    private function resourcesOnDisk(): array
    {
        if ($this->onDisk !== null) {
            return $this->onDisk;
        }

        $projectRoot = rtrim($this->projectRoot ?? base_path(), '/');
        $classes = [];

        foreach (self::RESOURCE_DIRECTORIES as $relativeDir) {
            foreach (AppFiles::phpClasses("{$projectRoot}/{$relativeDir}", $projectRoot) as $class) {
                $classes[] = [
                    'fqcn' => $class['fqcn'],
                    'path' => "{$relativeDir}/" . substr($class['path'], strlen("{$projectRoot}/{$relativeDir}/")),
                ];
            }
        }

        return $this->onDisk = $classes;
    }

    private function shortNameOf(string $fqcn): string
    {
        $lastSeparator = strrchr($fqcn, '\\');

        return substr($lastSeparator !== false ? $lastSeparator : "\\{$fqcn}", 1);
    }

    /**
     * The model's short name as an exact namespace/class segment anywhere in the FQCN (the
     * `Api\v2\Post\ReviewResource` shape), OR the conventional `{Model}Resource`/`{Model}Collection`
     * class name (the far more common `PostResource`/`PostCollection` shape) — exact equality on the
     * class's own last segment, never a substring/prefix match, so model `Post` never matches
     * `PostRevisionResource`, a different model's conventionally-named resource.
     */
    private static function matchesModelName(string $resourceFqcn, string $shortName): bool
    {
        $segments = explode('\\', $resourceFqcn);

        if (in_array($shortName, $segments, strict: true)) {
            return true;
        }

        $className = $segments[array_key_last($segments)];

        return $className === "{$shortName}Resource" || $className === "{$shortName}Collection";
    }
}
