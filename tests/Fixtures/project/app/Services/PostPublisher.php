<?php declare(strict_types=1);

namespace App\Services;

/**
 * Neutral fixture for plan 036: an ordinary service — not a bus dispatch target — used as a
 * changed/caller class that must never itself trip the scoped S2 blocker.
 */
final class PostPublisher
{
    public function publish(): void {}
}
