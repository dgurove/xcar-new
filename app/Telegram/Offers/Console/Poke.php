<?php

namespace App\Telegram\Offers\Console;

use App\Telegram\Offers\Handler;
use App\Telegram\Offers\Subscriber;
use Illuminate\Console\Command;

/**
 * Проверка руками: прислать подписчику то, что прислали бы часы, не дожидаясь 13:00 и 16:00. `--fresh` — забыть
 * отметки (13:00 сегодня, последний анонс), чтобы пришло ещё раз.
 */
final class Poke extends Command
{
    protected $signature = 'offers-bot:poke {timer : morning|announce|remind} {user : id пользователя} {--fresh}';

    protected $description = 'Прислать подписчику анонс бота предложений сейчас';

    public function handle(Handler $handler): int
    {
        $sub = Subscriber::where('user_id', (int) $this->argument('user'))->first();
        if (! $sub) {
            $this->error('Этот человек на бота не подписан');

            return self::FAILURE;
        }
        if ($this->option('fresh')) {
            $sub->forceFill(['morning_on' => null, 'announced_at' => now()->subDays(3), 'mode' => Subscriber::MENU])->save();
        }
        $handler->handle(['timer' => (string) $this->argument('timer'), 'chat_id' => $sub->chat_id]);
        $this->info('Отправлено, если было что');

        return self::SUCCESS;
    }
}
