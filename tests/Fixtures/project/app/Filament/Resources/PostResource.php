<?php declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Post;

final class PostResource
{
    public function __construct(private readonly Post $post) {}

    public function table(): void
    {
        $this->post->reviews()->get();
    }
}
