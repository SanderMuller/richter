<?php declare(strict_types=1);

namespace Acme\Http\Middleware;

use Illuminate\Auth\Middleware\EnsureEmailIsVerified;

/** The same shape one base further out: the `verified` guard, subclassed under the app's own name. */
final class VerifyCustomerEmail extends EnsureEmailIsVerified {}
