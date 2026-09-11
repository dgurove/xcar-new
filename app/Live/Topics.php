<?php

namespace App\Live;

use App\Users\User;

final class Topics
{
    public const CATALOG = 'catalog';

    public const STAFF = 'staff';

    public const PARK = 'park';

    public static function user(User|int $user): string
    {
        return 'user/'.($user instanceof User ? $user->id : $user);
    }

    /** Что человек вправе слушать. */
    public static function for(?User $user): array
    {
        $topics = [self::CATALOG];
        if ($user) {
            $topics[] = self::user($user);
            if ($user->isStaff()) {
                $topics[] = self::STAFF;
            }
            if ($user->canAccess(\App\Users\Section::Park)) {
                $topics[] = self::PARK;
            }
        }

        return $topics;
    }
}
