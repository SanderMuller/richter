<?php declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public const string DELETE = 'delete';

    public function delete(User $user, User $subject): bool
    {
        return false;
    }
}
