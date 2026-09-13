<?php

namespace App\Purchases\Actions;

use App\Purchases\Car;
use App\Purchases\Importer;
use App\Purchases\ImportState;
use App\Purchases\Jobs\FetchPhotos;
use App\Purchases\Jobs\FetchSpecs;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
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

    public function refetch(Purchase $purchase, bool $specs, bool $photos): int
    {
        $n = 0;
        foreach ($purchase->cars()->get() as $car) {
            if ($specs && $car->site_url && $car->specs_state !== ImportState::Gone) {
                $car->update(['specs_state' => ImportState::Pending]);
                FetchSpecs::dispatch($car->id);
                $n++;
            }
            if ($photos && $car->cloud_url && ! in_array($car->photos_state, [ImportState::Gone, ImportState::Done], true)) {
                $car->update(['photos_state' => ImportState::Pending]);
                FetchPhotos::dispatch($car->id);
                $n++;
            }
        }

        return $n;
    }

    /** Пустые в прошлый раз папки облака — по живым закупкам, папки с 404 не трогаем: шара удалена. */
    public function recheckEmpty(): int
    {
        $cars = Car::whereNotNull('cloud_url')->where('photos_count', 0)->where('photos_state', ImportState::Skipped)
            ->whereHas('purchase', fn ($q) => $q->whereIn('state', [PurchaseState::Draft, PurchaseState::Open]))->get();
        foreach ($cars as $car) {
            $car->update(['photos_state' => ImportState::Pending]);
            FetchPhotos::dispatch($car->id);
        }

        return $cars->count();
    }
}
