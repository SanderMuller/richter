<?php declare(strict_types=1);

namespace App\Contracts;

interface VideoTranscoder
{
    public function transcode(): void;
}
