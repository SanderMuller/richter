<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

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
 * reads it, and code that reads a file names its path. So a file whose top-level directory no
 * application or test source names as a path is inert.
 *
 * Each answer is the tests to select and the application files to analyse as if they had changed,
 * or null when the file withdraws the selection.
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

    /** A test that runs the default seeder: the property, the bare call, or the artisan forms. */
    private const string RUNS_DEFAULT_SEEDER = '/\$seed\s*=\s*true|->seed\(\s*\)|db:seed|--seed\b|seed:\s*true/';

    /**
     * Paths the framework, the test runner or Composer loads whatever the code names. A change to
     * one can reach any test, and nothing lists which.
     */
    private const array LOADED_REGARDLESS = [
        'composer.json', 'composer.lock', 'phpunit*', '.env*', 'artisan',
        'app/*', 'bootstrap/*', 'config/*', 'database/*', 'lang/*', 'resources/lang/*', 'routes/*', 'storage/*', 'tests/*',
    ];

    /** @var list<string>|null path prefixes Composer autoloads, read once */
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

    /** The regex that finds what names the file, or null when the file withdraws the selection outright. */
    private function patternFor(string $file): ?string
    {
        if (str_starts_with($file, self::CONFIG) && str_ends_with($file, '.php')) {
            $key = str_replace('/', '.', substr($file, strlen(self::CONFIG), -4));

            return $this->shippedByAPackage($file) ? null : '/[\'"]' . preg_quote($key, '/') . '(?:\.[^\'"]*)?[\'"]/';
        }

        if (str_starts_with($file, self::SEEDERS) && str_ends_with($file, '.php')) {
            return '/\b' . preg_quote(basename($file, '.php'), '/') . '\b/';
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
        $name = $segments[0] === 'resources' && isset($segments[2]) ? $segments[1] : $segments[0];

        return '/[\'"\/]' . preg_quote($name, '/') . '[\'"\/]/';
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
        $mentions = SourceMentions::in($this->projectRoot, $patterns);

        foreach ($seeders as $seeder) {
            $seen = [$seeder => true];
            $queue = [$seeder];

            for ($head = 0; isset($queue[$head]); ++$head) {
                $current = $queue[$head];
                $found = $current === $seeder
                    ? $mentions[$seeder]
                    : SourceMentions::in($this->projectRoot, [$current => '/\b' . preg_quote(basename($current, '.php'), '/') . '\b/'])[$current];

                if (basename($current, '.php') === self::DEFAULT_SEEDER) {
                    $found = [...$found, ...SourceMentions::in($this->projectRoot, ['default' => self::RUNS_DEFAULT_SEEDER])['default']];
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
     * @param  list<string>  $mentions  the files that name the changed one
     * @param  callable(list<string>): (list<string>|null)  $usersOfSupportFiles
     * @return NamedImpact|null
     */
    private function answer(string $file, array $mentions, callable $usersOfSupportFiles): ?array
    {
        $mentions = array_values(array_filter($mentions, static fn (string $mention): bool => $mention !== $file));
        $isConfig = str_starts_with($file, self::CONFIG);

        if (! $isConfig && ! str_starts_with($file, self::SEEDERS)) {
            // A file outside the application: any code that names its directory can read it, and
            // what that code does with it is not followed. No mention, no reader.
            return $mentions === [] ? ['tests' => [], 'readers' => []] : null;
        }

        // A config key nothing names is read through a computed key, or not at all. Not known.
        if ($isConfig && $mentions === []) {
            return null;
        }

        $tests = [];
        $readers = [];
        $support = [];
        $elsewhere = false;

        foreach ($mentions as $mention) {
            match (true) {
                str_starts_with($mention, 'app/') => $readers[] = $mention,
                ! str_starts_with($mention, 'tests/') => $elsewhere = true,
                TestReferenceIndex::runnableOnly([$mention]) !== [] => $tests[] = $mention,
                default => $support[] = $mention,
            };
        }

        $users = $support === [] ? [] : $usersOfSupportFiles($support);

        // Named by a route file, a view, a provider's bootstrap or another config file: code that
        // runs, in a place no analysis seeds from. And named by a support file whose users are unknown.
        if ($elsewhere || $users === null || ($support !== [] && $users === [])) {
            return null;
        }

        return ['tests' => array_values(array_unique([...$tests, ...$users])), 'readers' => $readers];
    }

    /**
     * Whether a package ships a config file of the same name. The framework and most packages keep
     * theirs under `config/`, and read the published copy themselves.
     */
    private function shippedByAPackage(string $file): bool
    {
        $relative = substr($file, strlen(self::CONFIG));

        return glob("{$this->projectRoot}/vendor/*/*/config/{$relative}") !== [];
    }

    /** Whether Composer loads the file: it sits under a PSR-4 or classmap path, or is a `files` entry. */
    private function isAutoloaded(string $file): bool
    {
        if ($this->autoloaded === null) {
            $this->autoloaded = [];
            $composer = json_decode((string) @file_get_contents("{$this->projectRoot}/composer.json"), associative: true);

            foreach (['autoload', 'autoload-dev'] as $section) {
                foreach (is_array($composer) && is_array($composer[$section] ?? null) ? $composer[$section] : [] as $paths) {
                    $paths = (array) $paths;
                    array_walk_recursive($paths, function (mixed $path): void {
                        if (is_string($path) && $path !== '') {
                            $this->autoloaded[] = ltrim($path, './');
                        }
                    });
                }
            }
        }

        return array_any($this->autoloaded, static fn (string $path): bool => $file === $path || str_starts_with($file, rtrim($path, '/') . '/'));
    }
}
