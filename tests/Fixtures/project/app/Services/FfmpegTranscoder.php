<?php declare(strict_types=1);

namespace App\Services;

use App\Contracts\PostTranscoder;

final class FfmpegTranscoder implements PostTranscoder
{
    public function transcode(): void {}
}
