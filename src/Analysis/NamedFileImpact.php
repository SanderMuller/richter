<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Support\ComposerAutoloadPaths;

/**
 * What a changed file means for a test selection, for the files that are reached by NAME: a config
 * file through its key, a seeder through its class, any other file through the directory it is in.
 *
 * A config file the application owns is read through `config('plans.basic')`, so the code and the
 * tests that hold a string starting with `plans.` are the ones a change can reach. A config file a
 * package ships as well (`config/services.php`, `config/app.php`) is read by code this never scans,
 * so it has no such list.
 *
 * A seeder runs when a test names its class, or when a test runs the default seeder and that one
 * calls it.
 *
 * Every other file is outside the directories a Laravel application loads: a script, a Dockerfile,
 * another service of a monorepo. Such a file can only change a PHP test's result when PHP code
 * reads it, and code that reads a file names its path. So what names its top-level directory as a
 * path is what can read it, and a file nothing names is inert.
 *
 * Only a path written as a literal that starts with the name is found. A path put together at
 * runtime (`"{$root}/stubs/x"`), and one that only package code names, reads as inert.
 *
 * Each answer is the tests to select and the files to analyse as if they had changed, or null when
 * the file withdraws the selection. A reader is any file that names the changed one and is no
 * test: application code, a route file, a view, another config file. Whether the graph can place
 * it is the caller's question.
 *
 * @phpstan-type NamedImpact array{tests: list<string>, readers: list<string>}
 *
 * @internal
 */
final class NamedFileImpact
{
    private const string CONFIG = 'config/';

    private const string SEEDERS = 'database/seeders/';

    private const string DEFAULT_SEEDER = 'DatabaseSeeder';

    /**
     * Code that runs the default seeder: the test property, the bare call, or the artisan forms.
     * Read in `tests/`, `app/` and `routes/` only: a migration or another seeder that holds one of
     * these words is not what decides whether a test seeds.
     */
    private const string RUNS_DEFAULT_SEEDER = '/\$seed\s*=\s*true|function\s+shouldSeed\b|->seed\(\s*\)|[\'"]db:seed[\'"]|--seed\b/';

    /**
     * Paths the framework, the test runner or Composer loads whatever the code names. A change to
     * one can reach any test, and nothing lists which.
     */
    private const array LOADED_REGARDLESS = [
        'composer.json', 'composer.lock', 'phpunit*', '.env*', 'artisan',
        'app/*', 'bootstrap/*', 'config/*', 'database/*', 'lang/*', 'resources/lang/*', 'routes/*', 'storage/*',
        // The Vite manifest: a test that renders a page reads it.
        'public/build/*', 'public/hot',
    ];

    /** @var list<string>|null the paths Composer loads PHP from, read once */
    private ?array $autoloaded = null;

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @param  list<string>  $files  changed files that are neither a support file nor a factory
     * @param  callable(list<string>): (list<string>|null)  $usersOfSupportFiles  the runnable tests
     *   that use a support file under `tests/`, or null when they cannot be listed
     * @return array<string, NamedImpact|null>
     */
    public function of(array $files, callable $usersOfSupportFiles): array
    {
        $patterns = [];
        $answers = [];

        foreach ($files as $file) {
            $pattern = $this->patternFor($file);
            $answers[$file] = $pattern === null ? null : ['tests' => [], 'readers' => []];

            if ($pattern !== null) {
                $patterns[$file] = $pattern;
            }
        }

        $seeders = array_values(array_filter(array_keys($patterns), static fn (string $file): bool => str_starts_with($file, self::SEEDERS)));
        $mentions = $this->mentionsWithCallingSeeders($patterns, $seeders);

        foreach ($patterns as $file => $_) {
            $answers[$file] = $this->answer($file, $mentions[$file] ?? [], $usersOfSupportFiles);
        }

        return $answers;
    }

