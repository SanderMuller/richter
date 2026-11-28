<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Closure;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tracers\FactoryModels;

/**
 * What a changed file that no analysis lane reads means for a test selection.
 *
 * The diff loop sorts such a file into `outOfScope` and nothing looks at it again. That is right
 * for a stylesheet and wrong for `config/services.php`, a model factory or the base test case: each
 * can change a test's result, and a selection that ignores them is determined and too small. That
 * is the one direction `richter:affected-tests` must never fail in.
 *
 * Each file gets one of four outcomes. A changed test or spec is selected elsewhere, as before. An
 * INERT file matches a glob for files that cannot change a PHP test's result. A MAPPED file names
 * the tests it reaches: a support class under `tests/` through {@see TestSupportUsers}, a model
 * factory through the model it builds. Every other file is a VETO: the run is not determinable.
 *
 * The veto is the default on purpose. An unknown file is not known to be harmless, and a helpers
 * file outside `app/` looks like any other unknown path. A project that knows better names the path
 * in `richter.tests.inert_paths`.
 *
 * @phpstan-type UnreadReason array{file: string, via: 'support'|'factory'}
 * @phpstan-type UnreadImpact array{selected: array<string, list<UnreadReason>>, vetoed: list<string>}
 *
 * @internal
 */
final class UnreadFileImpact
{
    /**
     * Files that cannot change the result of a PHP test: prose, CI definitions, static-analysis and
     * style configuration, editor and agent settings, and the frontend's packages, build
     * configuration and assets. `phpunit.xml`, every `.env` file and `composer.*` are deliberately
     * absent: each one can.
     *
     * `fnmatch()`'s default flags, where `*` crosses `/`, as `tests.unrunnable_paths` reads its globs.
     */
    public const array DEFAULT_INERT = [
        '*.md', 'docs/*', '.github/*', '.gitlab/*', 'LICENSE*', 'CHANGELOG*',
        'phpstan*.neon*', 'pint.json', 'rector*.php', '.editorconfig', '.gitignore', '.gitattributes',
        '.claude/*', '.cursor/*', '.ai/*', '.vscode/*', '.idea/*', '.mcp.json',
        'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml',
        'vite.config.*', 'vitest.config.*', 'tailwind*.js', 'tailwind*.ts', 'tsconfig*.json',
        'resources/css/*', 'resources/js/*', 'resources/sass/*', 'public/*',
    ];

    private const string FACTORIES = 'database/factories/';

    /**
     * The unread files that still need an answer: every path that is neither a test nor a spec (the
     * caller selects those itself) nor inert.
     *
     * @param  list<string>  $outOfScope
     * @param  list<string>  $inertGlobs
     * @return list<string>
     */
    public static function candidates(array $outOfScope, array $inertGlobs): array
    {
        return array_values(array_filter($outOfScope, static function (string $file) use ($inertGlobs): bool {
            if (FrontendTestIndex::isSpecFile($file)) {
                return false;
            }

            if (str_starts_with($file, 'tests/') && TestReferenceIndex::runnableOnly([$file]) !== []) {
                return false;
            }

            return ! array_any($inertGlobs, static fn (string $glob): bool => fnmatch($glob, $file));
        }));
    }

    /**
     * @param  list<string>  $files  {@see candidates()}
     * @param  Closure(): TestReferenceIndex  $tests  resolved only when a file can be mapped
     * @param  Closure(): CodeGraph  $graph  resolved only for a changed factory
     * @param  Closure(string): ?string  $sourceOf  a file's source on disk, or null when it is gone
     * @return UnreadImpact
     */
    public static function assess(array $files, Closure $tests, Closure $graph, Closure $sourceOf): array
    {
        $selected = [];
        $vetoed = [];

        foreach ($files as $file) {
            $mapped = match (true) {
                ! str_ends_with($file, '.php') => null,
                str_starts_with($file, 'tests/') => self::usersOfSupportFile($file, $tests()),
                str_starts_with($file, self::FACTORIES) => self::testsOfFactory($sourceOf($file), $tests(), $graph),
                default => null,
            };

            if ($mapped === null) {
                $vetoed[] = $file;

                continue;
            }

            $via = str_starts_with($file, 'tests/') ? 'support' : 'factory';

            foreach ($mapped as $test) {
                $selected[$test][] = ['file' => $file, 'via' => $via];
            }
        }

        return ['selected' => $selected, 'vetoed' => $vetoed];
    }

    /** @return UnreadImpact */
    public static function none(): array
    {
        return ['selected' => [], 'vetoed' => []];
    }

    /**
     * The tests that use a changed support file, or null when that cannot be said.
     *
     * Two answers from {@see TestSupportUsers} are not a mapping. A null is its own "cannot map".
     * And an empty list is unknown, not unused. A file that declares no class (helper functions,
     * `tests/Pest.php`) has no class to follow, so it always answers empty. A class with no user can
     * be used by a convention no scan reads: a request factory that a library finds by the form
     * request's name.
     *
     * @return list<string>|null
     */
    private static function usersOfSupportFile(string $file, TestReferenceIndex $tests): ?array
    {
        $users = $tests->runnableUsersOf([$file]);

        return $users === null || $users === [] ? null : $users;
    }

    /**
     * The tests a changed model factory reaches, or null when the factory cannot be tied to a model.
     *
     * Three sets. Tests that import the model. Tests that import the factory class, which many do
     * without importing the model. And the same two sets for every model whose factory builds this
     * one, which the graph's `factory` edges name.
     *
     * A caller of the factory node that is no factory is application code that builds models through
     * it. The selection for that code needs the whole analysis, so it is a null here.
     *
     * @param  Closure(): CodeGraph  $graph
     * @return list<string>|null
     */
    private static function testsOfFactory(?string $source, TestReferenceIndex $tests, Closure $graph): ?array
    {
        $pair = $source === null ? null : FactoryModels::builtBy($source);

        if ($pair === null) {
            return null;
        }

        $models = [$pair['model'] => [$pair['factory']]];
        $node = "{$pair['model']}::factory";
        $graph = $graph();

        if ($graph->hasNode($node)) {
            foreach ($graph->callersOf([$node]) as $hop) {
                [$class, $member] = [...explode('::', $hop['node'], 2), ''];

                if ($member !== 'factory') {
                    return null;
                }

                $models[$class] ??= FactoryModels::factoryNamesFor($class);
            }
        }

        $found = [];

        foreach ($models as $model => $factories) {
            array_push($found, ...$tests->testsImporting($model));

            foreach ($factories as $factory) {
                array_push($found, ...$tests->testsImportingFactory($factory));
            }
        }

        return TestReferenceIndex::runnableOnly(array_values(array_unique($found)));
    }
}
