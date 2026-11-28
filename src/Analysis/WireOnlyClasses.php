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
 * could not see. Here the scan shows there is none: no PHP outside `tests/` names the class. What
 * is left is the tests: the ones that name it, the ones that use a support file that names it, and
 * the ones that run the type generator, which finds the class without naming it.
 *
 * The attribute is the reason the class has no reader. The scan is the proof. A class that is
 * named anywhere else, even in a string or a comment, stays UNRESOLVED, and so does one named by a
 * support file whose users cannot be listed.
 *
 * @internal
 */
final class WireOnlyClasses
{
    private const string MARKER = 'Spatie\\TypeScriptTransformer\\Attributes\\TypeScript';

    private const string GENERATOR = '/typescript:transform|TypeScriptTransformer/i';

    /**
     * The unresolved files that hold a wire-only class, and the tests each one reaches.
     *
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<ChangedFileSymbols>  $changed
     * @param  callable(string): ?string  $sourceOf  a project-relative file's current source
     * @return array{placed: list<string>, selected: array<string, list<array{file: string, via: 'class'}>>}
     */
    public static function in(array $coverage, array $changed, callable $sourceOf, string $projectRoot, TestReferenceIndex $tests): array
    {
        $result = ['placed' => [], 'selected' => []];
        $candidates = self::candidates($coverage, $changed, $sourceOf);

        if ($candidates === []) {
            return $result;
        }

        $patterns = array_map(static fn (string $fqcn): string => '/\b' . preg_quote(substr((string) strrchr('\\' . $fqcn, '\\'), 1), '/') . '\b/', $candidates);
        $mentions = SourceMentions::in($projectRoot, [...$patterns, ' generator' => self::GENERATOR], ComposerAutoloadPaths::in($projectRoot));
        $generatorTests = array_filter($mentions[' generator'], static fn (string $mention): bool => str_starts_with($mention, 'tests/'));

        foreach (array_keys($candidates) as $file) {
            $named = array_values(array_filter($mentions[$file], static fn (string $mention): bool => $mention !== $file));
            $inTests = array_filter($named, static fn (string $mention): bool => str_starts_with($mention, 'tests/'));

            if (count($inTests) !== count($named)) {
                continue;
            }

            $reached = self::testsReachedBy([...$inTests, ...$generatorTests], $tests);

            if ($reached === null) {
                continue;
            }

            $result['placed'][] = $file;

            foreach ($reached as $test) {
                $result['selected'][$test][] = ['file' => $file, 'via' => 'class'];
            }
        }

        return $result;
    }

    /**
     * The unresolved changed files whose one class carries the marker, file => FQCN.
     *
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<ChangedFileSymbols>  $changed
     * @param  callable(string): ?string  $sourceOf
     * @return array<string, string>
     */
    private static function candidates(array $coverage, array $changed, callable $sourceOf): array
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

        return $candidates;
    }

    /**
     * The runnable tests among the files, plus the tests that use the support files among them.
     * Null when a support file's users cannot be listed, or it has none to show.
     *
     * @param  list<string>  $files
     * @return list<string>|null
     */
    private static function testsReachedBy(array $files, TestReferenceIndex $tests): ?array
    {
        $files = array_values(array_unique($files));
        $runnable = TestReferenceIndex::runnableOnly($files);
        $support = array_values(array_diff($files, $runnable));

        if ($support === []) {
            return $runnable;
        }

        $users = $tests->runnableUsersOf($support);

        return $users === null || $users === [] ? null : array_values(array_unique([...$runnable, ...$users]));
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
