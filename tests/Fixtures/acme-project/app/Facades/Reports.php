<?php declare(strict_types=1);

namespace Acme\Facades;

use Acme\Support\ReportBuilder;
use Illuminate\Support\Facades\Facade;

/** The accessor names a class, so the call through this facade can be carried to the code that runs. */
final class Reports extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ReportBuilder::class;
    }
}
