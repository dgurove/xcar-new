<?php

namespace App\Park\Actions;

use App\Mail\Actions\LinkThread;
use App\Offers\Actions\CreateOffer;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * «В продажу» в деле ТС: черновик предложения этой машины. Своих файлов у него нет — фото и документы ТС он видит сам
 * (`Offers\SaleMedia`), тождество зеркалит связь (`Park\Sale`); город — город парковки, закупочная пустая. Письма CRM
 * о той же машине едут к нему. Обратно — «Снять с продажи» в CRM (`UnlistParkOffer`).
 */
final class PutOnSale
{
    public function __construct(private CreateOffer $create, private LinkOffer $link, private LinkThread $threads) {}

    public function __invoke(Vehicle $vehicle, User $by): Offer
    {
        if ($vehicle->offer) {
            return $vehicle->offer;
        }

        return DB::transaction(function () use ($vehicle, $by) {
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
}
