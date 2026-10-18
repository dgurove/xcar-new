<?php

namespace App\Offers\Actions;

use App\Logistics\Transport;
use App\Offers\Deal;
use App\Park\Actions\MarkSold;
use App\Park\VehicleState;
use App\Users\User;

/**
 * Альфа, 18.10.2026 (владелец: «выдача сама после оплаты»): машина сделки стоит на нашей парковке или едет туда, оплата
 * подтверждена (`deals.prepaid_at`) — парковке «продано, заберёт …» сами, не дожидаясь письма Альфы на storage@
 * (исключение из «на парковке только storage@»): заявка на выдачу с получателем — тем, кто забирает (исполнитель вывоза,
 * иначе менеджер сделки). Машину ещё ждём — продажа записана, выдача встанет при приёме (`Park\Listeners\ReleaseSold`).
 */
final class HandToParking
{
    public function __construct(private MarkSold $sold) {}

    public function __invoke(Deal $deal, ?User $by): void
    {
        $offer = $deal->offer;
        $vehicle = $offer?->parkVehicle()->first();
        if (! $vehicle || ! in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true) || $vehicle->sold_at) {
            return;
        }
        $pickup = Transport::openFor($offer);
        $who = $pickup?->executor ?? $deal->buyer;
        if (! $who) {
            return;
        }
        ($this->sold)($vehicle, $by, now(), $who->name, $who->phone);
        $vehicle->log(\App\Park\EventType::Note, $by, ['text' => 'Оплачено по сделке № '.$offer->number.', заберёт '.$who->name]);
        // Вывоз — с парковки, а не от владельца: откуда ехать исполнителю.
        if ($pickup && ! $pickup->state->picked() && $vehicle->yard) {
            $pickup->update(['from_address' => 'Парковка «'.$vehicle->yard->name.'», '.$vehicle->yard->fullAddress()]);
        }
        $offer->log(\App\Offers\OfferEventType::Note, $by, ['text' => 'Парковке: на выдачу, заберёт '.$who->name]);
    }
}
