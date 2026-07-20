<?php declare(strict_types=1);

namespace App\Events;

use App\Models\Post;

final class PostPublished
{
    public function __construct(public readonly Post $post) {}
}
