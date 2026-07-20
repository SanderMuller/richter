<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ProcessPostJob implements ShouldQueue
{
    public function __construct(private readonly Post $post) {}

    public function handle(): void
    {
        $this->post->reviews()->get();
    }
}
