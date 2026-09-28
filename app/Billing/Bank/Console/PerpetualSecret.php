<?php

namespace App\Billing\Bank\Console;

use App\Billing\Bank\Connection;
use App\Billing\Bank\SberApi;
use Illuminate\Console\Command;

/** Один раз после выпуска доступа: client_secret Сбера живёт 40 дней, этот обмен даёт бессрочный и кладёт его в базу. */
final class PerpetualSecret extends Command
{
    protected $signature = 'bank:secret';

    protected $description = 'Меняет client_secret Sber API на бессрочный';

    public function handle(SberApi $api): int
    {
        if (! $this->confirm('Текущий client_secret перестанет работать, новый будет только в базе. Продолжить?', true)) {
            return self::SUCCESS;
        }
        $api->perpetualSecret(Connection::sber());
        $this->info('Готово: бессрочный client_secret сохранён, SBER_CLIENT_SECRET в .env больше не нужен');

        return self::SUCCESS;
    }
}
