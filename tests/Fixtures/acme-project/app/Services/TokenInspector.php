<?php declare(strict_types=1);

namespace Acme\Services;

final class TokenInspector
{
    public function inspect(string $token): bool
    {
        return $token !== '';
    }
}
