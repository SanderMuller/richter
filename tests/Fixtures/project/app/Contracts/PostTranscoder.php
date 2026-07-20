<?php declare(strict_types=1);

namespace App\Contracts;

interface PostTranscoder
{
    public function transcode(): void;
}
