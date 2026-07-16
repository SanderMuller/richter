<?php declare(strict_types=1);

namespace App\Listeners;

use App\Events\VideoPublished;
use App\Models\VideoContainer;

final class SendVideoNotification
{
    public function handle(VideoPublished $event): void
    {
        VideoContainer::query()->get();
    }
}
