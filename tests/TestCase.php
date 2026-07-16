<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests;

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
}
