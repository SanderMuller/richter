<?php declare(strict_types=1);

namespace Acme\Http\Middleware;

/** Two hops from the framework base: the walk has to be transitive, not one `extends` deep. */
final class CheckExpiringLink extends CheckSignedLink {}
