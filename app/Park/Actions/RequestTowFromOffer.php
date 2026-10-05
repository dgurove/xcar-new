<?php

namespace App\Park\Actions;

use App\Cars\Identity;
use App\Offers\Offer;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Users\User;

/** «Нужен вывоз» на оффере → ТС на стоянке «ожидается» и заявка на эвакуацию с контактом страхователя. */
final class RequestTowFromOffer
{
    public function __construct(private CreateRequest $create) {}

    public function __invoke(Offer $offer, User $by): ?Request
    {
        // ТС этой машины уже есть (номер убытка или VIN, `Identity`) — вывоз на неё, второй ТС не заводим.
        $vehicle = Vehicle::where('offer_id', $offer->id)->first() ?? Identity::vehicleFor($offer->claim_ref_key, $offer->vin);
        if ($vehicle && (($vehicle->offer_id && $vehicle->offer_id !== $offer->id) || $vehicle->state->isFinal() || $vehicle->openRequest(RequestType::Tow))) {
            return null;
        }
        if ($vehicle && ! $vehicle->offer_id) {
            $vehicle->update(['offer_id' => $offer->id]);
        }
        $data = [
            'ref' => $offer->claim_ref, 'vin' => $offer->vin, 'year' => $offer->year, 'brand_id' => $offer->brand_id, 'model_id' => $offer->model_id,
            'color' => $offer->color, 'vendor_id' => $offer->vendor_id, 'category' => $offer->body?->category()->value,
            'contact_name' => $offer->insured_name, 'contact_phone' => $offer->insured_phone, 'from_address' => $offer->inspection_address,
            'flags' => $offer->flags ?? [], 'docs_required' => $offer->docs_required ?? [], 'value' => $offer->floor_price,
            'note' => 'Вывоз по предложению № '.$offer->number,
        ];
        $request = ($this->create)($by, RequestType::Tow, $vehicle, $data);
        if (! $vehicle) {
            // Моделью, а не запросом: связь переносит файлы предложения к ТС (`Vehicle::saved`).
            Vehicle::find($request->vehicle_id)?->update(['offer_id' => $offer->id]);
        }

        return $request;
    }
}
