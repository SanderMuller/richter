<?php declare(strict_types=1);

namespace Acme\Facades;

use Illuminate\Support\Facades\Facade;

/** The accessor names a container key, not a class — resolvable only through the provider bindings. */
final class KeyedReports extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'reports';
    }
}
