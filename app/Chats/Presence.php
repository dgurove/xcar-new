<?php

namespace App\Chats;

use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Support\Facades\Cache;

/** Чат у человека на экране: лента с read=1 отмечается при каждом догоне и раз в 20 с, пока видна; живёт 45 с. Пуш, Telegram и строку в ленте такому не шлём. */
final class Presence
{
    public static function touch(Chat $chat, ?User $user): void
    {
        // Админ за человека — не он у экрана: пуш о сообщении человеку всё равно нужен.
        if ($user && ! Impersonation::active()) {
            Cache::put(self::key($chat, $user), 1, 45);
        }
    }

    public static function viewing(Chat $chat, User $user): bool
    {
        return Cache::has(self::key($chat, $user));
    }

    private static function key(Chat $chat, User $user): string
    {
        return "chat:viewing:{$chat->id}:{$user->id}";
    }
}
