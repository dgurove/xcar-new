<?php

namespace App\Offers\Actions;

use App\Offers\Destination;
use App\Offers\Events\PickupAssigned;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\Actions\CloseRequest;
use App\Park\Actions\RequestTowFromOffer;
use App\Users\User;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Кто вывозит ТС и куда (04.10.2026). Это не гараж: предложение живёт обычной жизнью, менеджер только забирает ТС и
 * держит у себя (или мы — у себя вне парковки). На парковку — прежний путь: дело на park.xcar с заявкой на эвакуацию.
 * Запускает маршрут вывоза, если его ещё нет; поменять можно, пока ТС не забрали.
 */
final class AssignPickup
{
    public function __construct(private StartRoute $start, private RequestTowFromOffer $tow, private CloseRequest $close) {}

    public function __invoke(Offer $offer, ?User $evacuator, Destination $to, User $by): Offer
    {
        if ($evacuator && ! ($evacuator->isManager() && $evacuator->canGarage())) {
            throw ValidationException::withMessages(['evacuator_id' => 'Вывозить может только менеджер']);
        }
        if ($to === Destination::Keeper && ! $evacuator) {
            throw ValidationException::withMessages(['evacuation_to' => 'К менеджеру — когда вывозит менеджер']);
        }
        if (in_array($offer->state, [OfferState::Garage, OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)) {
            throw ValidationException::withMessages(['evacuator_id' => 'Предложение '.mb_strtolower($offer->state->label()).', вывоз уже не назначить']);
        }
        $workflow = $offer->vendor?->workflow(Track::Service);
        if (! $workflow?->is_active) {
            throw ValidationException::withMessages(['evacuator_id' => 'У вендора нет маршрута вывоза']);
        }
        $position = $offer->position(Track::Service);
        // Забрали — место уже факт: менять, кто и куда, поздно (назад — «Отменить» шага).
        if ($position && in_array($position->stage->car_place?->value, ['keeper', 'with_us', 'ours'], true)) {
            throw ValidationException::withMessages(['evacuator_id' => 'ТС уже забрали']);
        }

        return DB::transaction(function () use ($offer, $evacuator, $to, $by, $position) {
            $previous = $offer->evacuator;
            $wasYard = $position && $offer->pickupDestination() === Destination::Yard;
            $offer->update(['evacuator_id' => $evacuator?->id, 'evacuation_to' => $to->value]);
            $offer->log(OfferEventType::PickupAssigned, $by, ['who' => $evacuator?->name ?? 'мы', 'to' => $to->value]);
            if (! $position) {
                $offer = ($this->start)($offer->load('vendor.workflows'), $by, Track::Service);
            } elseif ($wasYard && $to !== Destination::Yard && ($vehicle = $offer->parkVehicle)) {
                // Было на парковку, стало мимо неё: заявка на эвакуацию парковке больше не нужна.
                foreach ($vehicle->requests->filter(fn ($r) => $r->isTow() && $r->isOpen()) as $open) {
                    ($this->close)($open, $by, false, 'Вывозят не на парковку');
                }
            } elseif (! $wasYard && $to === Destination::Yard) {
                ($this->tow)($offer, $by);
            }
            PickupAssigned::dispatch($offer->fresh(), $previous, $by);

            return $offer;
        });
    }
}
