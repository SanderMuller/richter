<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Throwable;

/**
 * The paths of a project that Composer loads PHP from, outside `vendor/`.
 *
 * Two sources, because neither is complete. The root `composer.json` names the application's own
 * PSR-4, classmap and `files` paths. The installed autoload maps name every package's as well, and a
 * package installed from a path repository is a symlink in `vendor/` to a directory of the project
 * (`packages/acme/billing`), so its real path is the one a diff shows.
 *
 * @internal
 */
final class ComposerAutoloadPaths
{
    /** @return list<string> project-relative paths, a directory or a file each, without a trailing slash */
    public static function in(string $projectRoot): array
    {
        $root = realpath($projectRoot);

        if ($root === false) {
            return [];
        }

        $paths = [];

        foreach (self::declared($projectRoot) as $path) {
            $paths[trim($path, './')] = true;
        }

        foreach (self::installed($projectRoot) as $path) {
            $real = realpath($path);

            // Inside the project and not under `vendor/`: a path-repository package, or the
            // application's own entry again.
            if ($real !== false && str_starts_with($real, "{$root}/") && ! str_starts_with($real, "{$root}/vendor/")) {
                $paths[substr($real, strlen($root) + 1)] = true;
            }
        }

        unset($paths['']);

        return array_keys($paths);
    }

    /** @return list<string> */
    private static function declared(string $projectRoot): array
    {
        $composer = json_decode((string) @file_get_contents("{$projectRoot}/composer.json"), associative: true);
        $paths = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $declared = is_array($composer) && is_array($composer[$section] ?? null) ? $composer[$section] : [];

            array_walk_recursive($declared, static function (mixed $path) use (&$paths): void {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            });
        }

        return $paths;
    }

    /** @return list<string> absolute paths from Composer's generated maps */
    private static function installed(string $projectRoot): array
    {
        $paths = [];

        foreach (['autoload_psr4.php', 'autoload_classmap.php', 'autoload_files.php'] as $map) {
            $file = "{$projectRoot}/vendor/composer/{$map}";

            try {
                // Plain data files: each returns an array built from its own `$vendorDir`/`$baseDir`.
                $entries = is_file($file) ? include $file : [];
            } catch (Throwable) {
                $entries = [];
            }

            if (is_array($entries)) {
                array_walk_recursive($entries, static function (mixed $path) use (&$paths): void {
                    if (is_string($path)) {
                        $paths[] = $path;
                    }
                });
            }
        }

        return $paths;
    }
}
