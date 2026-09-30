<?php

namespace App\Telegram\Console;

use App\Telegram\Bot;
use Illuminate\Console\Command;

/**
 * Профиль бота: описание — экран «Что умеет этот бот?», который человек видит до «Запустить», и короткое — в карточке
 * бота. Пустой экран перед «Запустить» отпугивает. Идемпотентно, гонять после смены текстов.
 */
final class SetProfile extends Command
{
    public const DESCRIPTION = 'Уведомления xcar.ru по вашим сделкам: выбрали ваше подтверждение, ваш ход, счета и выплаты. Без рассылок';

    public const SHORT = 'Сделки xcar.ru в Telegram';

    protected $signature = 'telegram:profile';

    protected $description = 'Записывает описание бота в Telegram';

    public function handle(Bot $bot): int
    {
        if (! $bot->configured()) {
            $this->warn('Токен бота не задан');

            return self::SUCCESS;
        }
        $bot->setProfile(self::DESCRIPTION, self::SHORT);
        $this->info('Описание бота записано');

        return self::SUCCESS;
    }
}
