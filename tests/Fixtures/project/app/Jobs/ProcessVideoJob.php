<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ProcessVideoJob implements ShouldQueue
{
    public function __construct(private readonly Video $video) {}

    public function handle(): void
    {
        $this->video->questions()->get();
    }
}
