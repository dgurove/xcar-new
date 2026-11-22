<?php

namespace App\Chats\Actions;

use App\Chats\Chat;
use App\Chats\Events\ChatRead;
use App\Notifications\ReadNotices;
use App\Support\Nav;
use App\Users\Impersonation;
use App\Users\Role;
use App\Users\User;

/**
 * Читающий дочитал до конца: его непрочитанное — ноль, read_seq — последний номер, уведомления об этом чате в ленте
 * его стороны прочитаны (у сотрудников сторона общая — гаснет у всех). Сотрудник в чужом чате ничего не меняет.
 */
final class MarkChatRead
{
    public function __construct(private ReadNotices $readNotices) {}

    public function __invoke(Chat $chat, ?User $by, ?string $token = null): void
    {
        // Админ, вошедший за человека, читает не за него: непрочитанное и квитанция остаются.
        if (Impersonation::active() || ! $chat->canPost($by, $token)) {
            return;
        }
        $counterpart = $chat->isCounterpart($by);
        $side = match (true) {
            ! $counterpart => [$chat->user_id],
            $chat->isBuyerChat() => [$chat->manager_id],
            // Сторона площадки — и модераторы: `Notify::chat` шлёт им те же строки ленты (до 22.11.2026 у них они не гасли).
            default => User::withRole(Role::Admin, Role::Moderator)->pluck('id')->all(),
        };
        ($this->readNotices)($side, [Chat::subject($chat->id)]);
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
