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
 * the ones that mention the type generator, which finds the class without naming it.
 *
 * The attribute is the reason the class has no reader. The scan is the proof. A class that is
 * named anywhere else, even in a string or a comment, stays UNRESOLVED. So does one named by a
 * support file whose users cannot be listed, and every such class when application code runs the
 * generator: a test of that code runs it too, and names neither.
 *
 * @internal
 */
final class WireOnlyClasses
{
    private const string MARKER = 'Spatie\\TypeScriptTransformer\\Attributes\\TypeScript';

    private const string GENERATOR = '/typescript:transform|TypeScriptTransformer/i';

    private const string RUNS_GENERATOR = '/typescript:transform/i';

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
        $mentions = SourceMentions::in($projectRoot, [...$patterns, ' generator' => self::GENERATOR, ' runs' => self::RUNS_GENERATOR], ComposerAutoloadPaths::in($projectRoot));
        $inTests = static fn (string $mention): bool => str_starts_with($mention, 'tests/');

        if (! array_all($mentions[' runs'], $inTests)) {
            return $result;
        }

        $generatorTests = array_filter($mentions[' generator'], $inTests);

        foreach (array_keys($candidates) as $file) {
            $named = array_values(array_filter($mentions[$file], static fn (string $mention): bool => $mention !== $file));

            if (! array_all($named, $inTests)) {
                continue;
            }

            $reached = self::testsReachedBy([...$named, ...$generatorTests], $tests);

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
     * The runnable tests among the files, plus the tests that use each support file among them.
     * Null when one support file's users cannot be listed, or it has none to show: each is asked
     * alone, so one with users cannot hide one without.
     *
     * @param  list<string>  $files
     * @return list<string>|null
     */
    private static function testsReachedBy(array $files, TestReferenceIndex $tests): ?array
    {
        $files = array_values(array_unique($files));
        $runnable = TestReferenceIndex::runnableOnly($files);
        $reached = $runnable;

        foreach (array_diff($files, $runnable) as $support) {
            $users = $tests->runnableUsersOf([$support]);

            if ($users === null || $users === []) {
                return null;
            }

            $reached = [...$reached, ...$users];
        }

        return array_values(array_unique($reached));
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
