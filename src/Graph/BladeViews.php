<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use LaraMint\LaravelBrain\Graph\GraphBuilder;

/**
 * Maps a Blade view (name or file path) to the node id Laravel Brain uses for it, so the Blade
 * tracers and change-seeds below join Brain's own view nodes instead of minting parallel ones.
 *
 * Brain ids a view as `view::` + a slug of `blade__{dotted view name}`, lowercased with every
 * character outside `[a-z0-9._]` (notably `-`) folded to `_` while dots are kept — see
 * {@see GraphBuilder::viewNodeId()}. This mirrors that one rule; it is
 * the single place coupled to Brain's view id format, so a drift surfaces here and in its test, not
 * silently across the tracers.
 */
final class BladeViews
{
    private const string VIEWS_DIR = 'resources/views/';

    private const string BLADE_EXT = '.blade.php';

    /** Brain's blade-fqcn prefix; the view node id carries it verbatim (`view::blade__dashboard.home`). */
    private const string BLADE_FQCN_PREFIX = 'blade__';

    public static function nodeId(string $viewName): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9._]/', '_', self::BLADE_FQCN_PREFIX . $viewName));

        return 'view::' . $slug;
    }

    /** Dotted view name for a project-root-relative path, or null when it is not a `resources/views` Blade file. */
    public static function viewNameFromPath(string $relativePath): ?string
    {
        if (str_starts_with($relativePath, './')) {
            $relativePath = substr($relativePath, 2);
        }

        if (! str_starts_with($relativePath, self::VIEWS_DIR) || ! str_ends_with($relativePath, self::BLADE_EXT)) {
            return null;
        }

        $inner = substr($relativePath, strlen(self::VIEWS_DIR), -strlen(self::BLADE_EXT));

        return str_replace('/', '.', $inner);
    }

    /** The view node id a changed Blade file should seed, or null when the path is not a `resources/views` Blade file. */
    public static function seedForChangedFile(string $relativePath): ?string
    {
        $viewName = self::viewNameFromPath($relativePath);

        return $viewName === null ? null : self::nodeId($viewName);
    }
}
