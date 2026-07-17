<?php declare(strict_types=1);

namespace App\Services;

use App\Contracts\VideoTranscoder;

final class FfmpegTranscoder implements VideoTranscoder
{
    public function transcode(): void
    {
        // Fixture body — the graph only reads declarations.
    }
}
