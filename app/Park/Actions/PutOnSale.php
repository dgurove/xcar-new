<?php

namespace App\Park\Actions;

use App\Cars\Identity;
use App\Mail\Actions\LinkThread;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\EventType;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «В продажу» в деле ТС: черновик продажи этой машины (`Cars\Car`): тождество, фото и документы у них общие. Город —
 * город парковки (без неё — город ТС), вендор — парковки (у продажи он свой: `Vendor::forCrm`), закупочная — по правилу
 * от оценочной (`Sale::floorFrom`), пока её не вписали. Продажа этой машины уже была (снимали, архив) — она и
 * возвращается в черновики, второй не заводится. Письма CRM о той же машине едут к ней. Обратно — «Убрать из продажи»
 * (`TakeOffSale`). Без человека (`by` null) — сама, `AutoSale`.
 */
final class PutOnSale
{
    public function __construct(private CreateOffer $create, private LinkThread $threads, private ChangeOfferState $change) {}

    public function __invoke(Vehicle $vehicle, ?User $by): Offer
    {
        if ($vehicle->offer) {
            return $vehicle->offer;
        }

        return DB::transaction(function () use ($vehicle, $by) {
            $car = $vehicle->carRecord();
            if ($prev = $car->offer()->first()) {
                if (in_array($prev->state, [OfferState::Cancelled, OfferState::Delivered], true)) {
                    throw ValidationException::withMessages(['vehicle' => 'Эта машина уже продана: '.Identity::offerName($prev)]);
                }
                $prev->state === OfferState::Archived && ($this->change)($prev, OfferState::Draft, $by);

                return $prev->refresh();
            }
            $offer = ($this->create)($by, array_filter([
                // Без парковки — город ТС (`city_id` — населённый пункт, `Park\City`).
                'settlement_id' => $vehicle->yard?->settlement_id ?? $vehicle->city_id,
                'car_place' => $vehicle->state === VehicleState::Stored ? CarPlace::Ours : null,
                'vendor_id' => $vehicle->vendor_id,
                'prices_include_vat' => $vehicle->vendor_id ? Vendor::offersVat($vehicle->vendor_id) : null,
                'floor_price' => $car->value ? Sale::floorFrom((int) $car->value) : null,
            ], fn ($v) => $v !== null), ['park' => $vehicle->id], car: $car);
            $vehicle->log(EventType::Linked, $by, ['number' => $offer->number]);
            $this->threads->forOffer($offer->refresh());

            return $offer;
        });
    }
}
