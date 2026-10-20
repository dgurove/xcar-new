<?php

namespace App\Park\Listeners;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Logistics\Transport;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\Events\VehicleAccepted;
use App\Park\Events\VehicleReleased;
use App\Park\Events\VehicleRestored;
use App\Park\Vehicle;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * ТС с парковки в продаже — одна машина (`Cars\Car`): где она, знает парковка, «где машина» у предложения —
 * производное. Вывоз в логистике парковка не двигает и не снимает (08.10.2026: вывоз CRM дел на парковке не
 * заводит). Без связанного предложения слушатель молчит.
 */
final class SyncOffer
{
    public function __construct(private SetCarPlace $setPlace, private TakeExit $take) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            VehicleAccepted::class => 'accepted',
            VehicleReleased::class => 'released',
            VehicleRestored::class => 'restored',
        ];
    }

    public function accepted(VehicleAccepted $e): void
    {
        $this->moveTo($e->vehicle, CarPlace::Ours, $e->by);
    }

    /** Приём отменён или ТС снова ждём: у владельца. */
    public function restored(VehicleRestored $e): void
    {
        $this->moveTo($e->vehicle, CarPlace::Owner, $e->by);
    }

    /**
     * Выдали с парковки: если продажа ждёт передачи машины (своя машина, Stock), шаг «Автомобиль передан» делается сам.
     * Гаражную машину выдали её менеджеру (без менеджера — нам): стоит у него, гараж начинает подготовку (06.10.2026:
     * шага «Выдача — в гараж» у продажи больше нет).
     */
    public function released(VehicleReleased $e): void
    {
        if (! ($offer = $this->offer($e->vehicle))) {
            return;
        }
        $by = $e->by ?? $this->system();
        $offer->log(OfferEventType::Note, $e->by, ['text' => 'Выдана с парковки'.($e->vehicle->yard ? ' «'.$e->vehicle->yard->name.'»' : '')]);
        if ($car = $offer->garageCar()->first()) {
            // Выдали её менеджеру (без менеджера — нам): стоит у него, подготовка начинается.
            ($this->setPlace)($offer, $car->manager_id ? CarPlace::Keeper : CarPlace::WithUs, $by);
            if ($car->state === CarState::Waiting) {
                $car->moveTo(CarState::Repair, by: $by);
                GarageChanged::dispatch($car);
            }

            return;
        }
        // Продана на аукционе (20.10.2026): выдали покупателю аукциона — машина у него, маршрут уже закрыт.
        if ($offer->state === \App\Offers\OfferState::Delivered && \App\Offers\Deal::where('offer_id', $offer->id)->auctionSold()->exists()) {
            ($this->setPlace)($offer, CarPlace::Buyer, $by);

            return;
        }
        $exit = $offer->position(Track::Sale)?->stage->exitsFor(Actor::Staff, $offer->deal)->first(fn ($x) => mb_strtolower($x->label) === 'автомобиль передан');
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Staff, $by);
        }
        // Выдали менеджеру сделки (Альфа: выдача после оплаты, 18.10.2026) — его вывоз «Забрал», иначе задача висит, и
        // сделку без «Забрал» не закрыть (`Deal::closeBlocker`). Везёт логистика — её «Забрал» жмёт исполнитель.
        $deal = $offer->deal()->first();
        // Только если выдали по пропуску этой сделки: выдача страховой, её покупателю или вендору — не «у менеджера».
        $byDeal = $deal?->buyer && $e->vehicle->passes()->where('deal_id', $deal->id)->whereNotNull('used_at')->exists();
        $t = $byDeal ? Transport::openFor($offer) : null;
        if ($t && $t->isPickup() && ! $t->state->picked() && in_array($t->channel, [null, \App\Logistics\Channel::Manager], true)) {
            app(\App\Logistics\Actions\SelfPickup::class)($offer->fresh(), $deal->buyer, $by);
        } elseif ($byDeal && ! $t) {
            // С нашей парковки по пропуску сделки, вывоза не было (машину на парковке логистика не возит): у менеджера.
            ($this->setPlace)($offer, CarPlace::Keeper, $by);
        }
    }

    /** У машины идёт свой вывоз в логистике — где она, решает он, а не приём на парковке. */
    private function moveTo(Vehicle $vehicle, CarPlace $place, ?User $by): void
    {
        if (($offer = $this->offer($vehicle)) && ! Transport::openFor($offer)) {
            ($this->setPlace)($offer, $place, $by);
        }
    }

    private function offer(Vehicle $vehicle): ?Offer
    {
        return $vehicle->offer()->with('positions.stage.workflow')->first();
    }

    private function system(): User
    {
        return User::withRole(Role::Admin)->orderBy('id')->firstOrFail();
    }
}
