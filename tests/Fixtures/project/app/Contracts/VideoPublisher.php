<?php declare(strict_types=1);

namespace App\Contracts;

interface VideoPublisher
{
    public function publish(): void;
}
