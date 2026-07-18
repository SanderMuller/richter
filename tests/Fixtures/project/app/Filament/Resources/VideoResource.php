<?php declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Video;

final class VideoResource
{
    public function __construct(private readonly Video $video) {}

    public function table(): void
    {
        $this->video->questions()->get();
    }
}
