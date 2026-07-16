<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

/**
 * Maps a changed file's path to its fully-qualified class name, so a diff hunk under app/ can be
 * looked up against the FQCN-keyed code graph.
 */
final class Fqcn
{
    public static function fromPath(string $path): string
    {
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Only the app/ tree maps to the App\ namespace. A path that merely contains "app/"
        // somewhere (e.g. packages/x/app/Y.php) is not App\Y, so don't force it.
        if (! str_starts_with($path, 'app/')) {
            return basename($path, '.php');
        }

        $relative = preg_replace('/\.php$/', '', substr($path, strlen('app/'))) ?? '';

        return 'App\\' . str_replace('/', '\\', $relative);
    }
}
