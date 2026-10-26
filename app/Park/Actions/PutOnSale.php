<?php

namespace App\Park\Actions;

use App\Cars\Identity;
use App\Mail\Actions\LinkThread;
use App\Offers\CarPlace;
use App\Offers\Intake\DraftSource;
use App\Offers\Intake\OpenDraft;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Validation\ValidationException;

/**
 * «В продажу» в деле ТС: черновик продажи этой машины (`Cars\Car`): тождество, фото и документы у них общие. Город —
 * город парковки (без неё — город ТС), вендор — парковки (у продажи он свой: `Vendor::forCrm`), закупочная — по правилу
 * от оценочной (`Pricing::floorFrom`), пока её не вписали. Продажа этой машины уже была (снимали, архив) — она и
 * возвращается в черновики, второй не заводится. Письма CRM о той же машине едут к ней. Обратно — «Убрать из продажи»
 * (`TakeOffSale`). Без человека (`by` null) — сама, `AutoSale`.
 */
final class PutOnSale
{
    public function __construct(private OpenDraft $open, private LinkThread $threads) {}

    public function __invoke(Vehicle $vehicle, ?User $by): Offer
    {
        if ($vehicle->offer) {
            return $vehicle->offer;
        }
        $car = $vehicle->carRecord();
        [$offer, $outcome] = ($this->open)(new DraftSource('park', car: $car, fields: [
            // Без парковки — город ТС (`city_id` — населённый пункт, `Park\City`).
            'settlement_id' => $vehicle->yard?->settlement_id ?? $vehicle->city_id,
            'car_place' => $vehicle->state === VehicleState::Stored ? CarPlace::Ours : null,
            'vendor_id' => $vehicle->vendor_id,
            'floor_price' => $car->value ? Pricing::floorFrom((int) $car->value) : null,
        ], created: ['park' => $vehicle->id], why: 'С парковки', reopenCancelled: false), $by);
        // Отказ поставщика — не продажа: сказать как есть (было «уже продана» и для отменённой).
        if (in_array($offer->state, [OfferState::Cancelled, OfferState::Delivered], true)) {
            throw ValidationException::withMessages(['vehicle' => ($offer->state === OfferState::Cancelled ? 'По этому ТС поставщик отказал: ' : 'Это ТС уже продано: ').Identity::offerName($offer)]);
        }
        if ($outcome === OpenDraft::CREATED) {
            $vehicle->log(EventType::Linked, $by, ['number' => $offer->number]);
            $this->threads->forOffer($offer->refresh());
        }

        return $offer;
    }
}
