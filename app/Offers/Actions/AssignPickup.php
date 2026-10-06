<?php

namespace App\Offers\Actions;

use App\Offers\CarPlace;
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
 * Кто вывозит ТС и куда (04.10.2026). Без гаража предложение живёт обычной жизнью, менеджер только забирает ТС и
 * держит у себя (или мы — у себя вне парковки). Машину в гараже везут к её менеджеру (`Offer::keeper`), сам он или мы. На парковку — прежний путь: дело на park.xcar с заявкой на эвакуацию.
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
        // К менеджеру без вывозчика-менеджера — только к держателю гаража: везём мы, стоять у него.
        if ($to === Destination::Keeper && ! $evacuator && ! $offer->garageCar()->exists()) {
            throw ValidationException::withMessages(['evacuation_to' => 'К менеджеру — когда вывозит менеджер или машина у него в гараже']);
        }
        // Машину в гараже везут всегда (`Garage\Actions\EnsurePickup`, 06.10.2026): «В гараже» вывоз назначается.
        if (in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)
            || ($offer->state === OfferState::Garage && ! $offer->garageCar()->exists())) {
            throw ValidationException::withMessages(['evacuator_id' => 'Предложение '.mb_strtolower($offer->state->label()).', вывоз уже не назначить']);
        }
        $workflow = $offer->vendor?->workflow(Track::Service);
        if (! $workflow?->is_active) {
            throw ValidationException::withMessages(['evacuator_id' => 'У вендора нет маршрута вывоза']);
        }
        $position = $offer->position(Track::Service);
        // Забрали — место уже факт: менять, кто и куда, поздно (назад — «Отменить» шага).
        if ($offer->pickedUp()) {
            throw ValidationException::withMessages(['evacuator_id' => 'ТС уже забрали']);
        }
        // Развилка «куда» пройдена (перегон на парковку) — кто вывозит, поменять можно, а куда — нет: ветка уже выбрана.
        $pastFork = $position?->stage->car_place === CarPlace::Moving
            && ! $position->stage->exits->contains(fn ($e) => Destination::tryFrom((string) $e->branch) !== null);
        if ($pastFork && $to !== $offer->pickupDestination()) {
            throw ValidationException::withMessages(['evacuation_to' => 'ТС уже в пути, куда везём — не поменять']);
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
