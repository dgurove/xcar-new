<?php

namespace App\Park\Actions;

use App\Mail\Actions\LinkThread;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * «В продажу» в деле ТС: черновик предложения этой машины. Своих файлов у него нет — фото и документы ТС он видит сам
 * (`Offers\SaleMedia`), тождество зеркалит связь (`Park\Sale`); город — город парковки, закупочная пустая. Письма CRM
 * о той же машине едут к нему; убирали из продажи — прежнее предложение возвращается из архива. Обратно — «Убрать из
 * продажи» (`TakeOffSale`).
 */
final class PutOnSale
{
    public function __construct(private CreateOffer $create, private LinkOffer $link, private LinkThread $threads, private ChangeOfferState $change) {}

    public function __invoke(Vehicle $vehicle, User $by): Offer
    {
        if ($vehicle->offer) {
            return $vehicle->offer;
        }

        return DB::transaction(function () use ($vehicle, $by) {
            // Убирали из продажи, а предложение лежит в архиве — возвращаем его в черновики (номер, цены, история те же),
            // а не заводим второе.
            if ($prev = $this->archived($vehicle)) {
                ($this->change)($prev, OfferState::Draft, $by);
                $vehicle->refresh()->offer_id || ($this->link)($vehicle, $prev->refresh(), $by);

                return $prev->refresh();
            }
            $offer = ($this->create)($by, array_filter([
                'settlement_id' => $vehicle->yard?->settlement_id,
                'car_place' => $vehicle->state === VehicleState::Stored ? CarPlace::Ours : null,
                'prices_include_vat' => $vehicle->vendor_id ? Vendor::offersVat($vehicle->vendor_id) : null,
            ], fn ($v) => $v !== null), ['park' => $vehicle->id]);
            ($this->link)($vehicle, $offer, $by);
            $this->threads->forOffer($offer->refresh());

            return $offer;
        });
    }

    /** Прежнее предложение этой ТС — по последней отвязке в её истории, если оно в архиве и ни с кем не связано. */
    private function archived(Vehicle $vehicle): ?Offer
    {
        $number = $vehicle->events()->where('type', EventType::Unlinked)->reorder()->latest('id')->first()?->payload['number'] ?? null;
        $offer = $number ? OfferNumber::find($number) : null;

        return $offer && $offer->state === OfferState::Archived && ! Vehicle::where('offer_id', $offer->id)->exists() ? $offer : null;
    }
}
