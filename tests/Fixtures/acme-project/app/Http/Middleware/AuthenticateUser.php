<?php declare(strict_types=1);

namespace Acme\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;

/**
 * The shape Brain's name-based match cannot see: the app's own auth middleware, named and namespaced
 * by the app, authenticating only by virtue of what it extends.
 */
final class AuthenticateUser extends BaseAuthenticate {}
