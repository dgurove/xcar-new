<?php

namespace App\Chats\Actions;

use App\Chats\Chat;
use App\Chats\Events\ChatRead;
use App\Support\Nav;
use App\Users\User;

/** Читающий дочитал до конца: его непрочитанное — ноль, read_seq — последний номер. Сотрудник в чужом чате ничего не меняет. */
final class MarkChatRead
{
    public function __invoke(Chat $chat, ?User $by, ?string $token = null): void
    {
        if (! $chat->canPost($by, $token)) {
            return;
        }
        $counterpart = $chat->isCounterpart($by);
        $column = $counterpart ? 'unread_for_staff' : 'unread_for_user';
        $seqColumn = $counterpart ? 'read_seq_staff' : 'read_seq_user';
        if ($chat->{$column} > 0 || $chat->{$seqColumn} < $chat->messages_count) {
            $chat->update([$column => 0, $seqColumn => $chat->messages_count]);
            if ($column === 'unread_for_staff' && ! $chat->isBuyerChat()) {
                Nav::forgetStaffCounts();
            }
            ChatRead::dispatch($chat, $counterpart, (int) $chat->messages_count);
        }
    }
}
