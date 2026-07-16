<?php declare(strict_types=1);

namespace App\Providers;

use App\Events\VideoPublished;
use App\Listeners\SendVideoNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    protected $listen = [
        VideoPublished::class => [
            SendVideoNotification::class,
        ],
    ];
}
