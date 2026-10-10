<?php

namespace App\Logistics\Actions;

use App\Logistics\Choices;
use App\Logistics\Events\TransportRequested;
use App\Logistics\Scope;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Вывоз CRM и логистика — одно (10.10.2026): вывоз «Мы» (без менеджера) везёт логистика, поэтому у него всегда есть
 * открытая перевозка — без исполнителя она «Ждёт логиста», откуда — адрес осмотра, кто принимает — держатель гаража.
 * Вывоз поручили менеджеру — открытая перевозка отменяется; машину уже везут — поручить нельзя. Зовёт `AssignPickup`:
 * одна дверь для «Принять», гаража (`EnsurePickup`) и шторки «Вывоз».
 */
final class SyncPickupTransport
{
    public function __construct(private Cancel $cancel) {}

    public function __invoke(Offer $offer, User $by): ?Transport
    {
        $open = Transport::openFor($offer);
        if ($offer->evacuator_id) {
            if ($open?->state === TransportState::Moving) {
                throw ValidationException::withMessages(['evacuator_id' => 'Машину уже везут: сначала «Сдал» или отмена в логистике']);
            }
            if ($open) {
                ($this->cancel)($open, $by, system: true);
            }

            return null;
        }
        if ($open || ! $offer->evacuation_to || $offer->pickedUp()) {
            return $open;
        }
        $keeper = $offer->pickupDestination() === Destination::Keeper ? $offer->keeper() : null;
        $t = Transport::create([
            'offer_id' => $offer->id, 'scope' => Scope::Local, 'from_region_id' => Choices::regionOf($offer),
            'from_address' => $offer->inspection_address,
            'receiver' => $keeper ? trim($keeper->name.($keeper->phone ? ', '.$keeper->phoneFormatted() : '')) : null,
            'state' => TransportState::New, 'created_by' => $by->id,
        ]);
        TransportRequested::dispatch($t);

        return $t;
    }
}
