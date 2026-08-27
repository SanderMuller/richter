<?php declare(strict_types=1);

namespace Acme\Http\Middleware;

use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;

/**
 * Authentication by ancestry, under a name that matches nothing: neither an alias pattern nor the
 * basename of the one framework class Brain's own `extends` walk terminates on.
 */
final class RequireBasicCredentials extends AuthenticateWithBasicAuth {}