    /**
     * The seeders that build a model through one of the given model or factory classes:
     * `Post::factory()`, `PostFactory::new()`. A test that runs such a seeder runs the factory.
     *
     * @param  list<string>  $classes  model and factory class names
     * @return list<string> seeder files
     */
    public function seedersBuilding(array $classes): array
    {
        $names = array_map(static fn (string $class): string => preg_quote(basename(str_replace('\\', '/', $class)), '/'), $classes);
        $pattern = '/\b(?:' . implode('|', array_map(
            static fn (string $name): string => str_ends_with($name, 'Factory') ? $name : "{$name}::factory",
            $names,
        )) . ')\b/';

        return array_values(array_filter(
            $names === [] ? [] : SourceMentions::in($this->projectRoot, ['seeders' => $pattern])['seeders'],
            static fn (string $file): bool => str_starts_with($file, self::SEEDERS),
        ));
    }

    /** The regex that finds what names the file, or null when the file withdraws the selection outright. */
    private function patternFor(string $file): ?string
    {
        if (str_starts_with($file, self::CONFIG) && str_ends_with($file, '.php')) {
            $key = str_replace('/', '.', substr($file, strlen(self::CONFIG), -4));

            $key = preg_quote($key, '/');

            // A dotted key under the file, or the whole file asked for by name through `config()`,
            // the facade or the repository: `config()->array('plans')`, `$config->get('plans')`.
            // The bare word alone is not enough: `'site'` is also an array key.
            return $this->shippedByAPackage($file)
                ? null
                : "/['\"]{$key}\.[^'\"]*['\"]|(?:\bconfig|(?:->|::)\w+)\(\s*['\"]{$key}['\"]/i";
        }

        if (str_starts_with($file, self::SEEDERS) && str_ends_with($file, '.php')) {
            return '/\b' . preg_quote(basename($file, '.php'), '/') . '\b/';
        }

        // A fixture, a snapshot, a stub: a test reads it by its file name or by its directory.
        if (str_starts_with($file, 'tests/')) {
            return '/[\'"\/]' . preg_quote(basename($file), '/') . '[\'"]|[\'"\/]' . preg_quote(basename(dirname($file)), '/') . '\//';
        }

        if (array_any(self::LOADED_REGARDLESS, static fn (string $glob): bool => fnmatch($glob, $file)) && ! str_ends_with($file, '.example')) {
            return null;
        }

        if (str_ends_with($file, '.php') && $this->isAutoloaded($file)) {
            return null;
        }

        // `resources/` holds views and frontend sources, which are read elsewhere, and whatever else
        // the application keeps there. The directory under it is the name code reads it by.
        $segments = explode('/', $file);

        // A file directly under `resources/` has no directory of its own, so it is named whole,
        // as a file in the root is: `resource_path('countries.json')`.
        if ($segments[0] === 'resources') {
            array_shift($segments);
        }

        $name = preg_quote($segments[0], '/');

        // A path that STARTS with the name, after any `/`, `./` or `../`: `'stubs/report.stub'`,
        // `__DIR__ . '/../stubs/x'`. Or the name alone as the argument of a path helper:
        // `base_path('stubs')`. A string that merely contains it is not a path to it: a URL holds
        // `/tools/`, and `'tools'` is also an array key.
        $start = "['\"](?:\.{0,2}\/)*{$name}";

        return count($segments) === 1
            ? "/{$start}['\"]/"
            : "/{$start}\/|_path\(\s*['\"]{$name}['\"]/";
    }

    /**
     * The mentions of every pattern, with a changed seeder's widened to the seeders that call it:
     * `DatabaseSeeder` runs `$this->call([PostSeeder::class])`, so what names `DatabaseSeeder` runs
     * `PostSeeder` too.
     *
     * @param  array<string, string>  $patterns  file => regex
     * @param  list<string>  $seeders  the changed seeder files
     * @return array<string, list<string>> file => the files that mention it
     */
    private function mentionsWithCallingSeeders(array $patterns, array $seeders): array
    {
        $mentions = SourceMentions::in($this->projectRoot, $patterns, $this->autoloadPaths());

        foreach ($seeders as $seeder) {
            $seen = [$seeder => true];
            $queue = [$seeder];

            for ($head = 0; isset($queue[$head]); ++$head) {
                $current = $queue[$head];
                $found = $current === $seeder
                    ? $mentions[$seeder]
                    : SourceMentions::in($this->projectRoot, [$current => '/\b' . preg_quote(basename($current, '.php'), '/') . '\b/'], $this->autoloadPaths())[$current];

                if (basename($current, '.php') === self::DEFAULT_SEEDER) {
                    $found = [...$found, ...array_filter(
                        SourceMentions::in($this->projectRoot, ['default' => self::RUNS_DEFAULT_SEEDER])['default'],
                        static fn (string $file): bool => str_starts_with($file, 'tests/') || str_starts_with($file, 'app/') || str_starts_with($file, 'routes/'),
                    )];
                }

                foreach ($found as $file) {
                    if (str_starts_with($file, self::SEEDERS) && ! isset($seen[$file])) {
                        $seen[$file] = true;
                        $queue[] = $file;
                    }
                }

                $mentions[$seeder] = [...$mentions[$seeder], ...$found];
            }

            $mentions[$seeder] = array_values(array_unique(array_filter(
                $mentions[$seeder],
                static fn (string $file): bool => ! str_starts_with($file, self::SEEDERS),
            )));
        }

        return $mentions;
    }

