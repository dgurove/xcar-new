<?php

namespace App\Purchases\Actions;

use App\Offers\Actions\CreateOffer;
use App\Purchases\Car;
use App\Purchases\Purchase;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Строки контрпредложения → черновики предложений. Закупочная — итоговая цена поставщика как есть,
 * заявленная пустая (равна закупочной), цену продажи ставят руками в «Оценить». Цены менеджеров и наша
 * цена закупки не переносятся: они были нужны только для файла поставщику. Фото переходят к предложению
 * записью в базе — файлы, конверсии, порядок и скрытые кадры остаются как были. ТС с предложением из закупки
 * исключается; повторная загрузка того же файла её пропускает.
 */
final class MoveToOffers
{
    public function __construct(private CreateOffer $create) {}

    /** @param list<array{dl: string, price: ?int}> $rows */
    public function __invoke(Purchase $purchase, array $rows, User $by): int
    {
        $vendor = $purchase->vendor;
        if (! $vendor) {
            throw ValidationException::withMessages(['file' => 'Выберите поставщика в закупке']);
        }
        $prices = collect($rows)->filter(fn ($r) => $r['price'])->mapWithKeys(fn ($r) => [mb_strtolower(trim($r['dl'])) => $r['price']]);
        $cars = $purchase->cars()->whereNull('offer_id')->get()->filter(fn (Car $c) => $prices->has(mb_strtolower(trim($c->dl))));
        foreach ($cars as $car) {
            DB::transaction(function () use ($car, $purchase, $vendor, $prices, $by) {
                $offer = ($this->create)($by, [
                    'brand_id' => $car->brand_id, 'model_id' => $car->model_id, 'year' => $car->year, 'vin' => $car->vin, 'mileage' => $car->mileage,
                    'transmission' => $car->transmission, 'fuel' => $car->fuel, 'engine_volume' => $car->engine_volume, 'engine_power' => $car->engine_power,
                    'color' => $car->color, 'settlement_id' => $car->settlement_id, 'inspection_address' => $car->address, 'description' => $car->description,
                    'share_locked' => $car->share_locked, 'vendor_id' => $vendor->id, 'prices_include_vat' => (bool) $vendor->offers_include_vat,
                    'floor_price' => $prices[mb_strtolower(trim($car->dl))], 'claim_ref' => $car->dl,
                ], ['purchase' => $purchase->number, 'dl' => $car->dl]);
                // Фото не копируются, а переходят: путь файла у spatie — по id кадра, модель в нём не участвует.
                $car->media()->where('collection_name', 'photos')->update(['model_type' => $offer->getMorphClass(), 'model_id' => $offer->id]);
                $car->update(['offer_id' => $offer->id, 'photos_count' => 0]);
            });
        }

        return $cars->count();
    }
}
