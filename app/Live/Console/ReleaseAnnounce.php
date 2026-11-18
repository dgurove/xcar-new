<?php

namespace App\Live\Console;

use App\Live\Publisher;
use Illuminate\Console\Command;

/**
 * «Обновляемся» всем открытым окнам — зовёт `deploy/deploy.sh` за пару секунд до замены контейнера сайта (18.11.2026,
 * владелец: «каждый раз, когда деплою, им кажется, что сервер упал»). Окно показывает заставку и само её убирает, когда
 * сайт снова отвечает (`resources/js/lib/updating.js`).
 */
final class ReleaseAnnounce extends Command
{
    protected $signature = 'release:announce';

    protected $description = 'Сказать открытым окнам, что идёт выкладка';

    public function handle(Publisher $publish): int
    {
        $publish->everyone('release');

        return self::SUCCESS;
    }
}
