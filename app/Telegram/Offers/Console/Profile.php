<?php

namespace App\Telegram\Offers\Console;

use App\Telegram\Offers\OffersBot;
use Illuminate\Console\Command;

/** Описание бота предложений перед «Запустить» и меню команд. */
final class Profile extends Command
{
    /** Без «спрашивайте прямо здесь» — 💬 убран 18.10.2026; второй бот — сделки (21.10.2026). */
    private const DESCRIPTION = 'Новые предложения XCar каждый день в 16:00 для менеджеров. Смотрите по одному и добавляйте в избранное. Нажмите «Запустить» и войдите через XCar';

    private const SHORT = 'Новые предложения XCar каждый день в 16:00';

    protected $signature = 'offers-bot:profile';

    protected $description = 'Ставит описание и меню команд бота предложений';

    public function handle(OffersBot $bot): int
    {
        $bot->profile(self::DESCRIPTION, self::SHORT);
        $this->info('Готово: @'.$bot->username());

        return self::SUCCESS;
    }
}
