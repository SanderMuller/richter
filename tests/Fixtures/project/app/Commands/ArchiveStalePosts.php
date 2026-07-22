<?php declare(strict_types=1);

namespace App\Commands;

/**
 * A plain self-handling bus command — NO Dispatchable trait, not ShouldQueue, not under \Jobs\.
 * `dispatch($command)` / `Bus::dispatch($command)` still runs its handle() via Laravel's
 * BusDispatcher::dispatchNow fallback, so it is a real unresolved-dispatch target.
 */
final class ArchiveStalePosts
{
    public function handle(): void
    {
        //
    }
}
