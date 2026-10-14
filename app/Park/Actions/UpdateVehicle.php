<?php

namespace App\Park\Actions;

use App\Billing\Actions\IssueTransferObligation;
use App\Cars\Vin\RememberVin;
use App\Offers\Actions\UpdateOffer;
use App\Park\EventType;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Support\Changes;
use App\Users\User;

/**
 * Правка карточки ТС. Договор комиссии, вписанный после приёма, рождает обязательство перед вендором
 * (или пересчитывает неоплаченное). Поля машины (номер убытка, VIN, марка…) пишутся в машину (`Cars\Car`): их видит и
 * предложение той же машины; номер или VIN другой известной машины — та же машина (`JoinCars::settle`).
 * С `$sources` — поля взяты из документов и писем («✨», `park:fill-from-docs`): в истории дела «Заполнено по
 * документам» с их именами; без пользователя — это сделала система. Правка полей, которые сверяются с документами,
 * снимает метку «в документе иначе» до пересчёта (`FillFromDocs::forget`).
 */
final class UpdateVehicle
{
    public function __construct(private IssueTransferObligation $transfer) {}

    /** @param list<string> $sources имена документов и «письмо», откуда взяты поля */
    public function __invoke(Vehicle $vehicle, array $data, ?User $by, array $sources = []): Vehicle
    {
        $vehicle->fill($data);
        $diff = Changes::ofLane($vehicle);
        $changed = [...array_keys($vehicle->getDirty()), ...$vehicle->carDirty()];
        $vehicle->save();
        if (! $changed) {
            return $vehicle;
        }
        // Было → стало (`Changes`), с 14.10.2026.
        $sources
            ? $vehicle->log(EventType::FilledFromDocs, $by, $diff + ['sources' => array_values(array_unique($sources))])
            : $vehicle->log(EventType::Updated, $by, $diff);
        (new RememberVin)($vehicle);
        if (array_intersect($changed, ['vin', 'plate', 'year', 'color', 'value', 'brand_id', 'model_id'])) {
            FillFromDocs::forget($vehicle);
        }
        if ($by && $vehicle->accepted_at && array_intersect($changed, ['contract_kind', 'assigned_price', 'contract_no'])) {
            ($this->transfer)($vehicle, $by);
        }
        // Оценочная у машины одна: машина в продаже — закупочная от новой оценочной, если её не вписывали рукой
        // (`Sale::floorFor`, владелец 04.10.2026).
        if (in_array('value', $changed, true) && ($offer = $vehicle->offer)
            && ($floor = Sale::floorFor($offer->floor_price, $diff['changes']['value'][0] ?? null, $vehicle->value))) {
            app(UpdateOffer::class)($offer, ['floor_price' => $floor], $by, ['why' => 'Оценочная на парковке']);
        }

        return $vehicle;
    }
}
