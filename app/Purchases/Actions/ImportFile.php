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

    /**
     * Ночной дозабор по живым закупкам: пустые в прошлый раз папки облака (поставщик заливает фото
     * позже файла), упавшие и неполные выкачки характеристик и фото. Папки с 404 не трогаем — шара
     * удалена; свежие сбои (моложе 12 часов) и больше 200 машин за проход — тоже: у Carcade бан за напор.
     */
    public function recheck(): int
    {
        $stale = now()->subHours(12);
        $cars = Car::whereHas('purchase', fn ($q) => $q->whereIn('state', [PurchaseState::Draft, PurchaseState::Open]))
            ->where('updated_at', '<', $stale)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('cloud_url')->where('photos_count', 0)->where('photos_state', ImportState::Skipped))
                ->orWhere(fn ($w) => $w->whereNotNull('cloud_url')->whereIn('photos_state', [ImportState::Failed, ImportState::Partial]))
                ->orWhere(fn ($w) => $w->whereNotNull('site_url')->whereIn('specs_state', [ImportState::Failed, ImportState::Partial])))
            ->orderBy('id')->limit(200)->get();
        $n = 0;
        foreach ($cars as $car) {
            if ($car->cloud_url && ($car->photos_state === ImportState::Skipped && $car->photos_count === 0 || $car->photos_state === ImportState::Failed || $car->photos_state === ImportState::Partial && $car->cloud_leftovers)) {
                $car->update(['photos_state' => ImportState::Pending]);
                FetchPhotos::dispatch($car->id);
                $n++;
            }
            if ($car->site_url && in_array($car->specs_state, [ImportState::Failed, ImportState::Partial], true)) {
                $car->update(['specs_state' => ImportState::Pending]);
                FetchSpecs::dispatch($car->id);
                $n++;
            }
        }

        return $n;
    }
}
