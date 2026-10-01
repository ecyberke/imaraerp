<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/** A Notification belongs to exactly one User - visibility is "is this yours," not a role gate. */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notification $notification): bool
    {
        return $user->id === $notification->user_id;
    }

    public function update(User $user, Notification $notification): bool
    {
        return $user->id === $notification->user_id;
    }
}
