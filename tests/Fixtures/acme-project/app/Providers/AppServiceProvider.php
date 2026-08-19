<?php declare(strict_types=1);

namespace Acme\Providers;

use Acme\Support\ReportBuilder;
use Illuminate\Support\ServiceProvider;

/** Binds the container key the keyed facade's accessor returns — the registration that resolution needs. */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('reports', ReportBuilder::class);
    }
}
