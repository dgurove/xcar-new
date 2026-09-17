<?php

namespace App\Chats;

use App\Users\User;
use Illuminate\Support\Facades\Cache;

/** Чат у человека на экране: лента с read=1 отмечается раз в запрос, живёт 45 с (опрос — каждые 20). Пуш такому не шлём. */
final class Presence
{
    public static function touch(Chat $chat, ?User $user): void
    {
        if ($user) {
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
