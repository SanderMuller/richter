<?php declare(strict_types=1);

namespace Acme\Support;

/** Reached only through a static call — the shape Brain draws no hop for. */
final class ClientFactory
{
    public static function create(string $endpoint): string
    {
        return $endpoint;
    }
}
