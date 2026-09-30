<?php

namespace App\Telegram\Actions;

use App\Telegram\Chat;
use App\Users\User;

/** Отвязать Telegram: из профиля или сам человек заблокировал бота. Сама шторка подключения больше не открывается, карточка в «Сделках» — через 30 дней. */
final class UnlinkChat
{
    public function __invoke(User $user): void
    {
        // Переписка остаётся в «Бот Telegram», только без аккаунта.
        Chat::where('user_id', $user->id)->update(['user_id' => null]);
        $user->forceFill([
            'telegram_chat_id' => null, 'telegram_username' => null, 'telegram_linked_at' => null,
            'notification_settings' => [
                'telegram_intro' => now()->toIso8601String(), 'telegram_bid_n' => 3, 'telegram_card_hidden' => now()->addDays(30)->toIso8601String(),
            ] + ($user->notification_settings ?? []),
        ])->save();
    }

    public function byChat(int $chatId): void
    {
        User::where('telegram_chat_id', $chatId)->get()->each(fn (User $user) => $this($user));
    }
}
