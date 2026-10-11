<?php

namespace App\Users\Console;

use App\Users\Actions\TakeTelegramAvatar;
use App\Users\User;
use Illuminate\Console\Command;

/** Фото профиля Telegram в аватар тем, у кого Telegram привязан, а аватара нет (11.10.2026). Без --apply только считает. */
final class TelegramAvatars extends Command
{
    protected $signature = 'telegram:avatars {--apply}';

    protected $description = 'Аватар из фото профиля Telegram тем, у кого своего нет';

    public function handle(TakeTelegramAvatar $take): int
    {
        $users = User::whereNotNull('telegram_chat_id')->whereDoesntHave('media', fn ($m) => $m->where('collection_name', 'avatar'))->get();
        $this->info('Без аватара с Telegram: '.$users->count());
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $done = 0;
        foreach ($users as $user) {
            if ($take($user)) {
                $done++;
                $this->line('  '.$user->name);
            }
        }
        $this->info("Поставлено: {$done}");

        return self::SUCCESS;
    }
}
