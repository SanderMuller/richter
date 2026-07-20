<?php declare(strict_types=1);

namespace App\Listeners;

use App\Events\PostPublished;
use App\Models\PostContainer;

final class SendPostNotification
{
    public function handle(PostPublished $event): void
    {
        PostContainer::query()->get();
    }
}
