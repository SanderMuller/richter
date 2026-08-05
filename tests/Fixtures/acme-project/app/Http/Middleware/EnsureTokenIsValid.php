<?php declare(strict_types=1);

namespace Acme\Http\Middleware;

use Acme\Services\TokenInspector;

final class EnsureTokenIsValid
{
    public function handle(mixed $request, callable $next): mixed
    {
        $inspector = new TokenInspector();
        $inspector->inspect('token');

        return $next($request);
    }
}
