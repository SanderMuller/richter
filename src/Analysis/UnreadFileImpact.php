<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Closure;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\Fqcn;
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
 * INERT file cannot change a PHP test's result: it matches a glob, or it sits outside the
 * application and no code names it. A MAPPED file names what it reaches: a support class under
 * `tests/` its users ({@see TestSupportUsers}), a model factory the tests of its model, a config
 * file, a seeder and a file outside the application the tests and the files that name them
 * ({@see NamedFileImpact}). Every other file is a VETO: the run is not determinable.
 *
 * `selected` holds the tests to add. `readers` holds application files to analyse as if they had
 * changed: a class that reads a changed config key runs differently, and what that reaches is the
 * ordinary question the analysis already answers.
 *
 * @phpstan-type UnreadReason array{file: string, via: 'support'|'factory'|'config'|'seeder'|'path'}
 * @phpstan-type UnreadImpact array{selected: array<string, list<UnreadReason>>, vetoed: list<string>, readers: array<string, list<UnreadReason>>}
 *
 * @internal
 */
final class UnreadFileImpact
{
    /**
     * Files that by convention do not change the result of a PHP test: prose, CI definitions,
     * static-analysis and style configuration, editor and agent settings, and the frontend's
     * packages, build configuration and sources. `phpunit.xml`, every `.env` file and `composer.*`
     * are deliberately absent: each one can.
     *
     * A glob with a `/` crosses directories. A glob without one matches a file in the project root
     * only ({@see matches()}), which `tests.unrunnable_paths` does not do.
     */
    public const array DEFAULT_INERT = [
        '*.md', 'docs/*', '.github/*', '.gitlab/*', 'LICENSE*', 'CHANGELOG*',
        'phpstan*.neon*', 'pint.json', 'rector*.php', '.editorconfig', '.gitignore', '.gitattributes',
        '.claude/*', '.cursor/*', '.ai/*', '.vscode/*', '.idea/*', '.mcp.json',
        'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml',
        'vite.config.*', 'vitest.config.*', 'tailwind*.js', 'tailwind*.ts', 'tsconfig*.json',
        'resources/css/*', 'resources/js/*', 'resources/sass/*',
    ];

    private const string FACTORIES = 'database/factories/';

    /** Files named in the reason before the rest collapses into a count, as the dispatch sites are. */
    private const int NAMED_FILES = 15;

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

