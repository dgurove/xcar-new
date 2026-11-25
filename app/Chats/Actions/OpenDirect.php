<?php

namespace App\Chats\Actions;

use App\Chats\Chat;
use App\Chats\Direct;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Личка двух людей (`Chats\Direct`): есть — она же, нет — заводится в постоянной стороне пары. Без приветствия площадки.
 * Кто может начать — решает тот, кто зовёт (`Direct::canStart`). Замок на пару: два первых сообщения разом не заведут две.
 * Сотрудник не сотруднику (`Direct::viaPlatform`, 25.11.2026) — чат площадки человека «Администрация XCAR», без приветствия.
 */
final class OpenDirect
{
    public function __invoke(User $from, User $to): Chat
    {
        return DB::transaction(function () use ($from, $to) {
            if (Direct::viaPlatform($from, $to)) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['chat-platform:'.$to->id]);

                return Direct::platformOf($to)->first() ?? Chat::create(['offer_id' => null, 'user_id' => $to->id, 'manager_id' => null]);
            }
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['chat-direct:'.min($from->id, $to->id).':'.max($from->id, $to->id)]);
            if ($chat = Direct::between($from, $to)) {
                return $chat;
            }
            [$user, $manager] = Direct::orient($from, $to);

            return Chat::create(['offer_id' => null, 'user_id' => $user->id, 'manager_id' => $manager->id]);
        });
    }
}
