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

    /**
     * The nodes among `$nodes` that name a MEMBER of `$fqcn`, or `$nodes` unchanged when none does.
     * Matched on the `Fqcn::` prefix rather than on a bare `::`, since a node-id set gathered by
     * substring lookup can hold an entry-point id (`command::App\…`) that carries `::` too.
     *
     * The unchanged fallback is deliberate: a set that resolved to class nodes alone (an enum whose
     * cases carry no node of their own, a marker class) has nothing narrower to offer, and narrowing
     * it to nothing would unseed a walk instead of tightening it.
     *
     * @param  list<string>  $nodes
     * @return list<string>
     */
    public static function memberNodesOf(array $nodes, string $fqcn): array
    {
        $prefix = ltrim($fqcn, '\\') . '::';
        $members = array_values(array_filter($nodes, static fn (string $node): bool => str_starts_with($node, $prefix)));

        return $members === [] ? $nodes : $members;
    }
}
