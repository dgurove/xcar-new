<?php

namespace App\Telegram\Offers\Console;

use App\Telegram\Offers\OffersBot;
use Illuminate\Console\Command;

/** Описание бота предложений перед «Запустить» и меню команд. */
final class Profile extends Command
{
    /** Без «спрашивайте прямо здесь» (💬 убран 18.10.2026) и без «в 16:00» (21.10.2026: публикуют сразу, пояса разные). */
    private const DESCRIPTION = 'Новые предложения XCar для менеджеров, как только они выходят. Смотрите по одному и пересылайте клиентам. Нажмите «Запустить» и войдите через XCar';

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
