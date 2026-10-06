<?php

namespace App\Garage\Actions;

use App\Offers\Actions\AssignPickup;
use App\Offers\CarPlace;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Park\VehicleState;
use App\Users\User;
use App\Vendors\Kind;
use App\Workflow\Actions\EnsureServiceWorkflow;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Track;

/**
 * У гаражной машины вывоз есть всегда (06.10.2026): где она физически — это он, а не этап гаража. Везём к её
 * менеджеру (`Destination::Keeper`), без менеджера — к нам. Кто везёт: менеджер, мы (null) или как было (false).
 * Где встать: `owner` — с начала (машина у страхователя; у лизинговой и прочих — сразу «Вывоз и осмотр», связываться
 * со страхователем там не с кем), `collect` — «Вывоз и осмотр» (можно забирать), `arrived` — «Стоит у …».
 * Забрали уже или машина на нашей парковке (её двигает выдача, `SyncOffer`) — не трогаем.
 */
final class EnsurePickup
{
    public function __construct(private AssignPickup $assign, private EnsureServiceWorkflow $workflow, private PlaceOnStage $place) {}

    public function __invoke(Offer $offer, User $by, User|false|null $evacuator = false, string $where = 'owner'): Offer
    {
        $offer = $offer->fresh(['vendor.workflows', 'positions.stage.workflow', 'parkVehicle.requests', 'evacuator', 'garageCar.manager']);
        $car = $offer->garageCar;
        if (! $car || ! $offer->vendor || $offer->pickedUp() || $offer->parkVehicle?->state === VehicleState::Stored) {
            return $offer;
        }
        $workflow = ($this->workflow)($offer->vendor);
        if (! $workflow) {
            return $offer;
        }
        $offer->load('vendor.workflows');
        $to = $car->manager_id ? Destination::Keeper : Destination::Ours;
        $who = $evacuator === false ? $offer->evacuator : $evacuator;
        if (! $car->manager_id && $who) {
            $who = null;
        }
        if (! $offer->position(Track::Service) || $offer->pickupDestination() !== $to || $offer->evacuator_id !== $who?->id) {
            $offer = ($this->assign)($offer, $who, $to, $by)->fresh(['positions.stage.workflow', 'vendor.workflows']);
        }

        $target = match (true) {
            $where === 'arrived' => $workflow->stageForPlace($to === Destination::Keeper ? CarPlace::Keeper : CarPlace::WithUs),
            $where === 'collect' || $offer->vendor->kind !== Kind::Insurer => $workflow->stageForPlace(CarPlace::Moving),
            default => null,
        };
        // Только вперёд: пройденное вывозом не отматываем.
        $at = $offer->position(Track::Service)?->stage;
        $order = fn ($s) => [$s->block->position, $s->position];
        if ($target && $at && $order($at) < $order($target)) {
            $offer = ($this->place)($offer, $target, $by);
        }

        return $offer;
    }
}
