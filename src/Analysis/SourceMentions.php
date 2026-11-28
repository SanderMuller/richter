<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Symfony\Component\Finder\Finder;

/**
 * Which source files of an application mention something, in one pass over them.
 *
 * {@see NamedFileImpact} asks who names a changed config key, a seeder class or a directory. The
 * answer is a text match over the PHP the application and its tests are made of. A text match
 * over-matches (a comment, an unrelated string), and that is the safe direction for every caller:
 * a mention more selects a test more, or keeps a veto.
 *
 * @internal
 */
final class SourceMentions
{
    /** The directories whose PHP runs when a test runs, and the views it renders. */
    private const array DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests', 'resources/views'];

    /**
     * @param  array<string, string>  $patterns  id => regex
     * @param  list<string>  $also  more project-relative directories whose PHP runs, such as a PSR-4
     *   path outside `app/`
     * @return array<string, list<string>> id => the project-relative files that match, sorted
     */
    public static function in(string $projectRoot, array $patterns, array $also = []): array
    {
        $found = array_fill_keys(array_keys($patterns), []);
        $directories = [];

        foreach ([...self::DIRECTORIES, ...$also] as $directory) {
            $directory = trim($directory, '/');
            $covered = array_any($directories, static fn (string $kept): bool => $directory === $kept || str_starts_with($directory, "{$kept}/"));

            if (! $covered && is_dir("{$projectRoot}/{$directory}")) {
                $directories[] = $directory;
            }
        }

        $directories = array_map(static fn (string $directory): string => "{$projectRoot}/{$directory}", $directories);

        if ($patterns === [] || $directories === []) {
            return $found;
        }

        // `bootstrap/cache` holds generated manifests, not source.
        foreach (Finder::create()->files()->in($directories)->name('*.php')->notPath('#^cache/#')->sortByName() as $file) {
            $source = $file->getContents();
            $relative = substr($file->getPathname(), strlen($projectRoot) + 1);

            foreach ($patterns as $id => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $found[$id][] = $relative;
                }
            }
        }

        return $found;
    }
}