            return ! array_any($inertGlobs, static fn (string $glob): bool => self::matches($glob, $file));
        }));
    }

    /**
     * A glob with a `/` is a path, and its `*` crosses directories: `docs/*` covers the tree. A
     * glob without one names a file in the project ROOT only. `*.md` is the README and the
     * changelog, not `resources/skills/orders/SKILL.md`, which an application can load.
     */
    private static function matches(string $glob, string $file): bool
    {
        return (str_contains($glob, '/') || ! str_contains($file, '/')) && fnmatch($glob, $file);
    }

    /**
     * @param  list<string>  $files  {@see candidates()}
     * @param  Closure(): TestReferenceIndex  $tests  resolved only when a file can be mapped
     * @param  Closure(): CodeGraph  $graph  resolved only for a changed factory or a file with readers
     * @param  Closure(string): ?string  $sourceOf  a file's source on disk, or null when it is gone
     * @param  string  $projectRoot  where {@see NamedFileImpact} reads who names a file
     * @param  list<string>  $changedTests  the runnable tests the diff changed, which other tests may extend
     * @return UnreadImpact
     */
    public static function assess(array $files, Closure $tests, Closure $graph, Closure $sourceOf, string $projectRoot, array $changedTests = []): array
    {
        $impact = self::none();
        $named = [];
        $impactOf = new NamedFileImpact($projectRoot);
        /** @var array<string, string> $seederOf a seeder that builds through a changed factory => that factory's file */
        $seederOf = [];

        // A changed test that other tests extend or use: an abstract `BaseApiTest` runs nothing
        // itself, and what it changed runs in every test under it.
        foreach ($changedTests as $file) {
            $users = $tests()->runnableUsersOf([$file]);
            self::record($impact, $file, 'support', $users);
        }

        foreach ($files as $file) {
            $php = str_ends_with($file, '.php');

            if ($php && str_starts_with($file, 'tests/')) {
                self::record($impact, $file, 'support', self::usersOfSupportFile($file, $tests()));

                continue;
            }

            if (! $php || ! str_starts_with($file, self::FACTORIES)) {
                $named[] = $file;

                continue;
            }

            $factory = self::testsOfFactory($sourceOf($file), $tests(), $graph);
            self::record($impact, $file, 'factory', $factory['tests'] ?? null);

            // A seeder that builds through the factory runs it too, for every test that runs the seeder.
            foreach ($factory === null ? [] : $impactOf->seedersBuilding($factory['classes']) as $seeder) {
                $seederOf[$seeder] ??= $file;
            }
        }

        $named = array_values(array_unique([...$named, ...array_keys($seederOf)]));

        if ($named === []) {
            return $impact;
        }

        $answers = $impactOf->of($named, static fn (array $support): ?array => $tests()->runnableUsersOf($support));

        foreach ($answers as $file => $answer) {
            // What a seeder reaches because it builds through a changed factory is the factory's reach.
            $viaFactory = isset($seederOf[$file]) && ! in_array($file, $files, strict: true);
            self::recordNamed($impact, $viaFactory ? $seederOf[$file] : $file, $viaFactory ? 'factory' : self::kindOf($file), $answer, $graph);
        }

        $impact['vetoed'] = array_values(array_unique($impact['vetoed']));

        // Diff order for the files handled above, then the named ones: sorted, so a reason reads
        // the same whichever way the files arrived.
        sort($impact['vetoed']);

        return $impact;
    }

    /** @return 'config'|'seeder'|'path' how a file reached by name is named */
    private static function kindOf(string $file): string
    {
        return match (true) {
            str_starts_with($file, 'config/') => 'config',
            str_starts_with($file, 'database/seeders/') => 'seeder',
            default => 'path',
        };
    }

    /**
     * Record one answer of {@see NamedFileImpact}: its tests and its readers, or a veto when it has
     * none or names a reader the graph cannot place.
     *
     * @param  UnreadImpact  $impact
     * @param  'factory'|'config'|'seeder'|'path'  $via
     * @param  array{tests: list<string>, readers: list<string>}|null  $answer
     * @param  Closure(): CodeGraph  $graph
     */
    private static function recordNamed(array &$impact, string $file, string $via, ?array $answer, Closure $graph): void
    {
        $placed = $answer !== null && array_all($answer['readers'], static fn (string $reader): bool => self::canBeAnalysed($reader, $graph));
        self::record($impact, $file, $via, $placed ? $answer['tests'] : null);

        foreach ($placed ? $answer['readers'] : [] as $reader) {
            $impact['readers'][$reader][] = ['file' => $file, 'via' => $via];
        }
    }

    /**
     * Whether a reader can be analysed as changed.
     *
     * A PHP file under `app/` always can. Every node of an application class that is in an edge
     * carries its file, so a file the graph holds no node for is one nothing in the graph calls: it
     * reaches no entry point there, and the tests that import its class are still selected by name.
     * Code the framework runs with no edge to it (a provider's `boot()`) is the gap this leaves. A
     * route file or a view needs its nodes, and any other file (another config file) has none.
     *
     * @param  Closure(): CodeGraph  $graph
     */
    private static function canBeAnalysed(string $reader, Closure $graph): bool
    {
        return (str_starts_with($reader, 'app/') && str_ends_with($reader, '.php')) || $graph()->nodesDefinedIn($reader) !== [];
    }

    /**
     * @param  UnreadImpact  $impact
     * @param  'support'|'factory'|'config'|'seeder'|'path'  $via
     * @param  list<string>|null  $tests  the tests the file reaches, or null when it withdraws the selection
     */
    private static function record(array &$impact, string $file, string $via, ?array $tests): void
    {
        if ($tests === null) {
            $impact['vetoed'][] = $file;

            return;
        }

        foreach ($tests as $test) {
            $impact['selected'][$test][] = ['file' => $file, 'via' => $via];
        }
    }

    /** @return UnreadImpact */
    public static function none(): array
    {
        return ['selected' => [], 'vetoed' => [], 'readers' => []];
    }

    /**
     * The reason the vetoed files give, naming them, or none.
     *
     * @param  UnreadImpact  $unread
     * @return list<string>
     */
    public static function reasons(array $unread): array
    {
        if ($unread['vetoed'] === []) {
            return [];
        }

        $shown = array_slice($unread['vetoed'], 0, self::NAMED_FILES);
        $rest = count($unread['vetoed']) - count($shown);

        return ['changed file(s) no analysis reads can change test results: ' . implode(', ', $shown) . ($rest > 0 ? ", … and {$rest} more" : '')];
    }

    /**
     * Why each mapped test is in the selection, for `--explain`.
     *
     * @param  UnreadImpact  $unread
     */
    public static function recordIn(SelectionProvenance $provenance, array $unread): void
    {
        foreach ($unread['selected'] as $test => $reasons) {
            foreach ($reasons as $reason) {
                $provenance->reachedFromUnreadFile($test, $reason['file'], $reason['via']);
            }
        }
    }

    /**
     * The diff's changes with the readers added, as changes the analysis can seed from: every node
     * the reader's file defines. Coarser than the member that holds the read, and precise enough to
     * be a real seed, so it draws no low-confidence verdict.
     *
     * @param  UnreadImpact  $unread
     * @param  list<ChangedFileSymbols>  $changed
     * @return list<ChangedFileSymbols> the changes to analyse for the selection
     */
    public static function readersAsChanges(array $unread, array $changed, CodeGraph $graph): array
    {
        $byFile = [];

        foreach ($changed as $index => $file) {
            $byFile[$file->file] = $index;
        }

        foreach (array_keys($unread['readers']) as $file) {
            $seeds = $graph->nodesDefinedIn($file);

            // A reader with no node reaches nothing. Its importing tests are selected by its class.
            if ($seeds === []) {
                continue;
            }

            $existing = isset($byFile[$file]) ? $changed[$byFile[$file]] : null;

            // The diff changed the reader too, in some member. The read can sit in another one, so
            // the whole file is seeded on top of what the diff seeds.
            $reader = $existing instanceof ChangedFileSymbols
                ? $existing->alsoSeeding($seeds)
                : new ChangedFileSymbols($file, Fqcn::fromPath($file), [], cosmeticOnly: false, directSeeds: $seeds);

            $changed[$byFile[$file] ?? count($changed)] = $reader;
            $byFile[$file] ??= count($changed) - 1;
        }

        return array_values($changed);
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
     * @return array{tests: list<string>, classes: list<string>}|null the tests, and every model and
     *   factory class the change can run through
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

                $models[$class] ??= FactoryModels::factoryNamesOf($class);
            }
        }

        $found = [];
        $classes = [];

        foreach ($models as $model => $factories) {
            array_push($found, ...$tests->testsImporting($model));
            array_push($classes, $model, ...$factories);

            foreach ($factories as $factory) {
                array_push($found, ...$tests->testsImportingFactory($factory));
            }
        }

        $found = array_values(array_unique($found));
        $runnable = TestReferenceIndex::runnableOnly($found);
        // A support trait that builds the model stands for the tests that use the trait: they need
        // not import the model themselves.
        $support = array_values(array_diff($found, $runnable));
        $users = $tests->runnableUsersOf($support);

        // A support file that declares no class and no function has users this scan cannot follow.
        if ($users === null || array_any($support, static fn (string $file): bool => ! $tests->canFollowSupportFile($file))) {
            return null;
        }

        return ['tests' => array_values(array_unique([...$runnable, ...$users])), 'classes' => $classes];
    }
}
