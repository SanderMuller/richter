<?php declare(strict_types=1);

namespace Acme\Support;

use Acme\Facades\KeyedReports;

/** Calls through the keyed facade, so its reach depends on the container-key resolution. */
final class KeyedClientFactory
{
    public static function create(string $endpoint): string
    {
        return $endpoint . KeyedReports::assemble();
    }
}
