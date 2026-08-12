<?php declare(strict_types=1);

namespace Acme\Calculators;

/**
 * A config file with its own namespace and a literal array — the shape whose bare `::class`
 * constants only resolve because of that namespace declaration, and whose `return` therefore sits
 * one level deeper in the AST than an ordinary config file's.
 */
return [
    'timezone' => 'UTC',
    'handler' => Basic::class,
];
