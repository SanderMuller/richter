<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Override;
use SanderMuller\Richter\RichterServiceProvider;
use SanderMuller\Richter\Support\AppNamespace;

abstract class TestCase extends OrchestraTestCase
{
    /** The mini Laravel project tree the graph builder and tracers are exercised against. */
    public static function fixtureProjectPath(): string
    {
        return __DIR__ . '/Fixtures/project';
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [RichterServiceProvider::class];
    }

    /** @param  Application  $app */
    #[Override]
    protected function defineEnvironment($app): void
    {
        // The derived root namespace is memoised per (project root, config override) for the process;
        // a test that rewrites either under one path would otherwise inherit the previous test's value.
        AppNamespace::flush();

        // Every test builds the graph fresh so no state leaks between tests through the on-disk
        // cache; cache behaviour itself is exercised explicitly in GraphCacheTest.
        $app->make(Repository::class)->set('richter.cache.enabled', false);

        // Build serially by default so the suite never forks a child artisan per graph build;
        // the parallel path is exercised explicitly in ParallelGraphBuildTest.
        $app->make(Repository::class)->set('richter.parallel', false);
    }

    /** Recursively remove a throwaway directory tree built by a test. */
    protected function deleteTree(string $dir): void
    {
        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
