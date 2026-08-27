<?php declare(strict_types=1);

namespace Acme\Http\Middleware;

use Illuminate\Routing\Middleware\ValidateSignature;

/** A signed-URL guard under the app's own name — the HMAC authenticates the request, not a user. */
class CheckSignedLink extends ValidateSignature {}
