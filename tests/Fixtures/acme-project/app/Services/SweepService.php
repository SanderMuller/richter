<?php declare(strict_types=1);

namespace Acme\Services;

/** Called only from a method no static call names, so only a whole-class walk connects it. */
final class SweepService
{
    public function run(): string
    {
        return 'swept';
    }
}
