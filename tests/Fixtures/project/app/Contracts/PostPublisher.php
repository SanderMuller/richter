<?php declare(strict_types=1);

namespace App\Contracts;

interface PostPublisher
{
    public function publish(): void;
}
