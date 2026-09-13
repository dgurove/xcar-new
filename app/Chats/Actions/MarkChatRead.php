<?php

namespace App\Chats\Actions;

use App\Chats\Chat;
use App\Support\Nav;
use App\Users\User;

final class MarkChatRead
{
    public function __invoke(Chat $chat, ?User $by): void
    {
        $column = $by?->isStaff() ? 'unread_for_staff' : 'unread_for_user';
        if ($chat->{$column} > 0) {
            $chat->update([$column => 0]);
            if ($column === 'unread_for_staff') {
                Nav::forgetStaffCounts();
            }
        }
    }
}
