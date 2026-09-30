<?php

namespace App\Telegram\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Telegram\Chat;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Чат Telegram — к аккаунту. Один чат — один аккаунт: у прежнего владельца чата привязка снимается. */
final class LinkChat
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(User $user, int $chatId, ?string $username): void
    {
        DB::transaction(function () use ($user, $chatId, $username) {
            User::where('telegram_chat_id', $chatId)->whereKeyNot($user->id)
                ->update(['telegram_chat_id' => null, 'telegram_username' => null, 'telegram_linked_at' => null]);
            $user->forceFill(['telegram_chat_id' => $chatId, 'telegram_username' => $username ?: null, 'telegram_linked_at' => now()])->save();
            Chat::where('user_id', $user->id)->whereKeyNot($chatId)->update(['user_id' => null]);
            Chat::updateOrCreate(['id' => $chatId], ['user_id' => $user->id, 'username' => $username ?: null]);
        });
        // Открытая страница с окошком или профилем перерисуется сама.
        ($this->publish)(Topics::user($user), 'telegram', ['state' => 'linked']);
    }
}
