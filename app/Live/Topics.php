<?php

namespace App\Live;

use App\Users\Section;
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

    /** Обращение гостя: тема одного чата, попадает в токен только по cookie. */
    public static function chat(int $chat): string
    {
        return "chat/{$chat}";
    }

    /** Что человек вправе слушать. */
    public static function for(?User $user, ?int $guestChat = null): array
    {
        // Покупатель общий каталог не слушает: его лента приходит в личную тему.
        $topics = $user?->isBuyer() ? [] : [self::CATALOG];
        if ($guestChat) {
            $topics[] = self::chat($guestChat);
        }
        if ($user) {
            $topics[] = self::user($user);
            if ($user->isStaff()) {
                $topics[] = self::STAFF;
            }
            if ($user->canAccess(Section::Park)) {
                $topics[] = self::PARK;
            }
        }

        return $topics;
    }
}
