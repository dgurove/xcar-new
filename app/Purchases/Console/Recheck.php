<?php

namespace App\Purchases\Console;

use App\Purchases\Actions\ImportFile;
use Illuminate\Console\Command;

/**
 * Ночной дозабор: поставщик заливает фото в облако позже, чем выкладывает
 * файл (папка в момент выкачки пуста, через день-два в ней сорок кадров),
 * сайт иногда отдаёт 403 или таймаут. Раз в сутки такие машины — в очередь
 * заново; кнопок «перечитать» в закупке нет, только точечно у машины.
 */
final class Recheck extends Command
{
    protected $signature = 'purchases:recheck';

    protected $description = 'Заново забирает характеристики и фото у машин закупок, где выкачка не удалась или была неполной';

    public function handle(ImportFile $import): int
    {
        $this->info('В очереди: '.$import->recheck());

        return self::SUCCESS;
    }
}
