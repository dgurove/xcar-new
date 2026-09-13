<?php

namespace App\Purchases\Console;

use App\Purchases\Actions\ImportFile;
use Illuminate\Console\Command;

/**
 * Поставщик заливает фото в облако позже, чем выкладывает файл: папка в
 * момент выкачки пуста, а через день-два в ней сорок кадров. Раз в сутки
 * ставим такие машины в очередь заново — один PROPFIND на машину.
 */
final class RecheckPhotos extends Command
{
    protected $signature = 'purchases:recheck-photos';

    protected $description = 'Заново забирает фото у машин закупок, чьи папки в облаке были пусты';

    public function handle(ImportFile $import): int
    {
        $this->info('В очереди: '.$import->recheckEmpty());

        return self::SUCCESS;
    }
}
