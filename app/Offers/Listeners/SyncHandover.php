<?php

namespace App\Offers\Listeners;

use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\Handover;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * Менеджер сделки сам забирает ТС у владельца (04.10.2026) — два маршрута про одно событие: продажа («Менеджер
 * забирает автомобиль» → «Автомобиль забрал») и вывоз («Вывоз и осмотр» → «Стоит у менеджера»). Нажали в одном —
 * другой догоняет сам, в каком бы порядке это ни случилось:
 * — продажа ушла с шага получения → вывоз встаёт на «Стоит у менеджера»;
 * — вывоз дошёл до «Стоит у менеджера» (админ нажал «Забрал» за него) → продажа жмёт свой «Автомобиль забрал»;
 * — продажа пришла на шаг получения, а ТС уже у него → шаг проходит сам.
 */
final class SyncHandover
{
    public function __construct(private PlaceOnStage $place, private TakeExit $take) {}

    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'entered'];
    }

    public function entered(StageEntered $e): void
    {
        $offer = $e->offer;
        $deal = $e->deal ?? $offer->deal()->first();
        if (! $deal || $deal->isGarage() || $offer->evacuator_id !== $deal->buyer_id) {
            return;
        }
        $deal->setRelation('offer', $offer);
        $by = $e->by ?? $this->system();

        if ($e->track === Track::Sale) {
            // Ушли с шага получения вперёд — ТС у менеджера.
            if ($e->exit && $e->from && $e->from->exits->contains(fn ($x) => $x->actor === Actor::Manager && Handover::picked($x))
                && ($service = $offer->position(Track::Service)) && ! $offer->pickedUp()
                && ($keeper = $service->stage->workflow->stageForPlace(CarPlace::Keeper))) {
                ($this->place)($offer, $keeper, $by);

                return;
            }
            // Пришли на шаг получения, а ТС уже у него.
            if ($offer->position(Track::Service)?->stage->car_place === CarPlace::Keeper) {
                $this->pressSale($deal, $by);
            }

            return;
        }
        if ($e->to->car_place === CarPlace::Keeper && $deal->isActive()) {
            $this->pressSale($deal, $by);
        }
    }

    private function pressSale(Deal $deal, User $by): void
    {
        $offer = $deal->offer->unsetRelation('positions');
        $exit = $offer->position(Track::Sale)?->stage->exitsFor(Actor::Manager, $deal)->first(fn ($x) => Handover::picked($x));
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Manager, $by);
        }
    }

    private function system(): User
    {
        return User::withRole(Role::Admin)->orderBy('id')->firstOrFail();
    }
}
