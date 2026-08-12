<?php declare(strict_types=1);

use Acme\Calculators\Basic;

/**
 * The shape that made this lane over-report: a plain literal array holding ordinary settings beside
 * one class reference. Reading a scalar out of it must draw nothing, even though the file names an
 * app class somewhere else.
 */
return [
    'timezone' => 'UTC',
    'driver' => env('SETTINGS_DRIVER'),
    'handler' => Basic::class,
    'fallback' => env('SETTINGS_FALLBACK', Basic::class),
    'nested' => [
        'handler' => Basic::class,
    ],
];
