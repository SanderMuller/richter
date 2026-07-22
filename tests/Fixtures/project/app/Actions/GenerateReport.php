<?php declare(strict_types=1);

namespace App\Actions;

use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Neutral fixture for plan 036's dispatch-target predicate: a synchronous command dispatched via
 * the `Dispatchable` trait that is NOT `ShouldQueue` and NOT under `\Jobs\` — the shape v1 of the
 * plan missed (see plan 036 "Why v1 was unsound", point 2).
 */
final class GenerateReport
{
    use Dispatchable;

    public function handle(): void {}
}
