<?php declare(strict_types=1);

namespace SanderMuller\Richter;

use Laravel\Mcp\Facades\Mcp;
use Override;
use SanderMuller\Richter\Console\BenchmarkAddCommand;
use SanderMuller\Richter\Console\BenchmarkCommand;
use SanderMuller\Richter\Console\DetectChangesCommand;
use SanderMuller\Richter\Console\ImpactCommand;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Mcp\RichterServer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class RichterServiceProvider extends PackageServiceProvider
{
    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name('richter')
            ->hasConfigFile()
            ->hasCommands(ImpactCommand::class, DetectChangesCommand::class, BenchmarkCommand::class, BenchmarkAddCommand::class);
    }

    #[Override]
    public function packageRegistered(): void
    {
        // A singleton so one MCP session reuses the parsed graph in memory across tool calls.
        $this->app->singleton(GraphCache::class);
    }

    #[Override]
    public function packageBooted(): void
    {
        // laravel/mcp is a suggested dependency — the MCP surface lights up only when it is installed.
        if (class_exists(Mcp::class)) {
            Mcp::local('richter', RichterServer::class);
        }
    }
}
