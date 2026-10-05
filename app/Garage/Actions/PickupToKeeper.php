<?php

namespace App\Garage\Actions;

use App\Offers\Actions\AssignPickup;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Track;

/**
 * Машина ушла в гараж, а вывоз ещё идёт — везти её к менеджеру в гараж, а не к нам или на парковку (05.10.2026,
 * Бородин: вывоз остался «к нам»). Кто везёт, не трогаем. Забрали уже — поздно, это факт.
 */
final class PickupToKeeper
{
    public function __construct(private AssignPickup $assign) {}

    public function __invoke(Offer $offer, User $by): void
    {
        $offer->loadMissing('vendor.workflows', 'positions.stage.workflow', 'parkVehicle.requests', 'evacuator');
        if (! $offer->position(Track::Service) || $offer->pickedUp() || $offer->pickupDestination() === Destination::Keeper) {
            return;
        }
        ($this->assign)($offer, $offer->evacuator, Destination::Keeper, $by);
    }
}
