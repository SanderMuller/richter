<?php declare(strict_types=1);

namespace App\Observers;

use App\Models\Comment;

final class CommentObserver
{
    public function saving(Comment $comment): void
    {
        $comment->touch();
    }
}
