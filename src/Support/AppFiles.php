<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Shared file-scan + edge + parse helpers for the graph tracers. Centralises the app/ php-file walk
 * (path → FQCN via {@see Fqcn::fromPath}), the edge dedupe, and source parsing. Dev/CI tooling only.
 */
final class AppFiles
{
    /**
     * Parse PHP source to its statement AST, or null when it doesn't parse (advisory tooling skips
     * unparseable input rather than aborting).
     *
     * @return list<Stmt>|null
     */
    public static function parse(string $source): ?array
    {
        try {
            $ast = new ParserFactory()->createForHostVersion()->parse($source);

            return $ast === null ? null : array_values($ast);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{fqcn: string, path: string}> */
    public static function phpClasses(string $dir, string $projectRoot): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
            $path = $file->getPathname();
            $classes[] = [
                'fqcn' => Fqcn::fromPath(substr($path, strlen($projectRoot) + 1)),
                'path' => $path,
            ];
        }

        return $classes;
    }

    /**
     * Parse and name-resolve in one step — the shared front half of every AST tracer. One resolved
     * AST feeds all per-file tracers in {@see CodeGraphBuilder}, so those tracers cost one shared
     * app-tree walk instead of one each (Brain's own analysis and the member-declaration pass still
     * parse separately).
     *
     * @return list<Stmt>|null
     */
    public static function parseResolved(string $source): ?array
    {
        $ast = self::parse($source);

        if ($ast === null) {
            return null;
        }

        // NameResolver attaches a `resolvedName` FQCN to every Name node (imports/aliases applied);
        // replaceNodes=false keeps originals so names read by written form.
        new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]))->traverse($ast);

        return $ast;
    }

    /** The NameResolver-attached FQCN of a name node (imports/aliases applied), root-slash trimmed. */
    public static function resolveName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim($resolved instanceof Name ? $resolved->toString() : $name->toString(), '\\');
    }

    /** A class constant's string value, or null when the class/constant doesn't resolve or isn't a string. */
    public static function stringConstantValue(string $class, string $constant): ?string
    {
        try {
            if (! class_exists($class) || ! defined("{$class}::{$constant}")) {
                return null;
            }

            $value = constant("{$class}::{$constant}");

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function dedupeEdges(array $edges, bool $byType = false): array
    {
        $seen = [];
        $unique = [];

        foreach ($edges as $edge) {
            $key = $byType
                ? $edge['source'] . "\0" . $edge['target'] . "\0" . $edge['type']
                : $edge['source'] . "\0" . $edge['target'];

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $edge;
            }
        }

        return $unique;
    }
}
