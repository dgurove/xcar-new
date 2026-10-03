<?php

namespace App\Telegram\Offers\Console;

use App\Telegram\Offers\Handler;
use Illuminate\Console\Command;
use Illuminate\Support\Once;

/** Дорожка бота предложений: записи своих чатов строками JSON со stdin, по одной, по порядку (`offers-bot:run`). */
final class Lane extends Command
{
    protected $signature = 'offers-bot:lane {--lane=0}';

    protected $description = 'Дорожка бота предложений (запускает offers-bot:run)';

    public function handle(Handler $handler): int
    {
        while (($line = fgets(STDIN)) !== false) {
            $item = json_decode($line, true);
            if (is_array($item)) {
                $handler->handle($item);
                // Процесс живёт часами: памятки `once()` (вендоры, метки) иначе не обновились бы никогда.
                Once::flush();
            }
        }

        return self::SUCCESS;
    }
}
