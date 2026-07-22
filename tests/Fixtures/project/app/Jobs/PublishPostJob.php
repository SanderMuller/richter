<?php declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Neutral fixture for plan 036's dispatch-target predicate: a `\Jobs\`-namespaced ShouldQueue job. */
final class PublishPostJob implements ShouldQueue
{
    public function handle(): void {}
}
