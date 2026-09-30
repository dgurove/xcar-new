<?php

namespace App\Telegram\Actions;

use App\Users\User;

/** Отвязать Telegram: из профиля или сам человек заблокировал бота. Окошко «Привяжите» больше не навязываем. */
final class UnlinkChat
{
    public function __invoke(User $user): void
    {
        $user->forceFill([
            'telegram_chat_id' => null, 'telegram_username' => null, 'telegram_linked_at' => null,
            'notification_settings' => ['telegram_later' => now()->addDays(30)->toIso8601String()] + ($user->notification_settings ?? []),
        ])->save();
    }

    public function byChat(int $chatId): void
    {
        User::where('telegram_chat_id', $chatId)->get()->each(fn (User $user) => $this($user));
    }
}
