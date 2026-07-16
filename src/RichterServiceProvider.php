<?php declare(strict_types=1);

namespace Sandermuller\Richter;

use Override;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class RichterServiceProvider extends PackageServiceProvider
{
    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name('richter')
            ->hasConfigFile();
        // ->hasViews()
        // ->hasMigration('create_yourtable_table')   // <- replace with your actual migration name
        // ->hasCommand(YourCommand::class);
    }
}
