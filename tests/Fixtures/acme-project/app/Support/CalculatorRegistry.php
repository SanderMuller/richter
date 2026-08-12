<?php declare(strict_types=1);

namespace Acme\Support;

/** Resolves a calculator by a runtime key, the shape no static call can follow. */
final class CalculatorRegistry
{
    public static function resolve(string $key): ?string
    {
        return config("calculators.{$key}");
    }
}
