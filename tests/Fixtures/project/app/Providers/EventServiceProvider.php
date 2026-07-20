<?php declare(strict_types=1);

namespace App\Providers;

use App\Events\PostPublished;
use App\Listeners\SendPostNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    protected $listen = [
        PostPublished::class => [
            SendPostNotification::class,
        ],
    ];
}
