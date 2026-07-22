<?php declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Neutral fixture for plan 036's dispatch-target predicate: a `ShouldQueue` class OUTSIDE the
 * `\Jobs\` namespace — proves the predicate's `ShouldQueue` branch fires independently of the
 * `\Jobs\` string heuristic.
 */
final class PostDigestNotification implements ShouldQueue
{
    public function handle(): void {}
}
