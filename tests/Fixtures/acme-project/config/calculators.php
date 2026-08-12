<?php declare(strict_types=1);

namespace Acme\Calculators;

/**
 * The registry shape this lane exists for: the keys are built at runtime from the classes, so only
 * the class list is statically enumerable — and it resolves through this file's own namespace.
 */
$calculators = [
    Basic::class,
];

$byKey = [];

foreach ($calculators as $calculator) {
    $byKey[$calculator::key()] = $calculator;
}

return $byKey;
