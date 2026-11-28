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
     * @return array<string, list<string>> id => the project-relative files that match, sorted
     */
    public static function in(string $projectRoot, array $patterns): array
    {
        $found = array_fill_keys(array_keys($patterns), []);
        $directories = array_values(array_filter(
            array_map(static fn (string $directory): string => "{$projectRoot}/{$directory}", self::DIRECTORIES),
            is_dir(...),
        ));

        if ($patterns === [] || $directories === []) {
            return $found;
        }

        foreach (Finder::create()->files()->in($directories)->name('*.php')->sortByName() as $file) {
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
