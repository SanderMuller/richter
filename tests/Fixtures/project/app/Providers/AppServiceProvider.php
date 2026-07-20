<?php declare(strict_types=1);

namespace App\Providers;

use App\Contracts\PostTranscoder;
use App\Contracts\ThumbnailRenderer;
use App\Services\FfmpegTranscoder;
use App\Services\GdThumbnailRenderer;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        ThumbnailRenderer::class => GdThumbnailRenderer::class,
    ];

    public function register(): void
    {
        $this->app->bind(PostTranscoder::class, FfmpegTranscoder::class);
    }
}
