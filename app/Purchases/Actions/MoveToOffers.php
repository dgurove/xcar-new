<?php

namespace App\Purchases\Actions;

use App\Cars\Actions\JoinCars;
use App\Cars\Identity;
use App\Offers\Intake\DraftSource;
use App\Offers\Intake\OpenDraft;
use App\Offers\OfferEventType;
use App\Purchases\Car;
use App\Purchases\Purchase;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Строки контрпредложения → черновики предложений. Лот закупки — уже машина (`Cars\Car`: ДЛ, VIN, характеристики, фото),
 * предложение встаёт на неё же: ничего не копируется и не переносится. Закупочная — итоговая цена поставщика как есть,
 * заявленная пустая (равна закупочной), цену продажи ставят руками в «Оценить». Цены менеджеров и наша цена закупки не
 * переносятся: они были нужны только для файла поставщику. О машине уже знали в CRM под тем же VIN (другая запись) —
 * записи сливаются (`JoinCars`), второго предложения нет. ТС с предложением из закупки исключается; повторная загрузка
 * того же файла её пропускает.
 */
final class MoveToOffers
{
    public function __construct(private OpenDraft $open, private JoinCars $join) {}

    /** @param list<array{dl: string, price: ?int}> $rows */
    public function __invoke(Purchase $purchase, array $rows, User $by): int
    {
        $vendor = $purchase->vendor;
        if (! $vendor) {
            throw ValidationException::withMessages(['file' => 'Выберите поставщика в закупке']);
        }
        $prices = collect($rows)->filter(fn ($r) => $r['price'])->mapWithKeys(fn ($r) => [mb_strtolower(trim($r['dl'])) => $r['price']]);
        $cars = $purchase->cars()->whereDoesntHave('offer')->get()->filter(fn (Car $c) => $prices->has(mb_strtolower(trim((string) $c->dl))));
        foreach ($cars as $car) {
            $own = $car->carRecord();
            // Та же машина под тем же VIN уже живёт в CRM или на парковке (другая запись) — одна машина: записи сливаются.
            // Номер у той записи другой — это не ДЛ лота, сливать нельзя: лот остаётся в закупке.
            if ($twin = Identity::carByVin($car->vin, $own->id)) {
                if ($twin->claim_ref_key && $twin->claim_ref_key !== $own->claim_ref_key) {
                    $twin->offer?->log(OfferEventType::Note, $by, ['text' => "Закупка {$purchase->number}, ДЛ {$car->dl}: тот же VIN, другой номер — не перенесено"]);

                    continue;
                }
                ($this->join)($own, $twin, $by);
                $own = $twin->fresh();
                if ($holder = $own->offer) {
                    $holder->log(OfferEventType::Note, $by, ['text' => "Закупка {$purchase->number}, ДЛ {$car->dl}: контрпредложение к этому предложению"]);

                    continue;
                }
            }
            // НДС цен — от вендора (`UpdateOffer`), как у любого черновика.
            ($this->open)(new DraftSource('purchase', car: $own, fields: [
                'settlement_id' => $car->settlement_id, 'inspection_address' => $car->address,
                'share_locked' => $car->share_locked, 'vendor_id' => $vendor->id,
                'floor_price' => $prices[mb_strtolower(trim((string) $car->dl))],
                ...$car->offerExtras(),
            ], created: ['purchase' => $purchase->number, 'dl' => $car->dl], why: 'Контрпредложение закупки '.$purchase->number), $by);
        }

        return $cars->count();
    }
}
