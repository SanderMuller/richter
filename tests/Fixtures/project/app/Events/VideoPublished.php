<?php declare(strict_types=1);

namespace App\Events;

use App\Models\Video;

final class VideoPublished
{
    public function __construct(public readonly Video $video) {}
}
