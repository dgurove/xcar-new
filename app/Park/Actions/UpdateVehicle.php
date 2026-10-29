<?php

namespace App\Park\Actions;

use App\Billing\Actions\IssueTransferObligation;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Pricing;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Support\Changes;
use App\Users\User;

/**
 * Правка карточки ТС. Договор комиссии, вписанный после приёма, рождает обязательство перед вендором
 * (или пересчитывает неоплаченное). Поля машины (номер убытка, VIN, марка…) пишутся в машину (`Cars\Car`): их видит и
 * предложение той же машины; номер или VIN другой известной машины — та же машина (`JoinCars::settle`).
 * С `$sources` — поля взяты из документов и писем («✨», `park:fill-from-docs`): в истории дела «Заполнено по
 * документам» с их именами; без пользователя — это сделала система. Метку «в документе иначе» снимает запись машины
 * (`Car::saved`), из какой бы двери её ни правили.
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
        // Номер и VIN — у машины: у её продажи письма и лот Мигторга по новому номеру, как при правке из CRM.
        if ($offer = $vehicle->offer) {
            UpdateOffer::identityChanged($offer, array_keys($vehicle->carRecord()->getChanges()));
        }
        // Было → стало (`Changes`), с 14.10.2026.
        $sources
            ? $vehicle->log(EventType::FilledFromDocs, $by, $diff + ['sources' => array_values(array_unique($sources))])
            : $vehicle->log(EventType::Updated, $by, $diff);
        if ($by && $vehicle->accepted_at && array_intersect($changed, ['contract_kind', 'assigned_price', 'contract_no'])) {
            ($this->transfer)($vehicle, $by);
        }
        // Оценочная у машины одна: машина в продаже — закупочная от новой оценочной, если её не вписывали рукой
        // (`Pricing::floorFor`, владелец 04.10.2026). Машина уже в сделке или гараже — правка парковки закупочную не трогает:
        // новую закупочную поставщика вписывают в CRM (`UpdateOffer` → `UpdateDealCost`).
        if (in_array('value', $changed, true) && ($offer = $vehicle->offer) && ! $offer->costDeal()
            && ($floor = Pricing::floorFor($offer->floor_price, $diff['changes']['value'][0] ?? null, $vehicle->value))) {
            app(UpdateOffer::class)($offer, ['floor_price' => $floor], $by, ['why' => 'Оценочная на парковке']);
        }

        return $vehicle;
    }
}
