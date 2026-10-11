<?php

namespace App\Users\Actions;

use App\Media\PhotoIngest;
use App\Telegram\Bot;
use App\Users\User;

/**
 * Аватар из Telegram (11.10.2026, владелец): привязали Telegram, а своего аватара нет — берём фото профиля. Свой аватар
 * не трогаем никогда. true — поставили.
 */
final class TakeTelegramAvatar
{
    public function __construct(private Bot $bot, private PhotoIngest $ingest) {}

    public function __invoke(User $user): bool
    {
        if (! $user->telegram_chat_id || $user->getFirstMedia('avatar')) {
            return false;
        }
        $photo = $this->bot->profilePhoto((int) $user->telegram_chat_id);
        if (! $photo) {
            return false;
        }
        // Аватар живёт в 128 px — исходник больше 512 не нужен (как при загрузке в профиле).
        $this->ingest->fromString($user, 'avatar', $photo[0], basename($photo[1]), max: 512);

        return true;
    }
}
