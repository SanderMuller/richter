<?php declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

final class PostPolicy
{
    public const string UPDATE = 'update';

    public function update(User $user, Post $post): bool
    {
        return true;
    }
}
