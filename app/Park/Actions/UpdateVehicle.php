<?php

namespace App\Park\Actions;

use App\Billing\Actions\IssueTransferObligation;
use App\Cars\Vin\RememberVin;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;

/**
 * Правка карточки ТС. Договор комиссии, вписанный после приёма, рождает обязательство перед вендором
 * (или пересчитывает неоплаченное); номер убытка или VIN изменились без привязки — предложение ищется заново.
 */
final class UpdateVehicle
{
    public function __construct(private IssueTransferObligation $transfer, private LinkOffer $linkOffer) {}

    public function __invoke(Vehicle $vehicle, array $data, User $by): Vehicle
    {
        $vehicle->fill($data);
        $changed = array_diff(array_keys($vehicle->getDirty()), ['ref_key']);
        $vehicle->save();
        if (! $changed) {
            return $vehicle;
        }
        $vehicle->log(EventType::Updated, $by, ['fields' => array_values($changed)]);
        (new RememberVin)($vehicle);
        if ($vehicle->accepted_at && array_intersect($changed, ['contract_kind', 'assigned_price', 'contract_no'])) {
            ($this->transfer)($vehicle, $by);
        }
        if (! $vehicle->offer_id && array_intersect($changed, ['ref', 'vin'])) {
            ($this->linkOffer)($vehicle, null, $by);
        }

        return $vehicle;
    }
}
