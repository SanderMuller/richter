<?php declare(strict_types=1);

namespace App\Services;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Review;

/** Two shapes no lane drew before the relation index: a body traversal, and a nested eager-load path. */
final class CommentSummariser
{
    public function summarise(Comment $comment): string
    {
        return (string) $comment->post->title;
    }

    public function preload(Post $post): void
    {
        $post->load([Post::REVIEWS . '.' . Review::ANSWERS]);
    }
}
