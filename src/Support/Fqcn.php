<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

/**
 * Maps a changed file's path to its fully-qualified class name, so a diff hunk under app/ can be
 * looked up against the FQCN-keyed code graph. The namespace root comes from {@see AppNamespace},
 * not the `App\` literal — an app that maps another PSR-4 root to app/ must resolve too.
 */
final class Fqcn
{
    public static function fromPath(string $path): string
    {
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Only the app/ tree maps to the app's root namespace. A path that merely contains "app/"
        // somewhere (e.g. packages/x/app/Y.php) is not rooted there, so don't force it.
        if (! str_starts_with($path, 'app/')) {
            return basename($path, '.php');
        }

        $relative = preg_replace('/\.php$/', '', substr($path, strlen('app/'))) ?? '';

        return AppNamespace::qualify(str_replace('/', '\\', $relative));
    }
}
