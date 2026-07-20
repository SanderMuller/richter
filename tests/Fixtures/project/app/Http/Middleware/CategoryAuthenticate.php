<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CategoryAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
