<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\ComposerAutoloadPaths;

/**
 * Changed classes that only describe a shape for the frontend, which the graph cannot place.
 *
 * `#[TypeScript]` generates a type from the class. When no application code names it either, the
 * graph has no node for it, and the change reads UNRESOLVED. That veto is about code the graph
 * could not see. Here the scan shows there is none: no PHP outside `tests/` names the class, so
 * only the tests that name it can see the change, and the changed-class axis of
 * {@see AffectedTests} selects them.
 *
 * The attribute is the reason the class has no reader. The scan is the proof. A class that is
 * named anywhere else, even in a string or a comment, stays UNRESOLVED.
 *
 * @internal
 */
final class WireOnlyClasses
{
    private const string MARKER = 'Spatie\\TypeScriptTransformer\\Attributes\\TypeScript';

    /**
     * The unresolved files that hold a wire-only class.
     *
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<ChangedFileSymbols>  $changed
     * @param  callable(string): ?string  $sourceOf  a project-relative file's current source
     * @return list<string>
     */
    public static function in(array $coverage, array $changed, callable $sourceOf, string $projectRoot): array
    {
        $candidates = [];

        foreach ($changed as $file) {
            if (($coverage[$file->file] ?? null) !== 'unresolved' || $file->fqcn === '') {
                continue;
            }

            $source = $sourceOf($file->file);

            if ($source !== null && self::declaresWireClass($source, $file->fqcn)) {
                $candidates[$file->file] = $file->fqcn;
            }
        }

        if ($candidates === []) {
            return [];
        }

        $patterns = array_map(static fn (string $fqcn): string => '/\b' . preg_quote(substr((string) strrchr('\\' . $fqcn, '\\'), 1), '/') . '\b/', $candidates);
        $mentions = SourceMentions::in($projectRoot, $patterns, ComposerAutoloadPaths::in($projectRoot));
        $wireOnly = [];

        foreach (array_keys($candidates) as $file) {
            $readers = array_filter($mentions[$file], static fn (string $mention): bool => $mention !== $file && ! str_starts_with($mention, 'tests/'));

            if ($readers === []) {
                $wireOnly[] = $file;
            }
        }

        return $wireOnly;
    }

    /** Whether the source declares exactly the one class, and marks it with `#[TypeScript]`. */
    private static function declaresWireClass(string $source, string $fqcn): bool
    {
        $classes = new NodeFinder()->findInstanceOf(AppFiles::parseResolved($source) ?? [], ClassLike::class);

        if (count($classes) !== 1 || $classes[0]->namespacedName?->toString() !== $fqcn) {
            return false;
        }

        foreach ($classes[0]->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (AppFiles::resolveName($attribute->name) === self::MARKER) {
                    return true;
                }
            }
        }

        return false;
    }
}
