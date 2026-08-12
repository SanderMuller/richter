<?php declare(strict_types=1);

use Acme\Calculators\Basic;

/** A spread can set any key, so it makes every entry BEFORE it uncertain and none after it. */
return [
    'before' => Basic::class,
    ...require __DIR__ . '/plain.php',
    'after' => Basic::class,
];
