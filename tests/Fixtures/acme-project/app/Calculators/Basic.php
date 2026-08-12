<?php declare(strict_types=1);

namespace Acme\Calculators;

/** Reached only through the config registry, so without that lane nothing calls it. */
final class Basic
{
    public static function key(): string
    {
        return 'basic';
    }

    public function calculate(int $amount): int
    {
        return $amount * 2;
    }
}
