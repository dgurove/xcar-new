<?php

namespace App\Offers\Actions;

use App\Logistics\Actions\SyncPickupTransport;
use App\Offers\Destination;
use App\Offers\Events\PickupAssigned;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Кто вывозит ТС и куда (04.10.2026). Без гаража предложение живёт обычной жизнью, менеджер только забирает ТС и
 * держит у себя (или мы — у себя). Машину в гараже везут к её менеджеру (`Offer::keeper`), сам он или мы. Парковки
 * здесь нет: вывоз CRM дел на park.xcar не заводит.
 * Запускает маршрут вывоза, если его ещё нет; поменять можно, пока ТС не забрали.
 * «Мы» (без менеджера) с 10.10.2026 — это логистика: перевозку заводит `SyncPickupTransport` (`transport: false` — её
 * сейчас же заведёт и назначит сам вызывающий, шторка «Вывоз» → `SendToLogistics`).
 */
final class AssignPickup
{
    public function __construct(private StartRoute $start, private SyncPickupTransport $transport) {}

    public function __invoke(Offer $offer, ?User $evacuator, Destination $to, User $by, bool $transport = true): Offer
    {
        // Супер-покупатель забирает только машину своей сделки — так его и назначает «Принять».
        $ownDeal = $evacuator?->isSuperBuyer() && $offer->deal()->where('buyer_id', $evacuator->id)->exists();
        if ($evacuator && ! ($evacuator->isManager() && $evacuator->canGarage()) && ! $ownDeal) {
            throw ValidationException::withMessages(['evacuator_id' => 'Вывозить может только менеджер']);
        }
        // К менеджеру без вывозчика-менеджера — только к держателю гаража: везём мы, стоять у него.
        if ($to === Destination::Keeper && ! $evacuator && ! $offer->garageCar()->exists()) {
            throw ValidationException::withMessages(['evacuation_to' => '«К менеджеру» можно, только когда вывозит менеджер или ТС у него в гараже']);
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

        return DB::transaction(function () use ($offer, $evacuator, $to, $by, $position, $transport) {
            $previous = $offer->evacuator;
            $offer->update(['evacuator_id' => $evacuator?->id, 'evacuation_to' => $to->value]);
            $offer->log(OfferEventType::PickupAssigned, $by, ['who' => $evacuator?->name ?? 'логистика', 'to' => $to->value]);
            if (! $position) {
                $offer = ($this->start)($offer->load('vendor.workflows'), $by, Track::Service);
            }
            if ($transport) {
                ($this->transport)($offer->fresh(['positions.stage', 'garageCar.manager']), $by);
            }
            PickupAssigned::dispatch($offer->fresh(), $previous, $by);

            return $offer;
        });
    }
}
