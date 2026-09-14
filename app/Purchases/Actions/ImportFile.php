<?php

namespace App\Purchases\Actions;

use App\Purchases\Importer;
use App\Purchases\ImportState;
use App\Purchases\Jobs\FetchPhotos;
use App\Purchases\Jobs\FetchSpecs;
use App\Purchases\Purchase;
use App\Users\User;

/** Импорт подтверждённого файла и запуск выкачки: машины видны сразу, характеристики и фото едут фоном. */
final class ImportFile
{
    public function __construct(private Importer $importer) {}

    public function __invoke(Purchase $purchase, string $path, User $by): array
    {
        $result = $this->importer->import($purchase, $path, $by);
        foreach ($purchase->cars()->where('specs_state', ImportState::Pending)->pluck('id') as $id) {
            FetchSpecs::dispatch($id);
        }
        foreach ($purchase->cars()->where('photos_state', ImportState::Pending)->pluck('id') as $id) {
            FetchPhotos::dispatch($id);
        }

        return $result;
    }
}
