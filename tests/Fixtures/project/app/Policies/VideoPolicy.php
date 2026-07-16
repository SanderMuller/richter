<?php declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

final class VideoPolicy
{
    public const string UPDATE = 'update';

    public function update(User $user, Video $video): bool
    {
        return true;
    }
}
