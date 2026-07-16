<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Support\AppFiles;
use Symfony\Component\Finder\Finder;

/**
 * Laravel Brain anchors a view to its rendering controller (`action-to-view`) but never descends
 * into the views that view itself renders — its `<x-...>` components and `@include`/`@extends`
 * targets are captured only as inert metadata, with no edge. So a change to a nested component (e.g.
 * the action-buttons partial that decides which buttons a dashboard card shows) reaches no entry
 * point and reads as "no impact".
 *
 * Emits the missing `view-to-view` edges (parent renders child), keyed by the same node id Brain
 * uses ({@see BladeViews}) so they join the graph. Only references that resolve to a real Blade file
 * under `resources/views` are linked, so a class-backed `<x-...>` with no view template adds no edge.
 *
 * Dev/CI tooling only.
 */
final class BladeViewTracer
{
    private const string VIEWS_DIR = '/resources/views';

    private const string BLADE_EXT = '.blade.php';

    /** Blade directives whose first string argument is a view name. */
    private const string INCLUDE_PATTERN = '/@(?:include|includeIf|extends|component|each)\s*\(\s*[\'"]([^\'"]+)[\'"]/';

    /** Component tags `<x-foo.bar ...>` / `<x-foo.bar/>` — the dots are path segments under `components/`. */
    private const string COMPONENT_PATTERN = '/<\s*x-([A-Za-z0-9._:-]+)/';

    /** Tags that are not anonymous view components. */
    private const array NON_VIEW_TAGS = ['slot', 'dynamic-component'];

    /** @return list<array{source: string, target: string, type: string}> */
    public function trace(string $projectRoot): array
    {
        $viewsRoot = $projectRoot . self::VIEWS_DIR;

        if (! is_dir($viewsRoot)) {
            return [];
        }

        $edges = [];

        foreach (Finder::create()->files()->in($viewsRoot)->name('*.blade.php') as $file) {
            $sourceView = BladeViews::viewNameFromPath(substr($file->getPathname(), strlen($projectRoot) + 1));

            if ($sourceView === null) {
                continue;
            }

            $sourceId = BladeViews::nodeId($sourceView);

            foreach ($this->referencedViewCandidates((string) file_get_contents($file->getPathname())) as $candidate) {
                if ($this->viewFileExists($viewsRoot, $candidate)) {
                    $edges[] = ['source' => $sourceId, 'target' => BladeViews::nodeId($candidate), 'type' => 'view-to-view'];
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * Candidate view names referenced by one Blade file — both `@include`-family targets and the view
     * a `<x-...>` component resolves to (single-file and folder-`index` forms both offered; the caller
     * keeps whichever exists). Pure over the source so the parsing is unit-testable without a filesystem.
     *
     * @return list<string>
     */
    public function referencedViewCandidates(string $content): array
    {
        $names = [];

        if (preg_match_all(self::INCLUDE_PATTERN, $content, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                // A namespaced (`pkg::view`) or dynamic (`$var`) name can't be pinned to a file.
                if (! str_contains($raw, '::') && ! str_contains($raw, '$')) {
                    $names[] = $raw;
                }
            }
        }

        if (preg_match_all(self::COMPONENT_PATTERN, $content, $matches) > 0) {
            foreach ($matches[1] as $tag) {
                if (in_array($tag, self::NON_VIEW_TAGS, strict: true)) {
                    continue;
                }

                if (str_contains($tag, '::')) {
                    continue;
                }

                $names[] = 'components.' . $tag;
                $names[] = 'components.' . $tag . '.index';
            }
        }

        return array_values(array_unique($names));
    }

    private function viewFileExists(string $viewsRoot, string $viewName): bool
    {
        return is_file($viewsRoot . '/' . str_replace('.', '/', $viewName) . self::BLADE_EXT);
    }
}
