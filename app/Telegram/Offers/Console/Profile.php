<?php

namespace App\Telegram\Offers\Console;

use App\Telegram\Offers\OffersBot;
use Illuminate\Console\Command;

/** Описание бота предложений перед «Запустить» и меню команд. */
final class Profile extends Command
{
    private const DESCRIPTION = 'Новые предложения XCar каждый день в 16:00. Смотрите по одному, добавляйте в избранное и спрашивайте прямо здесь';

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
