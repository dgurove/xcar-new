<?php

namespace App\Telegram\Offers\Console;

use App\Telegram\Offers\OffersBot;
use Illuminate\Console\Command;

/** Описание бота предложений перед «Запустить» и меню команд. */
final class Profile extends Command
{
    /** С 03.11.2026 бот ничего не спрашивает: каждое новое предложение — сообщением с PDF для клиента. */
    private const DESCRIPTION = 'Новые предложения XCar для менеджеров, как только они выходят: каждое — PDF с текстом для клиента, просто перешлите его. Нажмите «Запустить» и войдите через XCar';

    private const SHORT = 'Новые предложения XCar для менеджеров';

    protected $signature = 'offers-bot:profile';

    protected $description = 'Ставит описание и меню команд бота предложений';

    public function handle(OffersBot $bot): int
    {
        $bot->profile(self::DESCRIPTION, self::SHORT);
        $this->info('Готово: @'.$bot->username());

        return self::SUCCESS;
    }
}
