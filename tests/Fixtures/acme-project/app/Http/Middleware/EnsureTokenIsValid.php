<?php declare(strict_types=1);

namespace Acme\Http\Middleware;

use Acme\Services\ReportMappingService;
use Acme\Services\TokenInspector;
use Acme\Support\ReportRegistry;

final class EnsureTokenIsValid
{
    public function handle(mixed $request, callable $next): mixed
    {
        $inspector = new TokenInspector();
        $inspector->inspect('token');

        $mapper = new ReportMappingService();
        $mapper->build();

        ReportRegistry::boot();

        return $next($request);
    }
}
