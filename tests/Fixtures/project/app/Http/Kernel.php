<?php declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CategoryAuthenticate;

final class Kernel
{
    /** @var array<string, class-string> */
    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'category.auth' => CategoryAuthenticate::class,
        'features' => 'Laravel\Pennant\Middleware\EnsureFeaturesAreActive',
    ];
}
