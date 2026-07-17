<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Override;
use SanderMuller\Richter\RichterServiceProvider;

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
        // Every test builds the graph fresh so no state leaks between tests through the on-disk
        // cache; cache behaviour itself is exercised explicitly in GraphCacheTest.
        $app->make(Repository::class)->set('richter.cache.enabled', false);
    }
}
