<?php

namespace App\Live;

use App\Support\Surface;
use App\Users\Section;
use App\Users\User;

final class Topics
{
    public const CATALOG = 'catalog';

    public const STAFF = 'staff';

    public const PARK = 'park';

    /** Только админам: переписка бота (Настройки → «Бот Telegram»). */
    public const ADMIN = 'admin';

    public static function user(User|int $user): string
    {
        return 'user/'.($user instanceof User ? $user->id : $user);
    }

    /** Обращение гостя: тема одного чата, попадает в токен только по cookie. */
    public static function chat(int $chat): string
    {
        return "chat/{$chat}";
    }

    /** Вход через Telegram: страница входа ждёт кнопку в чате — тема одной попытки, по сессии этого браузера. */
    public static function login(string $token): string
    {
        return "login/{$token}";
    }

    /**
     * Что слушает страница этого хоста — из того, что вправе (`for`, его и подписывает cookie хаба: она одна на три хоста).
     * Парковка — отдельное приложение (владелец, 05.10.2026): её события только на её хосте, а CRM-события (`staff`:
     * письма предложений, чаты, сделки) на парковке не нужны.
     */
    public static function here(?User $user, ?int $guestChat = null, ?Surface $surface = null): array
    {
        $park = ($surface ?? Surface::current()) === Surface::Park;

        return array_values(array_filter(self::for($user, $guestChat), fn ($t) => $park ? $t !== self::STAFF : $t !== self::PARK));
    }

    /** Что человек вправе слушать. */
    public static function for(?User $user, ?int $guestChat = null): array
    {
        // Покупатель общий каталог не слушает: его лента приходит в личную тему.
        $topics = $user?->isBuyer() ? [] : [self::CATALOG];
        if ($guestChat) {
            $topics[] = self::chat($guestChat);
        }
        if (! $user && request()->hasSession() && is_string($login = request()->session()->get('telegram.login'))) {
            $topics[] = self::login($login);
        }
        if ($user) {
            $topics[] = self::user($user);
            if ($user->isStaff()) {
                $topics[] = self::STAFF;
            }
            if ($user->isAdmin()) {
                $topics[] = self::ADMIN;
            }
            if ($user->canAccess(Section::Park)) {
                $topics[] = self::PARK;
            }
        }

        return $topics;
    }
}
