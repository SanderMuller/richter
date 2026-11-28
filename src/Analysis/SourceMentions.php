<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Symfony\Component\Finder\Finder;

/**
 * Which source files of an application mention something, in one pass over them.
 *
 * {@see NamedFileImpact} asks who names a changed config key, a seeder class or a directory. The
 * answer is a text match over the PHP the application and its tests are made of. A text match
 * over-matches (a comment, an unrelated string). A mention more selects a test more. For a config
 * file or a fixture it also replaces the "nothing names it" veto with a mapping, so a false match
 * there can select too little: the price of mapping these files at all.
 *
 * It under-matches too. `vendor/` is not read, and a path or key built at runtime has no literal
 * to find.
 *
 * @internal
 */
final class SourceMentions
{
    /** The directories whose PHP runs when a test runs, and the views it renders. */
    private const array DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests', 'resources/views'];

    /**
     * @param  array<string, string>  $patterns  id => regex
     * @param  list<string>  $also  more project-relative paths whose PHP runs: a PSR-4 directory
     *   outside `app/`, a Composer `files` entry
     * @return array<string, list<string>> id => the project-relative files that match, sorted
     */
    public static function in(string $projectRoot, array $patterns, array $also = []): array
    {
        $found = array_fill_keys(array_keys($patterns), []);
        $directories = [];
        $files = [];

        foreach ([...self::DIRECTORIES, ...$also] as $path) {
            $path = trim($path, '/');
            $covered = array_any($directories, static fn (string $kept): bool => $path === $kept || str_starts_with($path, "{$kept}/"));

            if ($covered) {
                continue;
            }

            if (is_dir("{$projectRoot}/{$path}")) {
                $directories[] = $path;
            } elseif (is_file("{$projectRoot}/{$path}")) {
                // A Composer `files` entry: one file, loaded on every request.
                $files[] = $path;
            }
        }

        if ($patterns === []) {
            return $found;
        }

        if ($directories !== []) {
            $finder = Finder::create()->files()->in(array_map(static fn (string $directory): string => "{$projectRoot}/{$directory}", $directories))->name('*.php')->sortByName();

            foreach ($finder as $file) {
                $files[] = substr($file->getPathname(), strlen($projectRoot) + 1);
            }
        }

        foreach (array_unique($files) as $relative) {
            // Generated manifests, not source.
            if (str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            $source = (string) file_get_contents("{$projectRoot}/{$relative}");

            foreach ($patterns as $id => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $found[$id][] = $relative;
                }
            }
        }

        return $found;
    }
}