    /**
     * Who names the file, as the tests to select and the files to analyse as changed.
     *
     * A test that names it is selected. A support file under `tests/` stands for the tests that use
     * it. Every other file that names it is a reader: application code, a route file, a view. The
     * caller checks that the graph can place each one.
     *
     * @param  list<string>  $mentions  the files that name the changed one
     * @param  callable(list<string>): (list<string>|null)  $usersOfSupportFiles
     * @return NamedImpact|null
     */
    private function answer(string $file, array $mentions, callable $usersOfSupportFiles): ?array
    {
        $mentions = array_values(array_filter($mentions, static fn (string $mention): bool => $mention !== $file));

        // A config key nothing names is read through a computed key, or not at all, and a fixture
        // nothing names is read through a directory listing. Not known. A seeder nothing runs, and
        // a file outside the application nothing reads, reach no test.
        if ($mentions === [] && (str_starts_with($file, self::CONFIG) || str_starts_with($file, 'tests/'))) {
            return null;
        }

        $tests = [];
        $readers = [];
        $support = [];

        foreach ($mentions as $mention) {
            match (true) {
                ! str_starts_with($mention, 'tests/') => $readers[] = $mention,
                TestReferenceIndex::runnableOnly([$mention]) !== [] => $tests[] = $mention,
                default => $support[] = $mention,
            };
        }

        $users = $support === [] ? [] : $usersOfSupportFiles($support);

        // Named by a support file whose users are unknown.
        if ($users === null || ($support !== [] && $users === [])) {
            return null;
        }

        return ['tests' => array_values(array_unique([...$tests, ...$users])), 'readers' => $readers];
    }

    /**
     * Whether a package reads this config file: it ships one of the same name under its own
     * `config/`, or one of its service providers merges a file under this key or publishes one to
     * this name. The framework and the package then read the published copy in code this never scans.
     * A provider is found in the package's `src/` or one directory below it, by its file name.
     */
    private function shippedByAPackage(string $file): bool
    {
        $relative = substr($file, strlen(self::CONFIG));

        if (glob("{$this->projectRoot}/vendor/*/*/config/{$relative}") !== []) {
            return true;
        }

        $key = preg_quote(str_replace('/', '.', substr($relative, 0, -4)), '/');
        $name = preg_quote($relative, '/');
        $providers = [];

        foreach (['*ServiceProvider.php', '*/*ServiceProvider.php'] as $depth) {
            $found = glob("{$this->projectRoot}/vendor/*/*/src/{$depth}");
            array_push($providers, ...$found === false ? [] : $found);
        }

        return array_any($providers, static fn (string $provider): bool => preg_match(
            "/mergeConfigFrom\\([^;]*['\"]{$key}['\"]|config_path\\(\\s*['\"]{$name}['\"]/",
            (string) file_get_contents($provider),
        ) === 1);
    }

    /** Whether Composer loads the file: it is one of its paths, or sits under one. */
    private function isAutoloaded(string $file): bool
    {
        return array_any($this->autoloadPaths(), static fn (string $path): bool => $file === $path || str_starts_with($file, "{$path}/"));
    }

    /** @return list<string> */
    private function autoloadPaths(): array
    {
        return $this->autoloaded ??= ComposerAutoloadPaths::in($this->projectRoot);
    }
}
